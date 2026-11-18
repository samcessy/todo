// NFR-03 / security.md T-03: fail on raw-HTML rendering in resources/js (ADR-0003).
// node scripts/check-raw-html.mjs                  scan resources/js/**/*.{vue,js,jsx,ts,tsx,mjs,cjs,mts,cts}
// node scripts/check-raw-html.mjs --fixtures <dir>  check <dir>/* against <dir>/expected.json
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { join, sep } from 'node:path';
import { parse } from '@vue/compiler-sfc';

const root = join(import.meta.dirname, '..');
const read = (...p) => readFileSync(join(...p), 'utf8');
const allowlist = JSON.parse(
    read(import.meta.dirname, 'raw-html-allowlist.json'),
);
// Bundled source files (B-1). Anything else (e.g. .vue.bak, .css, images) is not scanned.
const source = /\.(vue|[cm]?[jt]sx?)$/;
// Gitignored Wayfinder output, regenerated on build. Skipped only while untracked (A-5).
const generatedDirs = [
    'resources/js/actions',
    'resources/js/routes',
    'resources/js/wayfinder',
];
const generated = /^resources\/js\/(actions|routes|wayfinder)\//;
// HTML-parsing sinks (A-2). `dangerouslySetInnerHTML` is listed because \b does not split it.
const tokens = new RegExp(
    [
        String.raw`\b(innerHTML|outerHTML|insertAdjacentHTML|dangerouslySetInnerHTML)\b`,
        String.raw`\b(setHTMLUnsafe|parseHTMLUnsafe|createContextualFragment|srcdoc|DOMParser)\b`,
        String.raw`\bdocument\s*\.\s*write(ln)?\b`,
    ].join('|'),
    'g',
);
// Normalised v-bind arguments that render HTML (A-2, A-3).
const sinkArgs = new Set(['innerhtml', 'outerhtml', 'srcdoc']);
const DIRECTIVE = 7; // NodeTypes.DIRECTIVE: comments, text and plain attributes never match

function lineCol(src, offset) {
    const before = src.slice(0, offset).split('\n');
    return { line: before.length, col: before.at(-1).length + 1 };
}

// Vue's camelize (@vue/shared), as applied by the `.camel` modifier.
const camelize = (s) => s.replace(/-(\w)/g, (_, c) => c.toUpperCase());

// Compare case-insensitively and without hyphens, so `inner-h-t-m-l` matches `innerHTML` (A-3).
function normaliseArg(arg, modifiers) {
    const camel = modifiers.some((m) => (m.content ?? m) === 'camel');
    return (camel ? camelize(arg) : arg).replace(/-/g, '').toLowerCase();
}

// Token hits in a part ({ content, loc }) of `src`. Comments are not stripped (conservative).
function scan(src, part, kind) {
    return [...part.content.matchAll(tokens)].map((m) => {
        const rule = `${kind}:${m[0].replace(/\s/g, '')}`;
        return { ...lineCol(src, part.loc.start.offset + m.index), rule };
    });
}

function checkVue(path, src) {
    const { descriptor, errors } = parse(src, { filename: path });
    const found = errors.map((e) => {
        return { ...lineCol(src, e.loc?.start.offset ?? 0), rule: 'parse' };
    });
    const walk = (node) => {
        for (const p of node.props ?? []) {
            if (p.type !== DIRECTIVE) continue;
            const at = lineCol(src, p.loc.start.offset);
            if (p.name === 'html') {
                const expr = p.exp?.content.trim();
                const ok = allowlist.some(
                    (a) => a.file === path && a.expr === expr,
                );
                found.push({
                    ...at,
                    rule: ok ? 'v-html(allowed)' : 'v-html',
                    ok,
                });
            } else if (p.name === 'bind' && p.arg && !p.arg.isStatic) {
                // `:[x]` cannot be resolved statically (A-3).
                found.push({ ...at, rule: 'v-bind:[dynamic]' });
            } else if (
                p.name === 'bind' &&
                p.arg &&
                sinkArgs.has(normaliseArg(p.arg.content, p.modifiers ?? []))
            ) {
                found.push({ ...at, rule: `v-bind:${p.arg.content}` });
            }
            // Object-spread v-bind="{ innerHTML: x }" (ADR-0003 known limitation), handlers, etc.
            if (p.exp) found.push(...scan(src, p.exp, 'template'));
        }
        (node.children ?? []).concat(node.branches ?? []).forEach(walk);
    };
    const template = descriptor.template;
    if (template) {
        // No parsed tree to verify: external `src`, a non-HTML `lang`, or no AST (A-4).
        const lang = template.lang ?? 'html';
        if (template.src || lang !== 'html' || !template.ast) {
            const why = template.src ? 'src' : lang !== 'html' ? 'lang' : 'ast';
            found.push({
                ...lineCol(src, template.loc.start.offset),
                rule: `template-unverifiable(${why})`,
            });
        } else {
            walk(template.ast);
        }
    }
    for (const block of [descriptor.script, descriptor.scriptSetup]) {
        if (block) found.push(...scan(src, block, 'script'));
    }
    return found;
}

// `path` is the repository path (used for the allow-list), `src` the file content.
function check(path, src) {
    const whole = { content: src, loc: { start: { offset: 0 } } };
    const found = path.endsWith('.vue')
        ? checkVue(path, src)
        : scan(src, whole, 'script');
    const lines = src.split('\n');
    found.sort((a, b) => a.line - b.line || a.col - b.col);
    return found.map((f) => ({
        ...f,
        snippet: lines[f.line - 1].trim().slice(0, 100),
    }));
}

const format = (path, f) =>
    `${path}:${f.line}:${f.col}  ${f.rule}  ${f.snippet}`;

// Files under the generated dirs that git tracks; null outside a git checkout (then nothing is skipped).
function trackedGenerated() {
    try {
        const out = execFileSync(
            'git',
            ['ls-files', '-z', '--', ...generatedDirs],
            {
                cwd: root,
                encoding: 'utf8',
                stdio: ['ignore', 'pipe', 'ignore'],
            },
        );
        return new Set(out.split('\0').filter(Boolean));
    } catch {
        return null;
    }
}

function scanRepo() {
    const tracked = trackedGenerated();
    const skip = (f) =>
        tracked !== null && generated.test(f) && !tracked.has(f);
    const files = readdirSync(join(root, 'resources/js'), { recursive: true })
        .map((f) => `resources/js/${f.split(sep).join('/')}`)
        .filter((f) => source.test(f) && !skip(f))
        .sort();
    const counts = { allowed: 0, failed: 0 };
    for (const file of files) {
        for (const f of check(file, read(root, file))) {
            console.log(format(file, f));
            counts[f.ok ? 'allowed' : 'failed']++;
        }
    }
    if (tracked === null)
        console.log('check:html: not a git checkout, generated dirs scanned');
    if (counts.failed > 0) {
        console.error(`check:html: FAIL, ${counts.failed} raw-HTML finding(s)`);
        return 1;
    }
    const vue = files.filter((f) => f.endsWith('.vue')).length;
    const other = files.length - vue;
    console.log(
        `check:html: OK, ${files.length} files (${vue} .vue, ${other} script), ${counts.allowed} allow-listed`,
    );
    return 0;
}

function runFixtures(dir) {
    const expected = JSON.parse(read(dir, 'expected.json'));
    let passed = 0;
    for (const entry of expected) {
        // Same extension filter as repo mode, applied to the repository path `as`.
        const scanned = source.test(entry.as);
        const findings = scanned ? check(entry.as, read(dir, entry.file)) : [];
        const verdict = findings.some((f) => !f.ok) ? 'fail' : 'pass';
        const ok = verdict === entry.expect;
        if (ok) passed++;
        console.log(
            `${ok ? 'ok' : 'MISMATCH'}  ${entry.name} (expected ${entry.expect}, got ${verdict})`,
        );
        if (!scanned)
            console.log(`    ${entry.as}: not a bundled source, not scanned`);
        for (const f of findings)
            console.log(`    ${format(`${entry.file} as ${entry.as}`, f)}`);
    }
    const listed = new Set(expected.map((e) => e.file));
    const unlisted = readdirSync(dir).filter(
        (f) => f !== 'expected.json' && !listed.has(f),
    );
    for (const file of unlisted)
        console.log(`MISMATCH  ${file} has no entry in expected.json`);
    const mismatches = expected.length - passed + unlisted.length;
    console.log(
        `check:html:fixtures: ${passed}/${expected.length} ok, ${mismatches} mismatch(es)`,
    );
    return mismatches > 0 ? 1 : 0;
}

const at = process.argv.indexOf('--fixtures');
process.exitCode = at > -1 ? runFixtures(process.argv[at + 1]) : scanRepo();
