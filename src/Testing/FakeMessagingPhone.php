<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Rishadblack\SyllooAppBase\Contracts\MessagingPhone;

/**
 * A phone for the phone SMS screens' tests: SIMs, Android's permission and the background job, recording what the
 * screens asked for. `FakeMessagingPhone::bind()` also turns `sylloo-app-base.messages.enabled` on.
 */
class FakeMessagingPhone implements MessagingPhone
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array{base_url: string, device_key: string, app_version: string}|null */
    public ?array $started = null;

    /**
     * @param  list<array{slot: int, label: string}>  $simCards
     * @param  array<string, mixed>  $state  what {@see self::status()} answers
     */
    public function __construct(
        public bool $permission = true,
        public array $simCards = [['slot' => 0, 'label' => 'SIM 1 · Grameenphone']],
        public array $state = [],
        public bool $isAvailable = true,
        public bool $asks = true,
    ) {}

    /**
     * @param  list<array{slot: int, label: string}>|null  $sims
     * @param  array<string, mixed>  $state
     */
    public static function bind(bool $permission = true, ?array $sims = null, array $state = []): self
    {
        config(['sylloo-app-base.messages.enabled' => true]);
        $phone = new self($permission, $sims ?? [['slot' => 0, 'label' => 'SIM 1 · Grameenphone']], $state);
        app()->instance(MessagingPhone::class, $phone);

        return $phone;
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function hasPermission(): bool
    {
        return $this->permission;
    }

    public function askPermission(): bool
    {
        $this->calls[] = 'askPermission';

        return $this->asks;
    }

    public function sims(): ?array
    {
        return $this->permission ? $this->simCards : null;
    }

    public function start(string $baseUrl, string $deviceKey, string $appVersion): bool
    {
        $this->calls[] = 'start';
        $this->started = ['base_url' => $baseUrl, 'device_key' => $deviceKey, 'app_version' => $appVersion];

        return true;
    }

    public function wake(): bool
    {
        $this->calls[] = 'wake';

        return true;
    }

    public function stop(): bool
    {
        $this->calls[] = 'stop';
        $this->started = null;

        return true;
    }

    public function status(?int $slot = null): array
    {
        return [
            'online' => true,
            'can_send' => $this->permission,
            'sim_present' => $this->simCards !== [],
            'service' => null,
            'sent_today' => 0,
            'failed_today' => 0,
            ...$this->state,
        ];
    }
}
