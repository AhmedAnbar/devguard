<?php

declare(strict_types=1);

namespace DevGuard\Core\Commands;

use DevGuard\Core\ToolManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tools', description: 'List all registered DevGuard tools')]
final class ListToolsCommand extends Command
{
    public function __construct(private readonly ToolManager $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Name', 'Title', 'Description']);

        foreach ($this->manager->all() as $tool) {
            $table->addRow([
                "<fg=cyan>{$tool->name()}</>",
                $tool->title(),
                $tool->description(),
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }
}
