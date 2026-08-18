<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class MoyasarPaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PaymentGateway::query()->updateOrCreate(
            ['slug' => 'moyasar'],
            [
                'name' => 'Moyasar',
                'provider' => 'moyasar',
                'is_enabled' => true,
                'description' => 'Accept payments through Moyasar (Credit Cards, Wallets, etc.)',
                'sort_order' => 3,
                'creds' => [
                    'secret_key' => env('MOYASAR_SECRET_KEY', 'sk_test_xxx'),
                    'public_key' => env('MOYASAR_PUBLIC_KEY', 'pk_test_xxx'),
                ],
                'settings' => [
                    'test_mode' => (bool) env('MOYASAR_TEST_MODE', true),
                    'supported_currencies' => ['USD', 'SAR'],
                    'fees' => [
                        'fixed' => 0,
                        'percentage' => 2.9,
                    ],
                    'timeout' => 3600,
                    'allowed_card_types' => ['visa', 'mastercard', 'amex', 'mada'],
                    'webhook_url' => env('APP_URL') . '/api/payments/webhook/moyasar',
                ],
            ]
        );

        $this->command->info('Moyasar payment gateway updated successfully!');
    }
}
