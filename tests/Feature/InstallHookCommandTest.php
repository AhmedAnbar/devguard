<?php

declare(strict_types=1);

use DevGuard\Core\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function makeTempGitRepo(): string
{
    $dir = sys_get_temp_dir() . '/devguard-hook-test-' . uniqid('', true);
    mkdir($dir);
    mkdir($dir . '/.git');
    mkdir($dir . '/.git/hooks');
    file_put_contents($dir . '/composer.json', '{}');
    return $dir;
}

function rmTempDir(string $dir): void
{
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($dir);
}

it('installs a pre-push hook with executable bit and the expected script', function () {
    $dir = makeTempGitRepo();

    try {
        $app = new Application();
        $app->setAutoExit(false);
        $exit = $app->run(
            new ArrayInput(['command' => 'install-hook', '--path' => $dir]),
            $output = new BufferedOutput()
        );

        expect($exit)->toBe(0);
        $hookPath = $dir . '/.git/hooks/pre-push';
        expect(file_exists($hookPath))->toBeTrue();
        expect(is_executable($hookPath))->toBeTrue();

        $script = file_get_contents($hookPath);
        expect($script)->toContain('#!/bin/sh');
        expect($script)->toContain('devguard run deploy');
        expect($script)->toContain('devguard run architecture');
        expect($script)->toContain('command -v devguard');
    } finally {
        rmTempDir($dir);
    }
});

it('refuses to overwrite an existing hook without --force', function () {
    $dir = makeTempGitRepo();
    file_put_contents($dir . '/.git/hooks/pre-push', "#!/bin/sh\n# pre-existing\n");

    try {
        $app = new Application();
        $app->setAutoExit(false);
        $exit = $app->run(
            new ArrayInput(['command' => 'install-hook', '--path' => $dir]),
            new BufferedOutput()
        );

        // 2 is Command::INVALID — non-zero, hook untouched.
        expect($exit)->not->toBe(0);
        expect(file_get_contents($dir . '/.git/hooks/pre-push'))->toContain('pre-existing');
    } finally {
        rmTempDir($dir);
    }
});

it('overwrites with --force', function () {
    $dir = makeTempGitRepo();
    file_put_contents($dir . '/.git/hooks/pre-push', "#!/bin/sh\n# old\n");

    try {
        $app = new Application();
        $app->setAutoExit(false);
        $exit = $app->run(
            new ArrayInput(['command' => 'install-hook', '--path' => $dir, '--force' => true]),
            new BufferedOutput()
        );

        expect($exit)->toBe(0);
        expect(file_get_contents($dir . '/.git/hooks/pre-push'))->not->toContain('# old');
    } finally {
        rmTempDir($dir);
    }
});

it('fails cleanly when the directory is not a git repo', function () {
    $dir = sys_get_temp_dir() . '/devguard-not-git-' . uniqid('', true);
    mkdir($dir);

    try {
        $app = new Application();
        $app->setAutoExit(false);
        $output = new BufferedOutput();
        $exit = $app->run(
            new ArrayInput(['command' => 'install-hook', '--path' => $dir]),
            $output
        );

        expect($exit)->not->toBe(0);
        expect($output->fetch())->toContain('not a git repository');
    } finally {
        rmdir($dir);
    }
});
