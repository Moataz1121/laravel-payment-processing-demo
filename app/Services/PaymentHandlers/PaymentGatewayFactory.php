<?php

namespace App\Services\PaymentHandlers;

use App\Models\PaymentGateway;
use App\Services\PaymentHandlers\Contracts\PaymentGatewayInterface;
use App\Services\PaymentHandlers\Strategies\MockPaymentGateway;
use App\Services\PaymentHandlers\Strategies\MoyasarPaymentGateway;
use App\Services\PaymentHandlers\Strategies\StripePaymentGateway;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /**
     * Mapping of provider / gateway slug to strategy class implementation.
     *
     * @var array<string, class-string<PaymentGatewayInterface>>
     */
    protected array $strategies = [
        'moyasar' => MoyasarPaymentGateway::class,
        'stripe' => StripePaymentGateway::class,
        'mock' => MockPaymentGateway::class,
    ];

    /**
     * Resolve Strategy instance by PaymentGateway UUID (or slug fallback).
     *
     * @throws InvalidArgumentException
     */
    public function create(string $gatewayId): PaymentGatewayInterface
    {
        $query = PaymentGateway::query();

        if (Str::isUuid($gatewayId)) {
            $query->where('id', $gatewayId);
        } else {
            $query->where('slug', strtolower($gatewayId));
        }

        $gatewayModel = $query->first();

        if (! $gatewayModel) {
            throw new InvalidArgumentException("Payment gateway '{$gatewayId}' was not found.");
        }

        if (! $gatewayModel->is_enabled) {
            throw new InvalidArgumentException("Payment gateway '{$gatewayModel->name}' is currently disabled.");
        }

        $lookupKey = strtolower($gatewayModel->slug ?? $gatewayModel->provider);

        if (! isset($this->strategies[$lookupKey])) {
            throw new InvalidArgumentException("No strategy handler implementation registered for gateway '{$lookupKey}'.");
        }

        $strategyClass = $this->strategies[$lookupKey];

        return app($strategyClass);
    }
}
