<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;

/**
 * Named places in the shared screens where an app adds its part, without copying the screen (plan §4.4 "Hooks").
 *
 * - **Pieces**: something shown at a place: a view name (rendered with the screen's context) or a closure returning
 *   HTML. The till adds "14 sales are safe on this terminal" to `state.plan_ended`; the mobile app adds the
 *   STAFF / EMPLOYEE mark and "Scan QR code" to `activate.below_code`.
 * - **Lines**: counted lines at a place, as plain values a native face draws too ({@see Lines}): the till adds
 *   "3 refused · 12 sending · a shift is open" to `state.releasing` and "14 sales are safe" to `state.plan_ended`.
 * - **Questions**: asked at a moment before a screen goes on ({@see Question}, with a detail line and lines); the till
 *   asks "Reset terminal for the new code?" with "14 sales are safe on this terminal" at `activate`.
 *
 * Places are listed in {@see self::PLACES}. Bound as a singleton; register in a service provider's boot():
 *
 *     app(Hooks::class)->add('state.plan_ended', 'pos::hooks.sales-safe');
 *     app(Hooks::class)->lines('state.releasing', fn (array $context) => [['text' => 'Refused', 'count' => 3, 'variant' => 'danger']]);
 *     app(Hooks::class)->ask('activate', 'reset', fn (array $context) => $needsReset ? new Question(…) : null);
 */
class Hooks
{
    /**
     * Every place a shared web screen offers (the native faces offer the same names).
     */
    public const PLACES = [
        'layout.header', 'layout.footer',
        'activate.top', 'activate.below_code', 'activate.confirm_card', 'activate.bottom',
        'sign_in.top', 'sign_in.below_tiles', 'sign_in.waiting', 'sign_in.bottom',
        'create_pin.bottom', 'pin.below_pad', 'lock.below_pad',
        'state.top', 'state.bottom', 'state.<name>',
        'businesses.bottom', 'pin_settings.bottom', 'about.rows',
    ];

    /** Moments a shared screen asks the app's questions at. */
    public const MOMENTS = ['activate', 'use_another_code', 'deactivate', 'sign_out'];

    /** @var array<string, list<string|Closure(array<string, mixed>): (string|Htmlable|View|null)>> */
    protected array $pieces = [];

    /** @var array<string, array<string, Closure(array<string, mixed>): ?Question>> */
    protected array $questions = [];

    /** @var array<string, list<Closure(array<string, mixed>): array<mixed>>> */
    protected array $lines = [];

    /**
     * Points of {@see self::check()}: `activate.answer` (the office's activation answer, before anything is kept:
     * `fn (array $answer, array $context) => ?string`).
     */
    public const CHECKS = ['activate.answer'];

    /**
     * Points of {@see self::step()}:
     *
     * - `activate.before_keep` (`fn (array $answer, array $context)`): the office accepted, nothing kept yet (the till
     *   wipes the data of another activation here);
     * - `activate.after_keep` (`fn (array $answer, array $context)`): the activation is kept, before `DeviceActivated`
     *   (the till sets its terminal fields, starts its workers, pulls its profile);
     * - `state.check` (`fn (array $context)`): "Check again" and the state screen's poll (the till also asks its
     *   heartbeat);
     * - `sign_in.link` (`fn (string $url, array $context)`): the app was opened from a link while Sign in shows;
     * - `refused` (`fn (array $refusal, array $context)`): {@see self::refused()};
     * - `activate.continue` (`fn (array $context)`): "Continue" on the confirm card, before Sign in opens (the till's
     *   "Terminal activated" notice);
     * - `use_another_code` (`fn (array $context)`): "Use another code", after the office was told, before the activation
     *   is forgotten here (the till also says `released`);
     * - `sign_in.pick` (`fn (DeviceMember $member, array $context)`): a name tile was tapped, before its sign-in is
     *   checked (the till asks the office for the person's PIN hash, `GET me`);
     * - `sign_in.kept` (`fn (DeviceMember $member, SignedInPerson $person, array $context)`): a browser sign-in was kept
     *   here, before the PIN (the till pulls its staff list).
     */
    public const STEPS = ['activate.before_keep', 'activate.after_keep', 'activate.continue', 'use_another_code', 'state.check', 'sign_in.link', 'sign_in.pick', 'sign_in.kept', 'refused'];

    /** @var array<string, list<Closure(array<string, mixed>): array<string, mixed>>> */
    protected array $payloads = [];

    /** @var array<string, list<Closure>> */
    protected array $checks = [];

    /** @var array<string, list<Closure>> */
    protected array $steps = [];

    /** @var array<string, list<Closure(array<string, mixed>): (string|array<string, string|null>|null)>> */
    protected array $sentences = [];

    /** @var array<string, Closure|null> */
    protected array $finishers = [];

    /** @var list<Closure(array<string, mixed>, array<string, mixed>): ?string> */
    protected array $refusalGoes = [];

    /** @var list<Closure(DeviceMember, array<string, mixed>): ?string> */
    protected array $afterPinSaved = [];

    /**
     * Show a piece at a place: a view name, or a closure that returns HTML (or null to show nothing this time).
     *
     * @param  string|Closure(array<string, mixed>): (string|Htmlable|View|null)  $piece
     */
    public function add(string $place, string|Closure $piece): static
    {
        $this->pieces[$place][] = $piece;

        return $this;
    }

    public function has(string $place): bool
    {
        return ($this->pieces[$place] ?? []) !== [];
    }

    /**
     * The pieces of a place, rendered with the screen's context.
     *
     * @param  array<string, mixed>  $context
     */
    public function render(string $place, array $context = []): HtmlString
    {
        $html = '';

        foreach ($this->pieces[$place] ?? [] as $piece) {
            $out = is_string($piece) ? view($piece, $context) : $piece($context);

            $html .= match (true) {
                $out instanceof View => $out->render(),
                $out instanceof Htmlable => $out->toHtml(),
                is_string($out) => e($out),
                default => '',
            };
        }

        return new HtmlString($html);
    }

    /**
     * Show counted lines at a place: the closure returns them for the screen's context (none: `[]`).
     *
     * @param  Closure(array<string, mixed>): array<mixed>  $lines
     */
    public function lines(string $place, Closure $lines): static
    {
        $this->lines[$place][] = $lines;

        return $this;
    }

    /**
     * The lines of these places, in order.
     *
     * @param  list<string>  $places
     * @param  array<string, mixed>  $context
     * @return list<array{text: string, count: int|null, hint: string|null, variant: string, icon: string|null}>
     */
    public function linesFor(array $places, array $context = []): array
    {
        $lines = [];

        foreach ($places as $place) {
            foreach ($this->lines[$place] ?? [] as $make) {
                $lines = [...$lines, ...Lines::clean($make($context))];
            }
        }

        return $lines;
    }

    /**
     * Ask a question at a moment, when the closure returns one.
     *
     * @param  Closure(array<string, mixed>): ?Question  $question
     */
    public function ask(string $moment, string $key, Closure $question): static
    {
        $this->questions[$moment][$key] = $question;

        return $this;
    }

    /**
     * The first question the app asks at this moment, with its key, or null.
     *
     * @param  array<string, mixed>  $context
     * @return array{key: string, question: Question}|null
     */
    public function question(string $moment, array $context = []): ?array
    {
        foreach ($this->questions[$moment] ?? [] as $key => $ask) {
            $question = $ask($context);

            if ($question instanceof Question) {
                return ['key' => $key, 'question' => $question];
            }
        }

        return null;
    }

    /**
     * The person answered a question: run its "yes" or "no". What that closure returns is a short message the screen
     * shows as a toast: a text, or `['text' => …, 'variant' => …]` (the till's "Nothing changed" after "No"); anything
     * else shows nothing.
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, variant: string}|null
     */
    public function answer(string $moment, string $key, bool $yes, array $context = []): ?array
    {
        $ask = $this->questions[$moment][$key] ?? null;
        $question = $ask !== null ? $ask($context) : null;

        if (! $question instanceof Question) {
            return null;
        }

        $run = $yes ? $question->onConfirm : $question->onCancel;
        $said = $run !== null ? $run($context) : null;

        return match (true) {
            is_string($said) && $said !== '' => ['text' => $said, 'variant' => 'info'],
            is_array($said) && is_string($said['text'] ?? null) && $said['text'] !== '' => ['text' => $said['text'], 'variant' => is_string($said['variant'] ?? null) ? $said['variant'] : 'info'],
            default => null,
        };
    }

    /**
     * Add fields to an office call's body (`activate`: the till's queue counts). The closure gets the screen's context
     * and returns the fields; the base's own fields win.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $fields
     */
    public function payload(string $call, Closure $fields): static
    {
        $this->payloads[$call][] = $fields;

        return $this;
    }

    /**
     * The app's fields for a call.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function payloadFor(string $call, array $context = []): array
    {
        $fields = [];

        foreach ($this->payloads[$call] ?? [] as $make) {
            $fields = [...$fields, ...$make($context)];
        }

        return $fields;
    }

    /**
     * Check something before the screen goes on: the closure returns null when all is well, else the problem in plain
     * words (the screen shows it and keeps nothing). Points: {@see self::CHECKS}.
     *
     * @param  Closure  $check  its arguments are the point's (see {@see self::CHECKS}); returns ?string
     */
    public function check(string $point, Closure $check): static
    {
        $this->checks[$point][] = $check;

        return $this;
    }

    /**
     * The first problem a check of this point finds, or null.
     */
    public function problemAt(string $point, mixed ...$arguments): ?string
    {
        foreach ($this->checks[$point] ?? [] as $check) {
            $problem = $check(...$arguments);

            if (is_string($problem) && $problem !== '') {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Run the app's work at a step of a screen's flow (points: {@see self::STEPS}); every closure of the point runs, in
     * order. A step that throws stops the flow (the screen shows "unexpected").
     *
     * @param  Closure  $step  its arguments are the point's (see {@see self::STEPS})
     */
    public function step(string $point, Closure $step): static
    {
        $this->steps[$point][] = $step;

        return $this;
    }

    public function hasStep(string $point): bool
    {
        return ($this->steps[$point] ?? []) !== [];
    }

    /**
     * Run every step of a point.
     */
    public function runSteps(string $point, mixed ...$arguments): void
    {
        foreach ($this->steps[$point] ?? [] as $step) {
            $step(...$arguments);
        }
    }

    /**
     * The office refused a call of a screen: the closure gets `{call, code, status, message, answer}` and the screen's
     * context (the till writes its sync error log).
     *
     * @param  Closure(array<string, mixed>, array<string, mixed>): mixed  $listener
     */
    public function refused(Closure $listener): static
    {
        return $this->step('refused', $listener);
    }

    /**
     * Leave a screen when the office refuses one of its calls: the closure gets `{call, code, status, message, answer}`
     * and the screen's context, and returns where to go (a screen name such as `state`, `home`, or an address starting
     * with `/`), or null to show the refusal as usual. The till: `device_released` starts its release and goes to the
     * state screen; `plan_ended` and `business_suspended` at sign-in go there too. The first closure that answers wins.
     *
     * @param  Closure(array<string, mixed>, array<string, mixed>): ?string  $go
     */
    public function goOnRefusal(Closure $go): static
    {
        $this->refusalGoes[] = $go;

        return $this;
    }

    /**
     * Where a refused call sends the screen, or null.
     *
     * @param  array<string, mixed>  $refusal
     * @param  array<string, mixed>  $context
     */
    public function refusalGo(array $refusal, array $context = []): ?string
    {
        foreach ($this->refusalGoes as $go) {
            $to = $go($refusal, $context);

            if (is_string($to) && $to !== '') {
                return $to;
            }
        }

        return null;
    }

    /**
     * Choose a state screen's sentences: the closure gets the screen's context (with `plan`, `date`, `device_state`)
     * and returns the text, or `['title' => …, 'text' => …, 'todo' => …]` (any of them), or null for the base's.
     *
     * @param  Closure(array<string, mixed>): (string|array<string, string|null>|null)  $sentences
     */
    public function sentences(string $state, Closure $sentences): static
    {
        $this->sentences[$state][] = $sentences;

        return $this;
    }

    /**
     * The app's sentences of a state (the last closure that answers wins).
     *
     * @param  array<string, mixed>  $context
     * @return array{title?: string, text?: string, todo?: string|null}
     */
    public function sentencesFor(string $state, array $context = []): array
    {
        $chosen = [];

        foreach ($this->sentences[$state] ?? [] as $choose) {
            $answer = $choose($context);

            if (is_string($answer)) {
                $chosen = [...$chosen, 'text' => $answer];
            } elseif (is_array($answer)) {
                $chosen = [...$chosen, ...array_intersect_key($answer, ['title' => 1, 'text' => 1, 'todo' => 1])];
            }
        }

        return $chosen;
    }

    /**
     * Let the app finish a flow itself instead of the base (point `signed_in`: the app signs the person in its own way,
     * starts its workers and says where to go). The last one set wins; null gives the flow back to the base.
     *
     * @param  Closure|null  $finish  `signed_in`: fn (DeviceMember $member, bool $offline, ?SignedInPerson $person, array $context): ?string
     */
    public function finish(string $point, ?Closure $finish): static
    {
        $this->finishers[$point] = $finish;

        return $this;
    }

    public function finisher(string $point): ?Closure
    {
        return $this->finishers[$point] ?? null;
    }

    /**
     * After a new PIN was saved (Create PIN, or the PIN screen with `screens.pin.create_here`) and the person is in
     * (base 1.1.0): the app may send them somewhere first, e.g. a phone's "Use fingerprint next time?" offer. The
     * closure gets the person and the screen's context and returns where to go (a screen name or an app address such
     * as `/fingerprint-offer`), or null for the usual way (home). The first answer wins; it is not asked when an app's
     * `signed_in` finisher chose a place itself.
     *
     * @param  Closure(DeviceMember, array<string, mixed>): ?string  $then
     */
    public function afterPinSaved(Closure $then): static
    {
        $this->afterPinSaved[] = $then;

        return $this;
    }

    /**
     * Where to go after a new PIN was saved, or null (the usual way).
     *
     * @param  array<string, mixed>  $context
     */
    public function pinSavedGo(DeviceMember $member, array $context): ?string
    {
        foreach ($this->afterPinSaved as $then) {
            $go = $then($member, $context);

            if (is_string($go) && $go !== '') {
                return $go;
            }
        }

        return null;
    }

    /**
     * Forget every piece and question (tests).
     */
    public function flush(): void
    {
        $this->pieces = [];
        $this->questions = [];
        $this->lines = [];
        $this->payloads = [];
        $this->checks = [];
        $this->steps = [];
        $this->sentences = [];
        $this->finishers = [];
        $this->refusalGoes = [];
        $this->afterPinSaved = [];
    }
}
