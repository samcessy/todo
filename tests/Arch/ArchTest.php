<?php

/*
|--------------------------------------------------------------------------
| Architecture tests (T-003)
|--------------------------------------------------------------------------
|
| Proves T-12 (no logging from application code, security.md) and NFR-08
| (presets, banned debug calls, layering; ADR-0003 arch row).
| Docs: https://pestphp.com/docs/arch-testing (5.x).
|
*/

arch('NFR-08 follows the php preset')
    ->preset()
    ->php();

arch('NFR-08 follows the security preset')
    ->preset()
    ->security();

arch('NFR-08 follows the laravel preset')
    ->preset()
    ->laravel();

arch('NFR-08 uses no debug calls (dd, dump, ray, var_dump)')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('NFR-08 controllers do not use the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('NFR-08 models extend the Eloquent Model')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

/*
| T-12 logging ban, widened per the security review A-1
| (docs/03-plan/handoffs/T-004-security.md). Each target was proven to be
| detected by a throwaway probe class in app/ (see the T-003 QA handoff).
| Namespace targets match by prefix, so 'Illuminate\Log' covers LogManager
| and Logger. Not statically catchable: app('log') / resolve('log'), which
| stay on the review checklist.
*/
arch('T-12 nothing in App uses the Log facade, logger(), info(), logs(), PSR-3, Illuminate\Log, error_log() or syslog()')
    ->expect('App')
    ->not->toUse([
        'Illuminate\Support\Facades\Log',
        'Log', // kept from the original rule; arch cannot see the alias itself, see the next test
        'logger',
        'info',
        'logs',
        'Psr\Log\LoggerInterface',
        'Psr\Log',
        'Illuminate\Log',
        'error_log',
        'syslog',
    ]);

/*
| The global `Log` alias is registered only when the app boots, so Pest arch
| cannot resolve it: `->not->toUse('Log')` only prefix-matches the helper
| functions logger()/logs() and never sees `\Log::info()` (proven by probe).
| This test tokenizes every file under app/ instead and fails on a fully
| qualified `\Log` or a `use Log;` import (class names are case-insensitive).
*/
test('T-12 nothing in App references the global Log alias (\\Log or use Log)', function () {
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $tokens = PhpToken::tokenize((string) file_get_contents($file->getPathname()));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAME_FULLY_QUALIFIED) && strcasecmp($token->text, '\\Log') === 0) {
                $hits[] = $file->getPathname().':'.$token->line;
            }

            if (! $token->is(T_USE)) {
                continue;
            }

            $startOfItem = true;

            for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';' && $tokens[$j]->text !== '{'; $j++) {
                if ($tokens[$j]->isIgnorable()) {
                    continue;
                }

                if ($startOfItem && $tokens[$j]->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                    && strcasecmp(ltrim($tokens[$j]->text, '\\'), 'Log') === 0) {
                    $hits[] = $file->getPathname().':'.$tokens[$j]->line;
                }

                $startOfItem = $tokens[$j]->text === ',';
            }
        }
    }

    expect($hits)->toBe([]);
});
