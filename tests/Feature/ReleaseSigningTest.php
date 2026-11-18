<?php

use Illuminate\Support\Facades\File;
use Rishadblack\SyllooAppBase\Config\RemoteConfig;
use Rishadblack\SyllooAppBase\Update\ReleaseSigning;

/*
 * Release signing for any app on the base (moved from Sylloo App in 1.1.0): release:sign-update puts
 * `syllooSignature` into latest.yml, release:sign-config signs the remote config file. Every key here is a throw-away TEST key made in a temporary
 * folder for this test and removed after it; the real Sylloo release key never comes near a test.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sylloo-app-base-signing-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->dir);

    $keyPair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
    $this->keyPath = $this->dir.DIRECTORY_SEPARATOR.'test-signing.key';
    File::put($this->keyPath, base64_encode($this->secretKey));

    // The app's updateSignature.ts as it would read with the test key built in.
    $this->configPath = $this->dir.DIRECTORY_SEPARATOR.'updateSignature.ts';
    File::put($this->configPath, "export const UPDATE_PUBLIC_KEY = '{$this->publicKey}';\n");

    $this->latestPath = $this->dir.DIRECTORY_SEPARATOR.'latest.yml';
    File::put($this->latestPath, implode("\n", [
        'version: 1.0.0',
        'files:',
        '  - url: app-setup.exe',
        '    sha512: '.base64_encode(hash('sha512', 'installer', true)),
        '    size: 9',
        'path: app-setup.exe',
        'sha512: '.base64_encode(hash('sha512', 'installer', true)),
        "releaseDate: '2026-11-18T10:00:00.000Z'",
        '',
    ]));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function signUpdate(array $options = []): int
{
    return test()->artisan('release:sign-update', [
        'latest' => test()->latestPath,
        '--key' => test()->keyPath,
        '--config' => test()->configPath,
        ...$options,
    ])->run();
}

it('reads the public key built into the app from config, else from its updateSignature.ts', function () {
    config(['sylloo-app-base.release.public_key_file' => $this->configPath]);

    expect(ReleaseSigning::builtInPublicKey())->toBe($this->publicKey);

    config(['sylloo-app-base.release.public_key' => 'from-config']);

    expect(ReleaseSigning::builtInPublicKey())->toBe('from-config')
        ->and(ReleaseSigning::builtInPublicKey($this->configPath))->toBe($this->publicKey);

    config(['sylloo-app-base.release.public_key' => null, 'sylloo-app-base.release.public_key_file' => $this->dir.'/none.ts']);

    expect(ReleaseSigning::builtInPublicKey())->toBe('');
});

it('finds the key, the public key and latest.yml from config by default', function () {
    config([
        'sylloo-app-base.release.key_path' => $this->keyPath,
        'sylloo-app-base.release.latest_yml' => $this->latestPath,
        'sylloo-app-base.release.public_key' => $this->publicKey,
    ]);

    $this->artisan('release:sign-update')->assertSuccessful();

    expect(ReleaseSigning::topLevel(File::get($this->latestPath), 'syllooSignature'))->not->toBeNull()
        ->and(ReleaseSigning::keyPath('given'))->toBe('given')
        ->and(ReleaseSigning::keyPath())->toBe($this->keyPath);
});

it('signs what the base\'s Electron updateSignature.ts checks: "<version>|<sha512>"', function () {
    $check = (string) file_get_contents(dirname(__DIR__, 2).'/electron/src/updateSignature.ts');

    expect($check)->toContain('return `${version}|${sha512}`;')
        ->and(ReleaseSigning::sign('1.0.0', 'abc', $this->secretKey))
        ->toBe(base64_encode(sodium_crypto_sign_detached('1.0.0|abc', $this->secretKey)));
});

it('signs the version and the installer hash of latest.yml', function () {
    expect(signUpdate())->toBe(0);

    $latest = File::get($this->latestPath);
    $signature = ReleaseSigning::topLevel($latest, 'syllooSignature');
    $sha512 = base64_encode(hash('sha512', 'installer', true));

    expect($signature)->not->toBeNull()
        ->and(sodium_crypto_sign_verify_detached(base64_decode($signature), "1.0.0|{$sha512}", base64_decode($this->publicKey)))->toBeTrue()
        ->and($latest)->toContain('path: app-setup.exe');
});

it('replaces an old signature when it signs again', function () {
    signUpdate();
    signUpdate();

    expect(substr_count(File::get($this->latestPath), 'syllooSignature:'))->toBe(1);
});

it('refuses a key that is not the one built into the app', function () {
    File::put($this->keyPath, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
    $before = File::get($this->latestPath);

    expect(signUpdate())->toBe(1)
        ->and(File::get($this->latestPath))->toBe($before);
});

it('fails when the key is missing or not a signing key, and when the app has no public key', function () {
    expect(signUpdate(['--key' => $this->dir.DIRECTORY_SEPARATOR.'none.key']))->toBe(1);

    File::put($this->keyPath, 'not a key');
    expect(signUpdate())->toBe(1);

    File::put($this->keyPath, base64_encode($this->secretKey));
    File::put($this->configPath, "export const UPDATE_PUBLIC_KEY = '';\n");
    expect(signUpdate())->toBe(1)
        ->and(File::get($this->latestPath))->not->toContain('syllooSignature');
});

it('signs nothing when the build made no latest.yml', function () {
    File::delete($this->latestPath);

    expect(signUpdate())->toBe(0);
});

it('signs the remote config so the app takes it, with a higher serial each time', function () {
    config()->set('erp.remote_config.public_key', $this->publicKey);
    $file = $this->dir.DIRECTORY_SEPARATOR.'app-config.json';
    File::put($file, json_encode(['schema' => 1, 'erp' => ['base_url' => 'https://sylloo.app'], 'notice' => ['message' => 'Hello', 'level' => 'info']]));

    $this->artisan('release:sign-config', ['file' => $file, '--key' => $this->keyPath, '--latest-version' => '1.0.0'])->assertSuccessful();
    $first = json_decode(File::get($file), true);

    $this->artisan('release:sign-config', ['file' => $file, '--key' => $this->keyPath])->assertSuccessful();
    $second = json_decode(File::get($file), true);

    config()->set('erp.remote_config.enabled', true);
    $payload = app(RemoteConfig::class)->verify($second);

    expect($first['payload']['serial'])->toBe(1)
        ->and($first['payload']['release']['latest_version'])->toBe('1.0.0')
        ->and($payload)->not->toBeNull()
        ->and($payload['serial'])->toBe(2)
        ->and($payload['notice']['message'])->toBe('Hello');
});

it('writes no remote config an installed app would refuse', function () {
    config()->set('erp.remote_config.public_key', $this->publicKey);
    $file = $this->dir.DIRECTORY_SEPARATOR.'app-config.json';
    $bad = json_encode(['schema' => 1, 'erp' => ['base_url' => 'https://evil.example']]);
    File::put($file, $bad);

    $this->artisan('release:sign-config', ['file' => $file, '--key' => $this->keyPath])->assertFailed();
    expect(File::get($file))->toBe($bad);

    // A key that is not the app's
    config()->set('erp.remote_config.public_key', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
    File::put($file, json_encode(['schema' => 1]));

    $this->artisan('release:sign-config', ['file' => $file, '--key' => $this->keyPath])->assertFailed();
    expect(json_decode(File::get($file), true))->toBe(['schema' => 1]);
});
