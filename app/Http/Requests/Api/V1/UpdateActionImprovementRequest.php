<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateActionImprovementRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['string', 'max:255'],
            'detail' => ['string'],
            'due_date' => ['nullable', 'date'],
            'pic_email' => ['array'],
            'reminder' => ['boolean'],
            'reminder_frequency' => ['nullable', 'string'],
            'priority' => ['string', 'in:low,medium,high,critical'],
            'status' => ['string', 'in:pending,done,cancelled,tbd'],
            'result_note' => ['nullable', 'string'],
            'cancellation_reason' => ['nullable', 'string'],
        ];
    }
}
