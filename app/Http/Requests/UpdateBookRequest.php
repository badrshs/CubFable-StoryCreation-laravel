<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class UpdateBookRequest extends StoreBookRequest
{
    /**
     * A draft keeps the template it was created from; everything else the
     * wizard collects can be changed until the book is paid for.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['templateId']);
    }
}
