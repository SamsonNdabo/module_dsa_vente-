<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Les noms des cookies qui ne doivent PAS être encryptés.
     *
     * @var array<int, string>
     */
    protected $except = [
        'starehe_session',     // ✅ Cookie de session à ne pas encrypter
        'laravel_session',     // ✅ Au cas où
        'XSRF-TOKEN',          // ✅ Cookie CSRF
        'shared_auth_token',   // ✅ Token partagé si utilisé
        'pos_session',         // ✅ Cookie de session POS
    ];
}