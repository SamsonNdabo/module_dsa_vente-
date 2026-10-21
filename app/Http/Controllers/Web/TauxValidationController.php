<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class TauxValidationController extends Controller
{
    public function index()
    {
        return view('admin-bureau.validation');
    }
}