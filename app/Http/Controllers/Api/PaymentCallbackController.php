<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentCallbackController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Handle payment gateway redirect callback.
     */
    public function callback(string $gateway, Request $request): JsonResponse
    {
        try {
            $result = $this->paymentService->handleCallback($gateway, $request);

            return response()->json([
                'status' => $result['success'] ? 'success' : 'error',
                'message' => $result['message'],
                'data' => $result,
            ], $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Handle payment gateway webhook asynchronous event.
     */
    public function webhook(string $gateway, Request $request): JsonResponse
    {
        try {
            $result = $this->paymentService->handleWebhook($gateway, $request);

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
