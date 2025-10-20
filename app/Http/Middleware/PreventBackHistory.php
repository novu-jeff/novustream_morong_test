<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PreventBackHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof IlluminateResponse) {
            $response->header('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate')
                     ->header('Pragma', 'no-cache')
                     ->header('Expires', 'Sat, 01 Jan 1990 00:00:00 GMT');
        } elseif ($response instanceof BinaryFileResponse) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Sat, 01 Jan 1990 00:00:00 GMT');
        }

        return $response;
    }
}
