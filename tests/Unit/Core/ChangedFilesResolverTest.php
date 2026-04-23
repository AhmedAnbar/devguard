<?php

declare(strict_types=1);

use DevGuard\Core\ChangedFilesResolver;
use Symfony\Component\Process\Process;

/**
 * Run a git command in a fixture root using Symfony Process so we don't
 * touch the shell. Throws on failure so test setup bugs surface loudly.
 *
 * @param array<int, string> $args
 */
function runGit(string $cwd, array $args): void
{
    $process = new Process(array_merge(['git'], $args), $cwd);
    $process->setTimeout(10);
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('git ' . implode(' ', $args) . ' failed: ' . $process->getErrorOutput());
    }
}

/** Build a temp git repo with a few changes we control end-to-end. */
function gitFixture(): string
{
    $root = sys_get_temp_dir() . '/devguard-git-' . uniqid('', true);
    mkdir($root);
    runGit($root, ['init', '-q']);
    runGit($root, ['config', 'user.email', 'test@example.com']);
    runGit($root, ['config', 'user.name', 'Test']);
    file_put_contents($root . '/keep.txt', "alpha\n");
    file_put_contents($root . '/touch.txt', "beta\n");
    runGit($root, ['add', '.']);
    runGit($root, ['commit', '-q', '-m', 'init']);
    // Modify one file (unstaged), add one new file (untracked).
    file_put_contents($root . '/touch.txt', "beta-changed\n");
    file_put_contents($root . '/new.txt', "gamma\n");
    return $root;
}

it('returns project-relative paths from `git diff --name-only HEAD`', function () {
    $root = gitFixture();

    $files = (new ChangedFilesResolver())->resolve($root, 'HEAD');

    // touch.txt is modified → should appear. new.txt is untracked, NOT in
    // git diff (would need `git status` for that — out of scope for v1).
    expect($files)->toContain('touch.txt');
    expect($files)->not->toContain('keep.txt');
});

it('returns an empty array when nothing has changed', function () {
    $root = sys_get_temp_dir() . '/devguard-clean-git-' . uniqid('', true);
    mkdir($root);
    runGit($root, ['init', '-q']);
    runGit($root, ['config', 'user.email', 'test@example.com']);
    runGit($root, ['config', 'user.name', 'Test']);
    file_put_contents($root . '/x.txt', "hi\n");
    runGit($root, ['add', '.']);
    runGit($root, ['commit', '-q', '-m', 'init']);

    $files = (new ChangedFilesResolver())->resolve($root, 'HEAD');

    expect($files)->toBe([]);
});

it('throws a friendly error when the project is not a git repo', function () {
    $root = sys_get_temp_dir() . '/devguard-not-git-' . uniqid('', true);
    mkdir($root);

    expect(fn () => (new ChangedFilesResolver())->resolve($root, 'HEAD'))
        ->toThrow(RuntimeException::class, 'requires a git repository');
});

it('passes --cached through correctly so pre-commit hooks can use it', function () {
    $root = gitFixture();
    runGit($root, ['add', 'touch.txt']); // stage the modification

    $files = (new ChangedFilesResolver())->resolve($root, '--cached');

    expect($files)->toContain('touch.txt');
});
