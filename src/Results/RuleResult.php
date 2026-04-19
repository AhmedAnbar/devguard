<?php

declare(strict_types=1);

namespace DevGuard\Results;

final readonly class RuleResult
{
    public function __construct(
        public string $name,
        public Status $status,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $suggestion = null,
    ) {}

    public static function pass(string $name, string $message): self
    {
        return new self($name, Status::Pass, $message);
    }

    public static function warn(string $name, string $message, ?string $file = null, ?int $line = null, ?string $suggestion = null): self
    {
        return new self($name, Status::Warning, $message, $file, $line, $suggestion);
    }

    public static function fail(string $name, string $message, ?string $file = null, ?int $line = null, ?string $suggestion = null): self
    {
        return new self($name, Status::Fail, $message, $file, $line, $suggestion);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'suggestion' => $this->suggestion,
        ];
    }
}
