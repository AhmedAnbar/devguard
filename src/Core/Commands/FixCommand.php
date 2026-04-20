<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use DevGuard\Contracts\FixableInterface;
use DevGuard\Contracts\FixableToolInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Core\ToolManager;
use DevGuard\Results\Fix;
use DevGuard\Results\FixResult;
use DevGuard\Results\Status;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'fix', description: 'Apply auto-fixes for issues a tool can mutate (deps, env, all)')]
final class FixCommand extends Command
{
    public function __construct(private readonly ToolManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tool', InputArgument::OPTIONAL, 'Tool to fix (deps, env, all)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the plan without changing anything')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply every fix without prompting (CI mode)')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project path (default: current directory)', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $toolName = (string) ($input->getArgument('tool') ?? '');
        $path = (string) $input->getOption('path');
        $dryRun = (bool) $input->getOption('dry-run');
        $assumeYes = (bool) $input->getOption('yes');

        if ($toolName === '') {
            $output->writeln('<fg=red>Error:</> The "tool" argument is required.');
            $output->writeln('Try <fg=cyan>devguard fix deps</>, <fg=cyan>devguard fix env</>, or <fg=cyan>devguard fix all</>.');
            return Command::INVALID;
        }

        try {
            $context = ProjectContext::detect($path);
        } catch (\Throwable $e) {
            $output->writeln('<fg=red>Error:</> ' . $e->getMessage());
            return Command::INVALID;
        }

        $tools = $toolName === 'all'
            ? array_values($this->manager->all())
            : [$this->manager->get($toolName)];

        // Filter to fixable tools — explain instead of crashing if the user
        // points us at e.g. 'architecture' (which is not fixable by design).
        $fixableTools = array_values(array_filter(
            $tools,
            static fn ($t) => $t instanceof FixableToolInterface,
        ));

        if ($fixableTools === []) {
            $output->writeln(sprintf('<fg=yellow>No fixable tools matched "%s".</>', $toolName));
            $output->writeln('Currently fixable: <fg=cyan>deps</>, <fg=cyan>env</>.');
            $output->writeln('Architecture violations need human refactoring — no auto-fix.');
            return Command::SUCCESS;
        }

        $results = [];
        $totalProposed = 0;

        /** @var FixableToolInterface $tool */
        foreach ($fixableTools as $tool) {
            foreach ($tool->fixableRules() as $rule) {
                $fixes = $this->safelyPropose($rule, $context, $output);
                if ($fixes === []) {
                    continue;
                }

                $output->writeln('');
                $output->writeln(sprintf(
                    '<options=bold>%s</> · <fg=gray>%s</> — %d fix%s available',
                    $tool->title(),
                    $rule->name(),
                    count($fixes),
                    count($fixes) === 1 ? '' : 'es'
                ));

                foreach ($fixes as $fix) {
                    $totalProposed++;
                    $output->writeln(sprintf('  <fg=cyan>→</> <options=bold>%s</>', $fix->target));
                    $output->writeln(sprintf('    <fg=gray>%s</>', $fix->description));

                    if ($dryRun) {
                        continue;
                    }

                    if (! $assumeYes && ! $this->confirm($input, $output, "    Apply this fix?")) {
                        $results[] = FixResult::skipped($fix, 'Skipped by user');
                        $output->writeln('    <fg=yellow>↷ skipped</>');
                        continue;
                    }

                    $result = $this->safelyApply($rule, $context, $fix);
                    $results[] = $result;
                    $output->writeln('    ' . $this->formatResultLine($result));
                }
            }
        }

        $output->writeln('');

        if ($totalProposed === 0) {
            $output->writeln('<fg=green>✓</> Nothing to fix — no actionable issues found.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                '<fg=cyan>Dry run.</> %d fix%s would be applied. Re-run without <fg=cyan>--dry-run</> to mutate.',
                $totalProposed,
                $totalProposed === 1 ? '' : 'es'
            ));
            return Command::SUCCESS;
        }

        return $this->printSummary($output, $results);
    }

    /** @return array<int, Fix> */
    private function safelyPropose(FixableInterface $rule, ProjectContext $ctx, OutputInterface $output): array
    {
        try {
            return $rule->proposeFixes($ctx);
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<fg=red>Could not propose fixes for %s:</> %s',
                $rule->name(),
                $e->getMessage()
            ));
            return [];
        }
    }

    private function safelyApply(FixableInterface $rule, ProjectContext $ctx, Fix $fix): FixResult
    {
        try {
            return $rule->applyFix($ctx, $fix);
        } catch (\Throwable $e) {
            return FixResult::failed($fix, "Crashed: {$e->getMessage()}");
        }
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        return (bool) $helper->ask(
            $input,
            $output,
            new ConfirmationQuestion("<fg=yellow>{$question}</> [y/N] ", false)
        );
    }

    private function formatResultLine(FixResult $result): string
    {
        return match ($result->status) {
            Status::Pass => sprintf('<fg=green>✓ %s</>', $result->message),
            Status::Warning => sprintf('<fg=yellow>↷ %s</>', $result->message),
            Status::Fail => sprintf('<fg=red>✗ %s</>', $result->message),
        };
    }

    /** @param array<int, FixResult> $results */
    private function printSummary(OutputInterface $output, array $results): int
    {
        $applied = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($results as $r) {
            match ($r->status) {
                Status::Pass => $applied++,
                Status::Warning => $skipped++,
                Status::Fail => $failed++,
            };
        }

        $output->writeln(sprintf(
            '<options=bold>Fix summary:</> <fg=green>%d applied</> · <fg=yellow>%d skipped</> · <fg=red>%d failed</>',
            $applied,
            $skipped,
            $failed
        ));

        if ($failed > 0) {
            $output->writeln('<fg=red>Some fixes failed.</> Re-run <fg=cyan>devguard run <tool></> to see what remains.');
            return Command::FAILURE;
        }

        if ($applied > 0) {
            $output->writeln('<fg=green>Done.</> Re-run <fg=cyan>devguard run <tool></> to verify the issues are gone.');
        }
        return Command::SUCCESS;
    }
}
