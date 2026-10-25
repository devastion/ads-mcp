<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DeveloperController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('developer');
    }
}
