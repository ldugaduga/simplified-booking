<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\BookingController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', BookingController::INSTANT],
        ];
    }
}
