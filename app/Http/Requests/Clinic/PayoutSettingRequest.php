<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saves a clinic's payout configuration: the default cut its staff take, the
 * two payout rules, and any per-member overrides, all in one request.
 */
class PayoutSettingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return [
            'worker_share_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'include_pending_payments' => ['sometimes', 'boolean'],
            'require_attendance' => ['sometimes', 'boolean'],
            'member_shares' => ['sometimes', 'array'],
            'member_shares.*.clinic_user_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('clinic_users', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
            ],
            'member_shares.*.share_percentage' => ['present', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    /**
     * The clinic-wide settings to save.
     *
     * @return array<string, mixed>
     */
    public function settingAttributes(): array
    {
        return $this->safe()->only(['worker_share_percentage', 'include_pending_payments', 'require_attendance']);
    }

    /**
     * Per-member overrides, keyed by membership ID. A null share clears the
     * override so the member follows the clinic default again.
     *
     * @return array<int, string|null>
     */
    public function memberShares(): array
    {
        $shares = [];

        foreach ((array) $this->input('member_shares', []) as $row) {
            $shares[(int) $row['clinic_user_id']] = $row['share_percentage'] === null ? null : (string) $row['share_percentage'];
        }

        return $shares;
    }
}
