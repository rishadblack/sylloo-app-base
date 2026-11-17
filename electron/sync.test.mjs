import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { check, sync } from './sync.mjs';

const folders = [];

function folder() {
    const path = mkdtempSync(join(tmpdir(), 'desktop-base-'));
    folders.push(path);

    return path;
}

/** A small stand-in for this folder: one source file and one test. */
function source() {
    const path = folder();
    mkdirSync(join(path, 'src', 'printBridge'), { recursive: true });
    mkdirSync(join(path, 'tests'));
    writeFileSync(join(path, 'src', 'printBridge', 'ticket.ts'), 'export const a = 1;\n');
    writeFileSync(join(path, 'tests', 'ticket.test.ts'), 'test\n');

    return path;
}

afterEach(() => folders.splice(0).forEach((path) => rmSync(path, { recursive: true, force: true })));

describe('sync', () => {
    it('copies the shared files into the plugin, marked as copies, and the check passes', () => {
        const base = source();
        const plugin = folder();

        expect(sync(plugin, base)).toBe(2);
        expect(readFileSync(join(plugin, 'src', 'shared', 'printBridge', 'ticket.ts'), 'utf8')).toMatch(/^\/\/ Copied from .*Do not edit here.*\nexport const a = 1;\n$/);
        expect(readFileSync(join(plugin, 'tests', 'shared', 'ticket.test.ts'), 'utf8')).toContain('test');
        expect(check(plugin, base)).toEqual([]);
    });

    it('fails the check when the source changed, a copy is missing or one is left over', () => {
        const base = source();
        const plugin = folder();
        sync(plugin, base);

        writeFileSync(join(base, 'src', 'printBridge', 'ticket.ts'), 'export const a = 2;\n');
        writeFileSync(join(base, 'src', 'installKey.ts'), 'export {};\n');
        writeFileSync(join(plugin, 'src', 'shared', 'old.ts'), 'x');

        expect(check(plugin, base).sort()).toEqual(['differs src/shared/printBridge/ticket.ts', 'left over src/shared/old.ts', 'missing src/shared/installKey.ts']);

        rmSync(join(plugin, 'src', 'shared', 'old.ts'));
        sync(plugin, base);
        expect(check(plugin, base)).toEqual([]);
    });

    it('refuses to overwrite a copy that was changed inside the app', () => {
        const base = source();
        const plugin = folder();
        sync(plugin, base);

        const copy = join(plugin, 'src', 'shared', 'printBridge', 'ticket.ts');
        writeFileSync(copy, 'export const a = 3;\n');

        expect(() => sync(plugin, base)).toThrow(/src\/shared\/printBridge\/ticket\.ts/);
        expect(readFileSync(copy, 'utf8')).toBe('export const a = 3;\n');
    });
});
