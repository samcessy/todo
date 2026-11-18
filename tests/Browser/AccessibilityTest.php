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

/*
|--------------------------------------------------------------------------
| CC.AC1 direct contrast check for the appearance toggle (WCAG 2.2 AA 1.4.3)
|--------------------------------------------------------------------------
|
| The plugin's bundled axe-core (4.10.3) did not flag the inactive toggle labels; Lighthouse's
| axe-core 4.14 did (test-strategy §6). This test computes the ratio itself. The group has no
| role or name of its own, so it is found through its buttons' accessible names.
|
*/

/**
 * Builds a script() function that returns [label => contrast ratio] for every button in the group
 * holding the named buttons. The background is the first ancestor with a non-transparent
 * background-color. Colours go through a 1×1 canvas so any CSS format (oklch, rgb, …) becomes sRGB.
 *
 * @param  list<string>  $buttonNames
 */
function buttonGroupContrastScript(array $buttonNames): string
{
    $names = json_encode($buttonNames);

    return <<<JS
        () => {
            const ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
            const rgba = (css) => {
                ctx.clearRect(0, 0, 1, 1);
                ctx.fillStyle = css;
                ctx.fillRect(0, 0, 1, 1);
                return ctx.getImageData(0, 0, 1, 1).data;
            };
            const luminance = ([r, g, b]) => [r, g, b]
                .map((v) => v / 255)
                .map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
                .reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
            const background = (element) => {
                for (let node = element; node; node = node.parentElement) {
                    const colour = rgba(getComputedStyle(node).backgroundColor);
                    if (colour[3] > 0) return colour;
                }
                return [255, 255, 255, 255];
            };

            const named = [...document.querySelectorAll('button')]
                .filter((button) => {$names}.includes(button.textContent.trim()));
            const group = named[0].parentElement;

            return Object.fromEntries([...group.querySelectorAll('button')].map((button) => {
                const label = button.querySelector('span') ?? button;
                const [l1, l2] = [luminance(rgba(getComputedStyle(label).color)), luminance(background(label))]
                    .sort((a, b) => b - a);
                return [button.textContent.trim(), (l1 + 0.05) / (l2 + 0.05)];
            }));
        }
        JS;
}

it('CC.AC1 appearance toggle labels meet 4.5:1 contrast in light and dark mode', function (string $appearance) {
    $this->actingAs(User::factory()->create());

    $page = visit('/settings/appearance');
    $page = $appearance === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    $page->assertScript('document.documentElement.classList.contains("dark")', $appearance === 'dark');
    $page->script('async () => { await Promise.all(document.getAnimations().map((animation) => animation.finished)); }');

    /** @var array<string, float> $ratios */
    $ratios = $page->script(buttonGroupContrastScript(['Light', 'Dark', 'System']));

    expect($ratios)->toHaveKeys(['Light', 'Dark', 'System']);

    $belowAa = array_filter($ratios, fn (float $ratio): bool => $ratio < 4.5);

    expect($belowAa)->toBeEmpty(sprintf(
        'Labels below 4.5:1 in %s mode: %s',
        $appearance,
        implode(', ', array_map(fn (string $label, float $ratio): string => sprintf('"%s" %.3f:1', $label, $ratio), array_keys($belowAa), $belowAa)),
    ));
})->with('appearances');
