<?php

declare(strict_types=1);

namespace DevGuard\Results;

final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public Status $status,
        public string $message,
        public int $impact = 0,
        public ?string $suggestion = null,
    ) {}

    public static function pass(string $name, string $message): self
    {
        return new self($name, Status::Pass, $message);
    }

    public static function warn(string $name, string $message, int $impact = 0, ?string $suggestion = null): self
    {
        return new self($name, Status::Warning, $message, $impact, $suggestion);
    }

    public static function fail(string $name, string $message, int $impact = 0, ?string $suggestion = null): self
    {
        return new self($name, Status::Fail, $message, $impact, $suggestion);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'impact' => $this->impact,
            'suggestion' => $this->suggestion,
        ];
    }
}
