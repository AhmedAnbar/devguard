<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\EnvAudit\Rules\UndeclaredEnvCallsRule;

function undeclaredFixProject(string $envExample, string $env, array $phpFiles): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-undecl-fix-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env.example', $envExample);
    file_put_contents($root . '/.env', $env);
    foreach ($phpFiles as $rel => $contents) {
        $abs = $root . '/' . $rel;
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0o755, true);
        }
        file_put_contents($abs, $contents);
    }
    return ProjectContext::detect($root);
}

it('proposes one Fix per unique undeclared key (deduped across files)', function () {
    $ctx = undeclaredFixProject(
        envExample: "APP_NAME=Laravel\n",
        env: "APP_NAME=Laravel\n",
        phpFiles: [
            'config/a.php' => '<?php return ["x" => env("STRIPE_SECRET")];',
            'config/b.php' => '<?php return ["y" => env("STRIPE_SECRET")];',
            'config/c.php' => '<?php return ["z" => env("MAILGUN_KEY")];',
        ],
    );

    $fixes = (new UndeclaredEnvCallsRule())->proposeFixes($ctx);

    // STRIPE_SECRET appears twice, MAILGUN_KEY once → 2 unique → 2 fixes.
    expect($fixes)->toHaveCount(2);

    $targets = array_map(fn ($f) => $f->target, $fixes);
    sort($targets);
    expect($targets)->toBe(['MAILGUN_KEY', 'STRIPE_SECRET']);
});

it('applies a fix by appending the key to .env.example with empty value', function () {
    $ctx = undeclaredFixProject(
        envExample: "APP_NAME=Laravel\n",
        env: "APP_NAME=Laravel\n",
        phpFiles: ['config/services.php' => '<?php return ["s" => env("STRIPE_SECRET")];'],
    );

    $rule = new UndeclaredEnvCallsRule();
    $fixes = $rule->proposeFixes($ctx);
    expect($fixes)->toHaveCount(1);

    $result = $rule->applyFix($ctx, $fixes[0]);

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toContain('STRIPE_SECRET');

    $envExample = file_get_contents($ctx->path('.env.example'));
    expect($envExample)->toContain("STRIPE_SECRET=");

    // Backup written with the pre-fix contents.
    $backup = file_get_contents($ctx->path('.env.example.devguard.bak'));
    expect($backup)->toBe("APP_NAME=Laravel\n");
});

it('writes the .env.example.devguard.bak backup exactly once across multiple applies', function () {
    $ctx = undeclaredFixProject(
        envExample: "APP_NAME=Laravel\n",
        env: "APP_NAME=Laravel\n",
        phpFiles: [
            'config/a.php' => '<?php return ["a" => env("KEY_A")];',
            'config/b.php' => '<?php return ["b" => env("KEY_B")];',
            'config/c.php' => '<?php return ["c" => env("KEY_C")];',
        ],
    );

    $rule = new UndeclaredEnvCallsRule();
    foreach ($rule->proposeFixes($ctx) as $fix) {
        $rule->applyFix($ctx, $fix);
    }

    // Backup must reflect the ORIGINAL state, not any in-batch mutation.
    $backup = file_get_contents($ctx->path('.env.example.devguard.bak'));
    expect($backup)->toBe("APP_NAME=Laravel\n");

    // All three keys present in the post-fix .env.example.
    $envExample = file_get_contents($ctx->path('.env.example'));
    expect($envExample)->toContain('KEY_A=');
    expect($envExample)->toContain('KEY_B=');
    expect($envExample)->toContain('KEY_C=');
});

it('skips a fix when the key was added between propose and apply (idempotency)', function () {
    $ctx = undeclaredFixProject(
        envExample: "APP_NAME=Laravel\n",
        env: "APP_NAME=Laravel\n",
        phpFiles: ['config/x.php' => '<?php return ["s" => env("STRIPE_SECRET")];'],
    );

    $rule = new UndeclaredEnvCallsRule();
    $fixes = $rule->proposeFixes($ctx);

    // Simulate the user adding the key by hand between propose and apply.
    file_put_contents($ctx->path('.env.example'), "STRIPE_SECRET=manually_added\n", FILE_APPEND);

    $result = $rule->applyFix($ctx, $fixes[0]);
    expect($result->status)->toBe(Status::Warning);
    expect($result->message)->toContain('already declared');

    // We did NOT clobber the user's manual value.
    $envExample = file_get_contents($ctx->path('.env.example'));
    expect($envExample)->toContain('STRIPE_SECRET=manually_added');
});

it('returns no fixes when .env.example does not exist (nowhere to declare)', function () {
    $root = sys_get_temp_dir() . '/devguard-no-example-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env', "APP_NAME=Laravel\n");
    mkdir($root . '/config', 0o755, true);
    file_put_contents($root . '/config/x.php', '<?php return env("UNDECLARED");');
    $ctx = ProjectContext::detect($root);

    $fixes = (new UndeclaredEnvCallsRule())->proposeFixes($ctx);
    expect($fixes)->toBe([]);
});

it('skips dynamic env() calls in propose (matches run() behavior)', function () {
    $ctx = undeclaredFixProject(
        envExample: "APP_NAME=Laravel\n",
        env: "APP_NAME=Laravel\n",
        phpFiles: [
            'config/x.php' => '<?php return ["a" => env($key), "b" => env("APP_NAME" . $sfx)];',
        ],
    );

    $fixes = (new UndeclaredEnvCallsRule())->proposeFixes($ctx);
    expect($fixes)->toBe([]);
});
