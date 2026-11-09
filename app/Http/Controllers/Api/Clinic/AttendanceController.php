<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\AttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Clinic;
use App\Support\ClinicContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * List attendance, newest day first.
     *
     * Filter by `clinic_user_id`, `status` (`PRESENT`, `ABSENT`, `LATE`,
     * `EXCUSED`) and the day (`from` / `to`, dates in the clinic's timezone).
     * `meta.summary` counts each status for the same filters.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'clinic_user_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', Rule::in(AttendanceStatus::codes())],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $base = fn () => $clinic->attendances()
            ->when($filters['clinic_user_id'] ?? null, fn ($query, int|string $id) => $query->where('clinic_user_id', $id))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('work_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('work_date', '<=', $to));

        $attendances = $base()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('attendance_status_id', AttendanceStatus::fromCode($status)))
            ->with(['clinicUser.user', 'recordedBy.user'])
            ->orderByDesc('work_date')
            ->orderBy('clinic_user_id')
            ->paginate($filters['per_page'] ?? 25);

        $counts = $base()
            ->selectRaw('attendance_status_id, count(*) as total')
            ->groupBy('attendance_status_id')
            ->pluck('total', 'attendance_status_id');

        return AttendanceResource::collection($attendances)->additional(['meta' => [
            'summary' => collect(AttendanceStatus::cases())
                ->mapWithKeys(fn (AttendanceStatus $status): array => [$status->code() => (int) ($counts[$status->value] ?? 0)])
                ->all(),
        ]]);
    }

    /**
     * Record a member's working day.
     *
     * One row per member per day. A member without the Clinic Admin role may
     * only record their own attendance.
     */
    public function store(AttendanceRequest $request, Clinic $clinic, ClinicContext $context): AttendanceResource
    {
        $this->ensureMayRecordFor($context, $request->integer('clinic_user_id'));

        $attendance = $clinic->attendances()->create([
            ...$request->attendanceAttributes(),
            'recorded_by_clinic_user_id' => $context->member()->id,
        ]);

        return new AttendanceResource($attendance->load(['clinicUser.user', 'recordedBy.user']));
    }

    /**
     * Get one attendance record.
     */
    public function show(Clinic $clinic, Attendance $attendance): AttendanceResource
    {
        return new AttendanceResource($attendance->load(['clinicUser.user', 'recordedBy.user']));
    }

    /**
     * Change a day's status, times or notes. The member and the date do not change.
     */
    public function update(AttendanceRequest $request, Clinic $clinic, Attendance $attendance, ClinicContext $context): AttendanceResource
    {
        $this->ensureMayRecordFor($context, $attendance->clinic_user_id);

        $attendance->update($request->attendanceAttributes());

        return new AttendanceResource($attendance->load(['clinicUser.user', 'recordedBy.user']));
    }

    /**
     * Delete an attendance record.
     */
    public function destroy(Clinic $clinic, Attendance $attendance, ClinicContext $context): Response
    {
        $this->ensureMayRecordFor($context, $attendance->clinic_user_id);

        $attendance->delete();

        return response()->noContent();
    }

    /**
     * Only a Clinic Admin may write attendance for someone else.
     */
    private function ensureMayRecordFor(ClinicContext $context, int $clinicUserId): void
    {
        $member = $context->member();

        if (! $member->isAdmin() && $member->id !== $clinicUserId) {
            throw ValidationException::withMessages([
                'clinic_user_id' => __('Only a Clinic Admin can record attendance for another member.'),
            ]);
        }
    }
}
