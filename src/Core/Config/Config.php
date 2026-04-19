<?php

declare(strict_types=1);

namespace DevGuard\Core\Config;

final class Config
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
