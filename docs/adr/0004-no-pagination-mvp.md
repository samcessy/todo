# ADR-0004: No pagination in the MVP

**Status:** Accepted (G2, 2026-11-18) · **Date:** 2026-11-16, revised 2026-11-17 (G2-12) · **Approver:** project owner

## Context
- FR-03 and US-03 ask for the user's todos, newest first, with counts for all, active and completed. Pagination appears in no story.
- A1 expects fewer than 500 todos per user. NFR-05 is measured at **100 todos per user**, warm p95 under 300 ms (G1-5).
- US-07 filters through `?filter=` in the URL (AC3). With pagination, the filter would interact with page numbers, and new todos (US-02.AC1, "appears at the top") would shift pages.
- Laravel resources keep the `data`/`links`/`meta` wrapper for paginated collections, even with `withoutWrapping()` (https://laravel.com/docs/13.x/eloquent-resources#data-wrapping-and-pagination). Paginating would change the prop shape in ADR-0002.

## Decision
`GET /todos` returns **all** of the user's todos that match the active filter, in one query ordered by `created_at DESC, id DESC`. The counts come from one aggregate query (contracts.md, data-model.md).

There is no paginator, no infinite scroll, no deferred props and no hard cap in code.

The query-count equality test (1 vs 100 todos) and the staging NFR-05 measurement at 100 todos are the guards.

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| `->paginate(50)` / `simplePaginate` | Adds UI (pager links, accessible labelling), changes the prop shape, and complicates US-02.AC1 and US-07 for no measured benefit at A1 scale |
| Cursor pagination / infinite scroll (Inertia `merge` props) | More client complexity and new accessibility problems (focus management on load) |
| `Inertia::defer()` for the list | A second request, and a more complex query-count test (R-002 §Inertia v3 features) |
| Hard per-user cap (for example 1,000 todos) | It would be a product rule with no story behind it. Decided at G2 (G2-12): no cap; the `todo-writes` rate limit (120 per minute per user, G2-15) is the brake |

## Consequences
**Positive**
- The simplest controller and page possible, and the prop shape stays a plain `Todo[]`.

**Negative**
- Payload and render time grow linearly with a user's todo count. A user with thousands of todos gets a slow page. Write rate limiting (security.md T-08) slows automated bulk creation, but does not cap it.

**Follow-up work**
- None for the MVP. The data-model index `(user_id, created_at)` already supports a later `LIMIT/OFFSET` or a keyset query.

## Revisit triggers
- Any user has more than **500 todos** (A1 broken). A monthly `SELECT max(cnt)` check, or monitoring, reveals this.
- The staging or production warm p95 for `GET /todos` exceeds **200 ms**, which is two-thirds of the NFR-05 budget.
- The Inertia page payload for `todos/Index` exceeds **200 KB**.
- A story for archive or history views is accepted.

## Sources
- `requirements.md` FR-03, NFR-05. `assumptions.md` A1. `user-stories.md` US-02.AC1, US-03, US-07. G1-5.
- https://laravel.com/docs/13.x/eloquent-resources#data-wrapping-and-pagination (13.x)
- https://laravel.com/docs/13.x/pagination (13.x)
- R-002 §Inertia v3 features (deferred props not recommended)
