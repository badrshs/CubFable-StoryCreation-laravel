<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    /**
     * Show the account overview page.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('account', [
            'storyCount' => $request->user()->books()->count(),
        ]);
    }
}
