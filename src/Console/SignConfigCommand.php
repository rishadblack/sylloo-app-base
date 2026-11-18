<?php

namespace Rishadblack\SyllooAppBase\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use Rishadblack\SyllooAppBase\Config\RemoteConfig;
use Rishadblack\SyllooAppBase\Update\ReleaseSigning;

/**
 * Signs the app's remote config (the base's {@see RemoteConfig}; moved from Sylloo App, base 1.1.0): the JSON file on
 * the app's releases repo (`erp.remote_config.url`). It takes the file as edited (signed before or not), raises
 * `serial` by one, stamps `issued_at`, checks it as an installed app would and writes it back as
 * `{"payload": ..., "signature": ...}`, signed with the release key. Push the file afterwards.
 */
class SignConfigCommand extends Command
{
    protected $signature = 'release:sign-config
        {file : Path to the config file (a clone of the releases repo)}
        {--latest-version= : Set release.latest_version (e.g. after publishing a release)}
        {--key= : Path to the private key (default: SYLLOO_UPDATE_KEY_PATH or ~/.sylloo/update-signing.key)}';

    protected $description = 'Sign the remote config every installed app follows';

    public function handle(RemoteConfig $remoteConfig): int
    {
        $path = (string) $this->argument('file');
        $keyPath = ReleaseSigning::keyPath($this->option('key'));

        if (! File::exists($keyPath)) {
            $this->error("The signing key is missing ({$keyPath}). Restore it from the backup.");

            return self::FAILURE;
        }

        if (! File::exists($path)) {
            $this->error("No config file at {$path}.");

            return self::FAILURE;
        }

        try {
            $document = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("{$path} is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $payload = is_array($document) && is_array($document['payload'] ?? null) ? $document['payload'] : $document;

        if (! is_array($payload) || array_is_list($payload)) {
            $this->error('The file must hold the config object (or a signed file with "payload").');

            return self::FAILURE;
        }

        $secretKey = ReleaseSigning::secretKey($keyPath);

        if ($secretKey === null) {
            $this->error("{$keyPath} is not an Ed25519 signing key.");

            return self::FAILURE;
        }

        if ((string) config('erp.remote_config.public_key') !== ReleaseSigning::publicKeyOf($secretKey)) {
            $this->error('This key is not the one built into the app (erp.remote_config.public_key), so installed apps would refuse the file. Nothing was written.');

            return self::FAILURE;
        }

        $payload['schema'] ??= RemoteConfig::SCHEMA;
        $payload['serial'] = (is_int($payload['serial'] ?? null) ? $payload['serial'] : 0) + 1;
        $payload['issued_at'] = now()->utc()->toIso8601ZuluString();

        if ($this->option('latest-version')) {
            $payload['release'] = [...(array) ($payload['release'] ?? []), 'latest_version' => (string) $this->option('latest-version')];
        }

        $signed = ['payload' => $payload, 'signature' => RemoteConfig::sign($payload, $secretKey)];

        config(['erp.remote_config.enabled' => true]);

        if ($remoteConfig->verify($signed) === null) {
            $this->error("An installed app would refuse this config: {$remoteConfig->lastRefusal}. Nothing was written.");

            return self::FAILURE;
        }

        File::put($path, json_encode($signed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info("Signed serial {$payload['serial']} in {$path}. Push it to the releases repo.");

        return self::SUCCESS;
    }
}
