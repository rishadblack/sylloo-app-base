<?php

namespace Rishadblack\SyllooAppBase\Contracts;

/**
 * Keeps a few short secrets in the system's protected store (plan §4.3 "Keep a secret"): the till and the desktop app
 * through Electron's protected store, the phone through the Android Keystore (`sylloo-app-native` `Secret.*`). The
 * base never writes a secret anywhere else.
 */
interface SecretStore
{
    /**
     * The secret kept under the name, or null.
     */
    public function get(string $name): ?string;

    /**
     * Keep a secret under the name; null removes it.
     */
    public function put(string $name, ?string $secret): void;
}
