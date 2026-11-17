import { generateKeyPairSync, sign } from 'crypto';
import { mkdtempSync, rmSync, writeFileSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { EventEmitter } from 'events';
import { afterEach, describe, expect, it } from 'vitest';
import { checkUpdateSignatures, fileSha512, signedMessage, verifyInstaller } from '@desktop-base/updateSignature';

const { publicKey, privateKey } = generateKeyPairSync('ed25519');
// The raw 32 bytes, base64: what `php artisan release:update-key` writes into the app's updateSignature.ts.
const rawPublicKey = publicKey.export({ format: 'der', type: 'spki' }).subarray(12).toString('base64');

const folders: string[] = [];

function installer(contents: string): string {
    const folder = mkdtempSync(join(tmpdir(), 'update-'));
    folders.push(folder);
    const file = join(folder, 'Sylloo POS-2.7.0-setup.exe');
    writeFileSync(file, contents);

    return file;
}

function signatureFor(file: string, version: string): string {
    return sign(null, Buffer.from(signedMessage(version, fileSha512(file))), privateKey).toString('base64');
}

afterEach(() => folders.splice(0).forEach((folder) => rmSync(folder, { recursive: true, force: true })));

describe('verifyInstaller', () => {
    it('accepts an installer signed for its version', () => {
        const file = installer('the real installer');

        expect(verifyInstaller(file, { version: '2.7.0', syllooSignature: signatureFor(file, '2.7.0') }, rawPublicKey)).toBeNull();
    });

    it('refuses a changed installer, another version, or a release without a signature', () => {
        const file = installer('the real installer');
        const signature = signatureFor(file, '2.7.0');
        const tampered = installer('a different installer');

        expect(verifyInstaller(tampered, { version: '2.7.0', syllooSignature: signature }, rawPublicKey)).not.toBeNull();
        expect(verifyInstaller(file, { version: '2.7.1', syllooSignature: signature }, rawPublicKey)).not.toBeNull();
        expect(verifyInstaller(file, { version: '2.7.0' }, rawPublicKey)).not.toBeNull();
    });

    it('checks nothing while no public key is built in', () => {
        expect(verifyInstaller(installer('x'), { version: '2.7.0' }, '')).toBeNull();
    });
});

describe('checkUpdateSignatures', () => {
    // A stand-in for electron-updater's autoUpdater: an event emitter with the Windows installer check.
    function updater() {
        return Object.assign(new EventEmitter(), { verifySignature: async (_file: string): Promise<string | null> => 'not replaced' });
    }

    it('lets electron-updater install only the installer signed for the version on offer', async () => {
        const file = installer('the real installer');
        const autoUpdater = updater();

        checkUpdateSignatures(autoUpdater, rawPublicKey, 'win32');
        autoUpdater.emit('update-available', { version: '2.7.0', syllooSignature: signatureFor(file, '2.7.0') });

        expect(await autoUpdater.verifySignature(file)).toBeNull();
        expect(await autoUpdater.verifySignature(installer('a different installer'))).not.toBeNull();
    });

    it('refuses a download when no signed release was announced', async () => {
        const autoUpdater = updater();

        checkUpdateSignatures(autoUpdater, rawPublicKey, 'win32');

        expect(await autoUpdater.verifySignature(installer('x'))).toBe('The update is not signed by Sylloo.');
    });

    it('leaves other systems to electron-updater', async () => {
        const autoUpdater = updater();

        checkUpdateSignatures(autoUpdater, rawPublicKey, 'darwin');

        expect(await autoUpdater.verifySignature('any')).toBe('not replaced');
    });
});
