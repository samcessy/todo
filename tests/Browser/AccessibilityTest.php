<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| CC.AC1 accessibility scan over the kit pages (T-006, ADR-0003 state list)
|--------------------------------------------------------------------------
|
| The kit stores the appearance in the `appearance` cookie and defaults to `system`, which follows
| `prefers-color-scheme` and toggles the `dark` class on <html>. Each state is scanned with the
| browser colour scheme set to light and to dark, and the test proves the class matches.
| The /todos states are added in T-018.
|
*/

dataset('kit pages', [
    'guest /' => ['/', 'guest'],
    'guest /login' => ['/login', 'guest'],
    'guest /register' => ['/register', 'guest'],
    'guest /forgot-password' => ['/forgot-password', 'guest'],
    'unverified /email/verify' => ['/email/verify', 'unverified'],
    'verified /settings/profile' => ['/settings/profile', 'verified'],
    'verified /settings/security' => ['/settings/security', 'verified'],
    'verified /settings/appearance' => ['/settings/appearance', 'verified'],
]);

dataset('appearances', ['light', 'dark']);

it('CC.AC1 has no accessibility issues at moderate or above on the kit page', function (string $path, string $actor, string $appearance) {
    if ($actor === 'unverified') {
        $this->actingAs(User::factory()->unverified()->create());
    }

    if ($actor === 'verified') {
        $this->actingAs(User::factory()->create());
    }

    $page = visit($path);
    $page = $appearance === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    if ($path === '/settings/security') {
        $page->assertPathIs('/user/confirm-password')
            ->fill('password', 'password')
            ->press('internal:role=button[name="Confirm password"s]');
    }

    $page->assertPathIs($path)
        ->assertScript('document.documentElement.classList.contains("dark")', $appearance === 'dark');

    // Let entry transitions (for example @starting-style fades) finish so axe sees the final colours.
    $page->script('async () => { await Promise.all(document.getAnimations().map((animation) => animation.finished)); }');

    $page->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(2);
})->with('kit pages')->with('appearances');
