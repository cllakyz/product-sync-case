<?php

namespace App\Sync\Data;

/**
 * Mutable accumulator for a single sync run's outcome. Populated by
 * DeltaSyncService and copied onto the SyncLog by the orchestrator.
 */
final class DeltaSyncResult
{
    public int $added = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $deleted = 0;

    public int $failed = 0;

    /** @var array<int, string> external ids seen in this run */
    public array $seenExternalIds = [];

    /** True when the delete-guard cancelled a mass deletion (SPEC E18). */
    public bool $deleteGuardTripped = false;
}
