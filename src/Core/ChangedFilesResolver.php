<?php

declare(strict_types=1);

namespace DevGuard\Core;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Wraps `git diff --name-only <spec>` for the --changed-only flag.
 *
 * The spec is whatever the user passed after --changed-only=:
 *   ""              → diff against HEAD (uncommitted = staged + unstaged)
 *   "--cached"      → staged only (suits pre-commit hooks)
 *   "origin/main"   → diff against remote base (suits PR-style CI)
 *   any other ref   → passed straight through to git
 *
 * We deliberately do NOT pre-validate the spec — git's own error message
 * is more accurate than anything we could invent. If the user passes
 * garbage, git's exit code + stderr surface in the thrown exception.
 *
 * Deletes are excluded — there's nothing to scan when the file is gone.
 * Renames map to the new path — that's what `git diff --name-only`
 * returns by default (no --diff-filter needed).
 */
final class ChangedFilesResolver
{
    public function __construct(
        private readonly string $gitBinary = 'git',
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * @return array<int, string>  project-relative paths
     */
    public function resolve(string $projectRoot, string $spec = 'HEAD'): array
    {
        // git diff --name-only outputs renames as just the new path —
        // exactly what we want. Use --diff-filter=d to drop deletes since
        // a deleted file can't be statically analysed.
        $args = [$this->gitBinary, 'diff', '--name-only', '--diff-filter=d'];

        // The spec might contain its own flags (e.g. "--cached") or be a
        // ref. Either way, git's own arg parsing handles it correctly.
        if ($spec !== '') {
            // Split on whitespace so multi-word specs like "origin/main HEAD"
            // become separate argv elements. Quoting is the user's problem
            // if they need a literal space in a ref name (vanishingly rare).
            foreach (preg_split('/\s+/', trim($spec)) ?: [] as $token) {
                if ($token !== '') {
                    $args[] = $token;
                }
            }
        }

        $process = new Process($args, $projectRoot);
        $process->setTimeout((float) $this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            // Most common failure: the project isn't a git repo. Detect
            // and give a friendlier message than git's stock "fatal: not
            // a git repository" (works but the surrounding context helps).
            // Case-insensitive match — different git versions/locales use
            // different capitalization ("not a git repository" vs "Not a git
            // repository"). The substring is stable enough across versions.
            if (stripos($stderr, 'not a git repository') !== false) {
                throw new RuntimeException(
                    "--changed-only requires a git repository, but '{$projectRoot}' isn't one. " .
                    "Either run inside a git repo or omit --changed-only."
                );
            }
            throw new RuntimeException(
                'git diff failed: ' . ($stderr !== '' ? $stderr : "exit code " . ($process->getExitCode() ?? -1))
            );
        }

        $stdout = $process->getOutput();
        if (trim($stdout) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($stdout));
        return $lines === false ? [] : array_values(array_filter($lines, fn ($l) => $l !== ''));
    }
}
