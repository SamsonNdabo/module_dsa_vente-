<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class HistoriqueController extends Controller
{
    public function index()
    {
        return view('admin-bureau.historique');
    }
}