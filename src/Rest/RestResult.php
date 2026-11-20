<?php

namespace Rishadblack\SyllooAppBase\Rest;

use Rishadblack\SyllooAppBase\Erp\OfficeOutcome;
use Rishadblack\SyllooAppBase\Erp\Refusal;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * One answer of the REST door, with the same named results as the device calls ({@see OfficeOutcome},
 * {@see RefusalCode}). Its shape is read by {@see RestWire}.
 */
final class RestResult
{
    /**
     * @param  array<string, mixed>  $answer
     */
    public function __construct(
        public readonly OfficeOutcome $outcome,
        public readonly array $answer = [],
        public readonly int $status = 0,
        public readonly bool $replayed = false,
        public readonly ?int $retryAfter = null,
    ) {}

    public static function unreachable(): self
    {
        return new self(OfficeOutcome::Unreachable);
    }

    public function ok(): bool
    {
        return $this->outcome === OfficeOutcome::Ok;
    }

    public function isRefused(): bool
    {
        return $this->outcome === OfficeOutcome::Refused;
    }

    public function isUnreachable(): bool
    {
        return $this->outcome === OfficeOutcome::Unreachable;
    }

    /**
     * The office answered 422 with errors by field.
     */
    public function isInvalid(): bool
    {
        return $this->isRefused() && $this->code() === RefusalCode::ValidationFailed->value;
    }

    /**
     * Nothing was done and the same call may be sent again later (`call_in_progress`, `too_many_requests`).
     */
    public function triesLater(): bool
    {
        return $this->isRefused() && ($this->refusal()?->triesLater() ?? false);
    }

    /**
     * The office failed on its side (HTTP 5xx: an error, or a gateway that is down). Nothing was refused: the same
     * write may be sent again later with its one-use key.
     */
    public function isServerError(): bool
    {
        return $this->status >= 500 && $this->status <= 599;
    }

    /**
     * A write with this answer is kept to send later, as when offline (phone check 2026-11-20: an HTTP 500 lost the
     * punch): the office was not reached, asked to wait, or failed on its side.
     */
    public function keepsWaiting(): bool
    {
        return $this->isUnreachable() || $this->triesLater() || $this->isServerError();
    }

    /**
     * The door's gate refused the device or the person, not this call (`session_expired`, `device_released`, …, or
     * 426 `app_too_old`).
     */
    public function isGate(): bool
    {
        return $this->isRefused() && (($this->refusal()?->isGate() ?? false) || $this->status === 426);
    }

    public function code(): ?string
    {
        return Refusal::code($this->answer);
    }

    public function refusal(): ?RefusalCode
    {
        $code = $this->code();

        return $code !== null ? RefusalCode::tryFrom($code) : null;
    }

    public function text(): string
    {
        return match ($this->outcome) {
            OfficeOutcome::Unreachable => Texts::get('unreachable'),
            OfficeOutcome::Unexpected => Texts::get('unexpected'),
            default => Refusal::text($this->answer),
        };
    }

    /**
     * Validation errors by field: the first sentence of each.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $errors = is_array($this->answer['errors'] ?? null) ? $this->answer['errors'] : [];

        return collect($errors)
            ->map(fn ($messages) => is_array($messages) ? ($messages[0] ?? null) : $messages)
            ->filter(fn ($message) => is_string($message))
            ->all();
    }

    /**
     * The saved row of an action.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return RestWire::data($this->answer);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return RestWire::rows($this->answer);
    }

    /**
     * @return list<string>
     */
    public function removed(): array
    {
        return RestWire::removed($this->answer);
    }

    public function hasMore(): bool
    {
        return RestWire::hasMore($this->answer);
    }

    /**
     * The office's `server_time`: the "changed since" mark of the next read.
     */
    public function serverTime(): ?string
    {
        return RestWire::serverTime($this->answer);
    }

    /**
     * The office's signed time (`{at, sig}`) next to `server_time`, for offline time proofs.
     *
     * @return array{at: string, sig: string}|null
     */
    public function anchor(): ?array
    {
        return RestWire::anchor($this->answer);
    }

    /**
     * The id of the kept file (an upload).
     */
    public function fileId(): ?string
    {
        return RestWire::fileId($this->answer);
    }
}
