<?php

namespace App\Enums;

/**
 * Illustration styles selectable in the wizard. Legacy styles that can no
 * longer be picked (e.g. geometric) stay in StoryGenerator::ART_STYLE_PROMPTS
 * so books that already used them keep rendering and regenerating.
 */
enum ArtStyle: string
{
    case ThreeDAnimation = '3d-animation';
    case Watercolor = 'watercolor';
    case Storybook = 'storybook';
    case Crayon = 'crayon';
    case Gouache = 'gouache';
    case ClayAnimation = 'clay-animation';
    case FeltCraft = 'felt-craft';
    case StickerArt = 'sticker-art';
    case ComicBook = 'comic-book';
    case SoftAnime = 'soft-anime';
    case Collage = 'collage';
    case BlockWorld = 'block-world';
}
