<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCharacterRequest extends FormRequest
{
    /**
     * Upper bound on an uploaded photo data URL (the client downscales photos
     * before submitting, so real payloads stay far below this).
     */
    private const int MAX_PHOTO_DATA_URL_LENGTH = 2 * 1024 * 1024;

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
     * PATCH semantics: role, description, and photoUrl are only touched when
     * present in the payload; an explicit null clears them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'photoUrl' => ['sometimes', 'nullable', 'string', 'starts_with:data:image/', 'max:'.self::MAX_PHOTO_DATA_URL_LENGTH],
        ];
    }
}
