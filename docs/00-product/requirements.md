# Requirements

**Status:** Approved (G0, 2026-11-16)

## Functional requirements

| ID | Requirement | Story |
|---|---|---|
| FR-01 | Register, verify email, log in, log out, reset password, delete account | US-01 |
| FR-02 | Create a todo: title (required, at most 255 characters), description (optional, at most 5,000), due date (optional) | US-02 |
| FR-03 | List the user's own todos, newest first, with counts for all, active, and completed | US-03 |
| FR-04 | Edit any field of the user's own todo | US-04 |
| FR-05 | Mark the user's own todo complete or incomplete | US-05 |
| FR-06 | Delete the user's own todo after confirmation | US-06 |
| FR-07 | Filter the list by All, Active, or Completed. The filter is reflected in the URL | US-07 |
| FR-08 | Highlight overdue todos (due date before today and not completed) | US-03 |

## Non-functional requirements

| ID | Category | Requirement | Verified by |
|---|---|---|---|
| NFR-01 | Security | OWASP ASVS Level 1 | Security review checklist |
| NFR-02 | Security | A user can never read or change another user's todos | IDOR feature tests on every `{todo}` route |
| NFR-03 | Security | User-supplied content is never rendered as HTML | ESLint `vue/no-v-html` as an error |
| NFR-04 | Security | Dependencies have no known high or critical CVEs | `composer audit`, `npm audit --audit-level=high` in CI |
| NFR-05 | Performance | p95 server response under 300 ms with 100 todos per user. The list page runs a fixed number of queries (no N+1) | Query-count assertion test, staging measurement |
| NFR-06 | Accessibility | WCAG 2.2 AA: every action works by keyboard, controls are labelled, focus is visible, contrast is at least 4.5:1 | Browser tests, axe or Lighthouse audit |
| NFR-07 | Quality | Backend line coverage of at least 85%, and at least 90% on `app/Http` and `app/Policies`. Mutation score of at least 70% on changed domain code | Pest `--coverage --min`, `--mutate` |
| NFR-08 | Quality | PHPStan (Larastan) at level 8 or higher. Zero ESLint, Pint, or vue-tsc errors | CI |
| NFR-09 | Compatibility | The last 2 versions of Chrome, Firefox, Safari, and Edge. Usable from 320 px wide | Browser tests at mobile and desktop viewports |
| NFR-10 | Operability | Health endpoint `/up`, structured logs without PII, error tracking with release tags | Deployment checklist |
| NFR-11 | Privacy | Account deletion removes all of the user's todos | Feature test |

## Constraints

- Use framework features (FormRequests, Policies, Eloquent) before adding custom layers or packages.
- Every new dependency needs human approval (see `docs/governance.md`).

## Out of scope (MVP)

Sharing and collaboration, tags or projects, priorities, reordering, reminders and notifications, data export,
public API, i18n, social login.
