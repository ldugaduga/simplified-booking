<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWeeklyHoursRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array'],
            'rules.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i', 'after:rules.*.start_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.*.end_time.after' => 'The end time must be after the start time.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $byWeekday = collect($this->input('rules'))
                    ->map(fn (array $rule, int $index) => $rule + ['index' => $index])
                    ->groupBy('weekday');

                foreach ($byWeekday as $ranges) {
                    $sorted = $ranges->sortBy('start_time')->values();

                    for ($i = 1; $i < $sorted->count(); $i++) {
                        $previous = $sorted[$i - 1];
                        $current = $sorted[$i];

                        if ($current['start_time'] < $previous['end_time']) {
                            $validator->errors()->add(
                                "rules.{$current['index']}.start_time",
                                "Overlaps with {$previous['start_time']} - {$previous['end_time']}.",
                            );
                        }
                    }
                }
            },
        ];
    }
}
