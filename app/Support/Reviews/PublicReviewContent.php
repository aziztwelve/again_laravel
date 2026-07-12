<?php

namespace App\Support\Reviews;

final class PublicReviewContent
{
    public static function normalize(?string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content ?? '');
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content) ?? $content;
        $content = preg_replace('/<\/p\s*>/i', "\n", $content) ?? $content;
        $content = preg_replace('/<p(?:\s[^>]*)?>/i', '', $content) ?? $content;
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? $content;
    }
}
