<?php

namespace App\Modules\Correo\Services;

use App\Modules\Correo\Models\CorreoConfig;
use App\Modules\Correo\Models\CorreoLog;
use App\Modules\Correo\Models\CorreoPlantilla;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class NotificationService
{
    private ConfigService $configService;
    private PlantillaService $plantillaService;

    private int $maxRetries = 3;

    public function __construct(ConfigService $configService, PlantillaService $plantillaService)
    {
        $this->configService = $configService;
        $this->plantillaService = $plantillaService;
    }

    public function send(string $templateName, string $to, array $variables = []): array
    {
        $config = $this->configService->getActiveConfig();
        if (!$config) {
            return $this->logError($to, $templateName, null, 'Configuración de correo no encontrada');
        }

        $plantilla = $this->plantillaService->getByName($templateName);
        if (!$plantilla) {
            return $this->logError($to, $templateName, null, "Plantilla '$templateName' no encontrada");
        }

        $rendered = $this->plantillaService->renderTemplate($plantilla, $variables);

        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            $result = $this->attemptSend($config, $rendered['subject'], $rendered['body'], $to);

            if ($result['success']) {
                $this->logSuccess($to, $templateName, $rendered['subject'], $rendered['body']);
                return $result;
            }

            $lastError = $result['message'];
        }

        return $this->logError($to, $templateName, $rendered['subject'], $lastError);
    }

    private function attemptSend(CorreoConfig $config, string $subject, string $body, string $to): array
    {
        try {
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d',
                urlencode($config->username),
                urlencode($config->password_decrypted),
                $config->host,
                $config->port
            );

            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            $email = (new Email())
                ->from(new Address($config->from_email, $config->from_name))
                ->to($to)
                ->subject($subject)
                ->html($body);

            $mailer->send($email);

            return ['success' => true, 'message' => 'Correo enviado exitosamente'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function logSuccess(string $to, string $templateName, string $subject, string $body): void
    {
        CorreoLog::create([
            'destinatario' => $to,
            'plantilla' => $templateName,
            'asunto' => $subject,
            'body_sent' => $body,
            'estado' => CorreoLog::ESTADO_EXITO,
            'sent_at' => now(),
        ]);
    }

    private function logError(string $to, string $templateName, ?string $subject, string $errorMessage): array
    {
        CorreoLog::create([
            'destinatario' => $to,
            'plantilla' => $templateName,
            'asunto' => $subject ?? '',
            'body_sent' => null,
            'estado' => CorreoLog::ESTADO_ERROR,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);

        return ['success' => false, 'message' => $errorMessage];
    }

    public function getLogs(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return CorreoLog::orderBy('sent_at', 'desc')->limit($limit)->get();
    }
}
