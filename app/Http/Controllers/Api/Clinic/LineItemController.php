<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\LineItemRequest;
use App\Http\Resources\LineItemResource;
use App\Models\Clinic;
use App\Models\LineItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LineItemController extends Controller
{
    /**
     * List the clinic's billable item catalog.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $lineItems = $clinic->lineItems()
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('item_code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('item_code')
            ->paginate($filters['per_page'] ?? 25);

        return LineItemResource::collection($lineItems);
    }

    /**
     * Add an item to the catalog.
     */
    public function store(LineItemRequest $request, Clinic $clinic): LineItemResource
    {
        return new LineItemResource($clinic->lineItems()->create($request->validated()));
    }

    /**
     * Get a catalog item.
     */
    public function show(Clinic $clinic, LineItem $lineItem): LineItemResource
    {
        return new LineItemResource($lineItem);
    }

    /**
     * Update a catalog item.
     *
     * Price changes apply to items billed from now on only.
     */
    public function update(LineItemRequest $request, Clinic $clinic, LineItem $lineItem): LineItemResource
    {
        $lineItem->update($request->validated());

        return new LineItemResource($lineItem);
    }

    /**
     * Archive a catalog item.
     *
     * The item is soft-deleted: it can no longer be billed, but past bills keep it.
     */
    public function destroy(Clinic $clinic, LineItem $lineItem): Response
    {
        $lineItem->delete();

        return response()->noContent();
    }
}
