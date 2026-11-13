<?php

namespace Tests\Unit\Support;

use App\Support\RichText\DescriptionHtml;
use PHPUnit\Framework\TestCase;

class DescriptionHtmlTest extends TestCase
{
    public function test_plain_text_is_left_alone(): void
    {
        $this->assertSame("Строка 1\nСтрока 2", DescriptionHtml::normalize("Строка 1\nСтрока 2"));
        $this->assertNull(DescriptionHtml::normalize(null));
    }

    public function test_leaf_divs_become_paragraphs_and_wrappers_disappear(): void
    {
        $html = '<div><div><div>Текст <strong>жирный</strong></div></div><div><p>Абзац</p><ul><li>Раз</li></ul></div></div>';

        $this->assertSame('<p>Текст <strong>жирный</strong></p><p>Абзац</p><ul><li>Раз</li></ul>', DescriptionHtml::normalize($html));
    }

    public function test_font_and_spacing_styles_are_dropped_but_color_stays(): void
    {
        $html = '<p style="font-family: Arial; font-size: 14px; color: #f00; margin-top: 10px; line-height: 2">Текст</p>';

        $this->assertSame('<p style="color: #f00;">Текст</p>', DescriptionHtml::normalize($html));
    }

    public function test_font_tags_and_service_classes_are_removed(): void
    {
        $html = '<p class="MsoNormal"><font face="Arial" size="4">Текст</font></p>';

        $this->assertSame('<p>Текст</p>', DescriptionHtml::normalize($html));
    }

    public function test_empty_blocks_and_nbsp_paragraphs_are_removed(): void
    {
        $html = '<p>Один</p><p>&nbsp;</p><p><br></p><div></div><p>Два</p>';

        $this->assertSame('<p>Один</p><p>Два</p>', DescriptionHtml::normalize($html));
    }

    public function test_images_are_kept_and_h1_becomes_h2(): void
    {
        $html = '<h1>Заголовок</h1><p><img src="/a.jpg" alt=""></p>';

        $this->assertSame('<h2>Заголовок</h2><p><img src="/a.jpg" alt=""></p>', DescriptionHtml::normalize($html));
    }

    public function test_normalizing_twice_changes_nothing(): void
    {
        $html = '<div><div>Текст</div><h4>Состав</h4><ul><li style="font-size: 12px">Раз</li></ul></div>';
        $once = DescriptionHtml::normalize($html);

        $this->assertSame($once, DescriptionHtml::normalize($once));
    }
}
