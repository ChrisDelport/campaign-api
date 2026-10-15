<?php

/**
 * Validation for POST /api/campaigns/{campaign}/data.
 *
 * The spec shows a bare top-level JSON array as the request body, which
 * Laravel's dot-notation validation doesn't address directly - so
 * prepareForValidation() normalises the payload: a bare list becomes
 * ['records' => [...]], and a single object is wrapped into a one-element
 * list. After that point everything downstream only deals with 'records'.
 *
 * The 5000-record cap bounds the queue payload size. Records are held in
 * memory by the queued job, so an unbounded array would let one request OOM
 * a worker. Real multi-million imports should chunk at dispatch time.
 *
 * custom_fields.* uses 'present' rather than 'nullable' - arbitrary keys and
 * arbitrary nesting are all allowed, we only insist it's an object/array.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCampaignDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No auth layer in this system (see README — assessment defines none).
        // Kept as an explicit method so adding policies later is one change.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The endpoint accepts a bare JSON array at the top level, but
        // Laravel's array validation ('records.*.user_id') needs a named
        // key to work against. Normalise both accepted shapes into
        // ['records' => list]:
        //   [ {...}, {...} ]   -> records: [ {...}, {...} ]
        //   { ...single... }   -> records: [ { ...single... } ]
        $payload = $this->json()->all();

        /** @var mixed $payload */
        if (! is_array($payload) || ! array_is_list($payload)) {
            // Not a list = single object (or garbage, which rules() reject).
            $payload = [$payload];
        }

        $this->merge(['records' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The cap bounds job payload size and upsert statement size. 5000
        // is comfortably handled in one INSERT ... ON DUPLICATE KEY UPDATE;
        // larger imports should chunk client-side (or we chunk at dispatch).
        return [
            'records' => ['required', 'array', 'max:5000'],
            // user_id is a string, not an int: the spec's "user-123" style
            // ids are external identifiers, and we must not coerce/mangle
            // them. Max length mirrors campaign_data.user_id's column.
            'records.*.user_id' => ['required', 'string', 'max:255'],
            // Validated as a real URL so the queue never processes garbage
            // that a downstream renderer would choke on.
            'records.*.video_url' => ['required', 'string', 'url', 'max:2048'],
            // Optional per spec. 'array' (not 'json') lets any shape through:
            // arbitrary keys, arbitrary nesting, mixed scalars. No schema
            // changes are ever needed for new field names.
            'records.*.custom_fields' => ['nullable', 'array'],
            // 'present' (not 'nullable' on leaves): once custom_fields is
            // given as an object, its keys must carry values — this only
            // rejects nulls inside the object, keeping arbitrary content legal.
            'records.*.custom_fields.*' => ['present'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'records.max' => 'A single request may contain at most :max records.',
        ];
    }
}
