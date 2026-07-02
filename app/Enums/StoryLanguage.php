<?php

namespace App\Enums;

enum StoryLanguage: string
{
    case English = 'en';
    case Arabic = 'ar';
    case Turkish = 'tr';
    case Spanish = 'es';
    case French = 'fr';
    case German = 'de';
    case Italian = 'it';
    case Portuguese = 'pt';
    case Russian = 'ru';
    case Hindi = 'hi';
    case Urdu = 'ur';
    case Chinese = 'zh';

    /**
     * The English name of the language, as used inside AI prompts.
     */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Arabic => 'Arabic',
            self::Turkish => 'Turkish',
            self::Spanish => 'Spanish',
            self::French => 'French',
            self::German => 'German',
            self::Italian => 'Italian',
            self::Portuguese => 'Portuguese',
            self::Russian => 'Russian',
            self::Hindi => 'Hindi',
            self::Urdu => 'Urdu',
            self::Chinese => 'Simplified Chinese',
        };
    }
}
