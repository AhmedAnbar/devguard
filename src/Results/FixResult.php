<?php

declare(strict_types=1);

namespace DevGuard\Results;

final readonly class FixResult
{
    public function __construct(
        public Fix $fix,
        public Status $status,
        public string $message,
    ) {}

    public static function applied(Fix $fix, string $message): self
    {
        return new self($fix, Status::Pass, $message);
    }

    public static function skipped(Fix $fix, string $message): self
    {
        return new self($fix, Status::Warning, $message);
    }

    public static function failed(Fix $fix, string $message): self
    {
        return new self($fix, Status::Fail, $message);
    }
}
