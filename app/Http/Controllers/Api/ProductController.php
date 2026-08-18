<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * List active products catalog.
     */
    public function index(): JsonResponse
    {
        $products = Product::where('is_active', true)
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    /**
     * List products successfully paid for and purchased by the authenticated user.
     */
    public function purchased(Request $request): JsonResponse
    {
        $user = $request->user();

        $products = Product::whereHas('orderItems.order', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('status', OrderStatus::COMPLETED);
        })
        ->with(['orderItems' => function ($query) use ($user) {
            $query->whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where('status', OrderStatus::COMPLETED);
            });
        }])
        ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }
}
