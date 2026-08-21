<?php

namespace App\Services\Ia;

use App\Models\Keyword;

/**
 * Clasifica una regla del diccionario (wrong -> correct) para separar las
 * correcciones legítimas de español de las traducciones inglés->español.
 *
 * Motivo (2026-08-11): 583 reglas aprobadas eran pares ASCII->español
 * generados por `corrections:ai-suggest` / `corrections:mine-en-es` y
 * auto-aprobadas en risk_level='low'. Concentraban 200.696 de ~218.000
 * aplicaciones totales: `the`->`la` (84.011), `in`->`en` (41.104),
 * `and`->`y` (38.281), `are`->`están`, `where`->`donde`...
 *
 * Un motor de find/replace no puede traducir: la traducción necesita contexto
 * y concordancia. El resultado era espanglish PEOR que el original:
 *
 *   raw: "The cooperativas are dotadas of two motors."
 *   out: "la cooperativas están dotadas of two motors."
 *   raw: "Where are you from?"  ->  out: "donde están you from?"
 *
 * El clasificador se usa en dos sitios:
 *   1. `corrections:quarantine-en-es` — cuarentena del diccionario existente.
 *   2. Los guardrails de generación, para que un par así no vuelva a entrar.
 */
class EnEsRuleClassifier
{
    /** Regla que no cambia nada (wrong === correct): borrar. */
    public const NOISE = 'noise';

    /** Corrección legítima de tilde/ortografía española: conservar. */
    public const KEEP = 'keep';

    /** Traducción EN->ES sin contexto: cuarentena (risk_level='high'). */
    public const QUARANTINE = 'quarantine';

    /** Ambigua: ASCII -> ASCII. La decide un humano, nunca el --apply. */
    public const REVIEW = 'review';

    /**
     * Clasifica un par wrong -> correct.
     *
     * @return array{bucket: string, reason: string}
     */
    public function classify(string $wrong, string $correct): array
    {
        $wrong = trim($wrong);
        $correct = trim($correct);

        if ($wrong === '' || $correct === '') {
            return ['bucket' => self::REVIEW, 'reason' => 'par vacío'];
        }

        // 0. Regla que no cambia nada. Va PRIMERO porque el test de tilde de
        // abajo la daba por buena ("corrección de tilde/mayúsculas") al plegar
        // ambos lados al mismo ASCII: así se colaron 48 reglas inertes que el
        // corrector recorre en cada pasada sobre 20,6M de segmentos sin hacer
        // nada. Ojo, es igualdad EXACTA: `mas`->`más` sí cambia y no es ruido.
        if ($wrong === $correct) {
            return ['bucket' => self::NOISE, 'reason' => 'no cambia nada (wrong === correct)'];
        }

        // 1. Corrección de tilde: quitarle los diacríticos al correct devuelve
        // exactamente el wrong. mas->más, politica->política, region->región,
        // unica->única. Es el caso legítimo mayoritario y va primero para que
        // ninguna heurística posterior se lo lleve por delante.
        if ($this->foldToAscii($correct) === $this->foldToAscii($wrong)) {
            return ['bucket' => self::KEEP, 'reason' => 'corrección de tilde/mayúsculas'];
        }

        // 2. Palabra función inglesa en el wrong = traducción, tenga o no
        // tildes sueltas. Va ANTES del test de ASCII porque el corpus está
        // lleno de frases mixtas ("The canción alcanzó el número 7",
        // "in Nápoles"): mirar solo si el wrong es ASCII dejaba 406 reglas de
        // traducción marcadas como "español" por una tilde perdida.
        if ($this->containsEnglishFunctionWord($wrong)) {
            return ['bucket' => self::QUARANTINE, 'reason' => 'palabra función inglesa'];
        }

        // 3. Grafemas imposibles en español: si el wrong no puede ser una
        // palabra española, la regla no está corrigiendo español. Caza
        // work->trabajo, knowledge->conocimiento, shopping->compras.
        if ($this->looksNonSpanish($wrong)) {
            return ['bucket' => self::QUARANTINE, 'reason' => 'grafema no español en el wrong'];
        }

        // 4. Sin marcadores ingleses y con acentos/ñ propios: es español
        // corrigiendo español (reformulación, concordancia, typo con tilde).
        if ($this->hasNonAscii($wrong)) {
            return ['bucket' => self::KEEP, 'reason' => 'wrong es español, sin marcadores ingleses'];
        }

        // 5. wrong ASCII + correct acentuado y con otro significado =
        // traducción. baby->bebé, football->fútbol, design->el diseño.
        if ($this->hasNonAscii($correct)) {
            return ['bucket' => self::QUARANTINE, 'reason' => 'traducción EN->ES'];
        }

        // 6. ASCII -> ASCII sin marcadores. Aquí conviven correcciones reales
        // de español (echa->hecha, quando->cuando) y basura (dise->de). Lo
        // decide un humano: --apply no toca este cubo.
        return ['bucket' => self::REVIEW, 'reason' => 'ASCII->ASCII ambiguo, revisar a mano'];
    }

    /**
     * ¿El par `wrong`->`correct` es un arreglo de ORTOGRAFÍA y no un cambio de
     * significado?
     *
     * Es la puerta de los reemplazos de una o dos palabras, que disparan en todo
     * el corpus sin mirar el contexto. Parecerse no basta como criterio:
     * `presidenta`->`presidente` se parece un 90 % y cambia el género;
     * `ahorita`->`ahora`, un 83 %, y cambia el matiz. Por eso en vez de un umbral
     * de similitud se exige encajar en uno de los cuatro patrones ortográficos
     * que el ASR produce de verdad, todos ellos reversibles y sin carga
     * semántica.
     */
    public function isOrthographicVariant(string $wrong, string $correct): bool
    {
        $wrong = trim($wrong);
        $correct = trim($correct);
        $a = $this->foldToAscii($wrong);
        $b = $this->foldToAscii($correct);

        if ($a === '' || $b === '' || $wrong === $correct) {
            return false;
        }

        // 1. Solo cambian tildes o mayúsculas: mas->más, region->región.
        if ($a === $b) {
            return true;
        }

        // 2. Solo cambia la h, que es muda: echa->hecha, aora->ahora.
        if (str_replace('h', '', $a) === str_replace('h', '', $b)) {
            return true;
        }

        // 3. E protética: el español no admite s+consonante en inicio de palabra,
        // de ahí estrategia, España, espacio. strategia->estrategia.
        if (preg_match('/^s[bcdfglmnpqrstv]/', $a) && $b === 'e' . $a) {
            return true;
        }

        // 4. Consonante doble imposible en español sobre una palabra por lo demás
        // española: difficultades->dificultades, opportunidades->oportunidades.
        return $this->isMisspelledSpanish($wrong);
    }

    /**
     * ¿El lado `wrong` está ortográficamente MAL escrito en español?
     *
     * Sirve para decidir si un reemplazo de una o dos palabras es un arreglo de
     * ortografía (seguro) o un cambio de significado (peligroso). La diferencia
     * no la da el parecido entre las cadenas — `presidenta`->`presidente` se
     * parecen un 90 % y cambian el género — sino si el original puede existir
     * en español:
     *
     *   opportunidades  'pp' imposible en español  -> typo, arreglarlo es seguro
     *   professionales  'ff' y 'ss' imposibles     -> typo
     *   presidenta      palabra española válida    -> cambiarla es semántico
     *   ahorita         palabra española válida    -> cambiarla es semántico
     *
     * Deliberadamente conservador: solo dice `true` ante evidencia positiva de
     * que la grafía no puede ser española. Un typo por letra omitida
     * (`infrastructura`) no se detecta y cae del lado prudente, sin proponerse.
     */
    public function isMisspelledSpanish(string $wrong): bool
    {
        $text = $this->foldToAscii($wrong);

        if ($text === '') {
            return false;
        }

        // Primero se descarta lo que directamente NO es español. Es la distinción
        // que importa: `innocent` y `different` tampoco son grafías españolas
        // válidas, pero no son españolas mal escritas — son inglesas, y
        // sustituirlas es traducir. Solo una palabra con forma española por fuera
        // puede ser una española mal escrita.
        if ($this->looksNonSpanish($text)) {
            return false;
        }

        // Terminaciones consonánticas ajenas al español: las palabras españolas
        // acaban en vocal o en n, r, l, s, d, z, j, x. Acabar en t, c, m, p, g,
        // b, f, v, h delata extranjerismo (`innocent`, `different`, `top`).
        if (preg_match('/[tcmpgbfvh]$/', $text)) {
            return false;
        }

        // La -y final tampoco es española salvo en un puñado de casos.
        // Descarta `interdisciplinary`.
        if (str_ends_with($text, 'y') && !in_array($text, self::SPANISH_Y_ENDINGS, true)) {
            return false;
        }

        // Sufijos exclusivos del inglés. Ojo con los que sí existen en español:
        // -able/-ible (posible, amable) quedan fuera a propósito, y -sion también
        // porque `inversion` es "inversión" sin tilde, no una palabra inglesa.
        if (preg_match('/(tion|ity|ous|ive|ance|ence|ness|ship|hood)$/', $text)) {
            return false;
        }

        // Llegados aquí la palabra tiene forma española. La única evidencia
        // positiva de que está MAL escrita es una consonante doble imposible: el
        // español solo admite cc, ll, rr y nn, así que pp/ff/ss/tt/mm/bb/dd/gg/
        // zz/vv/jj delatan interferencia del inglés o el italiano, que es justo
        // lo que devuelve el ASR (`opportunidades`, `professionales`).
        return (bool) preg_match('/(pp|ff|ss|tt|mm|bb|dd|gg|zz|vv|jj)/', $text);
    }

    /**
     * ¿La palabra tiene forma de palabra española?
     *
     * Prueba POSITIVA de morfología, para usarla donde antes había denylists.
     * `CycleSuggestionsCommand` decidía si un token era un sustantivo español
     * enumerando lo que no lo era; `emergency` no estaba en la lista y así nació
     * la regla `of emergency`->`de emergency`, que deja el espanglish intacto.
     *
     * No afirma que la palabra exista, solo que podría: descarta grafías y
     * terminaciones imposibles en español.
     */
    public function looksSpanishWord(string $word): bool
    {
        $text = $this->foldToAscii($word);

        if ($text === '' || mb_strlen($text) < 2) {
            return false;
        }

        // Un diacrítico español zanja la cuestión: ninguna palabra inglesa los
        // lleva. Va antes que los sufijos porque el plegado a ASCII los borra y
        // deja falsos positivos — `gestión` se convierte en `gestion`, que acaba
        // en `-tion` y pasaría por inglesa. Igual `cuestión`, `congestión`.
        if (preg_match('/[áéíóúüñÁÉÍÓÚÜÑ]/u', $word)) {
            return true;
        }

        if ($this->looksNonSpanish($text)) {
            return false;
        }

        if (preg_match('/[tcmpgbfvh]$/', $text)) {
            return false;
        }

        if (str_ends_with($text, 'y') && !in_array($text, self::SPANISH_Y_ENDINGS, true)) {
            return false;
        }

        // `-ive` queda FUERA a propósito: en español lo llevan verbos corrientes
        // (`vive`, `revive`, `sobrevive`) y marcarlos como ingleses inundaba de
        // falsos positivos el análisis del corpus.
        return !preg_match('/(tion|ity|ous|ance|ence|ness|ship|hood|ing|ment|less|ful)$/', $text);
    }

    /** Las únicas palabras españolas frecuentes acabadas en -y. */
    private const SPANISH_Y_ENDINGS = [
        'y', 'muy', 'hoy', 'rey', 'ley', 'buey', 'soy', 'voy', 'doy', 'estoy', 'hay', 'ay', 'convoy',
    ];

    private function hasNonAscii(string $text): bool
    {
        return (bool) preg_match('/[^\x00-\x7F]/', $text);
    }

    /**
     * ¿El texto contiene grafemas que el español no usa?
     *
     * 'k' y 'w' solo aparecen en extranjerismos; los dígrafos th/sh/ck/ph/wh/kn/gh
     * directamente no existen. Si el wrong los tiene, no está corrigiendo una
     * palabra española. Deliberadamente conservador: los extranjerismos ya
     * asentados (kilo, whisky) saldrían marcados, pero aparecen en el informe
     * antes del --apply y la cuarentena es reversible.
     */
    private function looksNonSpanish(string $text): bool
    {
        return (bool) preg_match('/th|sh|ck|ph|wh|kn|gh|[kw]/i', $text);
    }

    /**
     * Minúsculas + transliteración ASCII. Reusa Keyword::asciiLower(), que es
     * la misma normalización con la que se construye `wrong_normalized`.
     */
    private function foldToAscii(string $text): string
    {
        return Keyword::asciiLower($text);
    }

    /**
     * ¿Algún token del wrong es una palabra función inglesa?
     *
     * Se mira token a token para cazar tanto `the` como `that is` o
     * `the other`, sin depender de la longitud de la frase.
     */
    private function containsEnglishFunctionWord(string $wrong): bool
    {
        $tokens = preg_split('/[^a-z0-9\']+/i', strtolower($wrong), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            if (in_array($token, self::ENGLISH_FUNCTION_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Palabras función / gramaticales del inglés. No incluye sustantivos ni
     * verbos de contenido a propósito: esos caen en REVIEW para que los mire
     * un humano en vez de que el --apply decida solo.
     *
     * Ojo con los homógrafos ES/EN: 'no', 'a', 'e', 'y', 'o', 'se', 'sin',
     * 'son', 'una', 'ha', 'van', 'da', 'fue', y también 'he', 'me' y 'has'
     * (yo he / me dijo / tú has) NO están en la lista porque son español
     * válido y meterlos convertiría el filtro en otro destrozo: con 'me'
     * dentro, la regla legítima "No me basta incontrar" -> "No me basta
     * encontrar" caía en cuarentena.
     *
     * No se pierde cobertura: una frase realmente inglesa trae siempre otros
     * marcadores ("Give it to me" tiene 'it' y 'to'; "Colombia has an excess"
     * tiene 'an').
     */
    private const ENGLISH_FUNCTION_WORDS = [
        // Artículos y determinantes
        'the', 'an', 'this', 'these', 'those', 'such', 'each', 'every',
        'both', 'either', 'neither', 'another',
        // Conjunciones y conectores
        'and', 'but', 'or', 'nor', 'because', 'although', 'though', 'while',
        'whereas', 'unless', 'since', 'if', 'then', 'than', 'so', 'yet',
        // Preposiciones
        'in', 'on', 'at', 'to', 'for', 'with', 'from', 'by', 'about',
        'into', 'onto', 'upon', 'over', 'under', 'above', 'below', 'between',
        'among', 'through', 'during', 'before', 'after', 'against', 'without',
        'within', 'toward', 'towards', 'across', 'behind', 'beyond',
        // Verbos auxiliares y copulativos
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am',
        'does', 'did', 'done', 'have', 'had', 'having',
        'will', 'would', 'shall', 'should', 'could', 'may', 'might',
        'must', 'ought',
        // Pronombres ('he' y 'me' fuera: son español)
        'i', 'you', 'she', 'it', 'we', 'they', 'him', 'her',
        'us', 'them', 'my', 'your', 'his', 'its', 'our', 'their', 'mine',
        'yours', 'hers', 'ours', 'theirs', 'myself', 'yourself', 'himself',
        'herself', 'itself', 'ourselves', 'themselves',
        // Interrogativos y relativos
        'who', 'whom', 'whose', 'which', 'what', 'when', 'where', 'why', 'how',
        'that', 'there', 'here',
        // Cuantificadores y adverbios de grado frecuentes
        'all', 'any', 'some', 'none', 'many', 'much', 'more', 'most', 'few',
        'less', 'least', 'other', 'others', 'same', 'very', 'too', 'only',
        'just', 'also', 'even', 'still', 'always', 'never', 'often',
        'sometimes', 'again', 'already', 'yes', 'not', 'nothing', 'something',
        'anything', 'everything', 'someone', 'anyone', 'everyone', 'nobody',
        // Auxiliares contraídos frecuentes en ASR
        "don't", "doesn't", "isn't", "aren't", "wasn't", "weren't", "can't",
        "won't", "it's", "that's", "there's", "i'm", "you're", "we're",
        "they're", "i've", "we've", "they've",
    ];
}
