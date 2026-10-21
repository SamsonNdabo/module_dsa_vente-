<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class ChangeController extends Controller
{
    public function create()
    {
        return view('agent-caisse.change');
    }
}