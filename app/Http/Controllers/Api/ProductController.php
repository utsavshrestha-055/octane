<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    private const ALL_PRODUCTS_CACHE = 'products:all';

    private function redis()
    {
        return Cache::store('redis');
    }

    private function productCacheKey(int $id): string
    {
        return "products:{$id}";
    }

    /**
     * GET /api/products
     */
    public function index(): JsonResponse
    {
        $products = $this->redis()->rememberForever(
            self::ALL_PRODUCTS_CACHE,
            fn() => Product::query()
                ->select(['id', 'name'])
                ->get()
                ->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data'    => $products,
        ]);
    }

    /**
     * POST /api/products
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $product     = Product::create($validated);
        $productData = $product->only(['id', 'name']);

        $redis = $this->redis();

        // Cache the newly created product.
        $redis->forever(
            $this->productCacheKey($product->id),
            $productData);

        // Invalidate the list so the next GET rebuilds it.
        $redis->forget(self::ALL_PRODUCTS_CACHE);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data'    => $productData,
        ], 201);
    }

    /**
     * GET /api/products/{id}
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->redis()->rememberForever(
            $this->productCacheKey($id),
            fn() => Product::query()
                ->select(['id', 'name'])
                ->whereKey($id)
                ->first()
            ?->toArray()
        );

        if ($product === null) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data'    => $product,
        ]);
    }

}
