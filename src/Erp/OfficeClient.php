<?php

namespace Rishadblack\SyllooAppBase\Erp;

use Closure;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Device\Activation;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\SignIn\BrowserSignIn;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;
use Throwable;

/**
 * The office calls of one activation (device-api.md §1), as named results ({@see OfficeAnswer}): `activate`, `state`,
 * `sign-in/start`, `sign-in/finish`, `pin`, `me`, `sign-out`, `deactivate`, `released`, `push-token`, and phone SMS's
 * `messages`, `messages/pairing`, `messages/pause` (§7). The headers, the
 * timeouts and the "office is down" breaker are {@see ApiService}'s and {@see DeviceApi}'s; this class only turns each
 * answer into an outcome, so no screen reads a raw Response or catches a connection error itself.
 *
 *     $office = OfficeClient::for($deviceState, $memberToken);
 *     $answer = $office->activate(Activation::payload(...));
 *     if ($answer->isRefused()) { $answer->text(); $answer->refusal(); }
 */
/**
 * @phpstan-consistent-constructor
 */
class OfficeClient
{
    public function __construct(protected DeviceApi $api) {}

    /**
     * The calls of an activation (its device key), carrying a person's token when given.
     */
    public static function for(?DeviceState $device = null, ?string $token = null): static
    {
        return new static(new DeviceApi(new ApiService($device, $token)));
    }

    public function deviceApi(): DeviceApi
    {
        return $this->api;
    }

    /**
     * @param  array<string, mixed>  $payload  {@see Activation::payload()}
     */
    public function activate(array $payload): OfficeAnswer
    {
        return $this->call(fn () => $this->api->activateDevice($payload), Activation::isComplete(...));
    }

    public function state(): OfficeAnswer
    {
        return $this->call(fn () => $this->api->deviceState(), fn (array $answer) => array_key_exists('blocked', $answer));
    }

    public function signInStart(string $challenge): OfficeAnswer
    {
        return $this->call(
            fn () => $this->api->signInStart($challenge),
            fn (array $answer) => is_string($answer['request_id'] ?? null) && BrowserSignIn::isUsableBrowserUrl($answer['browser_url'] ?? null),
        );
    }

    public function signInFinish(string $requestId, string $verifier): OfficeAnswer
    {
        return $this->call(fn () => $this->api->signInFinish($requestId, $verifier), SignedInPerson::isComplete(...));
    }

    public function setPin(string $pin): OfficeAnswer
    {
        return $this->call(fn () => $this->api->setPin($pin));
    }

    public function me(): OfficeAnswer
    {
        return $this->call(fn () => $this->api->me(), fn (array $answer) => SignedInPerson::personIn($answer) !== null);
    }

    public function signOut(): OfficeAnswer
    {
        return $this->call(fn () => $this->api->signOut());
    }

    public function deactivate(): OfficeAnswer
    {
        return $this->call(fn () => $this->api->deactivate());
    }

    public function released(): OfficeAnswer
    {
        return $this->call(fn () => $this->api->released());
    }

    public function pushToken(?string $token): OfficeAnswer
    {
        return $this->call(fn () => $this->api->pushToken($token));
    }

    /**
     * Phone SMS (device-api.md §7): `GET messages`.
     */
    public function messages(): OfficeAnswer
    {
        return $this->call(fn () => $this->api->messages(), fn (array $answer) => array_key_exists('connected', $answer));
    }

    /**
     * @param  array{slot: int, label: string}|null  $sim
     */
    public function messagesPairing(string $answer, ?array $sim = null): OfficeAnswer
    {
        return $this->call(fn () => $this->api->messagesPairing($answer, $sim), fn (array $answer) => array_key_exists('pairing', $answer));
    }

    public function messagesPause(bool $paused): OfficeAnswer
    {
        return $this->call(fn () => $this->api->messagesPause($paused), fn (array $answer) => array_key_exists('paused', $answer));
    }

    /**
     * @param  Closure(): Response  $send
     * @param  (callable(array<string, mixed>): bool)|null  $complete
     */
    protected function call(Closure $send, ?callable $complete = null): OfficeAnswer
    {
        try {
            return OfficeAnswer::fromResponse($send(), $complete);
        } catch (HttpClientException $exception) {
            Log::info('Office call: not reached: '.$exception->getMessage());

            return OfficeAnswer::unreachable();
        } catch (Throwable $exception) {
            Log::warning('Office call failed: '.$exception->getMessage());

            return new OfficeAnswer(OfficeOutcome::Unexpected);
        }
    }
}
