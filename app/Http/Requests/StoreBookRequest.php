<?php

namespace App\Http\Requests;

use App\Enums\AgeRange;
use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use App\Enums\StoryLanguage;
use App\Models\Template;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    /**
     * A storybook realistically has a handful of characters; the cap stops a
     * single request from mass-inserting rows in one long transaction.
     */
    public const int MAX_CAST = 24;

    /**
     * Upper bound on an uploaded photo data URL: base64 of the 12MB decoded
     * limit, since 'original' photo quality submits the untouched file.
     */
    private const int MAX_PHOTO_DATA_URL_LENGTH = 16 * 1024 * 1024;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'templateId' => ['required', 'integer', Rule::exists(Template::class, 'id')],
            'ageRange' => ['required', Rule::enum(AgeRange::class)],
            'theme' => ['required', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:2000'],
            'lifeLesson' => ['required', 'string', 'max:2000'],
            'artStyle' => ['required', Rule::enum(ArtStyle::class)],
            'font' => ['required', Rule::enum(FontChoice::class)],
            'language' => ['nullable', Rule::enum(StoryLanguage::class)],
            'characters' => ['required', 'array', 'min:1', 'max:'.self::MAX_CAST],
            'characters.*.characterId' => ['nullable', 'integer'],
            'characters.*.name' => ['required', 'string', 'max:120'],
            'characters.*.role' => ['nullable', 'string', 'max:120'],
            'characters.*.ageGroup' => ['nullable', 'in:adult,child'],
            'characters.*.description' => ['nullable', 'string', 'max:2000'],
            'characters.*.photoUrl' => ['nullable', 'string', 'starts_with:data:image/', 'max:'.self::MAX_PHOTO_DATA_URL_LENGTH],
            'characters.*.isMain' => ['nullable', 'boolean'],
        ];
    }
}
