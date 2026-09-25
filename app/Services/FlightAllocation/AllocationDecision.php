<?php

namespace App\Services\FlightAllocation;

use Carbon\CarbonImmutable;

final class AllocationDecision
{
    private function __construct(
        public readonly ?int $gateId,
        public readonly ?string $gateCode,
        public readonly ?CarbonImmutable $occupiedFrom,
        public readonly ?CarbonImmutable $occupiedUntil,
        public readonly ?int $delayMinutes,
        public readonly ?string $unallocationReason,
    ) {}

    public static function allocated(
        int $gateId,
        string $gateCode,
        CarbonImmutable $occupiedFrom,
        CarbonImmutable $occupiedUntil,
        int $delayMinutes,
    ): self {
        return new self($gateId, $gateCode, $occupiedFrom, $occupiedUntil, $delayMinutes, null);
    }

    public static function unallocated(string $reason): self
    {
        return new self(null, null, null, null, null, $reason);
    }

    public function isAllocated(): bool
    {
        return $this->gateId !== null;
    }
}
