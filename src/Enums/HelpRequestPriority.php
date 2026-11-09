<?php

namespace Joelseneque\HelpRequests\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum HelpRequestPriority: string implements HasColor, HasLabel
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::High => 'danger',
            self::Medium => 'warning',
            self::Low => 'gray',
        };
    }

    /**
     * How soon the requester expects an answer, worded for "needed {timeframe}".
     */
    public function timeframe(): string
    {
        return match ($this) {
            self::High => 'today',
            self::Medium => 'within a week',
            self::Low => 'within a month',
        };
    }

    /**
     * The deadline for a request raised at $from. Works on a copy so the
     * caller's instance (often `now()` or `created_at`) is never mutated.
     */
    public function dueAt(CarbonInterface $from): Carbon
    {
        $date = Carbon::instance($from);

        return match ($this) {
            self::High => $date->endOfDay(),
            self::Medium => $date->addWeek(),
            self::Low => $date->addMonthNoOverflow(),
        };
    }
}
