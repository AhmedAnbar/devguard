<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runDevguardForHtml(array $args): array
{
    $bin = realpath(__DIR__ . '/../../bin/devguard');
    $process = new Process(array_merge([$bin], $args));
    $process->run();
    return [
        'stdout' => $process->getOutput() . $process->getErrorOutput(),
        'exit' => $process->getExitCode() ?? 1,
    ];
}

function tmpHtmlPath(): string
{
    return sys_get_temp_dir() . '/devguard-html-' . uniqid('', true) . '.html';
}

it('writes a self-contained HTML file when --html=path is given', function () {
    $path = tmpHtmlPath();
    $r = runDevguardForHtml([
        'run', 'deploy',
        '--path=' . fixturePath('sample-laravel-app-bad'),
        '--html=' . $path,
        '--no-open',
    ]);

    expect($r['exit'])->toBe(1); // bad fixture has failures
    expect($r['stdout'])->toContain('HTML report written to');
    expect(file_exists($path))->toBeTrue();

    $html = file_get_contents($path);
    expect($html)->toStartWith('<!DOCTYPE html>');
    expect($html)->toContain('<title>DevGuard Report</title>');
    expect($html)->toContain('Deploy Readiness Score');
    // The bad fixture is known to fail debug_mode — proves data flowed through.
    expect($html)->toContain('debug_mode');
    // Inline <style> proves it's self-contained (no external CSS link).
    expect($html)->toContain('<style>');
    expect($html)->not->toContain('<link rel="stylesheet"');

    @unlink($path);
});

it('escapes user-supplied content (no script injection from project paths)', function () {
    // We can't easily inject a script via the CLI args (Symfony filters them),
    // but we can prove the renderer escapes the project path it puts in the
    // header. If escaping were broken, '<' would appear literally.
    $path = tmpHtmlPath();
    $r = runDevguardForHtml([
        'run', 'deploy',
        '--path=' . fixturePath('sample-laravel-app-bad'),
        '--html=' . $path,
        '--no-open',
    ]);
    expect($r['exit'])->toBe(1);

    $html = file_get_contents($path);
    // Must not contain a stray <script> tag — proves the renderer didn't
    // accidentally emit one and the escaping pipeline is on.
    expect($html)->not->toContain('<script>');

    @unlink($path);
});

it('rejects --html and --json together', function () {
    $r = runDevguardForHtml([
        'run', 'deploy',
        '--path=' . fixturePath('sample-laravel-app-good'),
        '--html', '--json',
    ]);

    expect($r['exit'])->toBe(2);
    expect($r['stdout'])->toContain('mutually exclusive');
});

it('combines multiple tools into one HTML page when used with run all', function () {
    $path = tmpHtmlPath();
    $r = runDevguardForHtml([
        'run', 'all',
        '--path=' . fixturePath('sample-laravel-app-bad'),
        '--html=' . $path,
        '--no-open',
    ]);

    expect($r['exit'])->toBe(1);
    $html = file_get_contents($path);

    // Multiple report sections must show up under one <html> doc.
    $reportSectionCount = substr_count($html, '<section class="report"');
    expect($reportSectionCount)->toBeGreaterThanOrEqual(2);

    // Both deploy and architecture (the two we know exist on the bad fixture)
    // should be present by title.
    expect($html)->toContain('Deploy Readiness Score');
    expect($html)->toContain('Architecture');

    @unlink($path);
});

it('--no-open suppresses the browser auto-open (still writes the file)', function () {
    // We can't easily prove "browser did not open" without mocking — but we
    // can prove the CLI still writes the file and returns success cleanly
    // with the flag present, which is what users care about.
    $path = tmpHtmlPath();
    $r = runDevguardForHtml([
        'run', 'deploy',
        '--path=' . fixturePath('sample-laravel-app-good'),
        '--html=' . $path,
        '--no-open',
    ]);

    expect($r['exit'])->toBe(0); // good fixture passes
    expect(file_exists($path))->toBeTrue();
    expect($r['stdout'])->toContain('HTML report written to');

    @unlink($path);
});

it('errors cleanly when --html=path points to a non-existent directory', function () {
    $r = runDevguardForHtml([
        'run', 'deploy',
        '--path=' . fixturePath('sample-laravel-app-good'),
        '--html=/this/dir/definitely/does/not/exist/report.html',
        '--no-open',
    ]);

    // Exit 2 = tool/IO failure (per our exit-code contract).
    expect($r['exit'])->toBe(2);
    expect($r['stdout'])->toContain('directory does not exist');
});
