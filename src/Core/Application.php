<?php

declare(strict_types=1);

namespace DevGuard\Core;

use DevGuard\Contracts\ToolInterface;
use DevGuard\Core\Commands\InstallHookCommand;
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

        // NOTE: We use add() not addCommand(). addCommand() only exists in
        // symfony/console 7.4+, and our constraint allows 7.0+. add() works on
        // every 7.x version (deprecation warning since 7.4 is acceptable —
        // it'll only become a real error in Symfony 8, at which point we bump
        // the constraint to ^8.0 and migrate.)
        $this->add(new RunCommand($this->manager));
        $this->add(new ListToolsCommand($this->manager));
        $this->add(new MenuCommand($this->manager));
        $this->add(new InstallHookCommand());

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
