<?php

namespace Tests\Unit;

use App\Http\Controllers\MisAvisosController;
use PHPUnit\Framework\TestCase;

/**
 * Guardrail de creación de keywords (avisos-keyword-word-boundary).
 *
 * Una keyword demasiado corta ("el", "a") matchearía casi todo el corpus
 * vía substring — inflaría el coste del scan compartido. El mínimo de 3
 * caracteres se evalúa sobre la forma NORMALIZADA (asciiLower + trim), de
 * modo que " a " o "Á" no lo burlan.
 */
class KeywordMinLengthGuardrailTest extends TestCase
{
    public function testKeywordsDemasiadoCortasSeRechazan(): void
    {
        $this->assertFalse(MisAvisosController::passesMinLength('el'));
        $this->assertFalse(MisAvisosController::passesMinLength('a'));
        $this->assertFalse(MisAvisosController::passesMinLength('é'));
    }

    public function testEspaciosYTildesNoBurlanElMinimo(): void
    {
        // 2 caracteres efectivos rodeados de espacios.
        $this->assertFalse(MisAvisosController::passesMinLength(' el '));
        // Dos letras pegadas, con o sin tilde, miden 2 tras normalizar.
        $this->assertFalse(MisAvisosController::passesMinLength('él'));
        $this->assertFalse(MisAvisosController::passesMinLength('áé'));
        // El espacio intermedio cuenta como carácter (keyword multi-palabra
        // "gustavo petro" es válida), así que "á é" normalizado mide 3 y
        // pasa el mínimo — comportamiento aceptado y documentado en design.md.
        $this->assertTrue(MisAvisosController::passesMinLength('á é'));
    }

    public function testKeywordsDeLongitudJustaSeAceptan(): void
    {
        $this->assertTrue(MisAvisosController::passesMinLength('petro'));
        $this->assertTrue(MisAvisosController::passesMinLength('OEA'));
        $this->assertTrue(MisAvisosController::passesMinLength(' el carro '));
    }

    public function testGustavoPetroSeAcepta(): void
    {
        $this->assertTrue(MisAvisosController::passesMinLength('Gustavo Petro'));
    }
}