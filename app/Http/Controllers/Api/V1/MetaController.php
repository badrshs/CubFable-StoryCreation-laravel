<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgeRange;
use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use App\Enums\StoryLanguage;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    /**
     * Story languages written right-to-left; their story text needs RTL
     * rendering on every client.
     */
    private const array RTL_LANGUAGES = ['ar', 'ur'];

    /**
     * The wizard option catalog, so clients never hardcode enum values. The
     * price is informational (web checkout); mobile purchases are priced by
     * the app stores.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'ageRanges' => array_column(AgeRange::cases(), 'value'),
                'artStyles' => array_column(ArtStyle::cases(), 'value'),
                'fonts' => array_column(FontChoice::cases(), 'value'),
                'languages' => array_map(fn (StoryLanguage $language): array => [
                    'code' => $language->value,
                    'label' => $language->label(),
                    'rtl' => in_array($language->value, self::RTL_LANGUAGES, true),
                ], StoryLanguage::cases()),
                'maxCast' => StoreBookRequest::MAX_CAST,
                'photoUploadQuality' => (string) config('cubfable.uploads.photo_quality'),
                'price' => (int) config('cubfable.price_cents'),
                'currency' => strtoupper((string) config('cubfable.price_currency')),
            ],
        ]);
    }
}
