# T-006 handoff: orchestrator

## Interim CI change (owner decision, 2026-11-18)

- **Why:** PR #7 CI failed with `PlaywrightOutdatedException` because the kit's `tests.yml` runner has no Chromium. The tests passed locally.
- **Change:** one step in `.github/workflows/tests.yml`, before `Run CI Checks`: `npx playwright install --with-deps chromium`. The owner chose option A over waiting for T-002.
- **Scope:** `.github/**` is owner-only and outside T-006's `scope.paths`; the owner approved this edit in the session. T-002 replaces `tests.yml` with its own workflows, browser job included, so the step is temporary.
- **No secrets, no new permissions:** the workflow keeps `permissions: contents: read`. Playwright comes from the locked `playwright` 1.65.1 in `package-lock.json`.
