<?php

namespace Tests\Unit;

use App\Support\Reviews\PublicReviewContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicReviewContentTest extends TestCase
{
    #[DataProvider('legacyContent')]
    public function test_it_normalizes_legacy_content_to_plain_text(string $input, string $expected): void
    {
        $normalized = PublicReviewContent::normalize($input);

        self::assertSame($expected, $normalized);
        self::assertSame($normalized, PublicReviewContent::normalize($normalized));
    }

    public static function legacyContent(): array
    {
        return [
            'paragraphs and breaks' => ['<p>Первая<br>строка</p><p class="x">Вторая</p>', "Первая\nстрока\nВторая\n"],
            'scripts and handlers' => ['<script>alert(1)</script><img src=x onerror=alert(2)>Текст', 'alert(1)Текст'],
            'entities and line endings' => ["A&nbsp;B\r\nC\rD", "A\u{00A0}B\nC\nD"],
            'control characters' => ["A\x00B\tC\nD", "AB\tC\nD"],
        ];
    }
}
