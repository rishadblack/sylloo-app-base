<?php

namespace Rishadblack\SyllooAppBase\Events;

/**
 * A page of the business could not load in the business window (no internet, or Sylloo did not answer): the window
 * hid it and keeps its address, so the app shows its own page and loads it again ({@see
 * \Rishadblack\SyllooAppBase\Window\ConnectionProblems}). Carries no address.
 *
 * An app whose window runs outside PHP extends it to receive it from there (NativePHP desktop:
 * `class LoadFailed extends WindowLoadFailed implements DispatchableFromNative { use Dispatchable; }`).
 */
class WindowLoadFailed
{
    public function __construct(public string $businessId, public bool $offline = false) {}
}
