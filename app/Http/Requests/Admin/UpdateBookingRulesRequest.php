<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRulesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slot_minutes' => ['required', 'integer', Rule::in([15, 30, 45, 60])],
            'buffer_minutes' => ['required', 'integer', 'between:0,120'],
            'min_notice_hours' => ['required', 'integer', 'between:0,720'],
            'max_days_ahead' => ['required', 'integer', 'between:1,365'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
