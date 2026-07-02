<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'page_number' => fake()->unique()->numberBetween(1, 10000),
            'text' => fake()->sentences(2, true),
            'scene' => fake()->sentence(),
            'image_path' => null,
            'status' => PageStatus::Pending,
        ];
    }

    /**
     * A fully generated page with an illustration on disk.
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PageStatus::Complete,
            'image_path' => 'books/'.fake()->numberBetween(1, 9999).'/pages/'.fake()->numberBetween(1, 12).'-'.Str::lower(Str::random(8)).'.png',
        ]);
    }
}
