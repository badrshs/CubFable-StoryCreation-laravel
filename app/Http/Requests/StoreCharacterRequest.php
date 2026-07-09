<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCharacterRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:120'],
            'ageGroup' => ['nullable', 'in:adult,child'],
            'description' => ['nullable', 'string', 'max:2000'],
            'photoUrl' => ['nullable', 'string', 'starts_with:data:image/', 'max:'.self::MAX_PHOTO_DATA_URL_LENGTH],
        ];
    }
}
