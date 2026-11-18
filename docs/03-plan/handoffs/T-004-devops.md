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
