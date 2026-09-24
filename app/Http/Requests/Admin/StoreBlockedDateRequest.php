<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBlockedDateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $today = now(Setting::current()->timezone)->toDateString();

        return [
            'date' => ['required', 'date_format:Y-m-d', "after_or_equal:{$today}", 'unique:blocked_dates,date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'Choose today or a future date.',
            'date.unique' => 'This date is already blocked.',
        ];
    }
}
