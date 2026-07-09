<?php

namespace App\Http\Requests\Admin;

use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update rules for a story template. The page count is bounded by the
 * admin-configurable pages_min/pages_max runtime settings.
 */
class TemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = (int) config('cubfable.pages_min', 4);
        $max = (int) config('cubfable.pages_max', 10);

        return [
            'title' => [
                'required', 'string', 'max:120',
                Rule::unique('templates', 'title')->ignore($this->route('id')),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'theme' => ['required', 'string', 'max:60'],
            'age_min' => ['required', 'integer', 'between:1,12', 'lte:age_max'],
            'age_max' => ['required', 'integer', 'between:1,12', 'gte:age_min'],
            'page_count' => ['required', 'integer', "between:{$min},{$max}"],
            'cover_image_url' => ['nullable', 'string', 'max:100000'],
            'life_lessons' => ['required', 'array', 'min:1', 'max:8'],
            'life_lessons.*' => ['string', 'max:60'],
            'art_styles' => ['required', 'array', 'min:1', 'max:5'],
            'art_styles.*' => [Rule::enum(ArtStyle::class)],
            'subjects' => ['required', 'array', 'min:1', 'max:8'],
            'subjects.*' => ['string', 'max:60'],
            'fonts' => ['required', 'array', 'min:1', 'max:4'],
            'fonts.*' => [Rule::enum(FontChoice::class)],
            'image_prompt' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
