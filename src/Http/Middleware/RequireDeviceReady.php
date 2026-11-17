<?php

namespace Rishadblack\SyllooAppBase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rishadblack\SyllooAppBase\Language\Language;
use Rishadblack\SyllooAppBase\Screens\FirstScreen;
use Rishadblack\SyllooAppBase\Screens\ScreenRoutes;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sylloo.device`: put it on the app's own pages. A page opens only when the device is activated, no state screen
 * applies, a person is in and the device is not locked; otherwise it goes to Activate, the state screen, Sign in or
 * the lock. Each page the person opens starts the idle time again.
 */
class RequireDeviceReady
{
    public function __construct(protected FirstScreen $first) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Language::apply();

        $screen = $this->first->name();

        if ($screen !== null) {
            return redirect()->to(ScreenRoutes::url($screen));
        }

        return $next($request);
    }
}
