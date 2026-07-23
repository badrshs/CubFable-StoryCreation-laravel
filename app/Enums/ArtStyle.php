<?php

namespace App\Enums;

/**
 * Illustration styles selectable in the wizard. Legacy styles that can no
 * longer be picked (e.g. geometric) stay in the Prompts\ArtStyleLibrary
 * so books that already used them keep rendering and regenerating.
 */
enum ArtStyle: string
{
    case ThreeDAnimation = '3d-animation';
    case Cartoon = 'cartoon';
    case Storybook = 'storybook';
    case Watercolor = 'watercolor';
    case SoftDigital = 'soft-digital';
    case SoftAnime = 'soft-anime';
    case ComicBook = 'comic-book';
}
