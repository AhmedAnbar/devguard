<?php

declare(strict_types=1);

namespace DevGuard\Core\Output;

use DevGuard\Core\Baseline\Baseline;
use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;
use DevGuard\Results\Status;
use DevGuard\Results\ToolReport;

/**
 * Builds a SARIF 2.1.0 document from one or more ToolReports.
 *
 * SARIF (Static Analysis Results Interchange Format) is the format
 * GitHub Code Scanning consumes. Once uploaded via the
 * github/codeql-action/upload-sarif Action, our findings appear as
 * inline annotations on PR diffs — that's the whole point of this
 * builder.
 *
 * Design choices:
 *   - One combined run with prefixed ruleIds (deploy/debug_mode,
 *     architecture/fat_controller). GitHub's UI groups by tool.driver.name
 *     ("DevGuard") and the prefix gives users useful sub-grouping.
 *   - partialFingerprints reuse the baseline signature (SHA-1 of
 *     rule + file + message). Same hash → GitHub correctly tracks
 *     "same issue across runs," so fixed-then-rerun cycles don't
 *     re-flag everything as new.
 *   - Baseline-suppressed results are NOT included. The filter runs
 *     before this builder is invoked, same as for every other renderer.
 *   - Passing results are not emitted — SARIF is for issues only.
 *
 * Pure builder: returns the JSON string. RunCommand handles file IO.
 * Doesn't implement RendererInterface (same reasoning as HtmlRenderer:
 * collect-then-emit, multiple reports → one document).
 */
final class SarifBuilder
{
    private const SARIF_VERSION = '2.1.0';
    private const SARIF_SCHEMA = 'https://schemastore.azurewebsites.net/schemas/json/sarif-2.1.0-rtm.6.json';
    private const FINGERPRINT_KEY = 'devguardSignature/v1';

    /**
     * Fallback file used as the location for results that don't have a
     * real source file (deploy checks, "composer.lock missing" warnings,
     * etc.). composer.json is the universal project-root marker — it
     * MUST exist in every project DevGuard audits because ProjectContext
     * uses it as the project-detection signal. Anchoring project-wide
     * findings here gives GitHub Code Scanning a sensible place to
     * render the alert.
     */
    private const FALLBACK_LOCATION_FILE = 'composer.json';

    /** @param array<int, ToolReport> $reports */
    public function build(array $reports, string $devguardVersion): string
    {
        $results = [];
        foreach ($reports as $report) {
            foreach ($report->results() as $result) {
                $sarifResult = $this->mapResult($report->tool, $result);
                if ($sarifResult !== null) {
                    $results[] = $sarifResult;
                }
            }
        }

        $document = [
            'version' => self::SARIF_VERSION,
            '$schema' => self::SARIF_SCHEMA,
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'DevGuard',
                            'version' => $devguardVersion,
                            'informationUri' => 'https://github.com/AhmedAnbar/devguard',
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            // json_encode failure here would mean we built a non-encodable
            // structure — that's a bug, not a runtime condition.
            throw new \RuntimeException('Failed to encode SARIF document as JSON');
        }
        return $json;
    }

    /**
     * Map a single CheckResult/RuleResult to a SARIF result object.
     * Returns null for passing results (SARIF is for issues only).
     *
     * @return array<string, mixed>|null
     */
    private function mapResult(string $toolName, CheckResult|RuleResult $result): ?array
    {
        if ($result->status === Status::Pass) {
            return null;
        }

        // CRITICAL: GitHub Code Scanning rejects results without locations
        // even though SARIF 2.1.0 lists locations as optional. v0.8.0 omitted
        // locations for results without a file (deploy checks, etc.) and
        // every SARIF upload was rejected with "expected at least one
        // location." Fixed in v0.8.1 — we always emit locations[], falling
        // back to composer.json:1 for results without a real file.
        // See lesson #26 in CLAUDE.md.
        return [
            'ruleId' => $toolName . '/' . $result->name,
            'level' => $this->mapLevel($result->status),
            'message' => [
                'text' => $this->buildMessageText($result),
            ],
            'partialFingerprints' => [
                self::FINGERPRINT_KEY => Baseline::signatureFor($result),
            ],
            'locations' => [$this->buildLocation($result) ?? $this->fallbackLocation()],
        ];
    }

    private function mapLevel(Status $status): string
    {
        return match ($status) {
            Status::Fail => 'error',
            Status::Warning => 'warning',
            // Pass is filtered out before reaching here, but the match
            // must be exhaustive for the type-checker.
            Status::Pass => 'none',
        };
    }

    /**
     * Append the suggestion to the message text when present, separated
     * with a "Suggestion:" prefix. SARIF supports a separate "fix" field
     * but it requires structured replacement data — overkill for v1, when
     * our suggestions are already free-form prose.
     */
    private function buildMessageText(CheckResult|RuleResult $result): string
    {
        $text = $result->message;
        if ($result->suggestion !== null && $result->suggestion !== '') {
            $text .= "\n\nSuggestion: " . $result->suggestion;
        }
        return $text;
    }

    /**
     * Synthetic location used as the SARIF anchor for project-wide results
     * that don't map to a single source file (deploy checks, missing
     * composer.lock warnings). Anchored at composer.json:1 because that
     * file is guaranteed to exist (ProjectContext requires it).
     *
     * @return array<string, mixed>
     */
    private function fallbackLocation(): array
    {
        return [
            'physicalLocation' => [
                'artifactLocation' => ['uri' => self::FALLBACK_LOCATION_FILE],
                'region' => ['startLine' => 1],
            ],
        ];
    }

    /**
     * Build a SARIF physicalLocation block for results that have a file.
     * Returns null for results without one (deploy checks, project-wide
     * warnings) — caller substitutes fallbackLocation() in that case.
     *
     * @return array<string, mixed>|null
     */
    private function buildLocation(CheckResult|RuleResult $result): ?array
    {
        if (! $result instanceof RuleResult) {
            return null;
        }
        if ($result->file === null || $result->file === '') {
            return null;
        }

        $region = [];
        if ($result->line !== null && $result->line > 0) {
            // SARIF startLine is 1-based — same as our RuleResult.line, so
            // no conversion needed.
            $region['startLine'] = $result->line;
        }

        $physical = [
            'artifactLocation' => [
                // SARIF expects a URI — relative paths are valid and
                // recommended for repo-relative locations.
                'uri' => $result->file,
            ],
        ];
        if ($region !== []) {
            $physical['region'] = $region;
        }

        return ['physicalLocation' => $physical];
    }
}
