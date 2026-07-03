<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Partial = 'partial';
    case Skipped = 'skipped';

    /**
     * Statuses that represent a sync currently occupying a provider slot.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [self::Pending->value, self::Running->value];
    }
}
