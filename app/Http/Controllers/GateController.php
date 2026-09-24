<?php

namespace App\Http\Controllers;

use App\Models\Gate;
use App\Models\GateException;
use App\Models\GateSchedule;
use App\Services\FlightAllocation\FlightAllocationResetter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Gate $gate)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Gate $gate, FlightAllocationResetter $allocationResetter): JsonResponse
    {
        $exceptionIds = $gate->exceptions()->pluck('id')->all();

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:10',
                Rule::unique('gates', 'code')
                    ->where(fn ($query) => $query->where('airport_id', $gate->airport_id))
                    ->ignore($gate),
            ],
            'occupancy_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'is_active' => ['required', 'boolean'],
            'exceptions' => ['present', 'array'],
            'exceptions.*.id' => ['nullable', 'integer', Rule::in($exceptionIds)],
            'exceptions.*.start_date' => ['required', 'date'],
            'exceptions.*.end_date' => ['required', 'date'],
            'exceptions.*.reason' => ['required', 'string', 'max:255'],
        ]);

        $seenExceptionIds = [];

        foreach ($data['exceptions'] as $index => $exception) {
            if ($exception['end_date'] < $exception['start_date']) {
                throw ValidationException::withMessages([
                    "exceptions.{$index}.end_date" => 'The exception end date must be on or after its start date.',
                ]);
            }

            if (isset($exception['id'])) {
                if (in_array($exception['id'], $seenExceptionIds, true)) {
                    throw ValidationException::withMessages([
                        "exceptions.{$index}.id" => 'Each exception may only be submitted once.',
                    ]);
                }

                $seenExceptionIds[] = $exception['id'];
            }
        }

        $shouldReallocate = false;

        $gate = DB::transaction(function () use ($data, $gate, $allocationResetter, &$shouldReallocate): Gate {
            $managedGate = Gate::query()
                ->lockForUpdate()
                ->findOrFail($gate->id);
            $existingExceptions = GateException::query()
                ->where('gate_id', $managedGate->id)
                ->lockForUpdate()
                ->get();

            $shouldReallocate = $managedGate->is_active !== (bool) $data['is_active']
                || $this->exceptionsAffectExistingSchedules($managedGate, $existingExceptions, $data['exceptions']);

            $managedGate->update(Arr::only($data, ['code', 'occupancy_minutes', 'is_active']));

            $submittedExceptionIds = [];
            $exceptionsById = $existingExceptions->keyBy('id');

            foreach ($data['exceptions'] as $exceptionData) {
                $attributes = Arr::only($exceptionData, ['start_date', 'end_date', 'reason']);

                if (isset($exceptionData['id'])) {
                    $exceptionsById->get($exceptionData['id'])->update($attributes);
                    $submittedExceptionIds[] = $exceptionData['id'];

                    continue;
                }

                $submittedExceptionIds[] = $managedGate->exceptions()->create($attributes)->id;
            }

            if ($submittedExceptionIds === []) {
                $managedGate->exceptions()->delete();

                if ($shouldReallocate) {
                    $allocationResetter->reset($managedGate->airport);
                }

                return $managedGate;
            }

            $managedGate->exceptions()->whereNotIn('id', $submittedExceptionIds)->delete();

            if ($shouldReallocate) {
                $allocationResetter->reset($managedGate->airport);
            }

            return $managedGate;
        });

        if ($shouldReallocate) {
            $allocationResetter->queue($gate->airport);
        }

        $gate = $gate->fresh()->load('exceptions');
        $gate->setAttribute('reallocation_queued', $shouldReallocate);

        return response()->json($gate);
    }

    /**
     * @param  Collection<int, GateException>  $existingExceptions
     * @param  array<int, array{id?: int, start_date: string, end_date: string, reason: string}>  $submittedExceptions
     */
    private function exceptionsAffectExistingSchedules(
        Gate $gate,
        Collection $existingExceptions,
        array $submittedExceptions,
    ): bool {
        $submittedById = collect($submittedExceptions)
            ->filter(fn (array $exception): bool => isset($exception['id']))
            ->keyBy('id');
        $changedIntervals = [];

        foreach ($existingExceptions as $existingException) {
            $submittedException = $submittedById->get($existingException->id);

            if ($submittedException === null) {
                $changedIntervals[] = $this->exceptionInterval(
                    $existingException->start_date->toDateString(),
                    $existingException->end_date->toDateString(),
                );

                continue;
            }

            if (
                $existingException->start_date->toDateString() !== $submittedException['start_date']
                || $existingException->end_date->toDateString() !== $submittedException['end_date']
            ) {
                $changedIntervals[] = $this->exceptionInterval(
                    $existingException->start_date->toDateString(),
                    $existingException->end_date->toDateString(),
                );
                $changedIntervals[] = $this->exceptionInterval(
                    $submittedException['start_date'],
                    $submittedException['end_date'],
                );
            }
        }

        foreach ($submittedExceptions as $submittedException) {
            if (! isset($submittedException['id'])) {
                $changedIntervals[] = $this->exceptionInterval(
                    $submittedException['start_date'],
                    $submittedException['end_date'],
                );
            }
        }

        foreach ($changedIntervals as $interval) {
            if (GateSchedule::query()
                ->where('gate_id', $gate->id)
                ->where('occupied_from', '<', $interval['until'])
                ->where('occupied_until', '>', $interval['from'])
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{from: CarbonImmutable, until: CarbonImmutable}
     */
    private function exceptionInterval(string $startDate, string $endDate): array
    {
        return [
            'from' => CarbonImmutable::parse($startDate, 'UTC')->startOfDay(),
            'until' => CarbonImmutable::parse($endDate, 'UTC')->startOfDay()->addDay(),
        ];
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Gate $gate)
    {
        //
    }
}
