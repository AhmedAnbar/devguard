<?php

declare(strict_types=1);

use DevGuard\Core\Baseline\IgnoreAnnotationParser;

function tmpPhpFile(string $contents): string
{
    $path = sys_get_temp_dir() . '/devguard-annot-' . uniqid('', true) . '.php';
    file_put_contents($path, $contents);
    return $path;
}

it('suppresses the matching rule when the annotation is on the same line', function () {
    $path = tmpPhpFile("<?php\n\$x = DB::table('users')->get(); // @devguard-ignore: direct_db_in_controller\n");
    // The DB::table call is on line 2.
    $parser = new IgnoreAnnotationParser();

    expect($parser->isSuppressed($path, 2, 'direct_db_in_controller'))->toBeTrue();
    expect($parser->isSuppressed($path, 2, 'some_other_rule'))->toBeFalse();

    @unlink($path);
});

it('suppresses when the annotation is on the line ABOVE (preceding-comment style)', function () {
    $path = tmpPhpFile("<?php\n// @devguard-ignore: fat_controller\nclass Big {} // imagine this is fat\n");

    $parser = new IgnoreAnnotationParser();
    // Class is on line 3; annotation on line 2.
    expect($parser->isSuppressed($path, 3, 'fat_controller'))->toBeTrue();

    @unlink($path);
});

it('a bare @devguard-ignore (no rule list) suppresses every rule at that line', function () {
    $path = tmpPhpFile("<?php\n\$x = 1; // @devguard-ignore\n");

    $parser = new IgnoreAnnotationParser();
    expect($parser->isSuppressed($path, 2, 'rule_a'))->toBeTrue();
    expect($parser->isSuppressed($path, 2, 'rule_b'))->toBeTrue();

    @unlink($path);
});

it('handles multiple comma-separated rules in one annotation', function () {
    $path = tmpPhpFile("<?php\n// @devguard-ignore: rule_a, rule_b, rule_c\n\$x = 1;\n");

    $parser = new IgnoreAnnotationParser();
    expect($parser->isSuppressed($path, 3, 'rule_a'))->toBeTrue();
    expect($parser->isSuppressed($path, 3, 'rule_b'))->toBeTrue();
    expect($parser->isSuppressed($path, 3, 'rule_c'))->toBeTrue();
    expect($parser->isSuppressed($path, 3, 'rule_not_listed'))->toBeFalse();

    @unlink($path);
});

it('does not suppress when the annotation is two or more lines away', function () {
    $path = tmpPhpFile("<?php\n// @devguard-ignore: foo\n\n\n\$x = 1;\n");

    $parser = new IgnoreAnnotationParser();
    // Variable on line 5; annotation on line 2 → too far.
    expect($parser->isSuppressed($path, 5, 'foo'))->toBeFalse();

    @unlink($path);
});

it('returns false when the file does not exist (no annotations possible)', function () {
    $parser = new IgnoreAnnotationParser();

    expect($parser->isSuppressed('/no/such/file.php', 1, 'whatever'))->toBeFalse();
});

it('caches files so repeated lookups in the same run do not re-read disk', function () {
    $path = tmpPhpFile("<?php\n// @devguard-ignore: cached\n\$x = 1;\n");

    $parser = new IgnoreAnnotationParser();
    expect($parser->isSuppressed($path, 3, 'cached'))->toBeTrue();

    // Delete the file. If caching works, the second call still returns true.
    unlink($path);
    expect($parser->isSuppressed($path, 3, 'cached'))->toBeTrue();
});
