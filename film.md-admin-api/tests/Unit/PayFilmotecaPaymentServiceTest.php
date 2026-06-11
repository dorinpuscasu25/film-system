<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PayFilmotecaPaymentService;
use App\Services\WalletService;
use Tests\TestCase;

class PayFilmotecaPaymentServiceTest extends TestCase
{
    public function test_provider_customer_name_falls_back_to_email_when_name_is_blank(): void
    {
        $service = new class($this->createMock(WalletService::class)) extends PayFilmotecaPaymentService
        {
            public function customerName(User $user): string
            {
                return $this->resolveProviderCustomerName($user);
            }
        };

        $user = new User([
            'name' => '   ',
            'email' => 'viewer@example.com',
        ]);

        $this->assertSame('viewer@example.com', $service->customerName($user));
    }
}
