<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use DevGuard\Core\Output\ConsoleRenderer;
use DevGuard\Core\ProjectContext;
use DevGuard\Core\ToolManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'run', description: 'Run a specific DevGuard tool')]
final class RunCommand extends Command
{
    public function __construct(private readonly ToolManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        // 'tool' is OPTIONAL (validated below) because Symfony's auto-merged
        // application 'command' argument is also optional — Symfony rejects
        // a required argument that follows an optional one.
        $this
            ->addArgument('tool', InputArgument::OPTIONAL, 'Tool name (e.g. deploy, architecture, all)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (CI-friendly)')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project path (default: current directory)', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $toolName = (string) ($input->getArgument('tool') ?? '');
        $path = (string) $input->getOption('path');
        $jsonMode = (bool) $input->getOption('json');

        if ($toolName === '') {
            $output->writeln('<fg=red>Error:</> The "tool" argument is required.');
            $output->writeln('Try <fg=cyan>devguard tools</> to list available tools,');
            $output->writeln('or <fg=cyan>devguard run all</> to run every registered tool.');
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

        $renderer = new ConsoleRenderer();
        $exitCode = Command::SUCCESS;
        $reports = [];

        foreach ($tools as $tool) {
            try {
                $report = $tool->run($context);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<fg=red>Tool "%s" crashed:</> %s', $tool->name(), $e->getMessage()));
                if ($output->isVerbose()) {
                    $output->writeln($e->getTraceAsString());
                }
                return 2;
            }

            if ($jsonMode) {
                $reports[] = $report->toArray();
            } else {
                $renderer->render($report, $output);
            }

            if ($report->hasFailures()) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($jsonMode) {
            $payload = count($reports) === 1 ? $reports[0] : ['reports' => $reports];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $exitCode;
    }
}
