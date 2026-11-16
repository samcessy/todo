# Data model

**Status:** Draft for G2 · **Date:** 2026-11-16 · Changing this after the baseline migration needs owner approval and expand/contract (ADR-0006).

## 1. Entities
```
users (kit, unchanged) 1 ────────< todos
  id BIGINT PK                       user_id BIGINT FK → users.id ON DELETE CASCADE
```
The kit's other tables are unchanged: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `passkeys`, and the 2FA columns on `users` (R-001 E17).

## 2. `todos` table
| Column | Type (Laravel → SQLite / PostgreSQL) | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `$table->id()` → INTEGER PK autoincrement / `bigint` identity | no | auto | Route key (`{todo}`) |
| `user_id` | `$table->foreignId('user_id')->constrained()->cascadeOnDelete()` → `bigint` | no | — | FK to `users.id`, **ON DELETE CASCADE** (US-01.AC5, NFR-11, D8). Never mass-assignable |
| `title` | `$table->string('title', 255)` → `varchar(255)` | no | — | FR-02: required, at most 255 characters |
| `description` | `$table->text('description')` → `text` | **yes** | NULL | FR-02: at most 5,000 characters, enforced by validation (`text` avoids a driver-specific varchar length) |
| `due_date` | `$table->date('due_date')` → `date` | **yes** | NULL | A calendar date with no time and no timezone (Q5) |
| `completed_at` | `$table->timestamp('completed_at')` → `timestamp(0) without time zone` (pgsql) | **yes** | NULL | ADR-0005. Completed if and only if NOT NULL. Stored in UTC (`app.timezone`) |
| `created_at`, `updated_at` | `$table->timestamps()` | yes (Laravel default) | NULL | `created_at` drives "newest first" |

**Index:** `$table->index(['user_id', 'created_at'])`, named `todos_user_id_created_at_index`.

### Index choice: `(user_id, created_at)` rather than `(user_id, completed_at)`
Queries per page load (contracts.md §3):

| # | Query | Frequency |
|---|---|---|
| Q-list | `WHERE user_id = ? [AND completed_at IS [NOT] NULL] ORDER BY created_at DESC, id DESC` | every `GET /todos` |
| Q-count | `SELECT count(*), count(completed_at) WHERE user_id = ?` | every `GET /todos` |
| Q-fk | `DELETE … WHERE user_id = ?` (cascade on account deletion) | rare |
| Q-pk | `WHERE id = ?` (route binding) | every write |

- **PostgreSQL does not create an index for a foreign key automatically.** We need an index with `user_id` leading anyway, for Q-list, Q-count and the cascade in Q-fk. Either composite index satisfies that.
- **`(user_id, created_at)`** serves the default and most common view, "All, newest first" (US-03.AC2), straight from the index **in order, with no sort step**: a backward index scan. With Active or Completed, the planner reads that user's slice (at most 500 rows, A1) and filters on `completed_at`, which costs almost nothing.
- **`(user_id, completed_at)`** would serve the filtered views and Q-count slightly better: index-only counting, when the visibility map allows it. But every list query would still need a sort on `created_at`. At A1 scale both differences are microseconds. We prefer the index that matches the *ordering* requirement, because that one will still matter if pagination arrives (ADR-0004: keyset on `created_at`).
- **Not chosen:** a three-column `(user_id, completed_at, created_at)` index (over-fitted for ≤500 rows), or two indexes (extra write cost, no measured benefit).
- **Revisit trigger:** `EXPLAIN ANALYZE` on staging shows Q-list or Q-count above 5 ms at 100 todos, or ADR-0004's pagination trigger fires.

### Migration sketch (for the engineer; not code to copy blindly)
```php
Schema::create('todos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title', 255);
    $table->text('description')->nullable();
    $table->date('due_date')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'created_at']);
});
```
- SQLite enforces foreign keys in Laravel by default (`'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)`, kit `config/database.php:40`). The NFR-11 cascade test therefore runs on both drivers.
- **Cascade versus Eloquent events:** this is a database-level cascade, so no `deleting` model events fire for todos. Nothing relies on them.

## 3. Model `App\Models\Todo`
This follows the kit's Laravel 13 attribute style (`#[Fillable]`, `#[Hidden]` on `User`).

| Aspect | Definition |
|---|---|
| Fillable | `#[Fillable(['title', 'description', 'due_date'])]`. **Not** `user_id` (set through `$user->todos()->create()`), **not** `completed_at` (set explicitly by `TodoCompletionController`) |
| Casts (`casts()` method) | `'due_date' => 'date'`, `'completed_at' => 'datetime'`. The kit sets `Date::use(CarbonImmutable::class)`, so both cast to `CarbonImmutable` |
| Relationships | `user(): BelongsTo<User, $this>`. On `User`: `todos(): HasMany<Todo, $this>` |
| Scopes | `#[Scope] active(Builder $q)` → `whereNull('completed_at')`; `#[Scope] completed(Builder $q)` → `whereNotNull('completed_at')`. The `#[Scope]` attribute style is documented at https://laravel.com/docs/13.x/eloquent#local-scopes |
| Helpers | `isCompleted(): bool`; `isOverdue(CarbonInterface $today): bool` → `! $this->isCompleted() && $this->due_date !== null && $this->due_date->lt($today->startOfDay())` |
| Serialisation | The model is never sent to the client directly. `TodoResource` defines the wire shape (contracts.md §5) |
| Strictness | `Model::preventLazyLoading()` and `Model::preventSilentlyDiscardingAttributes()` when not in production (AppServiceProvider) |

`TodoResource::toArray()` returns:
```php
[
  'id' => $this->id,
  'title' => $this->title,
  'description' => $this->description,
  'due_date' => $this->due_date?->toDateString(),        // 'YYYY-MM-DD' | null
  'completed' => $this->isCompleted(),
  'completed_at' => $this->completed_at?->toIso8601String(),
  'is_overdue' => $this->isOverdue(now()),
  'created_at' => $this->created_at?->toIso8601String(),
]
```
`user_id` and `updated_at` are deliberately left out.

## 4. Factory `Database\Factories\TodoFactory`
| State | Attributes | Used by |
|---|---|---|
| default | `user_id => User::factory()`, `title => fake()->sentence(4)`, `description => null`, `due_date => null`, `completed_at => null` | most tests |
| `completed()` | `completed_at => now()` | US-03.AC4, US-05.AC2, US-07 |
| `withDescription()` | `description => fake()->paragraph()` | US-04 |
| `dueOn(string $date)` | `due_date => $date` | US-03.AC5 |
| `overdue()` | `due_date => today()->subDay()`, `completed_at => null` | US-03.AC5, FR-08 |
| `dueToday()` | `due_date => today()` (not overdue, a boundary case) | FR-08 boundary, mutation |
| `createdAt(CarbonInterface $t)` | `created_at => $t` | US-03.AC2 ordering |

Owner is set with `->for($user)` (standard factory relationship). The `DatabaseSeeder` creates one verified demo user with about 10 todos in mixed states. It is used for staging only (ADR-0006: synthetic data).

## 5. Data lifecycle and privacy
- **Account deletion** (kit `profile.destroy`, password confirmed) deletes the user row. The FK cascade removes every todo (NFR-11). The kit already logs out and invalidates the session. We add `Inertia::clearHistory()` (security.md).
- Todos hold user-authored free text, which may contain personal data. They are never logged (security.md T-12). Staging gets no copies of production data.
- Backups: production PITR runs for 7 days (ADR-0006). A deleted account stays recoverable in PITR history for up to 7 days. The privacy notice must say so (D8).
