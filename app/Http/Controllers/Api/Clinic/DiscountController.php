<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\DiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Models\Clinic;
use App\Models\Discount;
use App\Support\ClinicContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DiscountController extends Controller
{
    /**
     * List the discounts the member may use: the clinic-wide ones plus their
     * own personal ones. Another member's personal discounts are never
     * returned, not even to a Clinic Admin.
     *
     * Filter with `scope` (`CLINIC_WIDE` or `PERSONAL`), `active=1` and `search`.
     */
    public function index(Request $request, Clinic $clinic, ClinicContext $context): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['sometimes', 'string', Rule::in(['CLINIC_WIDE', 'PERSONAL'])],
            'active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $discounts = $clinic->discounts()
            ->visibleTo($context->member())
            ->when(($filters['scope'] ?? null) === 'CLINIC_WIDE', fn ($query) => $query->whereNull('clinic_user_id'))
            ->when(($filters['scope'] ?? null) === 'PERSONAL', fn ($query) => $query->whereNotNull('clinic_user_id'))
            ->when(isset($filters['active']), fn ($query) => $query->where('is_active', $filters['active']))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereLike('name', "%{$search}%"))
            ->with('clinicUser.user')
            // Clinic-wide first, then the member's own. Written out rather
            // than ordering by the nullable clinic_user_id, because MySQL
            // sorts NULLs first and PostgreSQL sorts them last.
            ->orderByRaw('clinic_user_id is null desc')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return DiscountResource::collection($discounts);
    }

    /**
     * Create a discount.
     *
     * `scope: "CLINIC_WIDE"` needs the Clinic Admin role; `"PERSONAL"`
     * creates one only its author can see and use. `target` says whose cut
     * pays for it: `CLINIC` or `WORKER`.
     */
    public function store(DiscountRequest $request, Clinic $clinic, ClinicContext $context): DiscountResource
    {
        if ($request->isClinicWide() && ! $context->member()->isAdmin()) {
            throw ValidationException::withMessages([
                'scope' => __('Only a Clinic Admin can create a clinic-wide discount.'),
            ]);
        }

        $discount = $clinic->discounts()->create([
            ...$request->discountAttributes(),
            'clinic_user_id' => $request->isClinicWide() ? null : $context->member()->id,
        ]);

        return new DiscountResource($discount->load('clinicUser.user'));
    }

    /**
     * Get one discount.
     */
    public function show(Clinic $clinic, Discount $discount, ClinicContext $context): DiscountResource
    {
        $this->ensureVisibleTo($discount, $context);

        return new DiscountResource($discount->load('clinicUser.user'));
    }

    /**
     * Edit a discount. The scope never changes: a personal discount cannot
     * become clinic-wide, or the other way round.
     */
    public function update(DiscountRequest $request, Clinic $clinic, Discount $discount, ClinicContext $context): DiscountResource
    {
        $this->ensureWritableBy($discount, $context);

        $discount->update($request->discountAttributes());

        return new DiscountResource($discount->load('clinicUser.user'));
    }

    /**
     * Archive a discount. Items already billed with it keep what it took off.
     */
    public function destroy(Clinic $clinic, Discount $discount, ClinicContext $context): Response
    {
        $this->ensureWritableBy($discount, $context);

        $discount->delete();

        return response()->noContent();
    }

    /**
     * Another member's personal discount is reported as missing, not forbidden.
     */
    private function ensureVisibleTo(Discount $discount, ClinicContext $context): void
    {
        abort_unless($discount->isClinicWide() || $discount->clinic_user_id === $context->member()->id, 404);
    }

    /**
     * Clinic-wide discounts are the Clinic Admin's; personal ones are their owner's.
     */
    private function ensureWritableBy(Discount $discount, ClinicContext $context): void
    {
        $this->ensureVisibleTo($discount, $context);

        if ($discount->isClinicWide() && ! $context->member()->isAdmin()) {
            throw ValidationException::withMessages([
                'scope' => __('Only a Clinic Admin can change a clinic-wide discount.'),
            ]);
        }
    }
}
