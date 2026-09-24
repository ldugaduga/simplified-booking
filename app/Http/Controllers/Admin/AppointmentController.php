<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RescheduleAppointmentRequest;
use App\Http\Requests\Admin\StoreAppointmentRequest;
use App\Models\Appointment;
use App\Models\Setting;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    private const PER_PAGE = 20;

    private const TABS = ['upcoming', 'past', 'all'];

    public function __construct(private SlotService $slots) {}

    /**
     * List appointments with filters, plus the dashboard counts.
     */
    public function index(Request $request): Response
    {
        $settings = Setting::current();
        $tz = $settings->timezone;
        $filters = $this->filters($request);
        $now = CarbonImmutable::now('UTC');

        $appointments = Appointment::query()
            ->when($filters['tab'] === 'upcoming', fn (Builder $q) => $q->where('end_at', '>=', $now)->orderBy('start_at'))
            ->when($filters['tab'] === 'past', fn (Builder $q) => $q->where('end_at', '<', $now)->orderByDesc('start_at'))
            ->when($filters['tab'] === 'all', fn (Builder $q) => $q->orderByDesc('start_at'))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['date'], function (Builder $q, string $date) use ($tz) {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz);
                $q->where('start_at', '>=', $start->utc())->where('start_at', '<', $start->addDay()->utc());
            })
            ->when($filters['q'], function (Builder $q, string $term) {
                // Match "%" and "_" literally. "!" is the escape character because a backslash
                // is itself special inside MySQL string literals.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $q->where(fn (Builder $inner) => $inner
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like]));
            })
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'start_at' => $appointment->start_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'end_at' => $appointment->end_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'status' => $appointment->status->value,
                'name' => $appointment->name,
                'email' => $appointment->email,
                'notes' => $appointment->notes,
                'created_by_admin' => $appointment->created_by !== null,
            ]);

        return Inertia::render('admin/Appointments', [
            'appointments' => $appointments,
            'filters' => $filters,
            'counts' => $this->counts($tz),
            'timezone' => $tz,
            'slotMinutes' => $settings->slot_minutes,
        ]);
    }

    /**
     * Open slots for one day in the admin's timezone, ignoring minimum notice.
     */
    public function slots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'except' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], Setting::current()->timezone);

        return response()->json([
            'slots' => $this->slots
                ->availableSlots($day, $day->addDay(), ignoreMinimumNotice: true, exceptAppointmentId: $validated['except'] ?? null)
                ->map(fn (CarbonImmutable $slot) => $slot->format('Y-m-d\TH:i:s\Z'))
                ->values(),
        ]);
    }

    /**
     * Book an appointment on someone's behalf.
     */
    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $start = CarbonImmutable::parse($request->validated('start_at'))->utc();
        $length = Setting::current()->slot_minutes;

        $this->guardSlot(function () use ($request, $start, $length) {
            $this->ensureOpen($start);

            Appointment::create([
                'start_at' => $start,
                'end_at' => $start->addMinutes($length),
                'status' => AppointmentStatus::Confirmed,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'notes' => $request->validated('notes'),
                'created_by' => $request->user()->id,
            ]);
        });

        return back();
    }

    /**
     * Move a confirmed appointment to another open slot, keeping its duration.
     */
    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $start = CarbonImmutable::parse($request->validated('start_at'))->utc();

        $this->guardSlot(function () use ($appointment, $start) {
            // Re-read inside the transaction so a concurrent cancel is seen before we move it.
            $appointment->refresh();

            if (! $appointment->status->isActive()) {
                throw ValidationException::withMessages(['start_at' => 'Only confirmed appointments can be rescheduled.']);
            }

            $duration = (int) $appointment->start_at->diffInMinutes($appointment->end_at);
            $this->ensureOpen($start, $appointment->id);

            // The kept duration can be longer than today's slot length, so check the whole new range too.
            $buffer = Setting::current()->buffer_minutes;
            $clashes = Appointment::active()
                ->whereKeyNot($appointment->id)
                ->overlapping($start->subMinutes($buffer), $start->addMinutes($duration + $buffer))
                ->exists();

            if ($clashes) {
                throw ValidationException::withMessages(['start_at' => BookingController::SLOT_TAKEN]);
            }

            $appointment->update([
                'start_at' => $start,
                'end_at' => $start->addMinutes($duration),
            ]);
        });

        return back();
    }

    /**
     * Cancel an appointment; the row is kept and its slot frees up.
     */
    public function cancel(Appointment $appointment): RedirectResponse
    {
        if ($appointment->status->isActive()) {
            $appointment->update(['status' => AppointmentStatus::Cancelled]);
        }

        return back();
    }

    private function ensureOpen(CarbonImmutable $start, ?int $exceptAppointmentId = null): void
    {
        $open = $this->slots
            ->availableSlots($start, $start->addMinute(), ignoreMinimumNotice: true, exceptAppointmentId: $exceptAppointmentId)
            ->contains(fn (CarbonImmutable $slot) => $slot->equalTo($start));

        if (! $open) {
            throw ValidationException::withMessages(['start_at' => BookingController::SLOT_TAKEN]);
        }
    }

    private function guardSlot(callable $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            // Another booking took this exact slot between the check and the write.
            throw ValidationException::withMessages(['start_at' => BookingController::SLOT_TAKEN]);
        }
    }

    /**
     * @return array{tab: string, status: string|null, date: string|null, q: string|null}
     */
    private function filters(Request $request): array
    {
        $tab = $request->query('tab');
        $status = $request->query('status');
        $date = $request->query('date');
        $q = trim((string) $request->query('q', ''));

        $validDate = is_string($date)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

        return [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'upcoming',
            'status' => in_array($status, array_column(AppointmentStatus::cases(), 'value'), true) ? $status : null,
            'date' => $validDate ? $date : null,
            'q' => $q === '' ? null : mb_substr($q, 0, 100),
        ];
    }

    /**
     * @return array{today: int, thisWeek: int, cancelledLast30Days: int}
     */
    private function counts(string $tz): array
    {
        $today = CarbonImmutable::now($tz)->startOfDay();
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);

        $confirmedBetween = fn (CarbonImmutable $from, CarbonImmutable $to) => Appointment::active()
            ->where('start_at', '>=', $from->utc())
            ->where('start_at', '<', $to->utc())
            ->count();

        return [
            'today' => $confirmedBetween($today, $today->addDay()),
            'thisWeek' => $confirmedBetween($weekStart, $weekStart->addWeek()),
            'cancelledLast30Days' => Appointment::query()
                ->where('status', AppointmentStatus::Cancelled)
                ->where('updated_at', '>=', CarbonImmutable::now('UTC')->subDays(30))
                ->count(),
        ];
    }
}
