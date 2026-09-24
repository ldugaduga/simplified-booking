<?php

namespace App\Http\Requests;

use App\Http\Controllers\BookingController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StoreBookingRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 600;

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
            'company_website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_website.prohibited' => 'Something went wrong. Please try again.',
        ];
    }

    /**
     * Count every attempt, valid or not, so the form can't be hammered.
     */
    protected function prepareForValidation(): void
    {
        $key = 'booking:'.$this->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'start_at' => "Too many booking attempts. Please try again in {$minutes} ".str('minute')->plural($minutes).'.',
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
    }
}
