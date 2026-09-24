<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\BookingController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,filter', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
