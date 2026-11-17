import { randomBytes } from 'crypto';
import { existsSync, readFileSync, writeFileSync } from 'fs';

/**
 * The part of Electron's safeStorage used here (Windows DPAPI, macOS Keychain, the Linux secret store).
 */
export interface SecretStore {
    isEncryptionAvailable(): boolean;
    encryptString(plainText: string): Buffer;
    decryptString(encrypted: Buffer): string;
}

/**
 * Sylloo: this install's own Laravel app key (audit SEC-1). Every install shipped with the same APP_KEY, so anyone
 * with the installer could read or forge what the app encrypts locally (sign-in tokens, the device key). The
 * key is made at random on first launch and kept in a file only safeStorage can open (for this Windows user on this
 * PC); PHP gets it as NATIVEPHP_INSTALL_KEY and keeps the shipped key only to read older data.
 *
 * `file` is the app's own (the till uses `<userData>/install-key.dat`); `store` is Electron's safeStorage.
 *
 * Returns undefined when safeStorage is not available or the file can not be read: the shipped key is used then.
 */
export function installKey(file: string, store: SecretStore): string | undefined {
    try {
        if (!store.isEncryptionAvailable()) {
            return undefined;
        }

        if (existsSync(file)) {
            return store.decryptString(readFileSync(file));
        }

        const key = 'base64:' + randomBytes(32).toString('base64');
        writeFileSync(file, store.encryptString(key));

        return key;
    } catch (error) {
        console.error('Install key: could not read or create it; the shipped app key is used', error);

        return undefined;
    }
}
