<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use Composer\InstalledVersions;
use DevGuard\Core\Baseline\BaselineLoader;
use DevGuard\Core\ProjectContext;
use DevGuard\Core\ToolManager;
use DevGuard\Results\ToolReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'baseline', description: 'Record current issues as the baseline so future runs only show NEW issues')]
final class BaselineCommand extends Command
{
    public function __construct(private readonly ToolManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project path (default: current directory)', getcwd())
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the baseline file', BaselineLoader::DEFAULT_FILENAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getOption('path');
        $outputFile = (string) $input->getOption('output');

        try {
            $context = ProjectContext::detect($path);
        } catch (\Throwable $e) {
            $output->writeln('<fg=red>Error:</> ' . $e->getMessage());
            return Command::INVALID;
        }

        // Run every registered tool. Baseline is whole-project by design —
        // a per-tool baseline would let issues silently slip through when
        // a team adopts a new tool later.
        $reports = [];
        foreach ($this->manager->all() as $tool) {
            try {
                $report = $tool->run($context);
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '<fg=red>Tool "%s" crashed during baseline:</> %s',
                    $tool->name(),
                    $e->getMessage()
                ));
                return 2;
            }
            $reports[] = $report;
        }

        // Write into the audited project, not the user's cwd. The baseline
        // belongs WITH the project — that's the whole point of committing
        // it. Custom locations (`--output=/abs/path.json`) still work.
        $absolute = $this->resolveOutputPath($outputFile, $context->rootPath);
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            $output->writeln(sprintf('<fg=red>Error:</> directory does not exist: %s', $dir));
            return 2;
        }

        $loader = new BaselineLoader();
        try {
            $count = $loader->save($absolute, $reports, $this->toolVersionMap());
        } catch (\Throwable $e) {
            $output->writeln('<fg=red>Error writing baseline:</> ' . $e->getMessage());
            return 2;
        }

        $output->writeln(sprintf(
            '<fg=green>✓</> Baseline written with %d issue%s to <fg=cyan>%s</>',
            $count,
            $count === 1 ? '' : 's',
            $absolute
        ));
        $output->writeln('');
        $output->writeln('<fg=gray>Commit this file. Future `devguard run` invocations will silently</>');
        $output->writeln('<fg=gray>suppress these issues and surface only NEW ones.</>');

        return Command::SUCCESS;
    }

    private function resolveOutputPath(string $given, string $projectRoot): string
    {
        if (str_starts_with($given, '/')) {
            return $given;
        }
        return rtrim($projectRoot, '/') . '/' . ltrim($given, '/');
    }

    /** @return array<string, string> */
    private function toolVersionMap(): array
    {
        $version = '0.8.1'; // synced manually with bin/devguard fallback
        try {
            $installed = InstalledVersions::getPrettyVersion('ahmedanbar/devguard');
            if (is_string($installed) && $installed !== '') {
                $version = ltrim($installed, 'v');
            }
        } catch (\Throwable) {
            // Stay on the fallback.
        }
        return ['devguard' => $version];
    }
}
