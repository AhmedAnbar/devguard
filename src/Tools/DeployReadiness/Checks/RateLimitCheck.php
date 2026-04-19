<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class RateLimitCheck implements CheckInterface
{
    private const SCAN_FILES = [
        'routes/api.php',
        'bootstrap/app.php',
        'app/Providers/RouteServiceProvider.php',
    ];

    public function __construct(private readonly int $impact = 10) {}

    public function name(): string
    {
        return 'rate_limit';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        if (! $ctx->isLaravel) {
            return CheckResult::pass($this->name(), 'Skipped (not a Laravel project)');
        }

        foreach (self::SCAN_FILES as $relative) {
            $path = $ctx->path($relative);
            if (! is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if ($this->hasRateLimit($contents)) {
                return CheckResult::pass(
                    $this->name(),
                    "Rate limiting detected in {$relative}"
                );
            }
        }

        return CheckResult::fail(
            $this->name(),
            'No rate limiting detected on routes',
            $this->impact,
            "Apply 'throttle:api' middleware to your API routes or define a RateLimiter in bootstrap/app.php."
        );
    }

    private function hasRateLimit(string $contents): bool
    {
        return str_contains($contents, 'throttle')
            || str_contains($contents, 'RateLimiter::')
            || str_contains($contents, 'ThrottleRequests');
    }
}
