<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\PaymentGatewayController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Public authentication routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public catalog & options routes
Route::get('/products', [ProductController::class, 'index']);
Route::get('/payment-gateways', [PaymentGatewayController::class, 'index']);

// Public payment callback & webhook routes (invoked by gateways or browser redirects)
Route::match(['get', 'post'], '/payments/callback/{gateway}', [PaymentCallbackController::class, 'callback']);
Route::post('/payments/webhook/{gateway}', [PaymentCallbackController::class, 'webhook']);

// Protected routes (requires valid Sanctum Bearer token)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Purchased Products & Orders
    Route::get('/purchased-products', [ProductController::class, 'purchased']);
    Route::get('/orders', [OrderController::class, 'index']);

    // Checkout endpoint
    Route::post('/checkout', [CheckoutController::class, 'checkout']);
});
