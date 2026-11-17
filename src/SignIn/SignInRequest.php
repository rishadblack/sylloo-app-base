<?php

namespace Rishadblack\SyllooAppBase\SignIn;

/**
 * A browser sign-in in progress: the ERP's request id, the secret (verifier) the app keeps, and when it ends. Keep it
 * only on the native side (the session), never in a page; {@see self::toArray()} / {@see self::fromArray()} store it.
 */
final class SignInRequest
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $secret,
        public readonly int $expiresAt,
        public readonly ?string $browserUrl = null,
        public readonly int $pollSeconds = 3,
    ) {}

    public function isExpired(): bool
    {
        return now()->timestamp >= $this->expiresAt;
    }

    /**
     * @return array{request_id: string, secret: string, expires_at: int}
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'secret' => $this->secret,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * A stored request, or null when it is not one.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored) || ! is_string($stored['request_id'] ?? null) || ! is_string($stored['secret'] ?? null)) {
            return null;
        }

        return new self($stored['request_id'], $stored['secret'], (int) ($stored['expires_at'] ?? 0));
    }
}
