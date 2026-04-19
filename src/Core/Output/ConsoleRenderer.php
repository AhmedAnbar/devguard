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

        foreach ($report->results() as $result) {
            $this->renderResult($result, $output);
        }

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
        $output->writeln('');
    }

    private function renderResult(CheckResult|RuleResult $result, OutputInterface $output): void
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

    private function renderSuggestions(ToolReport $report, OutputInterface $output): void
    {
        $suggestions = $report->suggestions();
        if ($suggestions === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('<options=bold>Suggestions:</>');
        foreach ($suggestions as $r) {
            $output->writeln(sprintf('  <fg=cyan>→</> %s', $r->suggestion));
        }
    }

    private function renderFooter(ToolReport $report, OutputInterface $output): void
    {
        $output->writeln('');
        if ($report->hasFailures()) {
            $output->writeln('<fg=red;options=bold>Failed.</> Address the errors above before deploying.');
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
