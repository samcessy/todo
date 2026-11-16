# User Stories and Acceptance Criteria

**Status:** Approved (G0, 2026-11-16).
Each AC ID (for example `US-05.AC3`) becomes a test name and appears in `04-quality/traceability-matrix.md`.

---

## US-01 Account (Must)

As a visitor, I can create and manage an account, so that my todos are private to me.

- **AC1** Given I am a guest, when I visit `/todos`, then I am redirected to the login page.
- **AC2** Given I registered but have not verified my email, when I visit `/todos`, then I am asked to verify.
- **AC3** Given valid credentials, when I log in, then I land on `/todos`.
- **AC4** Given 5 failed login attempts in a row, when I try again, then I am rate-limited.
- **AC5** Given I am logged in, when I delete my account and confirm with my password, then my user record and all my todos are removed.

## US-02 Create a todo (Must)

As a user, I can add a todo, so that I can capture work quickly.

- **AC1** Given a title, when I submit, then the todo appears at the top of my list as active.
- **AC2** Given an empty title, when I submit, then I see "The title field is required." and nothing is created.
- **AC3** Given a title longer than 255 characters, when I submit, then I see a validation error and nothing is created.
- **AC4** Given an invalid due date, when I submit, then I see a validation error and nothing is created.
- **AC5** Given a request that includes another user's `user_id`, when I submit, then the todo belongs to me anyway.
- **AC6** After I submit successfully, focus returns to the title field (so I can add several todos with the keyboard).

## US-03 View my todos (Must)

As a user, I can see my todos, so that I know what is left to do.

- **AC1** Given another user's todos exist, when I view my list, then I see only my own.
- **AC2** Todos are ordered newest first.
- **AC3** Given I have no todos, then I see an empty state that invites me to add one.
- **AC4** The counts for all, active, and completed are shown and are correct.
- **AC5** An active todo whose due date is before today is visibly marked overdue, by text and not only by colour.
- **AC6** The page runs a fixed number of queries whether I have 1 or 100 todos.

## US-04 Edit a todo (Must)

As a user, I can change a todo, so that I can fix mistakes.

- **AC1** Given I own a todo, when I change its title, description, or due date, then the changes are saved.
- **AC2** The same validation rules as US-02 (AC2 to AC4) apply.
- **AC3** Given another user's todo, when I try to update it, then I get a 403 and it is unchanged.

## US-05 Complete or uncomplete (Must)

As a user, I can mark todos done, so that I can track progress.

- **AC1** Given I own an active todo, when I mark it complete, then `completed_at` is set and it appears under "Completed".
- **AC2** Given I own a completed todo, when I mark it incomplete, then `completed_at` is null.
- **AC3** Given another user's todo, when I try to toggle it, then I get a 403 and it is unchanged.
- **AC4** Given I am a guest, when I try to toggle any todo, then I am redirected to login.
- **AC5** The toggle is a labelled checkbox that can be operated with the keyboard (Space).

## US-06 Delete a todo (Must)

As a user, I can delete a todo, so that my list stays relevant.

- **AC1** Given I own a todo, when I choose delete and confirm, then it is removed.
- **AC2** Given I cancel the confirmation, then nothing is deleted.
- **AC3** Given another user's todo, when I try to delete it, then I get a 403 and it still exists.
- **AC4** The confirmation dialog traps focus and closes with Escape. Focus then returns to the trigger.

## US-07 Filter (Should)

As a user, I can filter by status, so that I can focus on what matters.

- **AC1** `?filter=active` shows only incomplete todos, `?filter=completed` only completed ones, and `all` or no parameter shows everything.
- **AC2** An unknown filter value falls back to `all`, without an error.
- **AC3** The current filter is in the URL, so reloading or bookmarking keeps it.
- **AC4** The active filter is announced to assistive technology (`aria-current`).

---

## Cross-cutting acceptance (applies to every story)

- Every interactive control has an accessible name and visible focus.
- Every form shows validation errors next to the field, and they are linked by `aria-describedby`.
- No user content is rendered as raw HTML.
