<?php

declare(strict_types=1);

namespace DevGuard\Tools\ArchitectureEnforcer;

use DevGuard\Core\ProjectContext;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class FileScanner
{
    /** @return iterable<SplFileInfo> */
    public function controllers(ProjectContext $ctx): iterable
    {
        return $this->phpFilesIn($ctx, 'app/Http/Controllers');
    }

    /** @return iterable<SplFileInfo> */
    public function phpFilesIn(ProjectContext $ctx, string $relativePath): iterable
    {
        $absolute = $ctx->path($relativePath);
        if (! is_dir($absolute)) {
            return [];
        }

        return (new Finder())
            ->files()
            ->in($absolute)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);
    }

    public function relativePath(ProjectContext $ctx, SplFileInfo $file): string
    {
        $root = rtrim($ctx->rootPath, '/') . '/';
        $abs = $file->getRealPath() ?: $file->getPathname();
        return str_starts_with($abs, $root) ? substr($abs, strlen($root)) : $abs;
    }
}
