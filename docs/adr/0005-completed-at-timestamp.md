# ADR-0005: Store completion as a nullable `completed_at` timestamp

**Status:** Accepted (G2, 2026-11-18) · **Date:** 2026-11-16, revised 2026-11-17 (F-14, F-15, A-18) · **Approver:** project owner

## Context
- US-05.AC1 and AC2 refer to `completed_at` by name: it is set on complete and null on incomplete. US-03.AC4 needs counts for active and completed. US-07 filters on status.
- FR-08 marks overdue todos ("due date before today **and not completed**").
- The MVP has no further states (no "in progress", no archive). D5 excludes priority and reordering.

## Decision
**Schema and model**
- Completion is one column, `completed_at TIMESTAMP NULL`. A todo is **completed if and only if `completed_at IS NOT NULL`**.
- There is no `is_completed`/`completed` boolean column and no status enum.
- The model exposes `isCompleted(): bool`.
- Local query scopes `active()` (`whereNull`) and `completed()` (`whereNotNull`) are used by the filter and the counts.

**API and props**
- The API sends a **desired state**, not a toggle: `PATCH /todos/{todo}/completion` with `{ completed: boolean }` (contracts.md).
- The controller sets `completed_at = now()` when the todo goes from active to completed, and `null` when `completed` is false.
- Re-sending `completed: true` to a todo that is already completed keeps the original timestamp and flashes the same toast, "Todo completed." (F-14). This makes the call idempotent, so a double-click or an Inertia retry can't flip the todo back.
- The controller reads `(bool) $request->validated('completed')`, never raw input (A-18). Accepted values are listed in contracts.md §2.
- `TodoResource` exposes both `completed: bool` (derived) and `completed_at: string|null` (`Y-m-d\TH:i:sP`, F-15), so the UI doesn't have to repeat the null check. The browser's overdue rule uses `completed_at` (G2-13, contracts.md §5.3).

**Counts**
- Counts use `COUNT(*)` and `COUNT(completed_at)` in one query. Active is total minus completed (data-model.md).

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| Boolean `completed` column | Loses *when* it was done, contradicts the wording of US-05.AC1, and a "completed at" display later would need a second column that could drift out of sync |
| Boolean + timestamp | Two sources of truth that can disagree (`completed = true, completed_at = null`) |
| Status enum (`active`, `completed`) | Over-modelled for two states. Revisit if a third state appears |
| `POST /todos/{todo}/toggle` (flip) | Not idempotent. A double submit or a retry reverts the change, and an optimistic UI (R-002 E7) can't reconcile a flip safely |

## Consequences
**Positive**
- One column, readable SQL (`whereNull`). Completion time comes for free for a future "completed on" display or sorting.

**Negative**
- Every "is it done?" check has to go through the scope or helper, or it risks a raw truthiness bug in the frontend. That is why the resource exposes `completed: bool`.
- Timestamps are stored in UTC (`config/app.php` timezone). Displaying "completed at" in local time would be a frontend concern, and it is not in the MVP.

**Follow-up work**
- Mutation testing should cover the scopes and the idempotency branch (`mutates(TodoCompletionController::class)`).

## Revisit triggers
- A third lifecycle state is requested (for example "archived" or "in progress").
- A completion history is requested (re-opened, re-completed). That would need an events table, not a column.

## Sources
- `user-stories.md` US-03.AC4, US-05.AC1–AC3, US-07. `requirements.md` FR-05, FR-08.
- https://laravel.com/docs/13.x/eloquent#local-scopes (13.x)
- https://laravel.com/docs/13.x/migrations#column-method-timestamp (13.x)
- R-002 E7 (optimistic updates require reconcilable server state)
