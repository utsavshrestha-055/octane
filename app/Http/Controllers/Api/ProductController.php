<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    private const MAX_ID_CACHE = 'products:max_id';

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
     * Returns one random product.
     */
    public function index(): JsonResponse
    {
        // Upper bound for the random ID (cached briefly to avoid a DB hit per request).
        $maxId = (int) $this->redis()->remember(
            self::MAX_ID_CACHE,
            60,
            fn() => (int) Product::max('id')
        );

        if ($maxId < 1) {
            return $this->notFound();
        }

        $product = $this->findProduct(random_int(1, $maxId));

        if ($product === null) {
            return $this->notFound();
        }

        return $this->success('Product retrieved successfully.', $product);
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
        $redis->forever($this->productCacheKey($product->id), $productData);

        // Make the new product immediately eligible for random selection.
        $redis->forget(self::MAX_ID_CACHE);

        return $this->success('Product created successfully.', $productData, 201);
    }

    /**
     * GET /api/products/{id}
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->findProduct($id);

        if ($product === null) {
            return $this->notFound();
        }

        return $this->success('Product retrieved successfully.', $product);
    }

    /**
     * Cache first, then DB. Only caches when the product exists.
     */
    private function findProduct(int $id): ?array
    {
        $redis = $this->redis();
        $key   = $this->productCacheKey($id);

        $cached = $redis->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $product = Product::query()
            ->select(['id', 'name'])
            ->whereKey($id)
            ->first()   
            ?->toArray();

        if ($product === null) {
            return null;
        }

        $redis->forever($key, $product);

        return $product;
    }

    private function success(string $message, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Product not found.',
            'data'    => null,
        ], 404);
    }
}
