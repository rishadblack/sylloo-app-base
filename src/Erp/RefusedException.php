<?php

namespace Rishadblack\SyllooAppBase\Erp;

use RuntimeException;

/**
 * The ERP refused a call (or answered something the app can not use): {@see self::$answer} is its JSON answer (empty
 * when it was not JSON). Read it with {@see Refusal}.
 */
class RefusedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $answer
     */
    public function __construct(public readonly array $answer, public readonly int $status = 0)
    {
        parent::__construct('The ERP refused: '.(Refusal::code($answer) ?? 'no code'));
    }

    public function code(): ?string
    {
        return Refusal::code($this->answer);
    }
}
