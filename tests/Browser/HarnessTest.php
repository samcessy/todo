<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Browser harness facts (T-006, ADR-0003 F-09, test-strategy §8 rows 8-12)
|--------------------------------------------------------------------------
|
| These supporting tests document the plugin APIs the later browser tests rely on.
| Keep them: if a plugin upgrade changes one of these APIs, the failure shows up here first.
|
*/

it('NFR-06 reads document.activeElement attributes through script()', function () {
    $page = visit('/login');

    $page->click('#email');

    expect($page->script('() => document.activeElement?.id'))->toBe('email')
        ->and($page->script('() => document.activeElement?.getAttribute("name")'))->toBe('email');

    $page->assertScript('document.activeElement.id', 'email');
});

it('NFR-06 presses Tab and Shift+Tab to move focus forward and back', function () {
    $page = visit('/login');

    // A fixed fixture keeps this fact independent of the kit's tab order (its login form sets tabindex 1-5).
    $page->script('() => { document.body.innerHTML = "<main><input id=\\"first\\"><input id=\\"second\\"><input id=\\"third\\"></main>"; }');

    // keys() focuses the located element before every key it presses, so keys('#first', ['Tab', 'Tab'])
    // lands on #second twice. Chain further presses on ':focus' to continue from the current focus.
    $page->keys('#first', 'Tab');
    $page->assertScript('document.activeElement.id', 'second');

    $page->keys(':focus', 'Tab');
    $page->assertScript('document.activeElement.id', 'third');

    $page->keys(':focus', 'Shift+Tab');
    $page->assertScript('document.activeElement.id', 'second');

    $page->keys('#first', ['Tab', 'Tab']);
    $page->assertScript('document.activeElement.id', 'second');
});

it('NFR-06 presses Space to toggle a checkbox', function () {
    $page = visit('/login');

    $page->assertAttribute('#remember', 'aria-checked', 'false');

    $page->keys('#remember', 'Space');

    $page->assertAttribute('#remember', 'aria-checked', 'true');
});

it('NFR-06 presses Escape to close a dialog', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/settings/profile');

    // Plain text falls back to the first exact text match (here the section heading), so locate
    // by role and exact accessible name with Playwright's internal:role selector.
    $page->press('internal:role=button[name="Delete account"s]')
        ->assertVisible('internal:role=dialog');

    $page->keys('internal:role=dialog', 'Escape');

    $page->assertMissing('internal:role=dialog')
        ->assertScript('document.activeElement.textContent.trim()', 'Delete account');
});

it('NFR-09 opens the page at exactly 320 px with the iPhone SE preset', function () {
    $page = visit('/login')->on()->iPhoneSE();

    $page->assertScript('window.innerWidth', 320)
        ->assertScript('window.innerHeight', 568);
});

it('NFR-09 resizes the viewport to exactly 320 px', function () {
    $page = visit('/login');

    $page->resize(320, 568);

    $page->assertScript('window.innerWidth', 320)
        ->assertScript('document.documentElement.clientWidth', 320);
});

it('NFR-06 scans with axe-core and level 2 reports moderate issues that level 1 ignores', function () {
    $page = visit('/login');

    // Replace the body with markup whose only violation is axe's moderate `region` rule.
    $page->script('() => { document.body.innerHTML = "<main><h1>Probe</h1></main><p>Outside every landmark</p>"; }');

    $page->assertNoAccessibilityIssues(1);

    expect($page->script('() => window.axe.version'))->toBe('4.10.3');

    $violations = $page->script('async () => (await window.axe.run()).violations.map(v => v.id + ":" + v.impact)');

    expect($violations)->toBe(['region:moderate'])
        ->and(fn () => $page->assertNoAccessibilityIssues(2))->toThrow(Exception::class, 'region');
});

it('T-07 serves browser tests from a secure context origin', function () {
    $page = visit('/login');

    $page->assertScript('window.isSecureContext', true)
        ->assertScript('location.protocol', 'http:')
        ->assertScript('location.hostname', '127.0.0.1');
});
