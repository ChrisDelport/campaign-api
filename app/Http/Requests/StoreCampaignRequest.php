<?php

/**
 * Validation rules for POST /api/campaigns.
 *
 * end_date is optional (the spec lists it as such) - a campaign without one
 * is open-ended. When present it must not precede start_date; start_date is
 * the anchor so the comparison works even when end_date is absent.
 *
 * client_id gets an exists: rule rather than a foreign-key-style array check
 * so the 422 error message is user-facing, not a 500 from the DB constraint.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
