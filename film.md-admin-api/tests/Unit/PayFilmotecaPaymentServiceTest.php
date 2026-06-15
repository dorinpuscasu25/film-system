<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PayFilmotecaPaymentService;
use App\Services\WalletService;
use Tests\TestCase;

class PayFilmotecaPaymentServiceTest extends TestCase
{
    public function test_provider_customer_name_falls_back_to_safe_default_when_name_is_blank(): void
    {
        $service = $this->paymentService();

        $user = new User([
            'name' => '   ',
            'email' => 'viewer@example.com',
        ]);

        $this->assertSame('Client Filmoteca', $service->customerName($user));
    }

    public function test_provider_customer_name_is_sanitized_for_provider(): void
    {
        $service = $this->paymentService();

        $user = new User([
            'name' => 'Ion Țurcanu @ Film.md!',
            'email' => 'viewer@example.com',
        ]);

        $this->assertSame('Ion Turcanu Film.md', $service->customerName($user));
    }

    public function test_provider_phone_is_normalized_to_e164(): void
    {
        $service = $this->paymentService();

        $this->assertSame('+37379018018', $service->providerPhone('079018018'));
        $this->assertSame('+37379018018', $service->providerPhone('+37379018018'));
        $this->assertSame('', $service->providerPhone('abc123'));
    }

    private function paymentService(): PayFilmotecaPaymentService
    {
        return new class($this->createMock(WalletService::class)) extends PayFilmotecaPaymentService
        {
            public function customerName(User $user): string
            {
                return $this->resolveProviderCustomerName($user);
            }

            public function providerPhone(string $phone): string
            {
                return $this->normalizePhoneForProvider($phone);
            }
        };
    }
}
