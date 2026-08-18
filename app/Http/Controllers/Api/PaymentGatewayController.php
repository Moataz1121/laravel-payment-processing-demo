<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\JsonResponse;

class PaymentGatewayController extends Controller
{
    /**
     * List active payment gateways available for user selection.
     */
    public function index(): JsonResponse
    {
        $gateways = PaymentGateway::where('is_enabled', true)
            ->orderBy('sort_order', 'asc')
            ->select(['id', 'name', 'slug', 'provider', 'description', 'sort_order'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $gateways,
        ]);
    }
}
