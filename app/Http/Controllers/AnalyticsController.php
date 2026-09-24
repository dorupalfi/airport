<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function filters(): JsonResponse
    {
        return response()->json([
            'airports' => Airport::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
            'dates' => DB::table('airport_allocation_snapshots')
                ->distinct()
                ->orderByDesc('snapshot_date')
                ->pluck('snapshot_date')
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'airport_id' => ['required', 'integer', 'exists:airports,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $snapshots = DB::table('airport_allocation_snapshots')
            ->where('airport_id', $filters['airport_id'])
            ->where('snapshot_date', $filters['date'])
            ->orderBy('captured_at')
            ->get()
            ->map(fn (object $snapshot): array => [
                'captured_at' => CarbonImmutable::parse($snapshot->captured_at, 'UTC')->toIso8601String(),
                'total_gates' => (int) $snapshot->total_gates,
                'inactive_gates' => (int) $snapshot->inactive_gates,
                'exception_blocked_gates' => (int) $snapshot->exception_blocked_gates,
                'busy_gates' => (int) $snapshot->busy_gates,
                'free_gates' => (int) $snapshot->free_gates,
                'on_time_flights' => (int) $snapshot->on_time_flights,
                'delayed_flights' => (int) $snapshot->delayed_flights,
                'unallocated_flights' => (int) $snapshot->unallocated_flights,
                'pending_flights' => (int) $snapshot->pending_flights,
                'active_exceptions' => (int) $snapshot->active_exceptions,
                'inactive_gate_allocations' => (int) $snapshot->inactive_gate_allocations,
                'exception_conflict_allocations' => (int) $snapshot->exception_conflict_allocations,
                'invalid_allocations' => (int) $snapshot->invalid_allocations,
            ]);

        return response()->json([
            'snapshots' => $snapshots,
            'latest' => $snapshots->last(),
        ]);
    }
}
