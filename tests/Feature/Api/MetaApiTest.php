<?php

namespace Tests\Feature\Api;

use App\Enums\AgeRange;
use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use App\Enums\StoryLanguage;
use App\Http\Requests\StoreBookRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_exposes_the_wizard_option_catalog()
    {
        $response = $this->getJson(route('api.v1.meta'));

        $response->assertOk()
            ->assertJsonPath('data.ageRanges', array_column(AgeRange::cases(), 'value'))
            ->assertJsonPath('data.artStyles', array_column(ArtStyle::cases(), 'value'))
            ->assertJsonPath('data.fonts', array_column(FontChoice::cases(), 'value'))
            ->assertJsonPath('data.maxCast', StoreBookRequest::MAX_CAST);

        $this->assertCount(count(StoryLanguage::cases()), $response->json('data.languages'));
    }

    public function test_arabic_and_urdu_are_flagged_right_to_left()
    {
        $response = $this->getJson(route('api.v1.meta'));

        $languages = collect($response->json('data.languages'))->keyBy('code');

        $this->assertTrue($languages['ar']['rtl']);
        $this->assertTrue($languages['ur']['rtl']);
        $this->assertFalse($languages['en']['rtl']);
    }

    public function test_meta_exposes_photo_quality_and_display_price()
    {
        config()->set('cubfable.uploads.photo_quality', 'optimized');
        config()->set('cubfable.price_cents', 899);
        config()->set('cubfable.price_currency', 'eur');

        $response = $this->getJson(route('api.v1.meta'));

        $response->assertOk()
            ->assertJsonPath('data.photoUploadQuality', 'optimized')
            ->assertJsonPath('data.price', 899)
            ->assertJsonPath('data.currency', 'EUR');
    }
}
