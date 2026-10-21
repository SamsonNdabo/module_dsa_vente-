<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class CaisseAgentController extends Controller
{
    public function show()
    {
        return view('agent-caisse.caisse');
    }
}