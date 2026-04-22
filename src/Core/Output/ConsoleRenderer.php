<?php

declare(strict_types=1);

namespace DevGuard\Core\Output;

use DevGuard\Contracts\RendererInterface;
use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;
use DevGuard\Results\Status;
use DevGuard\Results\ToolReport;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleRenderer implements RendererInterface
{
    public function render(ToolReport $report, OutputInterface $output): void
    {
        $this->renderHeader($report, $output);
        $this->renderSummary($report, $output);
        $this->renderGroupedResults($report, $output);
        $this->renderSuggestions($report, $output);
        $this->renderFooter($report, $output);
    }

    private function renderHeader(ToolReport $report, OutputInterface $output): void
    {
        $output->writeln('');
        if ($report->score !== null) {
            $color = $this->scoreColor($report->score);
            $output->writeln(sprintf(
                '<fg=%s;options=bold>%s: %d/100</>',
                $color,
                $report->title,
                $report->score
            ));
        } else {
            $output->writeln(sprintf('<options=bold>%s</>', $report->title));
        }
    }

    private function renderSummary(ToolReport $report, OutputInterface $output): void
    {
        $counts = ['fail' => 0, 'warning' => 0, 'pass' => 0];
        foreach ($report->results() as $r) {
            $counts[$r->status->value]++;
        }

        $parts = [];
        if ($counts['fail'] > 0) {
            $parts[] = sprintf('<fg=red>%d failed</>', $counts['fail']);
        }
        if ($counts['warning'] > 0) {
            $parts[] = sprintf('<fg=yellow>%d warning%s</>', $counts['warning'], $counts['warning'] === 1 ? '' : 's');
        }
        if ($counts['pass'] > 0) {
            $parts[] = sprintf('<fg=green>%d passed</>', $counts['pass']);
        }

        if ($parts !== []) {
            $output->writeln('  <fg=gray>' . implode(' · ', $parts) . '</>');
        }

        // Surface baseline suppression so users know there's hidden state.
        // Without this line, "0 issues" would be ambiguous: actually clean,
        // or just baselined?
        $suppressed = $report->suppressedCount();
        if ($suppressed > 0) {
            $output->writeln(sprintf(
                '  <fg=gray>(%d issue%s suppressed by baseline / @devguard-ignore)</>',
                $suppressed,
                $suppressed === 1 ? '' : 's'
            ));
        }

        $output->writeln('');
    }

    private function renderGroupedResults(ToolReport $report, OutputInterface $output): void
    {
        $groups = $this->groupByName($report);
        $sorted = $this->sortGroupsBySeverity($groups);

        foreach ($sorted as $name => $results) {
            if (count($results) === 1) {
                $this->renderSingleResult($results[0], $output);
            } else {
                $this->renderMultiResultGroup($name, $results, $output);
            }
        }
    }

    /** @return array<string, array<int, CheckResult|RuleResult>> */
    private function groupByName(ToolReport $report): array
    {
        $groups = [];
        foreach ($report->results() as $r) {
            $groups[$r->name][] = $r;
        }
        return $groups;
    }

    /**
     * @param array<string, array<int, CheckResult|RuleResult>> $groups
     * @return array<string, array<int, CheckResult|RuleResult>>
     */
    private function sortGroupsBySeverity(array $groups): array
    {
        uasort($groups, function (array $a, array $b): int {
            return $this->worstSeverityRank($b) <=> $this->worstSeverityRank($a);
        });
        return $groups;
    }

    /** @param array<int, CheckResult|RuleResult> $results */
    private function worstSeverityRank(array $results): int
    {
        $worst = 0;
        foreach ($results as $r) {
            $rank = match ($r->status) {
                Status::Fail => 2,
                Status::Warning => 1,
                Status::Pass => 0,
            };
            if ($rank > $worst) {
                $worst = $rank;
            }
        }
        return $worst;
    }

    private function renderSingleResult(CheckResult|RuleResult $result, OutputInterface $output): void
    {
        $color = $result->status->color();
        $icon = $result->status->icon();

        $line = sprintf('  <fg=%s>%s</> %s', $color, $icon, $result->message);

        if ($result instanceof CheckResult && $result->impact > 0 && $result->status !== Status::Pass) {
            $deduction = $result->status === Status::Warning
                ? (int) floor($result->impact / 2)
                : $result->impact;
            $line .= sprintf(' <fg=gray>[-%d]</>', $deduction);
        }

        if ($result instanceof RuleResult && $result->file !== null) {
            $location = $result->file . ($result->line !== null ? ':' . $result->line : '');
            $line .= sprintf("\n      <fg=gray>%s</>", $location);
        }

        $output->writeln($line);
    }

    /** @param array<int, CheckResult|RuleResult> $results */
    private function renderMultiResultGroup(string $name, array $results, OutputInterface $output): void
    {
        $worst = match ($this->worstSeverityRank($results)) {
            2 => Status::Fail,
            1 => Status::Warning,
            default => Status::Pass,
        };

        $output->writeln(sprintf(
            '  <fg=%s>%s</> <options=bold>%s</> <fg=gray>(%d violations)</>',
            $worst->color(),
            $worst->icon(),
            $name,
            count($results),
        ));

        foreach ($results as $r) {
            if ($r instanceof RuleResult && $r->file !== null) {
                $location = $r->file . ($r->line !== null ? ':' . $r->line : '');
                $output->writeln(sprintf('      <fg=gray>•</> <fg=gray>%s</>', $location));
                $output->writeln(sprintf('        %s', $r->message));
            } else {
                $output->writeln(sprintf('      <fg=gray>•</> %s', $r->message));
            }
        }
        $output->writeln('');
    }

    private function renderSuggestions(ToolReport $report, OutputInterface $output): void
    {
        $suggestions = $report->suggestions();
        if ($suggestions === []) {
            return;
        }

        // Deduplicate by suggestion text — many violations of the same rule share identical advice.
        $unique = [];
        $seen = [];
        foreach ($suggestions as $r) {
            $text = $r->suggestion ?? '';
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $unique[] = $text;
        }

        if ($unique === []) {
            return;
        }

        $output->writeln('');
        $countLabel = count($unique) === count($suggestions)
            ? ''
            : sprintf(' <fg=gray>(%d unique)</>', count($unique));
        $output->writeln('<options=bold>Suggestions:</>' . $countLabel);
        foreach ($unique as $text) {
            $output->writeln(sprintf('  <fg=cyan>→</> %s', $text));
        }
    }

    private function renderFooter(ToolReport $report, OutputInterface $output): void
    {
        $output->writeln('');
        if ($report->hasFailures()) {
            $failureCount = 0;
            foreach ($report->results() as $r) {
                if ($r->status === Status::Fail) {
                    $failureCount++;
                }
            }
            $output->writeln(sprintf(
                '<fg=red;options=bold>Failed.</> %d issue%s to address.',
                $failureCount,
                $failureCount === 1 ? '' : 's'
            ));
        } elseif ($report->hasWarnings()) {
            $output->writeln('<fg=yellow;options=bold>Passed with warnings.</>');
        } else {
            $output->writeln('<fg=green;options=bold>All checks passed.</>');
        }
        $output->writeln('');
    }

    private function scoreColor(int $score): string
    {
        return match (true) {
            $score >= 80 => 'green',
            $score >= 50 => 'yellow',
            default => 'red',
        };
    }
}
