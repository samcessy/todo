# Handoff: T-006 (vue-engineer)

## What changed
Fix commit `24c1d3a6` (`fix(a11y): resolve kit accessibility findings F-1 to F-5 (CC.AC1)`). No test changed.

| Finding | File | Fix |
|---|---|---|
| F-1 `color-contrast` | `resources/js/pages/Welcome.vue` | "Documentation" and "Laracasts" links: light-mode colour `#f53003` (3.95:1) → `#d92b00` (4.89:1 on `#ffffff`, 4.80:1 on `#FDFDFC`). Same Laravel red, slightly darker. Dark mode keeps `#FF4433` on `#161615`, which already passed. The logo SVG (`#F53003`, not text) is unchanged |
| F-2 `landmark-one-main`, F-3 `region` | `resources/js/layouts/auth/AuthSimpleLayout.vue`, `AuthCardLayout.vue`, `AuthSplitLayout.vue` | Root element `div` → `main` (same classes). Only Simple is used (`layouts/AuthLayout.vue`); Card and Split changed the same way so switching the layout cannot bring the finding back |
| F-4 `tabindex` | `resources/js/pages/auth/Login.vue`, `Register.vue` | All positive `tabindex` values removed. In Login the positive values pushed "Forgot your password?" after the submit button. The link now sits below the password field (right-aligned), so DOM order and visual order agree: email → password → forgot → remember → submit → sign up. Register already had the right DOM order |
| F-5 `region` | `resources/js/components/AppSidebar.vue` | Slot content of the vendor `Sidebar` (header, content, footer incl. `NavUser`) wrapped in `<nav aria-label="Main" class="flex min-h-0 flex-1 flex-col">`. The flex classes keep the vendor column layout (header / scrolling content / footer). `components/ui/*` untouched |

Also `docs/03-plan/tasks/T-006.md` `## Questions`: answer under Q1 (F-5 fixed outside `ui/`, no owner decision needed).

## Decisions made inside the task scope
- **Contrast colour.** Picked the lightest red in the kit's hue family with a margin over 4.5:1 rather than a different colour or removing colour, to keep the design.
- **"Forgot your password?" placement.** Moved in markup rather than kept next to the label, so the focus order does not stop on the link between email and password and matches what users see (WCAG 2.4.3).
- **Sidebar landmark.** `nav` covers the whole sidebar, including the user menu button in the footer; leaving `NavUser` outside would leave its text outside any landmark (the `region` rule again). Label "Main" is unique: the other navs are "breadcrumb" and "Settings". `TextLink` keeps its optional `tabindex` prop (no positive values passed any more).

## Deviations from the spec
None.

## Commands run and results
```
npm run build                                                                →  pass
APP_KEY="base64:$(openssl rand -base64 32)" ./vendor/bin/pest --testsuite=Browser  →  pass: 24 tests, 91 assertions (was 9/24 passing); 16/16 CC.AC1 cases
APP_KEY=… php artisan test                                                   →  pass: 66 tests, 238 assertions
npm run check                                                                →  pass (after `vp fmt` on Register.vue)
npm run types:check                                                          →  pass (no Wayfinder regeneration needed)
grep -rn "v-html" resources/js                                               →  1 hit, pre-existing kit code (TwoFactorSetupModal.vue:174, from 90ad2fb2); none introduced
```
The usual environmental `.env` warning per browser run (no `.env` in this checkout) remains; not a failure.

## Open questions / follow-ups
- QA: re-run the Browser suite in verification mode and record `24c1d3a6` against F-1 to F-5 in the QA handoff; flip the CC.AC1 kit-pages row in the traceability matrix.
- Owner (unchanged from QA handoff): `PasswordInput` toggle `tabindex="-1"` and RR-3 (`InputError` id / `aria-describedby`) were out of this fix's brief and are not touched.
- The pre-existing `v-html` in `TwoFactorSetupModal.vue` (kit QR code SVG) needs the owner-approved allow-list for `check:html` (T-002/T-004).
