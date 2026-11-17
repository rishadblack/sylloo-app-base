<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * Where a screen is: its step, what it knows (plain values only: no secrets, they stay in the session), the field
 * errors, a problem line, and what the face must do after an action (go to a screen, open the browser, show a short
 * message, ask a question). A face keeps this between requests ({@see self::toArray()}); the native face does the same.
 */
final class ScreenState
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     * @param  array{text: string, variant: string}|null  $toast
     * @param  array<string, mixed>|null  $question  {moment, key, action, title, text, confirm, cancel, danger}
     */
    public function __construct(
        public string $step = 'start',
        public array $data = [],
        public array $errors = [],
        public ?string $problem = null,
        public ?string $go = null,
        public ?string $open = null,
        public ?array $toast = null,
        public ?array $question = null,
    ) {}

    public function error(string $field, string $text): self
    {
        $this->errors[$field] = $text;

        return $this;
    }

    public function problem(?string $text): self
    {
        $this->problem = $text;

        return $this;
    }

    /**
     * Go to another screen (a screen name of {@see ScreenRoutes}, or `home`).
     */
    public function goTo(string $screen): self
    {
        $this->go = $screen;

        return $this;
    }

    public function toast(string $text, string $variant = 'success'): self
    {
        $this->toast = ['text' => $text, 'variant' => $variant];

        return $this;
    }

    /**
     * Forget what the last action asked of the face (before the next action runs).
     */
    public function settle(): self
    {
        $this->errors = [];
        $this->problem = null;
        $this->go = null;
        $this->open = null;
        $this->toast = null;

        return $this;
    }

    /**
     * @return array{step: string, data: array<string, mixed>, errors: array<string, string>, problem: string|null, go: string|null, open: string|null, toast: array{text: string, variant: string}|null, question: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'data' => $this->data,
            'errors' => $this->errors,
            'problem' => $this->problem,
            'go' => $this->go,
            'open' => $this->open,
            'toast' => $this->toast,
            'question' => $this->question,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $question = $state['question'] ?? null;
        $toast = $state['toast'] ?? null;

        return new self(
            is_string($state['step'] ?? null) ? $state['step'] : 'start',
            is_array($state['data'] ?? null) ? $state['data'] : [],
            array_filter(is_array($state['errors'] ?? null) ? $state['errors'] : [], 'is_string'),
            is_string($state['problem'] ?? null) ? $state['problem'] : null,
            is_string($state['go'] ?? null) ? $state['go'] : null,
            is_string($state['open'] ?? null) ? $state['open'] : null,
            is_array($toast) && is_string($toast['text'] ?? null) ? ['text' => $toast['text'], 'variant' => (string) ($toast['variant'] ?? 'success')] : null,
            is_array($question) && is_string($question['moment'] ?? null) ? $question : null,
        );
    }
}
