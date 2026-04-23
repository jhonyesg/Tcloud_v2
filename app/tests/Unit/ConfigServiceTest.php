<?php

namespace Tests\Unit;

use App\Modules\Correo\Models\CorreoConfig;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Crypt;

class ConfigServiceTest extends TestCase
{
    public function testPasswordEncryptionAndDecryption(): void
    {
        $config = new CorreoConfig();
        $config->host = 'smtp.example.com';
        $config->port = 587;
        $config->secure = false;
        $config->user = 'test@example.com';
        $config->from_name = 'TCloud';
        $config->from_email = 'noreply@example.com';
        $config->is_active = true;

        $plainPassword = 'MySecretPassword123';
        $config->password_encrypted = $plainPassword;

        $this->assertNotEquals($plainPassword, $config->password_encrypted);
        
        $decrypted = $config->password_decrypted;
        $this->assertEquals($plainPassword, $decrypted);
    }

    public function testToSmtpArrayReturnsCorrectFormat(): void
    {
        $config = new CorreoConfig();
        $config->host = 'smtp.example.com';
        $config->port = 587;
        $config->secure = false;
        $config->user = 'test@example.com';
        $config->password_encrypted = 'secret';
        $config->from_name = 'TCloud';
        $config->from_email = 'noreply@example.com';

        $smtpArray = $config->toSmtpArray();

        $this->assertArrayHasKey('host', $smtpArray);
        $this->assertArrayHasKey('port', $smtpArray);
        $this->assertArrayHasKey('secure', $smtpArray);
        $this->assertArrayHasKey('auth', $smtpArray);
        $this->assertEquals('smtp.example.com', $smtpArray['host']);
        $this->assertEquals(587, $smtpArray['port']);
        $this->assertFalse($smtpArray['secure']);
    }
}
