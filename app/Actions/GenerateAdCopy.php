<?php

namespace App\Actions;

use App\Enums\Niche;
use Random\Randomizer;

class GenerateAdCopy
{
    private const array HEADLINE_TEMPLATES = [
        '%s for everyday routines',
        'Meet our new %s',
        'Why %s matters this season',
        'Top-rated %s picks',
        'Shop %s today',
        'The %s you keep reaching for',
    ];

    private const array BODY_TEMPLATES = [
        'Discover our %s range from %s, made for real life.',
        'Looking for %s? Our %s collection has you covered.',
        'Find the %s that fits your %s routine.',
    ];

    /**
     * @return array{headline: string, body_text: string}
     */
    public function handle(Randomizer $random, Niche $niche): array
    {
        $keywords = $niche->keywords();
        $keyword = $keywords[$random->getInt(0, count($keywords) - 1)];

        $headline = sprintf(
            self::HEADLINE_TEMPLATES[$random->getInt(0, count(self::HEADLINE_TEMPLATES) - 1)],
            $keyword,
        );

        $body = sprintf(
            self::BODY_TEMPLATES[$random->getInt(0, count(self::BODY_TEMPLATES) - 1)],
            $keyword,
            $niche->displayName(),
        );

        return [
            'headline' => $headline,
            'body_text' => $body,
        ];
    }
}
