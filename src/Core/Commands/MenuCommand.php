<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use DevGuard\Core\ToolManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;

#[AsCommand(name: 'menu', description: 'Interactive tool selection menu', hidden: true)]
final class MenuCommand extends Command
{
    public function __construct(private readonly ToolManager $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('  <fg=cyan;options=bold>DevGuard</> — developer toolkit');
        $output->writeln('');

        $options = [];
        foreach ($this->manager->all() as $tool) {
            $options[$tool->name()] = $tool->title();
        }
        $options['all'] = 'Run all tools';
        $options['exit'] = 'Exit';

        $choice = select(
            label: 'Select a tool to run',
            options: $options,
        );

        if ($choice === 'exit') {
            return Command::SUCCESS;
        }

        $runCommand = $this->getApplication()?->find('run');
        if ($runCommand === null) {
            $output->writeln('<fg=red>Error: run command not registered.</>');
            return 2;
        }

        return $runCommand->run(
            new ArrayInput(['tool' => $choice]),
            $output
        );
    }
}
