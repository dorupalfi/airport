<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\GateSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GateScheduleController extends Controller
{
    private const PER_PAGE = 15;

    public function filters(): JsonResponse
    {
        $airports = Airport::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $dates = GateSchedule::query()
            ->whereNotNull('occupied_from')
            ->selectRaw('DATE(occupied_from) as schedule_date')
            ->distinct()
            ->orderByDesc('schedule_date')
            ->pluck('schedule_date')
            ->map(fn ($date): string => (string) $date)
            ->values();

        return response()->json([
            'airports' => $airports,
            'dates' => $dates,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'airport_id' => ['required', 'integer', Rule::exists('airports', 'id')],
            'search' => ['nullable', 'string', 'max:100'],
            'gate' => ['nullable', 'string', 'max:100'],
        ]);

        $search = strtolower(trim((string) ($data['search'] ?? '')));
        $gate = strtolower(trim((string) ($data['gate'] ?? '')));
        $schedules = GateSchedule::query()
            ->select('gate_schedules.*')
            ->join('gates', 'gates.id', '=', 'gate_schedules.gate_id')
            ->where('gates.airport_id', $data['airport_id'])
            ->whereDate('gate_schedules.occupied_from', $data['date'])
            ->when($gate !== '', function (Builder $query) use ($gate): void {
                $query->whereRaw('LOWER(gates.code) = ?', [$gate]);
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = "%{$search}%";

                $query->whereHas('flight.arrivalExternalAirport', function (Builder $airportQuery) use ($like): void {
                    $airportQuery->where(function (Builder $destinationQuery) use ($like): void {
                        $destinationQuery
                            ->whereRaw('LOWER(code) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(city) LIKE ?', [$like]);
                    });
                });
            })
            ->with([
                'gate:id,airport_id,code',
                'flight:id,callsign,estimated_departure_at,arrival_external_airport_id,arrival_external_airport_code',
                'flight.arrivalExternalAirport:id,code,name,city,country',
            ])
            ->orderBy('gate_schedules.occupied_from')
            ->orderBy('gates.id')
            ->paginate(self::PER_PAGE);

        $schedules->getCollection()->transform(fn (GateSchedule $schedule): array => [
            'id' => $schedule->id,
            'gate_code' => $schedule->gate->code,
            'callsign' => $schedule->flight->callsign,
            'destination' => $schedule->flight->arrivalExternalAirport ? [
                'code' => $schedule->flight->arrivalExternalAirport->code,
                'name' => $schedule->flight->arrivalExternalAirport->name,
                'city' => $schedule->flight->arrivalExternalAirport->city,
                'country' => $schedule->flight->arrivalExternalAirport->country,
            ] : null,
            'arrival_external_airport_code' => $schedule->flight->arrival_external_airport_code,
            'estimated_departure_at' => $schedule->flight->estimated_departure_at?->utc()->toIso8601String(),
            'occupied_from' => $schedule->occupied_from?->utc()->toIso8601String(),
            'occupied_until' => $schedule->occupied_until?->utc()->toIso8601String(),
            'delay_minutes' => $schedule->delay_minutes,
        ]);

        return response()->json($schedules);
    }
}
