<?php

namespace App\Enums;

enum TriggerType: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
