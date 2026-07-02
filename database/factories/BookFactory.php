<?php

namespace Database\Factories;

use App\Enums\AgeRange;
use App\Enums\ArtStyle;
use App\Enums\BookStatus;
use App\Enums\FontChoice;
use App\Enums\StoryLanguage;
use App\Models\Book;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'template_id' => Template::factory(),
            'child_name' => fake()->firstName(),
            'age_range' => fake()->randomElement(AgeRange::cases())->value,
            'theme' => fake()->randomElement(['forest', 'pirates', 'space', 'kitchen', 'dinosaurs', 'rainbow']),
            'subject' => fake()->words(2, true),
            'life_lesson' => fake()->randomElement(['Kindness', 'Courage', 'Friendship', 'Patience', 'Honesty']),
            'art_style' => fake()->randomElement(ArtStyle::cases())->value,
            'font' => fake()->randomElement(FontChoice::cases())->value,
            'language' => StoryLanguage::English->value,
            'status' => BookStatus::Draft,
        ];
    }

    /**
     * A book awaiting payment.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookStatus::Draft,
            'paid_at' => null,
        ]);
    }

    /**
     * A paid book waiting for generation to start.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookStatus::Pending,
            'paid_at' => now(),
        ]);
    }

    /**
     * A paid book whose generation is in progress.
     */
    public function generating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookStatus::Generating,
            'paid_at' => now(),
        ]);
    }

    /**
     * A fully generated book with a cover image on disk.
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookStatus::Complete,
            'paid_at' => now(),
            'cover_image_path' => 'books/'.fake()->numberBetween(1, 9999).'/cover-'.Str::lower(Str::random(8)).'.png',
        ]);
    }
}
