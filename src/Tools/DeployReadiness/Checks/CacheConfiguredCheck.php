<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class CacheConfiguredCheck implements CheckInterface
{
    private const PRODUCTION_DRIVERS = ['redis', 'memcached', 'database', 'dynamodb'];
    private const SUBOPTIMAL_DRIVERS = ['file'];
    private const FORBIDDEN_DRIVERS = ['array', 'null'];

    public function __construct(private readonly int $impact = 10) {}

    public function name(): string
    {
        return 'cache_configured';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        $driver = $ctx->envValue('CACHE_STORE')
            ?? $ctx->envValue('CACHE_DRIVER');

        if ($driver === null) {
            return CheckResult::warn(
                $this->name(),
                'No CACHE_STORE / CACHE_DRIVER set in .env',
                $this->impact,
                'Set CACHE_STORE=redis (or another production driver) in your .env.'
            );
        }

        $driver = strtolower((string) $driver);

        if (in_array($driver, self::PRODUCTION_DRIVERS, true)) {
            return CheckResult::pass($this->name(), "Cache driver configured ({$driver})");
        }

        if (in_array($driver, self::FORBIDDEN_DRIVERS, true)) {
            return CheckResult::fail(
                $this->name(),
                "Cache driver '{$driver}' is not safe for production",
                $this->impact,
                "Switch CACHE_STORE to redis, memcached, or database."
            );
        }

        if (in_array($driver, self::SUBOPTIMAL_DRIVERS, true)) {
            return CheckResult::warn(
                $this->name(),
                "Cache driver '{$driver}' is suboptimal for production",
                $this->impact,
                'Consider switching to redis or memcached for better performance.'
            );
        }

        return CheckResult::pass($this->name(), "Cache driver configured ({$driver})");
    }
}
