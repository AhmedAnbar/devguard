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

        // Composer exit codes for `audit`:
        //   0 = no advisories
        //   1 = advisories found (we still parse the JSON)
        //   anything else = real error (composer missing, network, malformed lock, ...)
        if ($exit > 1) {
            return [RuleResult::fail(
                $this->name(),
                'composer audit failed: ' . trim($process->getErrorOutput() ?: 'unknown error'),
                null,
                null,
                'Verify `composer` is on PATH and `composer audit` runs cleanly outside DevGuard first.'
            )];
        }

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            return [RuleResult::pass($this->name(), 'No advisories reported by composer audit')];
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
