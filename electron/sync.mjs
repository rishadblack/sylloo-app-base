#!/usr/bin/env node
/**
 * Brings Sylloo's shared Electron pieces (this folder's src/ and tests/) into a desktop app's NativePHP Electron plugin.
 *
 *   node <this folder>/sync.mjs <electron-plugin folder>           copy (before every plugin build and test run)
 *   node <this folder>/sync.mjs <electron-plugin folder> --check   only compare; exit 1 when a copy differs
 *
 * The copies land in `<plugin>/src/shared/` and `<plugin>/tests/shared/`, which the app git-ignores: this folder is the
 * one source. The app's tsc build compiles the copies with the rest of its plugin (tsc can not compile files outside
 * the plugin without changing the dist layout NativePHP loads), and its vitest runs the shared tests with the alias
 * `@desktop-base` pointing at `<plugin>/src/shared`.
 *
 * A copy changed by hand is never overwritten silently: the sync stops and names it, so the change can be made here.
 */
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, realpathSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const SOURCE = dirname(fileURLToPath(import.meta.url));
const MANIFEST = '.desktop-base.json';
const PARTS = ['src', 'tests'];

const sha256 = (content) => createHash('sha256').update(content).digest('hex');

const toPosix = (path) => path.split(sep).join('/');

function filesIn(folder) {
    if (!existsSync(folder)) {
        return [];
    }

    return readdirSync(folder).flatMap((name) => {
        const path = join(folder, name);

        if (statSync(path).isDirectory()) {
            return name === 'node_modules' ? [] : filesIn(path);
        }

        return /\.(m?ts|m?js)$/.test(name) && name !== MANIFEST ? [path] : [];
    });
}

function header(sourcePath) {
    return `// Copied from rishadblack/sylloo-app-base electron/${sourcePath} by its sync.mjs. Do not edit here: change it there.\n`;
}

/** What each copy must hold, by its path inside the plugin. */
export function expectedCopies(source = SOURCE) {
    const copies = new Map();

    for (const part of PARTS) {
        for (const file of filesIn(join(source, part))) {
            const inside = toPosix(relative(join(source, part), file));
            copies.set(`${part}/shared/${inside}`, header(`${part}/${inside}`) + readFileSync(file, 'utf8'));
        }
    }

    return copies;
}

function presentCopies(plugin) {
    return PARTS.flatMap((part) => filesIn(join(plugin, part, 'shared')).map((file) => toPosix(relative(plugin, file))));
}

function readManifest(plugin) {
    try {
        return JSON.parse(readFileSync(join(plugin, 'src', 'shared', MANIFEST), 'utf8')).files ?? {};
    } catch {
        return {};
    }
}

/** The copies that differ from this folder (changed, missing or left over). */
export function check(plugin, source = SOURCE) {
    const expected = expectedCopies(source);
    const problems = [];

    for (const [path, content] of expected) {
        const file = join(plugin, path);

        if (!existsSync(file)) {
            problems.push(`missing ${path}`);
        } else if (readFileSync(file, 'utf8') !== content) {
            problems.push(`differs ${path}`);
        }
    }

    for (const path of presentCopies(plugin)) {
        if (!expected.has(path)) {
            problems.push(`left over ${path}`);
        }
    }

    return problems;
}

/** Copies this folder into the plugin. Throws, changing nothing, when a copy was edited by hand. */
export function sync(plugin, source = SOURCE) {
    const manifest = readManifest(plugin);
    const edited = presentCopies(plugin).filter((path) => manifest[path] !== sha256(readFileSync(join(plugin, path), 'utf8')));

    if (edited.length > 0) {
        throw new Error(
            `These shared Electron files were changed inside the app, not in rishadblack/sylloo-app-base/electron:\n  ${edited.join('\n  ')}\n` +
                `Make the change in ${source}, then delete ${join(plugin, 'src', 'shared')} and ${join(plugin, 'tests', 'shared')} and run the sync again.`,
        );
    }

    const expected = expectedCopies(source);

    for (const part of PARTS) {
        rmSync(join(plugin, part, 'shared'), { recursive: true, force: true });
    }

    const files = {};

    for (const [path, content] of expected) {
        const file = join(plugin, path);
        mkdirSync(dirname(file), { recursive: true });
        writeFileSync(file, content);
        files[path] = sha256(content);
    }

    mkdirSync(join(plugin, 'src', 'shared'), { recursive: true });
    writeFileSync(join(plugin, 'src', 'shared', MANIFEST), JSON.stringify({ source: toPosix(source), files }, null, 4) + '\n');

    return expected.size;
}

// Run as a command (also through the app's vendor link, which Node resolves to this folder).
if (process.argv[1] && realpathSync(resolve(process.argv[1])) === realpathSync(fileURLToPath(import.meta.url))) {
    const [target, flag] = process.argv.slice(2);

    if (!target) {
        console.error('Usage: node sync.mjs <electron-plugin folder> [--check]');
        process.exit(2);
    }

    const plugin = resolve(target);

    try {
        if (flag === '--check') {
            const problems = check(plugin);

            if (problems.length > 0) {
                console.error(`Shared Electron files are not in step with ${SOURCE}:\n  ${problems.join('\n  ')}\nRun the sync (npm run shared:sync).`);
                process.exit(1);
            }

            console.log('Shared Electron files are in step.');
        } else {
            console.log(`Shared Electron files: ${sync(plugin)} copied from ${SOURCE}`);
        }
    } catch (error) {
        console.error(error.message);
        process.exit(1);
    }
}
