<?php

declare(strict_types=1);

namespace DevGuard\Results;

enum Status: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Fail = 'fail';

    public function icon(): string
    {
        return match ($this) {
            self::Pass => '✓',
            self::Warning => '⚠',
            self::Fail => '✗',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pass => 'green',
            self::Warning => 'yellow',
            self::Fail => 'red',
        };
    }
}
