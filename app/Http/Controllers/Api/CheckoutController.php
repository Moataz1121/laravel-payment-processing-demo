<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Services\PaymentService;
use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Process order checkout and initiate payment gateway strategy via UUID.
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $result = $this->paymentService->initiateCheckout(
                user: $user,
                items: $request->input('items'),
                gatewayId: $request->input('payment_gateway_id'),
                idempotencyKey: $request->input('idempotency_key')
            );

            return response()->json([
                'status' => 'success',
                'message' => $result['message'] ?? 'Checkout initiated successfully',
                'data' => $result,
            ], $result['is_idempotent'] ? Response::HTTP_OK : Response::HTTP_CREATED);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
