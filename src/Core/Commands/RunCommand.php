<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use DevGuard\Core\Output\ConsoleRenderer;
use DevGuard\Core\Output\HtmlRenderer;
use DevGuard\Core\ProjectContext;
use DevGuard\Core\ToolManager;
use Composer\InstalledVersions;
use Symfony\Component\Process\Process;
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
            ->addOption('html', null, InputOption::VALUE_OPTIONAL, 'Write a self-contained HTML report. Optional path; defaults to ./devguard-report.html', false)
            ->addOption('no-open', null, InputOption::VALUE_NONE, 'With --html: do not auto-open the report in your browser')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project path (default: current directory)', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $toolName = (string) ($input->getArgument('tool') ?? '');
        $path = (string) $input->getOption('path');
        $jsonMode = (bool) $input->getOption('json');

        // --html accepts no value (uses default path) or =path. The option's
        // default is `false` so we can distinguish "not passed" from "passed".
        $htmlOpt = $input->getOption('html');
        $htmlMode = $htmlOpt !== false;
        $htmlPath = $htmlMode
            ? (is_string($htmlOpt) && $htmlOpt !== '' ? $htmlOpt : 'devguard-report.html')
            : null;

        if ($jsonMode && $htmlMode) {
            $output->writeln('<fg=red>Error:</> --json and --html are mutually exclusive — pick one output format.');
            return Command::INVALID;
        }

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
        $jsonReports = [];
        $reportObjects = [];

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

            if ($htmlMode) {
                // Buffer for the HTML pass — don't print individual reports
                // to the terminal in HTML mode.
                $reportObjects[] = $report;
            } elseif ($jsonMode) {
                $jsonReports[] = $report->toArray();
            } else {
                $renderer->render($report, $output);
            }

            if ($report->hasFailures()) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($jsonMode) {
            $payload = count($jsonReports) === 1 ? $jsonReports[0] : ['reports' => $jsonReports];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($htmlMode && $htmlPath !== null) {
            $writeResult = $this->writeHtmlReport(
                $reportObjects,
                $context->rootPath,
                $htmlPath,
                $output,
                ! (bool) $input->getOption('no-open'),
            );
            if ($writeResult !== Command::SUCCESS) {
                return $writeResult;
            }
        }

        return $exitCode;
    }

    /**
     * @param array<int, \DevGuard\Results\ToolReport> $reports
     */
    private function writeHtmlReport(array $reports, string $projectPath, string $path, OutputInterface $output, bool $autoOpen): int
    {
        $version = '0.4.0';
        try {
            $installed = InstalledVersions::getPrettyVersion('ahmedanbar/devguard');
            if (is_string($installed) && $installed !== '') {
                $version = ltrim($installed, 'v');
            }
        } catch (\Throwable) {
            // Fall back to the constant above.
        }

        $renderer = new HtmlRenderer();
        $html = $renderer->renderReports($reports, $projectPath, $version);

        // Resolve the file path. Relative paths are against CWD (where the
        // user invoked devguard), not against --path (the project under audit).
        $absolute = $path;
        if (! str_starts_with($path, '/')) {
            $cwd = getcwd();
            $absolute = ($cwd === false ? $path : rtrim($cwd, '/') . '/' . $path);
        }

        // Make sure the target directory exists; create only the leaf if missing
        // so we don't accidentally create deep paths the user didn't intend.
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            $output->writeln(sprintf('<fg=red>Error:</> directory does not exist: %s', $dir));
            return 2;
        }

        if (file_put_contents($absolute, $html) === false) {
            $output->writeln(sprintf('<fg=red>Error:</> could not write HTML report to %s', $absolute));
            return 2;
        }

        $output->writeln(sprintf('<fg=green>✓</> HTML report written to <fg=cyan>%s</>', $absolute));

        if ($autoOpen) {
            $this->openInBrowser($absolute, $output);
        }

        return Command::SUCCESS;
    }

    /**
     * Best-effort browser launch. Skips silently in CI, on unsupported OSes,
     * or if the OS-level open command isn't available. Never fails the run.
     */
    private function openInBrowser(string $absolutePath, OutputInterface $output): void
    {
        // Don't pop a browser in CI (GitHub Actions, GitLab, CircleCI etc.
        // all set CI=true). The user still gets the path in the line above.
        $ci = getenv('CI');
        if ($ci !== false && $ci !== '' && $ci !== '0' && strtolower((string) $ci) !== 'false') {
            return;
        }

        $cmd = $this->browserOpenCommand($absolutePath);
        if ($cmd === null) {
            // Unsupported OS — be silent, don't pretend we tried.
            return;
        }

        try {
            $process = new Process($cmd);
            $process->setTimeout(5);
            // Fire-and-forget: the OS open commands return in milliseconds
            // (the browser is spawned as a detached process by the OS itself).
            $process->start();
        } catch (\Throwable $e) {
            // Browser open is a nice-to-have; don't make it fatal.
            $output->writeln(sprintf('<fg=gray>(could not auto-open browser: %s)</>', $e->getMessage()));
        }
    }

    /**
     * Returns the OS-appropriate command to open a file in the default browser.
     * Returns null on platforms we don't support (BSD/Solaris/etc).
     *
     * @return array<int, string>|null
     */
    private function browserOpenCommand(string $path): ?array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $path],
            'Linux' => ['xdg-open', $path],
            // Windows 'start' is a cmd.exe builtin; the empty "" is the
            // window title placeholder, required when the path may be quoted.
            'Windows' => ['cmd', '/c', 'start', '', $path],
            default => null,
        };
    }
}
