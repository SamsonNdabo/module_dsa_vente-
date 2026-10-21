<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

class StatsController extends Controller
{
    public function index()
    {
        return view('admin-bureau.stats');
    }
}