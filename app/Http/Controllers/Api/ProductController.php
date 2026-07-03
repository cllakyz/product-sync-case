<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Paginated, filterable local product listing (soft-deleted excluded by default).
     */
    public function index(ProductIndexRequest $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->when($request->input('provider'), fn ($q, $v) => $q->where('provider_type', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', '%'.$v.'%'))
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->float('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->float('max_price')))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('stock', '>', 0))
            ->orderBy($request->sortColumn(), $request->sortDirection() === 'asc' ? 'asc' : 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($products, ProductResource::class, 'Ürün listesi.');
    }
}
