<?php

declare(strict_types=1);

namespace DevGuard\Core;

use DevGuard\Contracts\ToolInterface;
use RuntimeException;

final class ToolManager
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @return array<string, ToolInterface> */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ToolInterface
    {
        if (! isset($this->tools[$name])) {
            $available = implode(', ', array_keys($this->tools));
            throw new RuntimeException(
                "Unknown tool '{$name}'. Available: {$available}"
            );
        }
        return $this->tools[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }
}
