<?php

namespace Tests\Fixtures;

enum SampleStatus: string
{
    case Available = 'available';
    case NotReady = 'not_ready';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::NotReady => 'Not ready',
        };
    }
}
