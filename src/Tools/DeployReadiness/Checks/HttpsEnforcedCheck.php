<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class HttpsEnforcedCheck implements CheckInterface
{
    private const PROVIDER_PATHS = [
        'app/Providers/AppServiceProvider.php',
        'app/Providers/AppServiceProvider.php.stub',
    ];

    public function __construct(private readonly int $impact = 10) {}

    public function name(): string
    {
        return 'https_enforced';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        $appUrl = (string) $ctx->envValue('APP_URL', '');
        $env = (string) $ctx->envValue('APP_ENV', 'production');

        $forcedInProvider = $this->forcedInProvider($ctx);
        $appUrlIsHttps = str_starts_with(strtolower($appUrl), 'https://');

        if ($forcedInProvider) {
            return CheckResult::pass($this->name(), 'HTTPS forced via AppServiceProvider');
        }

        if ($appUrlIsHttps) {
            return CheckResult::pass($this->name(), "APP_URL uses https ({$appUrl})");
        }

        if ($env !== 'production') {
            return CheckResult::warn(
                $this->name(),
                "HTTPS not enforced (APP_ENV={$env})",
                $this->impact,
                'In production, set APP_URL to an https:// URL or call URL::forceScheme("https") in AppServiceProvider::boot.'
            );
        }

        return CheckResult::fail(
            $this->name(),
            'HTTPS is not enforced in production',
            $this->impact,
            'Set APP_URL to an https:// URL or call URL::forceScheme("https") in AppServiceProvider::boot.'
        );
    }

    private function forcedInProvider(ProjectContext $ctx): bool
    {
        foreach (self::PROVIDER_PATHS as $relative) {
            $path = $ctx->path($relative);
            if (! is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (str_contains($contents, "forceScheme('https')")
                || str_contains($contents, 'forceScheme("https")')) {
                return true;
            }
        }
        return false;
    }
}
