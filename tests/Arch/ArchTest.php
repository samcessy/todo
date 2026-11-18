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

arch('T-12 nothing in App uses the Log facade, logger() or info()')
    ->expect('App')
    ->not->toUse(['Illuminate\Support\Facades\Log', 'Log', 'logger', 'info']);
