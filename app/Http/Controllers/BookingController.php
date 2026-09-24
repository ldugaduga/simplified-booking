<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Appointment;
use App\Models\Setting;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    private const MAX_WINDOW_DAYS = 45;

    private const SLOT_TAKEN = 'That time is no longer available. Please pick another.';

    public function __construct(private SlotService $slots) {}

    /**
     * Show the public booking page.
     */
    public function show(): Response
    {
        $settings = Setting::current();

        return Inertia::render('booking/Book', [
            'businessName' => config('app.name'),
            'slotMinutes' => $settings->slot_minutes,
            'maxDaysAhead' => $settings->max_days_ahead,
        ]);
    }

    /**
     * Open slot start times (UTC) for a requested window.
     */
    public function slots(Request $request): JsonResponse
    {
        $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $start = CarbonImmutable::parse($request->query('start'))->utc();
        $end = CarbonImmutable::parse($request->query('end'))->utc();

        if ($start->diffInDays($end) > self::MAX_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'end' => 'The window can be at most '.self::MAX_WINDOW_DAYS.' days.',
            ]);
        }

        return response()->json([
            'slots' => $this->slots->availableSlots($start, $end)
                ->map(fn (CarbonImmutable $slot) => $slot->format('Y-m-d\TH:i:s\Z'))
                ->values(),
        ]);
    }

    /**
     * Book an open slot.
     */
    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $start = CarbonImmutable::parse($request->validated('start_at'))->utc();
        $length = Setting::current()->slot_minutes;

        try {
            $appointment = DB::transaction(function () use ($request, $start, $length) {
                if (! $this->isOpen($start)) {
                    throw ValidationException::withMessages(['start_at' => self::SLOT_TAKEN]);
                }

                return Appointment::create([
                    'start_at' => $start,
                    'end_at' => $start->addMinutes($length),
                    'status' => AppointmentStatus::Confirmed,
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    'notes' => $request->validated('notes'),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Someone else took this exact slot between the check and the insert.
            throw ValidationException::withMessages(['start_at' => self::SLOT_TAKEN]);
        }

        return redirect()->to(URL::temporarySignedRoute('booking.confirmed', now()->addDay(), $appointment));
    }

    /**
     * Show a booking confirmation; the signed URL keeps it private to the visitor.
     */
    public function confirmed(Appointment $appointment): Response
    {
        return Inertia::render('booking/Confirmed', [
            'name' => $appointment->name,
            'email' => $appointment->email,
            'start_at' => $appointment->start_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'end_at' => $appointment->end_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'slotMinutes' => $appointment->start_at->diffInMinutes($appointment->end_at),
            'businessName' => config('app.name'),
        ]);
    }

    private function isOpen(CarbonImmutable $start): bool
    {
        return $this->slots
            ->availableSlots($start, $start->addMinute())
            ->contains(fn (CarbonImmutable $slot) => $slot->equalTo($start));
    }
}
