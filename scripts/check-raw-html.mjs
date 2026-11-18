// NFR-03 / security.md T-03: fail on raw-HTML rendering in resources/js (ADR-0003).
// node scripts/check-raw-html.mjs                  scan resources/js/**/*.{vue,ts} (incl. .d.ts)
// node scripts/check-raw-html.mjs --fixtures <dir>  check <dir>/* against <dir>/expected.json
import { readdirSync, readFileSync } from 'node:fs';
import { join, sep } from 'node:path';
import { parse } from '@vue/compiler-sfc';

const root = join(import.meta.dirname, '..');
const read = (...p) => readFileSync(join(...p), 'utf8');
const allowlist = JSON.parse(
    read(import.meta.dirname, 'raw-html-allowlist.json'),
);
// Gitignored Wayfinder output, regenerated on build.
const generated = /^resources\/js\/(actions|routes|wayfinder)\//;
const tokens =
    /\b(innerHTML|outerHTML|insertAdjacentHTML)\b|\bdocument\s*\.\s*write(ln)?\b/g;
const DIRECTIVE = 7; // NodeTypes.DIRECTIVE: comments, text and plain attributes never match

function lineCol(src, offset) {
    const before = src.slice(0, offset).split('\n');
    return { line: before.length, col: before.at(-1).length + 1 };
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
            const arg = p.arg?.isStatic ? p.arg.content : '';
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
            } else if (p.name === 'bind' && /^(inner|outer)html$/i.test(arg)) {
                found.push({ ...at, rule: `v-bind:${arg}` });
            }
            // Object-spread v-bind="{ innerHTML: x }" (ADR-0003 known limitation), handlers, etc.
            if (p.exp) found.push(...scan(src, p.exp, 'template'));
        }
        (node.children ?? []).concat(node.branches ?? []).forEach(walk);
    };
    if (descriptor.template?.ast) walk(descriptor.template.ast);
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

function scanRepo() {
    const files = readdirSync(join(root, 'resources/js'), { recursive: true })
        .map((f) => `resources/js/${f.split(sep).join('/')}`)
        .filter((f) => /\.(vue|ts)$/.test(f) && !generated.test(f))
        .sort();
    const counts = { allowed: 0, failed: 0 };
    for (const file of files) {
        for (const f of check(file, read(root, file))) {
            console.log(format(file, f));
            counts[f.ok ? 'allowed' : 'failed']++;
        }
    }
    if (counts.failed > 0) {
        console.error(`check:html: FAIL, ${counts.failed} raw-HTML finding(s)`);
        return 1;
    }
    const vue = files.filter((f) => f.endsWith('.vue')).length;
    const ts = files.length - vue;
    console.log(
        `check:html: OK, ${files.length} files (${vue} .vue, ${ts} .ts), ${counts.allowed} allow-listed`,
    );
    return 0;
}

function runFixtures(dir) {
    const expected = JSON.parse(read(dir, 'expected.json'));
    let passed = 0;
    for (const entry of expected) {
        const findings = check(entry.as, read(dir, entry.file));
        const verdict = findings.some((f) => !f.ok) ? 'fail' : 'pass';
        const ok = verdict === entry.expect;
        if (ok) passed++;
        console.log(
            `${ok ? 'ok' : 'MISMATCH'}  ${entry.name} (expected ${entry.expect}, got ${verdict})`,
        );
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
