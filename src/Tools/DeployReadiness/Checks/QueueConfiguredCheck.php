<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness\Checks;

use DevGuard\Contracts\CheckInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\CheckResult;

final class QueueConfiguredCheck implements CheckInterface
{
    private const PRODUCTION_CONNECTIONS = ['redis', 'database', 'sqs', 'beanstalkd'];
    private const FORBIDDEN_CONNECTIONS = ['sync', 'null'];

    public function __construct(private readonly int $impact = 10) {}

    public function name(): string
    {
        return 'queue_configured';
    }

    public function run(ProjectContext $ctx): CheckResult
    {
        $conn = $ctx->envValue('QUEUE_CONNECTION');

        if ($conn === null) {
            return CheckResult::warn(
                $this->name(),
                'QUEUE_CONNECTION not set in .env',
                $this->impact,
                'Set QUEUE_CONNECTION=redis (or another async driver) for background jobs.'
            );
        }

        $conn = strtolower((string) $conn);

        if (in_array($conn, self::FORBIDDEN_CONNECTIONS, true)) {
            return CheckResult::fail(
                $this->name(),
                "Queue connection '{$conn}' runs jobs synchronously",
                $this->impact,
                'Switch QUEUE_CONNECTION to redis, database, or sqs to avoid blocking requests.'
            );
        }

        if (in_array($conn, self::PRODUCTION_CONNECTIONS, true)) {
            return CheckResult::pass($this->name(), "Queue connection configured ({$conn})");
        }

        return CheckResult::pass($this->name(), "Queue connection configured ({$conn})");
    }
}
