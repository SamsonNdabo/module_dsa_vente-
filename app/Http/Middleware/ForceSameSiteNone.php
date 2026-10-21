<?php

// app/Http/Middleware/ForceSameSiteNone.php
namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\Response;

class ForceSameSiteNone
{
    public function handle($request, Closure $next): Response
    {
        $response = $next($request);
        
        // Modifie TOUS les cookies pour forcer SameSite=None
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->setCookie(
                $cookie->withSameSite('none')
            );
        }
        
        return $response;
    }
}