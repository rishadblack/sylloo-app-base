<?php

namespace Rishadblack\SyllooAppBase\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Rishadblack\SyllooAppBase\Update\ReleaseSigning;

/**
 * Signs a release for the installed apps (moved from Sylloo App, base 1.1.0): adds `syllooSignature` to the release's
 * latest.yml, an Ed25519 signature over "<version>|<sha512 of the installer>" made with the release key. An installed
 * app checks it (the base's Electron `updateSignature.ts`) before it installs an update and refuses an installer
 * without it. Run it after every build (NativePHP `postbuild`) and upload the signed latest.yml with the installer.
 *
 * A key whose public half is not the one built into the app is refused, so a release is never signed with a key the
 * installed apps would reject. Paths and keys: {@see ReleaseSigning}.
 */
class SignUpdateCommand extends Command
{
    protected $signature = 'release:sign-update
        {latest? : Path to latest.yml (default: config sylloo-app-base.release.latest_yml, else the Electron build output)}
        {--key= : Path to the private key (default: SYLLOO_UPDATE_KEY_PATH or ~/.sylloo/update-signing.key)}
        {--config= : Path to updateSignature.ts, the public key built into the app (default: config sylloo-app-base.release)}';

    protected $description = 'Sign latest.yml so installed apps install only this release\'s installer';

    public function handle(): int
    {
        $latestPath = (string) ($this->argument('latest') ?: ReleaseSigning::latestYmlPath());
        $keyPath = ReleaseSigning::keyPath($this->option('key'));
        $publicKey = ReleaseSigning::builtInPublicKey($this->option('config') ?: null);

        if (! File::exists($latestPath)) {
            $this->warn("No latest.yml at {$latestPath}; nothing to sign.");

            return self::SUCCESS;
        }

        if ($publicKey === '') {
            $this->error('No update public key is built into the app (UPDATE_PUBLIC_KEY in updateSignature.ts, or sylloo-app-base.release.public_key).');

            return self::FAILURE;
        }

        if (! File::exists($keyPath)) {
            $this->error("The update signing key is missing ({$keyPath}). Installed apps refuse an unsigned update: restore the key.");

            return self::FAILURE;
        }

        $secretKey = ReleaseSigning::secretKey($keyPath);

        if ($secretKey === null) {
            $this->error("{$keyPath} is not an Ed25519 signing key.");

            return self::FAILURE;
        }

        if (ReleaseSigning::publicKeyOf($secretKey) !== $publicKey) {
            $this->error('This key is not the one built into the app (UPDATE_PUBLIC_KEY): installed apps would refuse the update. Nothing was signed.');

            return self::FAILURE;
        }

        $latest = File::get($latestPath);
        $version = ReleaseSigning::topLevel($latest, 'version');
        $sha512 = ReleaseSigning::topLevel($latest, 'sha512');

        if ($version === null || $sha512 === null) {
            $this->error('latest.yml has no version or sha512.');

            return self::FAILURE;
        }

        $signature = ReleaseSigning::sign($version, $sha512, $secretKey);
        $latest = (string) preg_replace('/^syllooSignature:.*\R?/m', '', $latest);
        File::put($latestPath, rtrim($latest)."\nsyllooSignature: {$signature}\n");

        $this->info("Signed {$version} in {$latestPath}");

        return self::SUCCESS;
    }
}
