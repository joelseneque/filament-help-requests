<?php

namespace Joelseneque\HelpRequests\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum HelpRequestStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }

    /**
     * The statuses that end a request. One list, so the overdue filter's
     * query and the row's red highlight can never disagree.
     *
     * @return list<self>
     */
    public static function finished(): array
    {
        return [self::Resolved, self::Closed];
    }

    public function isFinished(): bool
    {
        return in_array($this, self::finished(), true);
    }
}
