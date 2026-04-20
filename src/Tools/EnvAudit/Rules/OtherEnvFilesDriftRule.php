<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\EnvAudit\Support\EnvFileDiscovery;
use DevGuard\Tools\EnvAudit\Support\EnvFileLoader;

/**
 * For every .env-family file beyond the canonical .env / .env.example pair
 * (e.g. .env.testing, .env.staging, .env.local), report keys that appear
 * in .env.example but are missing from that file.
 *
 * Severity is warning, not fail — secondary env files often *intentionally*
 * skip keys (e.g. .env.testing usually doesn't need MAIL_HOST). The rule
 * surfaces drift so humans can decide; it does not assume drift is a bug.
 */
final class OtherEnvFilesDriftRule implements RuleInterface
{
    public function __construct(
        private readonly EnvFileLoader $loader = new EnvFileLoader(),
        private readonly EnvFileDiscovery $discovery = new EnvFileDiscovery(),
    ) {}

    public function name(): string
    {
        return 'other_env_files_drift';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        // No template = nothing to compare against. The env_example_exists
        // rule will already have flagged this; we stay quiet.
        if (! $ctx->fileExists('.env.example')) {
            return [RuleResult::pass($this->name(), 'Skipped (.env.example missing — see env_example_exists rule)')];
        }

        $otherFiles = $this->discovery->discover($ctx->rootPath);
        if ($otherFiles === []) {
            return [RuleResult::pass($this->name(), 'No additional .env files found beyond .env / .env.example')];
        }

        $exampleKeys = array_keys($this->loader->load($ctx->rootPath, '.env.example'));

        $results = [];
        foreach ($otherFiles as $filename) {
            $envKeys = array_keys($this->loader->load($ctx->rootPath, $filename));
            $missing = array_values(array_diff($exampleKeys, $envKeys));

            if ($missing === []) {
                $results[] = RuleResult::pass(
                    $this->name(),
                    sprintf('%s: all keys from .env.example present', $filename)
                );
                continue;
            }

            // One result per missing key per file → renderer groups them
            // visibly and the suggestion is actionable per-key.
            foreach ($missing as $key) {
                $results[] = RuleResult::warn(
                    $this->name(),
                    sprintf('Missing in %s: %s', $filename, $key),
                    $filename,
                    null,
                    sprintf(
                        'Add %s= to %s, or document why it is intentionally absent (e.g. .env.testing rarely needs mail keys).',
                        $key,
                        $filename
                    )
                );
            }
        }

        return $results;
    }
}
