<?php

namespace App\Enums;

enum DeadLetterStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Resolved = 'resolved';
    case Discarded = 'discarded';
}
