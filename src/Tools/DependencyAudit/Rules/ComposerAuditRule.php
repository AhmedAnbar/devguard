<?php

declare(strict_types=1);

namespace DevGuard\Tools\DependencyAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use Symfony\Component\Process\Process;

final class ComposerAuditRule implements RuleInterface
{
    public function __construct(
        private readonly string $composerBinary = 'composer',
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'composer_audit';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->fileExists('composer.lock')) {
            return [RuleResult::warn(
                $this->name(),
                'composer.lock is missing — cannot audit unlocked dependencies',
                'composer.lock',
                null,
                'Run `composer install` to generate composer.lock, then re-run DevGuard.'
            )];
        }

        $process = new Process(
            [$this->composerBinary, 'audit', '--format=json', '--no-interaction'],
            $ctx->rootPath
        );
        $process->setTimeout((float) $this->timeoutSeconds);
        $process->run();

        $exit = $process->getExitCode() ?? 0;
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        // composer audit's exit code is a SEVERITY BITMASK, not a failure flag:
        //   1 = high-severity advisories present
        //   2 = medium
        //   4 = low
        //   8 = abandoned packages
        // Any combination is OR'd. Exit 7 = high+medium+low, etc.
        // The scan itself succeeded as long as we can parse the JSON output.
        $decoded = json_decode($stdout, true);

        if (! is_array($decoded)) {
            // Genuinely failed — composer crashed, network died, json was empty.
            $detail = $this->summariseFailure($exit, $stdout, $stderr);
            return [RuleResult::fail(
                $this->name(),
                "composer audit failed (exit {$exit}): {$detail}",
                null,
                null,
                'Run `composer audit` directly in this directory to see the full error. ' .
                'Common causes: composer < 2.4 (which lacks the audit command), missing composer.lock, ' .
                'or no network access to packagist.org.'
            )];
        }

        $advisories = is_array($decoded['advisories'] ?? null) ? $decoded['advisories'] : [];
        $abandoned = is_array($decoded['abandoned'] ?? null) ? $decoded['abandoned'] : [];

        if ($advisories === [] && $abandoned === []) {
            return [RuleResult::pass($this->name(), 'No security advisories or abandoned packages')];
        }

        $results = [];

        foreach ($advisories as $package => $packageAdvisories) {
            if (! is_array($packageAdvisories)) {
                continue;
            }
            foreach ($packageAdvisories as $advisory) {
                $results[] = $this->advisoryToResult((string) $package, is_array($advisory) ? $advisory : []);
            }
        }

        foreach ($abandoned as $package => $replacement) {
            $results[] = RuleResult::warn(
                $this->name(),
                sprintf(
                    'Abandoned package: %s%s',
                    $package,
                    is_string($replacement) && $replacement !== '' ? " (replace with {$replacement})" : ''
                ),
                'composer.lock',
                null,
                is_string($replacement) && $replacement !== ''
                    ? "Switch to {$replacement} via `composer require {$replacement}` and remove {$package}."
                    : "Find and switch to a maintained alternative for {$package}."
            );
        }

        return $results;
    }

    /**
     * Combine the most useful bits of stderr/stdout into a one-line summary
     * for surfacing in the report when composer audit fails.
     */
    private function summariseFailure(int $exit, string $stdout, string $stderr): string
    {
        // Composer often writes the actual error to STDOUT when --format=json is set
        // (because stderr is reserved for warnings). Try stderr first, then stdout.
        $raw = trim($stderr) !== '' ? trim($stderr) : trim($stdout);

        if ($raw === '') {
            return "no output captured (composer may be too old; `composer audit` was added in 2.4.0)";
        }

        // Collapse whitespace and truncate so the report stays readable.
        $cleaned = (string) preg_replace('/\s+/', ' ', $raw);
        return strlen($cleaned) > 240 ? substr($cleaned, 0, 240) . '...' : $cleaned;
    }

    /** @param array<string, mixed> $advisory */
    private function advisoryToResult(string $package, array $advisory): RuleResult
    {
        $severity = strtolower((string) ($advisory['severity'] ?? 'medium'));
        $title = (string) ($advisory['title'] ?? 'Security advisory');
        $cve = (string) ($advisory['cve'] ?? $advisory['advisoryId'] ?? '');
        $link = (string) ($advisory['link'] ?? '');

        $message = sprintf(
            '%s — %s%s [%s]',
            $package,
            $title,
            $cve !== '' ? " ({$cve})" : '',
            $severity
        );

        $suggestion = sprintf(
            'Update %s to a patched version. %s',
            $package,
            $link !== '' ? "See: {$link}" : 'Check the package changelog for a fix.'
        );

        return in_array($severity, ['critical', 'high'], true)
            ? RuleResult::fail($this->name(), $message, 'composer.lock', null, $suggestion)
            : RuleResult::warn($this->name(), $message, 'composer.lock', null, $suggestion);
    }
}
