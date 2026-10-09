<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    /**
     * List categories of the current store.
     *
     * Every query is scoped to the store resolved by the `current.store`
     * middleware. Pagination, search, active filter, and ordering are all
     * validated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Category::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'in:true,false,1,0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:name,created_at'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $categories = $store->categories()
            ->when(
                ($validated['search'] ?? '') !== '',
                fn ($query) => $query->where('name', 'like', '%'.$validated['search'].'%'),
            )
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy($validated['sort'] ?? 'name', $validated['direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return CategoryResource::collection($categories);
    }

    /**
     * Create a category in the current store.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);

        $category = $store->categories()->create([
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new CategoryResource($category))
            ->additional(['message' => 'Kategori berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a category of the current store.
     */
    public function show(Request $request, string $category): CategoryResource
    {
        $model = $this->resolveCategory($request, $category);

        Gate::authorize('view', $model);

        return new CategoryResource($model);
    }

    /**
     * Replace or partially update a category of the current store.
     *
     * PUT expects `name`; PATCH accepts partial input. Ownership and unique
     * rules are enforced per store by the request.
     */
    public function update(UpdateCategoryRequest $request, string $category): JsonResponse
    {
        $model = $this->resolveCategory($request, $category);

        $data = $request->validated();

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $model->update($data);

        return (new CategoryResource($model->refresh()))
            ->additional(['message' => 'Kategori berhasil diperbarui.'])
            ->response();
    }

    /**
     * Delete a category of the current store.
     *
     * Deletion is a hard delete. Once the catalog items table exists
     * (Phase 1b), a category that is still referenced by items must be
     * rejected here with a 409 instead of cascading item deletion.
     */
    public function destroy(Request $request, string $category): JsonResponse
    {
        $model = $this->resolveCategory($request, $category);

        Gate::authorize('delete', $model);

        $model->delete();

        return response()->json([
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }

    /**
     * Resolve a category strictly inside the current store.
     *
     * A missing id and an id belonging to another tenant behave identically
     * (404), so tenant existence is never leaked.
     */
    private function resolveCategory(Request $request, string $category): Category
    {
        return $this
            ->currentStore($request)
            ->categories()
            ->findOrFail($category);
    }

    /**
     * Current store resolved by the `current.store` middleware.
     */
    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
