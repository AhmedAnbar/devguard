<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install-hook', description: 'Install a git hook that runs DevGuard before commits or pushes')]
final class InstallHookCommand extends Command
{
    private const SUPPORTED_TYPES = ['pre-commit', 'pre-push'];

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Hook type: pre-commit or pre-push', 'pre-push')
            ->addOption('tools', null, InputOption::VALUE_REQUIRED, 'Comma-separated tools to run', 'deploy,architecture')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project path (default: current directory)', getcwd())
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing hook');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getOption('type');
        $tools = (string) $input->getOption('tools');
        $path = (string) $input->getOption('path');
        $force = (bool) $input->getOption('force');

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            $output->writeln(sprintf(
                '<fg=red>Error:</> --type must be one of: %s',
                implode(', ', self::SUPPORTED_TYPES)
            ));
            return Command::INVALID;
        }

        $hooksDir = rtrim($path, '/') . '/.git/hooks';
        if (! is_dir($hooksDir)) {
            $output->writeln('<fg=red>Error:</> not a git repository — no .git/hooks directory at ' . $hooksDir);
            $output->writeln('Run <fg=cyan>git init</> first or pass <fg=cyan>--path=/path/to/repo</>.');
            return Command::INVALID;
        }

        $toolList = array_values(array_filter(array_map('trim', explode(',', $tools))));
        if ($toolList === []) {
            $output->writeln('<fg=red>Error:</> --tools cannot be empty');
            return Command::INVALID;
        }

        $hookPath = $hooksDir . '/' . $type;
        if (file_exists($hookPath) && ! $force) {
            $output->writeln(sprintf('<fg=red>Error:</> hook already exists at %s', $hookPath));
            $output->writeln('Use <fg=cyan>--force</> to overwrite, or back up the existing hook first.');
            return Command::INVALID;
        }

        $script = $this->buildHookScript($type, $toolList);

        if (file_put_contents($hookPath, $script) === false) {
            $output->writeln('<fg=red>Error:</> could not write to ' . $hookPath);
            return 2;
        }
        chmod($hookPath, 0o755);

        $output->writeln(sprintf('<fg=green>✓</> Installed <options=bold>%s</> hook at <fg=gray>%s</>', $type, $hookPath));
        $output->writeln(sprintf('  <fg=gray>Runs:</> %s', implode(' && ', array_map(fn ($t) => "devguard run {$t}", $toolList))));
        $output->writeln('');
        $output->writeln('<fg=gray>The hook will skip silently if `devguard` is not on PATH (so collaborators');
        $output->writeln('without DevGuard installed are not blocked).</>');

        return Command::SUCCESS;
    }

    /** @param array<int, string> $toolList */
    private function buildHookScript(string $type, array $toolList): string
    {
        $lines = [];
        foreach ($toolList as $tool) {
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tool) ?? '';
            $lines[] = "devguard run {$safe} || exit \$?";
        }
        $body = implode("\n", $lines);

        return <<<SHELL
#!/bin/sh
# DevGuard {$type} hook — installed by `devguard install-hook`.
# https://github.com/AhmedAnbar/devguard
#
# To bypass once: pass --no-verify to git.
# To remove: rm .git/hooks/{$type}

if ! command -v devguard >/dev/null 2>&1; then
    echo "DevGuard not on PATH — skipping checks. (Install: composer global require ahmedanbar/devguard)"
    exit 0
fi

{$body}

SHELL;
    }
}
