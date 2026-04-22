<?php

declare(strict_types=1);

namespace DevGuard\Core\Baseline;

use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;
use DevGuard\Results\Status;
use DevGuard\Results\ToolReport;
use RuntimeException;

/**
 * Reads and writes baseline JSON files.
 *
 * The on-disk format is intentionally human-readable so a reviewer can
 * eyeball a baseline diff in a PR (e.g. "this PR adds 3 baseline entries
 * — why?"). We trade some bytes for readability; baselines are rarely
 * over a few hundred KB even on large legacy codebases.
 */
final class BaselineLoader
{
    public const DEFAULT_FILENAME = 'devguard-baseline.json';

    public function load(string $absolutePath): Baseline
    {
        if (! is_file($absolutePath)) {
            return Baseline::empty();
        }

        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            throw new RuntimeException("Cannot read baseline file: {$absolutePath}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Baseline file is not valid JSON: {$absolutePath}");
        }

        $version = $decoded['version'] ?? null;
        if ($version !== Baseline::FORMAT_VERSION) {
            // Future: handle migrations here. For now: fail loudly so the
            // user knows something needs attention rather than silently
            // ignoring the baseline (which would reintroduce 200 issues).
            throw new RuntimeException(sprintf(
                'Baseline format version mismatch: file is %s, this DevGuard expects %d. Re-run `devguard baseline` to regenerate.',
                var_export($version, true),
                Baseline::FORMAT_VERSION
            ));
        }

        $signatures = [];
        $issues = is_array($decoded['issues'] ?? null) ? $decoded['issues'] : [];
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $sig = $issue['signature'] ?? null;
            if (is_string($sig) && $sig !== '') {
                $signatures[$sig] = true;
            }
        }

        $meta = [
            'generated_at' => $decoded['generated_at'] ?? null,
            'tool_versions' => $decoded['tool_versions'] ?? [],
        ];

        return new Baseline($signatures, $meta);
    }

    /**
     * Write a baseline file from a set of ToolReports.
     *
     * @param array<int, ToolReport> $reports
     * @param array<string, string>  $toolVersions  e.g. ['devguard' => '0.6.0']
     */
    public function save(string $absolutePath, array $reports, array $toolVersions = []): int
    {
        $issueRows = [];
        $seen = [];

        foreach ($reports as $report) {
            foreach ($report->results() as $result) {
                // Don't bake passes into the baseline — they aren't issues.
                if ($result->status === Status::Pass) {
                    continue;
                }
                $sig = Baseline::signatureFor($result);
                if (isset($seen[$sig])) {
                    continue;
                }
                $seen[$sig] = true;

                $issueRows[] = $this->resultToRow($result, $sig);
            }
        }

        // Sort for deterministic file output → stable git diffs.
        usort($issueRows, function (array $a, array $b): int {
            return [$a['rule'], $a['file'] ?? '', $a['signature']]
                <=> [$b['rule'], $b['file'] ?? '', $b['signature']];
        });

        $payload = [
            'version' => Baseline::FORMAT_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'tool_versions' => $toolVersions,
            'issues' => $issueRows,
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Could not encode baseline as JSON');
        }

        if (file_put_contents($absolutePath, $json . "\n") === false) {
            throw new RuntimeException("Could not write baseline file: {$absolutePath}");
        }

        return count($issueRows);
    }

    /** @return array<string, mixed> */
    private function resultToRow(CheckResult|RuleResult $result, string $signature): array
    {
        $row = [
            'rule' => $result->name,
            'message' => $result->message,
            'signature' => $signature,
        ];
        if ($result instanceof RuleResult && $result->file !== null) {
            $row['file'] = $result->file;
        }
        return $row;
    }
}
