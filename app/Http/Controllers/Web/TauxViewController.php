<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class TauxViewController extends Controller
{
    public function index()
    {
        return view('admin-bureau.taux');
    }
}