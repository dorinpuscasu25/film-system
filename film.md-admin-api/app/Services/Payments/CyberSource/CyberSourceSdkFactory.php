<?php

namespace App\Services\Payments\CyberSource;

use RuntimeException;
use Victoriabank\CyberSource\Client;
use Victoriabank\CyberSource\Transport;
use Victoriabank\CyberSource\VbCyberSource;

/**
 * Builds the Victoriabank CyberSource SDK from config. Tests swap the transport with
 * useTransport(); everything else (request building, rule checks R1–R13, JWT
 * verification, result interpretation) stays the bank's own code.
 */
class CyberSourceSdkFactory
{
    protected ?Transport $transport = null;

    public function isConfigured(): bool
    {
        $config = $this->config();

        return filled($config['merchant_id'] ?? null)
            && filled($config['key_id'] ?? null)
            && filled($config['shared_secret'] ?? null)
            && ($config['target_origins'] ?? []) !== [];
    }

    public function useTransport(?Transport $transport): void
    {
        $this->transport = $transport;
    }

    public function make(string $currency): VbCyberSource
    {
        $config = $this->config();
        $acceptAttempted = $config['accept_attempted_3ds'] ?? null;

        return new VbCyberSource($this->transport(), strtoupper($currency), true, $acceptAttempted === null ? null : (bool) $acceptAttempted);
    }

    public function transport(): Transport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('CyberSource is not configured.');
        }

        $config = $this->config();

        // The SDK client only signs (JWT v2, HS256); Laravel sends the request. The SDK client refuses
        // 'production' because it has no Message-Level Encryption; production needs the official SDK
        // transport (see the plan doc).
        $signer = new Client(
            (string) $config['merchant_id'],
            (string) $config['key_id'],
            (string) $config['shared_secret'],
            (string) ($config['environment'] ?? 'test'),
            filled($config['base_url'] ?? null) ? (string) $config['base_url'] : null,
        );

        return $this->transport = new LaravelCyberSourceTransport($signer, max(10, (int) ($config['timeout'] ?? 65)));
    }

    protected function config(): array
    {
        return (array) config('services.cybersource', []);
    }
}
