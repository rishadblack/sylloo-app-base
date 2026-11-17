<?php

namespace Rishadblack\SyllooAppBase\Events;

/**
 * A person created or changed their PIN here (the office keeps it for every device of the business).
 */
class PinSaved
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $memberKey,
    ) {}
}
