<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Carbon\CarbonInterface;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;

/**
 * A member signed in on a device, in memory.
 */
class FakeMember implements DeviceMember
{
    public function __construct(
        public string $key = 'member-1',
        public string $name = 'Rahim',
        public ?string $token = 'member-token',
        public ?CarbonInterface $tokenExpiresAt = null,
        public ?CarbonInterface $offlineUntil = null,
        public ?string $photo = null,
    ) {
        $this->tokenExpiresAt ??= now()->addDay();
    }

    public function memberKey(): string
    {
        return $this->key;
    }

    public function memberName(): string
    {
        return $this->name;
    }

    public function photoUrl(): ?string
    {
        return $this->photo;
    }

    public function hasValidToken(): bool
    {
        return filled($this->token) && $this->tokenExpiresAt?->isFuture() === true;
    }

    public function offlineSignInUntil(): ?CarbonInterface
    {
        return $this->offlineUntil;
    }

    public function allowOfflineSignInFor(int $days): void
    {
        $this->offlineUntil = now()->addDays(max(0, min($days, 30)));
    }

    public function forgetSignIn(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = null;
        $this->offlineUntil = null;
    }
}
