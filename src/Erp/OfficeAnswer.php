<?php

namespace Rishadblack\SyllooAppBase\Erp;

use Illuminate\Http\Client\Response;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * One answer of the office, as a named result: {@see OfficeOutcome}, the JSON answer, the HTTP status and the refusal
 * code ({@see RefusalCode}, or the raw code for one the base does not know yet). Screens and apps read this, never a raw
 * Response.
 */
final class OfficeAnswer
{
    /**
     * @param  array<string, mixed>  $answer
     */
    public function __construct(
        public readonly OfficeOutcome $outcome,
        public readonly array $answer = [],
        public readonly int $status = 0,
    ) {}

    /**
     * Read a Response: refused when the office answered 4xx with a code (or any 4xx/5xx it explains), ok when it is
     * successful and $complete says the answer holds what the app needs.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $complete
     */
    public static function fromResponse(Response $response, ?callable $complete = null): self
    {
        $json = rescue(fn () => $response->json(), null, false);
        $answer = is_array($json) ? $json : [];

        if ($response->status() === 409 && Refusal::code($answer) === RefusalCode::SignInPending->value) {
            return new self(OfficeOutcome::Pending, $answer, 409);
        }

        if ($response->successful()) {
            return ($complete === null || $complete($answer))
                ? new self(OfficeOutcome::Ok, $answer, $response->status())
                : new self(OfficeOutcome::Unexpected, $answer, $response->status());
        }

        if (in_array($response->status(), [502, 503, 504], true)) {
            return new self(OfficeOutcome::Unreachable, $answer, $response->status());
        }

        return new self(Refusal::code($answer) !== null || $response->clientError() ? OfficeOutcome::Refused : OfficeOutcome::Unexpected, $answer, $response->status());
    }

    public static function unreachable(): self
    {
        return new self(OfficeOutcome::Unreachable);
    }

    public function ok(): bool
    {
        return $this->outcome === OfficeOutcome::Ok;
    }

    public function isPending(): bool
    {
        return $this->outcome === OfficeOutcome::Pending;
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
     * The raw refusal code, or null.
     */
    public function code(): ?string
    {
        return Refusal::code($this->answer);
    }

    /**
     * The refusal as a named code, or null (no code, or one the base does not know).
     */
    public function refusal(): ?RefusalCode
    {
        $code = $this->code();

        return $code !== null ? RefusalCode::tryFrom($code) : null;
    }

    /**
     * Whether the refusal is this code.
     */
    public function is(RefusalCode $code): bool
    {
        return $this->code() === $code->value;
    }

    /**
     * The plain sentence for the person: unreachable, the refusal's text, or "unexpected".
     *
     * @param  array<string, mixed>  $replace  more words for the refusal's text (`:allowed` of the device limit)
     */
    public function text(array $replace = []): string
    {
        return match ($this->outcome) {
            OfficeOutcome::Unreachable => Texts::get('unreachable'),
            OfficeOutcome::Unexpected => Texts::get('unexpected'),
            default => Refusal::text($this->answer, $replace),
        };
    }

    /**
     * The first validation error of a field (422 `validation_failed` with `errors`), or null.
     */
    public function fieldError(string $field): ?string
    {
        $errors = $this->answer['errors'][$field] ?? null;

        return is_array($errors) && is_string($errors[0] ?? null) ? $errors[0] : (is_string($errors) ? $errors : null);
    }

    /**
     * A value of the answer by dot path.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->answer, $key, $default);
    }
}
