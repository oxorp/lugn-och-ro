<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function methodology(): Response
    {
        return Inertia::render('methodology');
    }
}
