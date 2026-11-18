# Handoff: T-004 (devops-engineer)

Green handoff for the NFR-03 raw-HTML check, against QA's red fixtures (`17e0ce02`). Implementation
commit: `89a5f456`.

## What changed
- `scripts/check-raw-html.mjs`: the check (Node built-ins + `@vue/compiler-sfc`, no new dependency). Default
  mode scans `resources/js`; `--fixtures <dir>` verifies `<dir>/expected.json`.
- `scripts/raw-html-allowlist.json`: exactly one entry, `TwoFactorSetupModal.vue` / `qrCodeSvg` (G1-3).
- `package.json`: `check:html` and `check:html:fixtures` scripts, next to `check` / `check:fix`.
- `CLAUDE.md`: both commands in the Commands block; the "added by" sentence now names only T-002's commands.

## Decisions made inside the task scope
- **Files scanned:** every `resources/js/**/*.vue` and `**/*.ts`, **including `*.d.ts`**. Declarations
  cannot render HTML, but scanning them costs nothing, has no hits today and avoids a blind spot.
  `resources/js/{actions,routes,wayfinder}/` are skipped (gitignored Wayfinder output, regenerated on build);
  checked against freshly generated output (56 files): no hits there anyway.
- **Template walk:** `descriptor.template.ast`, recursing through `children` and `branches`. Only
  `DIRECTIVE` props are inspected, so comments, text and interpolations mentioning `v-html` never match
  (`safe-v-html-as-text.vue`). Rules: `v-html`; `v-bind` (`:`, `v-bind:`, `.prop` shorthand) with a static
  argument `innerHTML`/`outerHTML`, compared case-insensitively.
- **Object-spread form (ADR-0003 known limitation):** instead of a regex over the whole template source,
  the script scans **every directive's expression** for the four script tokens. This catches
  `v-bind="{ innerHTML: x }"` and also handlers such as `@click="el.innerHTML = x"`, while staying immune to
  comments and text. Dynamic arguments (`:[name]="x"`) are not resolvable statically and are not flagged.
- **Script tokens:** `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write` / `writeln`, in
  `<script>` and `<script setup>` content and in `.ts` files. Comments are not stripped (conservative: a
  comment naming a token fails and must be reworded).
- **Allow-list:** a `v-html` is allowed only if repository path **and** trimmed expression both equal an
  entry. Allowed findings are still printed, tagged `v-html(allowed)`, so the kit run shows the one QR binding.
- **SFC parse errors** are reported as a `parse` finding and fail the check (no silent skip).
- **Fixture mode:** each entry's content is checked as if at `as`; an unlisted file in the directory is a
  mismatch. Every finding prints with its line and column, so the DoD's "file and line" is visible.
- Length is 136 lines after Oxfmt (80-column wrap); ADR-0003 estimated about 40. Pre-format it was ~105.

## Deviations from the spec
None.

## Commands run and results
```
npm run check:html            →  pass, exit 0: 200 files (164 .vue, 36 .ts), 1 allow-listed
                                 resources/js/components/TwoFactorSetupModal.vue:174:41  v-html(allowed)  v-html="qrCodeSvg"
npm run check:html:fixtures   →  pass, exit 0: 15/15 ok, 0 mismatch(es), each fail fixture reported with line:col
gate bites (scratch copy outside the repo: script + allow-list + 2 kit components, node_modules symlinked)
  + <span v-html="user.name" /> in AppLogo.vue, :innerHTML="x" next to the QR binding,
  + insertAdjacentHTML in a .ts, + innerHTML in resources/js/actions/gen.ts
                              →  fail, exit 1: 3 findings (v-html, v-bind:innerHTML, script:insertAdjacentHTML);
                                 QR still allowed; generated-folder file skipped. resources/ untouched.
npx vp check scripts package.json   →  pass (formatted, 0 lint warnings)
npm run check                 →  fail, pre-existing: Oxfmt formatting issues in ~72 .md/.json files under
                                 docs/ and .claude/ (incl. CLAUDE.md). A clean `git archive HEAD` copy fails the
                                 same way; this change adds no new file to the list. scripts/ is clean.
npm run types:check           →  fail in the worktree, environmental: 38 x TS2307 for '@/routes', '@/actions'
                                 (Wayfinder output is gitignored and not generated in a fresh worktree).
                                 In a scratch copy after `php artisan wayfinder:generate --with-form`
                                 → vue-tsc --noEmit pass, exit 0.
```

## Open questions / follow-ups
- Owner (G4, security.md T-03): approve the new gate script and its single-entry allow-list.
- T-002: run `npm run check:html` and `npm run check:html:fixtures` in the CI `lint` job; generate Wayfinder
  output before `types:check`.
- `npm run check` currently fails on Markdown/JSON formatting in `docs/` and `.claude/` (pre-existing, outside
  this task's scope). It needs either an Oxfmt ignore for docs or a one-off `check:fix`, decided before
  `npm run check` becomes a required CI job.
- QA: traceability rows CC.AC3 / T-03 / NFR-03 can move from Red to Green in verification.

## Security follow-up (2026-11-18)

Source: `docs/03-plan/handoffs/T-004-security.md` (B-1, A-2..A-6) and the QA fixture contract in
`docs/03-plan/handoffs/T-004-qa.md` → "Security follow-up". The only file changed was `scripts/check-raw-html.mjs`.
I did not touch `tests/` or the allow-list.

| Finding | Change in `scripts/check-raw-html.mjs` | Rule printed |
|---|---|---|
| B-1 | Repo mode now scans every `resources/js` file that matches `/\.(vue\|[cm]?[jt]sx?)$/`. `.vue` gets the SFC walk and everything else gets the script token scan. Fixture mode applies the same filter to each entry's `as` path. A non-matching `as` (`.vue.bak`) is not scanned, so it passes, and the output says "not a bundled source, not scanned". The JSX/TSX `innerHTML={...}` prop is caught by the token scan. `dangerouslySetInnerHTML` was added explicitly, because `\binnerHTML\b` does not match inside it. | `script:<token>` |
| A-2 | Added the tokens `setHTMLUnsafe`, `parseHTMLUnsafe`, `createContextualFragment`, `srcdoc` and `DOMParser`. They apply to scripts and to every directive expression. A `v-bind`/`:` whose normalised static argument is `srcdoc` fails. `safe-iframe-src.vue` (`:src`, `:title`, `sandbox`) still passes. | `v-bind:srcdoc` |
| A-3 | Static `v-bind` arguments are normalised before the comparison: a local `camelize` (same regex as `@vue/shared`) when `.camel` is present, then hyphens are stripped and the result is lower-cased. The normalised argument is matched against `innerhtml`/`outerhtml`/`srcdoc`, so `:inner-h-t-m-l.camel` fails. Any dynamic argument (`:[x]`, `v-bind:[x]`) fails, whatever its value. | `v-bind:<arg>`, `v-bind:[dynamic]` |
| A-4 | A `<template>` with `src`, with a `lang` other than `html`, or that yields no AST fails closed. | `template-unverifiable(src\|lang\|ast)` |
| A-5 | Done. The generated folders (`actions`, `routes`, `wayfinder`) are skipped only for files that `git ls-files -z -- <dirs>` does not list. The call uses `execFileSync` with fixed arguments: no shell and no network. If git is unavailable or this is not a checkout, nothing is skipped (generated output is scanned) and a note is printed. Probed in a scratch copy: an untracked `routes/evil.ts` is skipped, the same file after `git add -f` fails, and with no git it fails. | as for any other file |
| A-6 | Not in this task's scope (it covers `security.md` T-18 and `check-scope.mjs`). It stays with the architect/owner and T-002. | n/a |

The ADR-0003 NFR-03 row and `security.md` T-03 still describe the old scope (`*.vue` + `*.ts`, four tokens). The wording
update belongs to the architect (owner-visible), as the security review notes. The open question about fail-closed for
unknown extensions stays in the task file and is unchanged. Not done, and out of the fixture contract: `<script src>`
and token-scanning `{{ }}` interpolations. An external script file under `resources/js` is still scanned on its own.

### Commands run and results
```
npm run check:html:fixtures  →  pass, exit 0: 31/31 ok, 0 mismatch(es) (was 20/31)
npm run check:html           →  pass, exit 0: 200 files (164 .vue, 36 script), 1 allow-listed
                                resources/js/components/TwoFactorSetupModal.vue:174:41  v-html(allowed)  v-html="qrCodeSvg"
                                (the new sinks flag no kit code; the kit has only .vue/.ts files today)
npm run check                →  pass: 102 files formatted, 0 lint warnings in 93 files (scripts/ clean)
A-5 probe (scratch copy, node_modules symlinked): no git → fail; git + untracked → skipped; git + tracked → fail
```
