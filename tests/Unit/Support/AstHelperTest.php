<?php

declare(strict_types=1);

use DevGuard\Support\AstHelper;

function tmpPhpForAst(string $contents): string
{
    $path = sys_get_temp_dir() . '/devguard-ast-' . uniqid('', true) . '.php';
    file_put_contents($path, $contents);
    return $path;
}

it('parses a valid file and leaves $error null', function () {
    $path = tmpPhpForAst("<?php class X {}\n");

    $error = 'sentinel'; // Should be overwritten to null on success.
    $ast = (new AstHelper())->parseFile($path, $error);

    expect($ast)->toBeArray();
    expect($error)->toBeNull();

    @unlink($path);
});

it('returns null + a syntax-error message for unparseable files (no more silent skips)', function () {
    // Free-standing function call at class body level — not legal PHP.
    // This is the exact mistake a real user made in the v0.7.0 walkthrough.
    $path = tmpPhpForAst("<?php\nclass X {\n    env('FAKE');\n}\n");

    $error = null;
    $ast = (new AstHelper())->parseFile($path, $error);

    expect($ast)->toBeNull();
    expect($error)->toBeString();
    expect($error)->toContain('syntax error');

    @unlink($path);
});

it('reports "file not found" rather than silently returning null for missing files', function () {
    $error = null;
    $ast = (new AstHelper())->parseFile('/no/such/file.php', $error);

    expect($ast)->toBeNull();
    expect($error)->toBe('file not found');
});

it('is backward compatible: callers that ignore the second arg still work', function () {
    $path = tmpPhpForAst("<?php class X {}\n");

    // Old signature: just one argument. Must still work.
    $ast = (new AstHelper())->parseFile($path);

    expect($ast)->toBeArray();

    @unlink($path);
});
