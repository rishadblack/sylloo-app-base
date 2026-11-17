<?php

namespace Rishadblack\SyllooAppBase\SignIn;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The person a finished browser sign-in brought (`sign-in/finish`, device-api.md §1 and §6.2): a **member** (staff,
 * till: `member{id, name, photo}`) or an **employee** (Employee mode: `employee{id, name, code, photo}`), with the
 * device token, its end, the PIN state and the offline days. The two answers become one shape here, so the screens
 * and the apps never read the raw answer.
 */
final class SignedInPerson
{
    public const MEMBER = 'member';

    public const EMPLOYEE = 'employee';

    /**
     * @param  array<string, mixed>  $answer  the whole `sign-in/finish` answer (a till reads its `user`, `limits`, `terminal`)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $photo,
        public readonly ?string $code,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
        public readonly bool $pinSet,
        public readonly ?string $pinHash,
        public readonly ?int $offlineLoginDays,
        public readonly array $answer = [],
    ) {}

    /**
     * Whether a `sign-in/finish` (or `me`) answer names a person: `member` or `employee` with an id and a name.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>|null
     */
    public static function personIn(array $answer): ?array
    {
        foreach ([self::MEMBER, self::EMPLOYEE] as $kind) {
            $person = $answer[$kind] ?? null;

            if (is_array($person)
                && is_scalar($person['id'] ?? null) && (string) $person['id'] !== ''
                && is_scalar($person['name'] ?? null) && (string) $person['name'] !== '') {
                return ['kind' => $kind, ...$person];
            }
        }

        return null;
    }

    /**
     * Whether a `sign-in/finish` answer holds what the app keeps (audit A11): a token and a member or an employee.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function isComplete(array $answer): bool
    {
        return is_string($answer['token'] ?? null) && $answer['token'] !== '' && self::personIn($answer) !== null;
    }

    /**
     * The person of a complete answer, or null.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function fromAnswer(array $answer): ?self
    {
        if (! self::isComplete($answer)) {
            return null;
        }

        $person = (array) self::personIn($answer);
        $photo = $person['photo'] ?? null;
        $expires = is_string($answer['expires_at'] ?? null)
            ? rescue(fn () => Carbon::parse($answer['expires_at']), null, false)
            : null;

        return new self(
            kind: (string) $person['kind'],
            id: (string) $person['id'],
            name: (string) $person['name'],
            photo: is_string($photo) && $photo !== '' ? $photo : null,
            code: is_scalar($person['code'] ?? null) ? (string) $person['code'] : null,
            token: (string) $answer['token'],
            expiresAt: $expires ?? now()->addDays(180),
            pinSet: ($answer['pin_set'] ?? false) === true,
            pinHash: is_string($answer['pin_hash'] ?? null) ? $answer['pin_hash'] : null,
            offlineLoginDays: is_numeric($answer['offline_login_days'] ?? null) ? (int) $answer['offline_login_days'] : null,
            answer: $answer,
        );
    }

    public function isEmployee(): bool
    {
        return $this->kind === self::EMPLOYEE;
    }
}
