<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class LoggingConfiguredCheck implements CheckInterface
{
    private const WEAK_CHANNELS = ['single', 'null', 'errorlog'];

    public function __construct(private readonly int $impact = 5) {}

    public function name(): string
    {
        return 'logging_configured';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        $channel = $ctx->envValue('LOG_CHANNEL');

        if ($channel === null) {
            return CheckResult::warn(
                $this->name(),
                'LOG_CHANNEL not set in .env (defaults to "stack")',
                $this->impact,
                'Explicitly set LOG_CHANNEL=stack (or daily/papertrail/etc) for predictable logging.'
            );
        }

        $channel = strtolower((string) $channel);

        if (in_array($channel, self::WEAK_CHANNELS, true)) {
            return CheckResult::warn(
                $this->name(),
                "LOG_CHANNEL='{$channel}' is weak for production",
                $this->impact,
                "Use 'daily' or 'stack' to get rotation and multi-channel fan-out."
            );
        }

        return CheckResult::pass($this->name(), "Logging configured ({$channel})");
    }
}
