<?php

declare(strict_types=1);

namespace DevGuard\Core;

use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\Commands\ListToolsCommand;
use DevGuard\Core\Commands\MenuCommand;
use DevGuard\Core\Commands\RunCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    private ToolManager $manager;

    public function __construct(string $name = 'DevGuard', string $version = '0.1.0')
    {
        parent::__construct($name, $version);

        $this->manager = new ToolManager();

        $this->add(new RunCommand($this->manager));
        $this->add(new ListToolsCommand($this->manager));
        $this->add(new MenuCommand($this->manager));

        $this->setDefaultCommand('menu');
    }

    public function addTool(ToolInterface $tool): self
    {
        $this->manager->register($tool);
        return $this;
    }

    public function manager(): ToolManager
    {
        return $this->manager;
    }
}
