<?php

namespace App\Enums;

enum AlertType: string
{
    case SyncFailure = 'SYNC_FAILURE';
    case DlqThreshold = 'DLQ_THRESHOLD';
    case ApiFailure = 'API_FAILURE';
    case QueueBacklog = 'QUEUE_BACKLOG';

    /**
     * Default severity for this alert type.
     */
    public function severity(): string
    {
        return match ($this) {
            self::QueueBacklog => 'warning',
            default => 'critical',
        };
    }
}
