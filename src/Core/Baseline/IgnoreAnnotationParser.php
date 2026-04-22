<?php

declare(strict_types=1);

namespace DevGuard\Core\Baseline;

/**
 * Reads source files and decides whether a `// @devguard-ignore` annotation
 * suppresses a given rule at a given line.
 *
 * Two annotation patterns are recognised:
 *
 *   // @devguard-ignore
 *       → suppresses every rule at this line
 *
 *   // @devguard-ignore: rule_a, rule_b
 *       → suppresses only the listed rules
 *
 * Lookup window: the same line as the issue, OR the line immediately above.
 * That covers both trailing comments (issue + comment on one line) and
 * preceding comments (comment on the line before the issue).
 *
 * Files are cached in-memory for the life of the parser instance so a
 * single run doesn't re-read the same file once per issue.
 */
final class IgnoreAnnotationParser
{
    /** @var array<string, array<int, string>>  absolute path → 1-indexed lines */
    private array $fileCache = [];

    /**
     * Returns true if the given rule is suppressed by an annotation at or
     * just above the given line. Returns false if the file can't be read,
     * the line is out of range, or no matching annotation is found —
     * suppression is opt-in, never the default.
     */
    public function isSuppressed(string $absolutePath, int $line, string $ruleName): bool
    {
        if ($line < 1) {
            return false;
        }

        $lines = $this->loadLines($absolutePath);
        if ($lines === null) {
            return false;
        }

        // Check the issue's line and the line directly above. The line
        // above wins for the common pattern:
        //   // @devguard-ignore: foo
        //   $bar = doSomethingFoo();
        foreach ([$line, $line - 1] as $candidate) {
            if (! isset($lines[$candidate])) {
                continue;
            }
            if ($this->lineSuppresses($lines[$candidate], $ruleName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a single source line carries an annotation that
     * suppresses the given rule.
     */
    private function lineSuppresses(string $sourceLine, string $ruleName): bool
    {
        // Match `@devguard-ignore` followed optionally by `: rule_a, rule_b`.
        // The leading `//` or `#` or `*` is allowed but not required —
        // we don't care about the comment style, just the annotation.
        if (preg_match('/@devguard-ignore(?:\s*:\s*([A-Za-z0-9_,\s\-]+))?/', $sourceLine, $m) !== 1) {
            return false;
        }

        // No rule list = suppress every rule at this line.
        if (! isset($m[1]) || trim($m[1]) === '') {
            return true;
        }

        $rules = array_filter(array_map('trim', explode(',', $m[1])));
        return in_array($ruleName, $rules, true);
    }

    /**
     * Read a file once, split into 1-indexed lines, cache for later issues
     * in the same run. Returns null on read failure.
     *
     * @return array<int, string>|null
     */
    private function loadLines(string $absolutePath): ?array
    {
        if (isset($this->fileCache[$absolutePath])) {
            return $this->fileCache[$absolutePath];
        }

        if (! is_file($absolutePath)) {
            return null;
        }

        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            return null;
        }

        // 1-indexed for parity with how editors / RuleResult.line report lines.
        $split = preg_split('/\r\n|\n|\r/', $contents);
        if ($split === false) {
            return null;
        }
        $lines = [];
        foreach ($split as $i => $text) {
            $lines[$i + 1] = $text;
        }

        $this->fileCache[$absolutePath] = $lines;
        return $lines;
    }
}
