import { mkdtempSync, existsSync, readFileSync, rmSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { afterEach, describe, expect, it } from 'vitest';
import { installKey, SecretStore } from '@desktop-base/installKey';

// A stand-in for safeStorage that "encrypts" by reversing the text, so the file never holds the key as it is.
const store = (available = true): SecretStore => ({
    isEncryptionAvailable: () => available,
    encryptString: (plainText) => Buffer.from([...plainText].reverse().join('')),
    decryptString: (encrypted) => [...encrypted.toString()].reverse().join(''),
});

const folders: string[] = [];

function keyFile(): string {
    const folder = mkdtempSync(join(tmpdir(), 'install-key-'));
    folders.push(folder);

    return join(folder, 'install-key.dat');
}

afterEach(() => folders.splice(0).forEach((folder) => rmSync(folder, { recursive: true, force: true })));

describe('installKey', () => {
    it('makes a random Laravel key on first launch and keeps it only encrypted', () => {
        const file = keyFile();

        const key = installKey(file, store());

        expect(key).toMatch(/^base64:[A-Za-z0-9+/]{43}=$/);
        expect(existsSync(file)).toBe(true);
        expect(readFileSync(file).toString()).not.toContain(key!.slice(7));
    });

    it('gives the same key on every later launch', () => {
        const file = keyFile();

        expect(installKey(file, store())).toBe(installKey(file, store()));
    });

    it('gives no key without safeStorage, so the shipped key is used', () => {
        const file = keyFile();

        expect(installKey(file, store(false))).toBeUndefined();
        expect(existsSync(file)).toBe(false);
    });
});
