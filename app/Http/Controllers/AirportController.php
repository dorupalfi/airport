<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\Gate;
use App\Services\FlightAllocation\FlightAllocationResetter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AirportController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Airport::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                    'city',
                    'country',
                    'default_gate_occupancy_minutes',
                ]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10', 'unique:airports,code'],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'default_gate_occupancy_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'gate_count' => ['required', 'integer', 'min:0', 'max:200'],
        ]);

        $airport = DB::transaction(function () use ($data): Airport {
            $airport = Airport::query()->create(Arr::except($data, 'gate_count'));
            $timestamp = now();

            if ($data['gate_count'] > 0) {
                Gate::query()->insert(
                    collect(range(1, $data['gate_count']))
                        ->map(fn (int $number): array => [
                            'airport_id' => $airport->id,
                            'code' => "A{$number}",
                            'occupancy_minutes' => $airport->default_gate_occupancy_minutes,
                            'is_active' => true,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ])
                        ->all(),
                );
            }

            return $airport;
        });

        return response()->json($airport, 201);
    }

    public function show(Airport $airport): JsonResponse
    {
        return response()->json(
            $airport->load([
                'gates' => fn ($query) => $query
                    ->with(['exceptions' => fn ($exceptionQuery) => $exceptionQuery->orderBy('id')])
                    ->orderBy('id'),
            ]),
        );
    }

    public function update(Request $request, Airport $airport, FlightAllocationResetter $allocationResetter): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10', Rule::unique('airports', 'code')->ignore($airport)],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'default_gate_occupancy_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'gate_count' => ['required', 'integer', 'min:0', 'max:200'],
        ]);

        $gateCountChanged = false;

        $airport = DB::transaction(function () use ($airport, $data, $allocationResetter, &$gateCountChanged): Airport {
            $managedAirport = Airport::query()
                ->lockForUpdate()
                ->findOrFail($airport->id);
            $existingGates = Gate::query()
                ->where('airport_id', $managedAirport->id)
                ->lockForUpdate()
                ->get();

            $gateCountChanged = $existingGates->count() !== $data['gate_count'];
            $managedAirport->update(Arr::except($data, 'gate_count'));

            if (! $gateCountChanged) {
                return $managedAirport;
            }

            $allocationResetter->reset($managedAirport);

            if ($existingGates->isNotEmpty()) {
                Gate::query()->whereKey($existingGates->modelKeys())->delete();
            }

            if ($data['gate_count'] > 0) {
                $timestamp = now();

                Gate::query()->insert(
                    collect(range(1, $data['gate_count']))
                        ->map(fn (int $number): array => [
                            'airport_id' => $managedAirport->id,
                            'code' => "A{$number}",
                            'occupancy_minutes' => $managedAirport->default_gate_occupancy_minutes,
                            'is_active' => true,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ])
                        ->all(),
                );
            }

            return $managedAirport;
        });

        if ($gateCountChanged) {
            $allocationResetter->queue($airport);
        }

        $airport = $airport->fresh()->load([
            'gates' => fn ($query) => $query
                ->with(['exceptions' => fn ($exceptionQuery) => $exceptionQuery->orderBy('id')])
                ->orderBy('id'),
        ]);
        $airport->setAttribute('reallocation_queued', $gateCountChanged);

        return response()->json($airport);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Airport $airport)
    {
        //
    }
}
