<?php

declare(strict_types=1);

namespace DevGuard\Support;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class AstHelper
{
    private Parser $parser;
    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->finder = new NodeFinder();
    }

    /**
     * Parse a file into AST nodes. Returns null if parsing fails.
     *
     * The optional $error parameter captures *why* parsing failed so callers
     * can surface a warning to the user instead of silently skipping the
     * file (the silent-swallow anti-pattern bit a real user in v0.7.0 —
     * see CLAUDE.md lesson #21). Backward compatible: existing callers that
     * ignore the second argument behave exactly as before.
     *
     * @param ?string $error  written-by-reference; null when parsing
     *                        succeeded, otherwise a short human-readable
     *                        explanation
     * @return array<int, Node>|null
     */
    public function parseFile(string $absolutePath, ?string &$error = null): ?array
    {
        $error = null;

        if (! is_file($absolutePath)) {
            $error = 'file not found';
            return null;
        }

        $source = @file_get_contents($absolutePath);
        if ($source === false) {
            $error = 'could not read file';
            return null;
        }

        try {
            return $this->parser->parse($source);
        } catch (PhpParserError $e) {
            // Strip the leading "Syntax error, " prefix nikic adds — our
            // wrapping message already establishes the context.
            $msg = $e->getMessage();
            $msg = (string) preg_replace('/^Syntax error,\s*/i', '', $msg);
            $error = "syntax error — {$msg}";
            return null;
        }
    }

    /**
     * Find all class methods within an AST.
     *
     * @param array<int, Node> $ast
     * @return array<int, Node\Stmt\ClassMethod>
     */
    public function classMethods(array $ast): array
    {
        /** @var array<int, Node\Stmt\ClassMethod> */
        return $this->finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
    }

    /**
     * Find the first class declaration in an AST.
     *
     * @param array<int, Node> $ast
     */
    public function firstClass(array $ast): ?Node\Stmt\Class_
    {
        /** @var Node\Stmt\Class_|null */
        return $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
    }
}
