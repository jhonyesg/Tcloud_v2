<?php

namespace App\Modules\Correo\Services;

use App\Modules\Correo\Models\CorreoConfig;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class ConfigService
{
    public function getActiveConfig(): ?CorreoConfig
    {
        return CorreoConfig::where('is_active', true)->first();
    }

    public function saveConfig(array $data): CorreoConfig
    {
        if (isset($data['password'])) {
            $data['password_encrypted'] = $data['password'];
            unset($data['password']);
        }

        $config = CorreoConfig::where('is_active', true)->first() ?? new CorreoConfig();
        
        if ($config->exists) {
            $config->update($data);
        } else {
            $data['is_active'] = true;
            $config = CorreoConfig::create($data);
        }

        return $config;
    }

    public function testConnection(CorreoConfig $config): array
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
                ->to('test@example.com')
                ->subject('Test Connection')
                ->text('Test');

            $mailer->send($email);

            return ['success' => true, 'message' => 'Conexión exitosa'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function getConfigForDisplay(): ?array
    {
        $config = $this->getActiveConfig();
        if (!$config) {
            return null;
        }

        return [
            'id' => $config->id,
            'host' => $config->host,
            'port' => $config->port,
            'secure' => $config->secure,
            'user' => $config->username,
            'from_name' => $config->from_name,
            'from_email' => $config->from_email,
            'is_active' => $config->is_active,
        ];
    }
}
