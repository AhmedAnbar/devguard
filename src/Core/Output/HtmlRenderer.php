<?php

declare(strict_types=1);

namespace DevGuard\Core\Output;

use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;
use DevGuard\Results\Status;
use DevGuard\Results\ToolReport;

/**
 * Builds a self-contained HTML page from one or more ToolReports.
 *
 * Design choices:
 *  - All CSS is inlined, no external assets. The output file works opened
 *    locally, attached to email, uploaded as a CI artifact, or sent in
 *    Slack — none of which can rely on a CDN being reachable.
 *  - Repeated rule names get grouped (one heading + N messages, dedup'd
 *    suggestion) — same approach as ConsoleRenderer to avoid 200-line walls.
 *  - Every interpolated string passes through htmlspecialchars(); the data
 *    lives in commit messages and config files which we don't trust.
 *  - Pure builder: returns the HTML string. RunCommand handles the file IO.
 *    Doesn't implement RendererInterface because the interface is single-
 *    report streaming and HTML needs collect-then-emit (multi-tool pages).
 */
final class HtmlRenderer
{
    /**
     * @param array<int, ToolReport> $reports
     */
    public function renderReports(array $reports, string $projectPath, string $devguardVersion): string
    {
        $generatedAt = date('Y-m-d H:i:s T');
        $totals = $this->aggregateTotals($reports);
        $overallPassed = $totals['fail'] === 0;
        $overallBadge = $overallPassed
            ? '<span class="overall-badge pass">' . htmlspecialchars($totals['warn'] === 0 ? 'All checks passed' : 'Passed (with warnings)', ENT_QUOTES) . '</span>'
            : '<span class="overall-badge fail">' . htmlspecialchars($totals['fail'] . ' failed') . '</span>';

        $sections = '';
        foreach ($reports as $report) {
            $sections .= $this->renderReport($report);
        }

        $css = $this->css();
        $projectPathSafe = htmlspecialchars($projectPath, ENT_QUOTES);
        $generatedAtSafe = htmlspecialchars($generatedAt, ENT_QUOTES);
        $versionSafe = htmlspecialchars($devguardVersion, ENT_QUOTES);
        $passedCount = (int) $totals['pass'];
        $warnCount = (int) $totals['warn'];
        $failCount = (int) $totals['fail'];

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DevGuard Report</title>
<style>{$css}</style>
</head>
<body>
<div class="page">
  <header class="topbar">
    <div class="brand">
      <div class="brand-mark">DG</div>
      <div>
        <div class="brand-name">DevGuard Report</div>
        <div class="brand-sub">{$projectPathSafe}</div>
      </div>
    </div>
    <div class="topbar-meta">
      {$overallBadge}
      <div class="generated">Generated {$generatedAtSafe}</div>
    </div>
  </header>

  <section class="summary-card">
    <div class="summary-row">
      <div class="summary-stat pass">
        <div class="summary-num">{$passedCount}</div>
        <div class="summary-label">passed</div>
      </div>
      <div class="summary-stat warn">
        <div class="summary-num">{$warnCount}</div>
        <div class="summary-label">warnings</div>
      </div>
      <div class="summary-stat fail">
        <div class="summary-num">{$failCount}</div>
        <div class="summary-label">failed</div>
      </div>
    </div>
  </section>

  {$sections}

  <footer class="page-footer">
    DevGuard {$versionSafe} · <a href="https://github.com/AhmedAnbar/devguard">github.com/AhmedAnbar/devguard</a>
  </footer>
</div>
</body>
</html>
HTML;
    }

    private function renderReport(ToolReport $report): string
    {
        $title = htmlspecialchars($report->title, ENT_QUOTES);
        $tool = htmlspecialchars($report->tool, ENT_QUOTES);

        $scoreBlock = $report->score !== null ? $this->renderScoreRing((int) $report->score) : '';
        $statusLine = $this->renderStatusLine($report);
        $resultsBlock = $this->renderResultGroups($report);

        return <<<HTML
  <section class="report" id="report-{$tool}">
    <header class="report-header">
      <div>
        <h2 class="report-title">{$title}</h2>
        <div class="report-status">{$statusLine}</div>
      </div>
      {$scoreBlock}
    </header>
    <div class="report-body">
      {$resultsBlock}
    </div>
  </section>
HTML;
    }

    private function renderStatusLine(ToolReport $report): string
    {
        $counts = $this->countByStatus($report);
        $parts = [];
        if ($counts['fail'] > 0) {
            $parts[] = '<span class="status-pill fail">' . $counts['fail'] . ' failed</span>';
        }
        if ($counts['warn'] > 0) {
            $parts[] = '<span class="status-pill warn">' . $counts['warn'] . ' warnings</span>';
        }
        if ($counts['pass'] > 0) {
            $parts[] = '<span class="status-pill pass">' . $counts['pass'] . ' passed</span>';
        }
        if ($parts === []) {
            $parts[] = '<span class="status-pill muted">No results</span>';
        }
        return implode(' ', $parts);
    }

    private function renderScoreRing(int $score): string
    {
        $score = max(0, min(100, $score));
        // Score-band colour: green (good) / amber (warning) / red (alarm).
        $band = $score >= 80 ? 'pass' : ($score >= 50 ? 'warn' : 'fail');
        // SVG circle math: r=44 → circumference ~276.46; dashoffset shrinks with higher score.
        $circumference = 2 * 3.14159265 * 44;
        $offset = $circumference * (1 - $score / 100);
        $offsetStr = number_format($offset, 2, '.', '');
        $circumferenceStr = number_format($circumference, 2, '.', '');

        return <<<SVG
      <div class="score-ring band-{$band}">
        <svg viewBox="0 0 100 100" width="96" height="96" aria-label="Score: {$score} out of 100">
          <circle cx="50" cy="50" r="44" class="score-track"/>
          <circle cx="50" cy="50" r="44" class="score-fill" stroke-dasharray="{$circumferenceStr}" stroke-dashoffset="{$offsetStr}"/>
        </svg>
        <div class="score-text">
          <div class="score-num">{$score}</div>
          <div class="score-denom">/100</div>
        </div>
      </div>
SVG;
    }

    private function renderResultGroups(ToolReport $report): string
    {
        // Group by result.name so 17 fat-controller hits collapse into
        // one heading with 17 messages — same trick ConsoleRenderer does.
        $groups = [];
        foreach ($report->results() as $r) {
            $groups[$r->name][] = $r;
        }

        // Sort: failures first, then warnings, then passes (severity-descending).
        uasort($groups, function (array $a, array $b): int {
            return $this->severityRank($b[0]->status) <=> $this->severityRank($a[0]->status);
        });

        $html = '';
        foreach ($groups as $name => $items) {
            $html .= $this->renderGroup((string) $name, $items);
        }
        return $html !== '' ? $html : '<p class="empty">No results to display.</p>';
    }

    /** @param array<int, CheckResult|RuleResult> $items */
    private function renderGroup(string $name, array $items): string
    {
        $first = $items[0];
        $status = $first->status;
        $statusClass = $this->statusClass($status);
        $icon = htmlspecialchars($status->icon(), ENT_QUOTES);
        $nameSafe = htmlspecialchars($name, ENT_QUOTES);
        $count = count($items);
        $countLabel = $count > 1 ? "<span class=\"group-count\">{$count} occurrences</span>" : '';

        $messageRows = '';
        foreach ($items as $item) {
            $messageRows .= $this->renderMessageRow($item);
        }

        // Dedup suggestions: 17 fat-controller hits all suggesting the same
        // refactor → show that suggestion once.
        $suggestions = [];
        foreach ($items as $item) {
            if ($item->suggestion !== null && $item->suggestion !== '') {
                $suggestions[$item->suggestion] = true;
            }
        }
        $suggestionBlock = '';
        if ($suggestions !== []) {
            $suggestionBlock = '<div class="suggestion-box"><div class="suggestion-label">Suggestion</div><ul>';
            foreach (array_keys($suggestions) as $s) {
                $suggestionBlock .= '<li>' . htmlspecialchars($s, ENT_QUOTES) . '</li>';
            }
            $suggestionBlock .= '</ul></div>';
        }

        return <<<HTML
      <div class="group {$statusClass}">
        <div class="group-header">
          <span class="group-icon">{$icon}</span>
          <span class="group-name">{$nameSafe}</span>
          {$countLabel}
        </div>
        <div class="group-messages">
          {$messageRows}
        </div>
        {$suggestionBlock}
      </div>
HTML;
    }

    private function renderMessageRow(CheckResult|RuleResult $item): string
    {
        $msg = htmlspecialchars($item->message, ENT_QUOTES);

        $location = '';
        if ($item instanceof RuleResult && $item->file !== null && $item->file !== '') {
            $loc = $item->file . ($item->line !== null ? ":{$item->line}" : '');
            $location = '<span class="loc">' . htmlspecialchars($loc, ENT_QUOTES) . '</span>';
        }

        $impact = '';
        if ($item instanceof CheckResult && $item->impact > 0 && $item->status !== Status::Pass) {
            $impact = '<span class="impact">−' . (int) $item->impact . '</span>';
        }

        return "<div class=\"row\">{$location}<span class=\"msg\">{$msg}</span>{$impact}</div>";
    }

    private function statusClass(Status $s): string
    {
        return match ($s) {
            Status::Pass => 'pass',
            Status::Warning => 'warn',
            Status::Fail => 'fail',
        };
    }

    private function severityRank(Status $s): int
    {
        return match ($s) {
            Status::Fail => 3,
            Status::Warning => 2,
            Status::Pass => 1,
        };
    }

    /**
     * @param array<int, ToolReport> $reports
     * @return array{pass:int,warn:int,fail:int}
     */
    private function aggregateTotals(array $reports): array
    {
        $totals = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($reports as $r) {
            $c = $this->countByStatus($r);
            $totals['pass'] += $c['pass'];
            $totals['warn'] += $c['warn'];
            $totals['fail'] += $c['fail'];
        }
        return $totals;
    }

    /** @return array{pass:int,warn:int,fail:int} */
    private function countByStatus(ToolReport $report): array
    {
        $c = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($report->results() as $r) {
            $key = match ($r->status) {
                Status::Pass => 'pass',
                Status::Warning => 'warn',
                Status::Fail => 'fail',
            };
            $c[$key]++;
        }
        return $c;
    }

    private function css(): string
    {
        // Single inline stylesheet. CSS variables let us tweak the palette in
        // one place. Light mode only for v1 — dark mode can come later if asked.
        return <<<CSS
:root {
  --bg: #f8fafc;
  --surface: #ffffff;
  --text: #0f172a;
  --muted: #64748b;
  --border: #e2e8f0;
  --accent: #6366f1;
  --pass: #16a34a;
  --pass-bg: #f0fdf4;
  --warn: #d97706;
  --warn-bg: #fffbeb;
  --fail: #dc2626;
  --fail-bg: #fef2f2;
  --shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.04);
}
* { box-sizing: border-box; }
html, body {
  margin: 0;
  padding: 0;
  background: var(--bg);
  color: var(--text);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
.page { max-width: 960px; margin: 0 auto; padding: 32px 24px 64px; }

.topbar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 20px 24px; background: var(--surface);
  border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow);
  margin-bottom: 16px;
}
.brand { display: flex; align-items: center; gap: 14px; }
.brand-mark {
  width: 40px; height: 40px; border-radius: 10px;
  background: linear-gradient(135deg, var(--accent), #8b5cf6);
  color: white; font-weight: 700; font-size: 14px;
  display: flex; align-items: center; justify-content: center; letter-spacing: 0.5px;
}
.brand-name { font-weight: 600; font-size: 16px; }
.brand-sub { color: var(--muted); font-size: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
.topbar-meta { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.generated { color: var(--muted); font-size: 12px; }

.overall-badge {
  display: inline-block; padding: 4px 10px; border-radius: 999px;
  font-size: 12px; font-weight: 600; letter-spacing: 0.2px;
}
.overall-badge.pass { background: var(--pass-bg); color: var(--pass); border: 1px solid #bbf7d0; }
.overall-badge.fail { background: var(--fail-bg); color: var(--fail); border: 1px solid #fecaca; }

.summary-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
  box-shadow: var(--shadow); padding: 18px 24px; margin-bottom: 20px;
}
.summary-row { display: flex; gap: 32px; }
.summary-stat { display: flex; flex-direction: column; gap: 2px; }
.summary-num { font-size: 28px; font-weight: 700; line-height: 1; }
.summary-label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.summary-stat.pass .summary-num { color: var(--pass); }
.summary-stat.warn .summary-num { color: var(--warn); }
.summary-stat.fail .summary-num { color: var(--fail); }

.report {
  background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
  box-shadow: var(--shadow); margin-bottom: 16px; overflow: hidden;
}
.report-header {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 18px 24px; border-bottom: 1px solid var(--border);
}
.report-title { margin: 0 0 4px; font-size: 16px; font-weight: 600; }
.report-status { display: flex; gap: 6px; flex-wrap: wrap; }
.status-pill {
  display: inline-block; padding: 2px 8px; border-radius: 999px;
  font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
}
.status-pill.pass { background: var(--pass-bg); color: var(--pass); }
.status-pill.warn { background: var(--warn-bg); color: var(--warn); }
.status-pill.fail { background: var(--fail-bg); color: var(--fail); }
.status-pill.muted { background: #f1f5f9; color: var(--muted); }

.report-body { padding: 12px 16px 18px; }

.score-ring { position: relative; width: 96px; height: 96px; flex-shrink: 0; }
.score-ring svg { transform: rotate(-90deg); display: block; }
.score-track { fill: none; stroke: var(--border); stroke-width: 8; }
.score-fill {
  fill: none; stroke-width: 8; stroke-linecap: round;
  transition: stroke-dashoffset 0.6s ease;
}
.band-pass .score-fill { stroke: var(--pass); }
.band-warn .score-fill { stroke: var(--warn); }
.band-fail .score-fill { stroke: var(--fail); }
.score-text {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 0;
}
.score-num { font-size: 22px; font-weight: 700; line-height: 1; }
.score-denom { font-size: 10px; color: var(--muted); margin-top: 2px; }

.group {
  border-left: 3px solid var(--border); margin: 12px 8px;
  padding: 10px 14px; border-radius: 6px;
}
.group.fail { border-left-color: var(--fail); background: var(--fail-bg); }
.group.warn { border-left-color: var(--warn); background: var(--warn-bg); }
.group.pass { border-left-color: var(--pass); background: var(--pass-bg); }

.group-header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.group-icon { font-size: 16px; line-height: 1; }
.group-name {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-weight: 600; font-size: 13px;
}
.group-count {
  margin-left: auto; font-size: 11px; color: var(--muted);
  background: rgba(15, 23, 42, 0.05); padding: 2px 8px; border-radius: 999px;
}

.group-messages { display: flex; flex-direction: column; gap: 4px; }
.row {
  display: flex; gap: 10px; align-items: baseline; padding: 4px 0; flex-wrap: wrap;
}
.loc {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: var(--muted); font-size: 12px; flex-shrink: 0;
}
.msg { flex: 1; min-width: 0; word-break: break-word; }
.impact {
  color: var(--fail); font-weight: 600; font-size: 12px;
  background: rgba(220, 38, 38, 0.08); padding: 2px 8px; border-radius: 4px;
}

.suggestion-box {
  margin-top: 10px; padding: 10px 12px; background: rgba(255,255,255,0.6);
  border: 1px dashed var(--border); border-radius: 6px;
}
.suggestion-label {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
  color: var(--muted); margin-bottom: 4px;
}
.suggestion-box ul { margin: 0; padding-left: 18px; }
.suggestion-box li { padding: 2px 0; }

.empty { color: var(--muted); padding: 12px 16px; }

.page-footer {
  text-align: center; color: var(--muted); font-size: 12px; margin-top: 24px;
}
.page-footer a { color: var(--accent); text-decoration: none; }
.page-footer a:hover { text-decoration: underline; }
CSS;
    }
}
