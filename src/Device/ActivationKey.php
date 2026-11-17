<?php

namespace Rishadblack\SyllooAppBase\Device;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Rishadblack\SyllooAppBase\Contracts\DeviceStore;

/**
 * The device key of one activation, kept encrypted in its DeviceStore (audit SEC-2): with the per-install app key
 * (SEC-1, held by Windows for this user) a copy of the store is no use on another PC. A key saved before it was
 * encrypted is used as it is and saved encrypted. The ERP keeps only its hash.
 *
 * An encrypted key that can not be read here any more (another install's key, or a damaged file) is never sent to the
 * ERP as if it were a plain key: the device counts as not activated and opens the activation page (audit A11).
 */
class ActivationKey
{
    public const SETTING = 'activation_key';

    public function __construct(protected DeviceStore $store) {}

    public function get(): ?string
    {
        $stored = $this->store->get(self::SETTING);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            if (self::isEncryptedPayload($stored)) {
                return null;
            }

            rescue(fn () => $this->store->set(self::SETTING, Crypt::encryptString($stored)), report: false);

            return $stored;
        }
    }

    public function put(?string $key): void
    {
        $this->store->set(self::SETTING, filled($key) ? Crypt::encryptString((string) $key) : null);
    }

    /**
     * Whether a key is stored but can not be read: encrypted, and not with a key this install has.
     */
    public function isUnreadable(): bool
    {
        $stored = $this->store->get(self::SETTING);

        return is_string($stored) && $stored !== '' && self::isEncryptedPayload($stored) && $this->get() === null;
    }

    /**
     * Whether a value is a Laravel encrypted payload (base64 JSON with iv, value and mac) rather than a plain key.
     */
    public static function isEncryptedPayload(string $value): bool
    {
        $payload = json_decode((string) base64_decode($value, true), true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
