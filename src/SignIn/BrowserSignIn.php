<?php

namespace Rishadblack\SyllooAppBase\SignIn;

use Illuminate\Http\Client\HttpClientException;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Erp\RefusedException;

/**
 * Sign in with browser (../sylloo-erp-docs/erp/device-activation-plan.md §5.2–§5.4, device-api.md §1): the app makes a
 * random secret and sends only its SHA-256 (`sign-in/start`), opens `browser_url` in the system browser (the app does
 * that), then asks `sign-in/finish` with the secret every `poll_seconds` and at once when the browser hands back with
 * `<scheme>://signed-in`. No password is ever typed in the app.
 */
class BrowserSignIn
{
    public function __construct(protected DeviceApi $api) {}

    /**
     * A new random secret (64 hex characters).
     */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The challenge sent to `sign-in/start`: lowercase hex SHA-256 of the secret.
     */
    public static function challengeFor(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Start a request. Throws HttpClientException when the ERP can not be reached and RefusedException when it refuses
     * or answers without a request id or a usable browser address.
     *
     * @throws HttpClientException
     * @throws RefusedException
     */
    public function start(): SignInRequest
    {
        $secret = self::newSecret();
        $response = $this->api->signInStart(self::challengeFor($secret));
        $answer = $response->json();

        if (! $response->successful() || ! is_array($answer) || ! is_string($answer['request_id'] ?? null) || ! self::isUsableBrowserUrl($answer['browser_url'] ?? null)) {
            throw new RefusedException(is_array($answer) ? $answer : [], $response->status());
        }

        $expiresIn = is_numeric($answer['expires_in'] ?? null) ? (int) $answer['expires_in'] : 600;

        return new SignInRequest(
            $answer['request_id'],
            $secret,
            now()->addSeconds(max(60, $expiresIn))->timestamp,
            $answer['browser_url'],
            is_numeric($answer['poll_seconds'] ?? null) ? max(2, min(30, (int) $answer['poll_seconds'])) : 3,
        );
    }

    /**
     * Ask `sign-in/finish` with the secret: null while the member has not pressed Allow (409 `sign_in_pending`), the
     * complete answer ({@see self::isComplete()}) once they did. Throws RefusedException for any other answer and
     * HttpClientException when the ERP can not be reached.
     *
     * @return array<string, mixed>|null
     *
     * @throws HttpClientException
     * @throws RefusedException
     */
    public function finish(SignInRequest $request): ?array
    {
        $response = $this->api->signInFinish($request->requestId, $request->secret);
        $answer = $response->json();
        $answer = is_array($answer) ? $answer : [];

        if ($response->status() === 409 && ($answer['code'] ?? null) === 'sign_in_pending') {
            return null;
        }

        if ($response->successful() && self::isComplete($answer)) {
            return $answer;
        }

        throw new RefusedException($answer, $response->status());
    }

    /**
     * Whether a `sign-in/finish` answer holds what the app keeps (audit A11): a token and the member or the employee ({@see SignedInPerson}).
     *
     * @param  array<string, mixed>  $answer
     */
    public static function isComplete(array $answer): bool
    {
        return SignedInPerson::isComplete($answer);
    }

    /**
     * Whether a link is the browser's hand-back `<scheme>://signed-in` (in the shapes each system hands it over: a
     * string, `['url' => …]` or `[…]`).
     */
    public static function isSignedInLink(mixed $url, string $scheme): bool
    {
        if (is_array($url)) {
            $url = $url['url'] ?? ($url[0] ?? null);
        }

        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === strtolower($scheme)
            && strtolower($parts['host'] ?? '') === 'signed-in';
    }

    /**
     * A browser address the app may open: https (http outside production), a real URL.
     */
    public static function isUsableBrowserUrl(mixed $url): bool
    {
        return DeviceState::isSafeUrl($url);
    }
}
