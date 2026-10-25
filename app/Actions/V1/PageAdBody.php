<?php

namespace App\Actions\V1;

class PageAdBody
{
    /**
     * Slices by Unicode characters, never bytes, so a page cannot cut a character in half.
     *
     * @return array{text: string, total: int, hasMore: bool, nextOffset: int|null}
     */
    public function handle(string $body, int $offset, int $limit): array
    {
        $total = mb_strlen($body);
        $text = $offset >= $total ? '' : mb_substr($body, $offset, $limit);
        $next = $offset + mb_strlen($text);
        $hasMore = $text !== '' && $next < $total;

        return [
            'text' => $text,
            'total' => $total,
            'hasMore' => $hasMore,
            'nextOffset' => $hasMore ? $next : null,
        ];
    }
}
