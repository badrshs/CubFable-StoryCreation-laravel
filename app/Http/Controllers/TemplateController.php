<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Models\Template;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    use MapsCubfableProps;

    /**
     * Show the full template catalog with the filter facets the library wall
     * offers (distinct themes and the overall age span).
     */
    public function index(): Response
    {
        $templates = Template::query()->orderBy('id')->get();

        return Inertia::render('templates', [
            'templates' => $templates->map(fn (Template $template): array => $this->templateProps($template))->all(),
            'themes' => $templates->pluck('theme')->unique()->sort()->values()->all(),
        ]);
    }
}
