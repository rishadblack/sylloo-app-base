<?php

namespace Rishadblack\SyllooAppBase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rishadblack\SyllooAppBase\Language\Language;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sylloo.language`: show the page in the language chosen on the device (the shared screens use it already).
 */
class UseChosenLanguage
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Language::apply();

        return $next($request);
    }
}
