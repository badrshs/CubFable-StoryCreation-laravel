<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TemplateController extends Controller
{
    /**
     * The public template catalog with the theme filter facet.
     */
    public function index(): AnonymousResourceCollection
    {
        $templates = Template::query()->orderBy('id')->get();

        return TemplateResource::collection($templates)->additional([
            'meta' => [
                'themes' => $templates->pluck('theme')->unique()->sort()->values()->all(),
            ],
        ]);
    }
}
