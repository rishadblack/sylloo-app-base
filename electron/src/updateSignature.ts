import { createHash, createPublicKey, verify } from 'crypto';
import { readFileSync } from 'fs';

/**
 * Sylloo: our own check of every update (audit SEC-3). The installers are signed with a self-signed certificate that
 * Windows does not trust, so electron-updater's signature check was turned off and anyone controlling the releases
 * repo could ship an update. Now each release's latest.yml carries `syllooSignature`, an Ed25519 signature (made by
 * `php artisan release:sign-update` with a key that never leaves the release PC) over the version and the installer's
 * SHA-512, and the downloaded installer must match it before it can be installed.
 *
 * The public key is the app's own (each app keeps it in its own source, written by `php artisan release:update-key`);
 * while it is empty updates are not checked.
 */

// DER header of an Ed25519 public key (SubjectPublicKeyInfo) in front of its 32 raw bytes.
const ed25519SpkiPrefix = Buffer.from('302a300506032b6570032100', 'hex');

export interface SignedUpdateInfo {
    version?: string;
    syllooSignature?: string;
}

/**
 * What the signature covers: the version and the installer's SHA-512 (base64, as in latest.yml).
 */
export function signedMessage(version: string, sha512: string): string {
    return `${version}|${sha512}`;
}

export function fileSha512(file: string): string {
    return createHash('sha512').update(readFileSync(file)).digest('base64');
}

/**
 * Null when the installer may be installed, otherwise why not (electron-updater then discards the download).
 */
export function verifyInstaller(file: string, info: SignedUpdateInfo | null, publicKey: string): string | null {
    if (!publicKey) {
        return null;
    }

    if (!info?.version || !info.syllooSignature) {
        return 'The update is not signed by Sylloo.';
    }

    try {
        const key = createPublicKey({
            key: Buffer.concat([ed25519SpkiPrefix, Buffer.from(publicKey, 'base64')]),
            format: 'der',
            type: 'spki',
        });
        const message = Buffer.from(signedMessage(info.version, fileSha512(file)));

        return verify(null, message, key, Buffer.from(info.syllooSignature, 'base64'))
            ? null
            : 'The update signature does not match the downloaded file.';
    } catch (error) {
        return `The update signature could not be checked: ${error}`;
    }
}

/** The part of electron-updater's autoUpdater used here. */
export interface CheckedUpdater {
    addListener(event: 'update-available', listener: (info: never) => void): unknown;
}

/**
 * Makes electron-updater check our signature on every downloaded Windows installer (after its SHA-512) and discard it
 * when the check fails. Its own check needs a certificate Windows trusts; ours needs the Sylloo signature instead.
 * Call once, where the app sets up its autoUpdater, with the app's own public key.
 */
export function checkUpdateSignatures(updater: CheckedUpdater, publicKey: string, platform: string = process.platform): void {
    // The latest.yml of the update being downloaded, with our signature.
    let pendingUpdateInfo: SignedUpdateInfo | null = null;

    updater.addListener('update-available', (info: SignedUpdateInfo) => {
        pendingUpdateInfo = info;
    });

    if (platform === 'win32' && 'verifySignature' in updater) {
        (updater as unknown as { verifySignature: (file: string) => Promise<string | null> }).verifySignature = async (file: string) => {
            const problem = verifyInstaller(file, pendingUpdateInfo, publicKey);

            if (problem) {
                console.error('Update refused:', problem);
            }

            return problem;
        };
    }
}
