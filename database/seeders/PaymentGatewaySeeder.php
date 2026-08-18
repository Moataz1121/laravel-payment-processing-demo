<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            [
                'name' => 'Moyasar Payment Gateway',
                'slug' => 'moyasar',
                'provider' => 'moyasar',
                'is_enabled' => true,
                'creds' => [
                    'api_key' => 'sk_test_moyasar_key_sample',
                    'publishable_key' => 'pk_test_moyasar_key_sample',
                ],
                'settings' => [
                    'currency' => 'USD',
                ],
                'description' => 'Accept Credit Cards and Apple Pay via Moyasar',
                'sort_order' => 1,
            ],
            [
                'name' => 'Stripe Payments',
                'slug' => 'stripe',
                'provider' => 'stripe',
                'is_enabled' => true,
                'creds' => [
                    'secret_key' => 'sk_test_stripe_sample_key',
                    'publishable_key' => 'pk_test_stripe_sample_key',
                    'webhook_secret' => 'whsec_sample_key',
                ],
                'settings' => [
                    'currency' => 'USD',
                ],
                'description' => 'Global Payments with Credit Card & Digital Wallets via Stripe',
                'sort_order' => 2,
            ],
            [
                'name' => 'Mock Gateway (Testing)',
                'slug' => 'mock',
                'provider' => 'mock',
                'is_enabled' => true,
                'creds' => [],
                'settings' => [],
                'description' => 'Instant simulated payment gateway for local API testing',
                'sort_order' => 3,
            ],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::updateOrCreate(
                ['slug' => $gateway['slug']],
                $gateway
            );
        }
    }
}
