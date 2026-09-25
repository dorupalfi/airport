<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\GateSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnallocatedFlightController extends Controller
{
    private const PER_PAGE = 15;

    public function filters(): JsonResponse
    {
        $airports = Airport::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $dates = GateSchedule::query()
            ->join('flights', 'flights.id', '=', 'gate_schedules.flight_id')
            ->where('flights.allocation_status', 'unallocated')
            ->whereNotNull('gate_schedules.unallocation_reason')
            ->whereNotNull('flights.estimated_departure_at')
            ->selectRaw('DATE(flights.estimated_departure_at) as flight_date')
            ->distinct()
            ->orderByDesc('flight_date')
            ->pluck('flight_date')
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
        ]);

        $search = strtolower(trim((string) ($data['search'] ?? '')));
        $flights = GateSchedule::query()
            ->select('gate_schedules.*')
            ->join('flights', 'flights.id', '=', 'gate_schedules.flight_id')
            ->where('flights.airport_id', $data['airport_id'])
            ->where('flights.allocation_status', 'unallocated')
            ->whereNotNull('gate_schedules.unallocation_reason')
            ->whereDate('flights.estimated_departure_at', $data['date'])
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
                'flight:id,callsign,estimated_departure_at,arrival_external_airport_id,arrival_external_airport_code',
                'flight.arrivalExternalAirport:id,code,name,city,country',
            ])
            ->orderBy('flights.estimated_departure_at')
            ->paginate(self::PER_PAGE);

        $flights->getCollection()->transform(fn (GateSchedule $schedule): array => [
            'id' => $schedule->id,
            'callsign' => $schedule->flight->callsign,
            'destination' => $schedule->flight->arrivalExternalAirport ? [
                'code' => $schedule->flight->arrivalExternalAirport->code,
                'name' => $schedule->flight->arrivalExternalAirport->name,
                'city' => $schedule->flight->arrivalExternalAirport->city,
                'country' => $schedule->flight->arrivalExternalAirport->country,
            ] : null,
            'arrival_external_airport_code' => $schedule->flight->arrival_external_airport_code,
            'planned_departure_at' => $schedule->flight->estimated_departure_at?->utc()->toIso8601String(),
            'unallocation_reason' => $schedule->unallocation_reason,
        ]);

        return response()->json($flights);
    }
}
