<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * List authenticated user's orders with items, products, and payment status.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $orders = Order::where('user_id', $user->id)
            ->with(['orderItems.product', 'payments'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $orders,
        ]);
    }
}
