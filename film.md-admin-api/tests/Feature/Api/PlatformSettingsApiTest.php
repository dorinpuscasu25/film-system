<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($admin, 'test-admin');
    }

    public function test_admin_can_manage_localized_contact_settings_exposed_to_storefront(): void
    {
        $contact = [
            'operator_name' => 'Operator Filmoteca',
            'email' => 'ajutor@filmoteca.md',
            'phone' => '+373 22 000 000',
            'address' => [
                'ro' => 'Strada Exemplu 1, Chișinău',
                'ru' => 'Примерная улица 1, Кишинёв',
                'en' => '1 Example Street, Chisinau',
            ],
            'working_hours' => [
                'ro' => 'Luni–Vineri, 09:00–18:00',
                'ru' => 'Понедельник–Пятница, 09:00–18:00',
                'en' => 'Monday–Friday, 09:00–18:00',
            ],
            'description' => [
                'ro' => 'Scrie-ne pentru ajutor.',
                'ru' => 'Напишите нам для помощи.',
                'en' => 'Contact us for help.',
            ],
        ];

        $this->putJson('/api/v1/admin/platform-settings', [
            'settings' => ['contact' => $contact],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->assertOk()
            ->assertJsonPath('settings.contact.operator_name', 'Operator Filmoteca')
            ->assertJsonPath('settings.contact.address.ro', 'Strada Exemplu 1, Chișinău');

        $this->getJson('/api/v1/public/settings?locale=en')
            ->assertOk()
            ->assertJsonPath('contact.operator_name', 'Operator Filmoteca')
            ->assertJsonPath('contact.email', 'ajutor@filmoteca.md')
            ->assertJsonPath('contact.address', '1 Example Street, Chisinau')
            ->assertJsonPath('contact.working_hours', 'Monday–Friday, 09:00–18:00')
            ->assertJsonPath('contact.description', 'Contact us for help.');
    }

    public function test_contact_settings_validate_support_email(): void
    {
        $this->putJson('/api/v1/admin/platform-settings', [
            'settings' => [
                'contact' => [
                    'email' => 'not-an-email',
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.contact.email');
    }
}
