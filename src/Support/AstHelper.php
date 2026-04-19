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
     * @return array<int, Node>|null
     */
    public function parseFile(string $absolutePath): ?array
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $source = file_get_contents($absolutePath);
        if ($source === false) {
            return null;
        }

        try {
            return $this->parser->parse($source);
        } catch (PhpParserError) {
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
