<?php

namespace Tests\Unit\Support;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public static function validPhones(): array
    {
        return [
            'формат из заказа 40747' => ['+7 (985) 353-12-22', '79853531222'],
            'через 8' => ['8 985 353 12 22', '79853531222'],
            'без кода страны' => ['9853531222', '79853531222'],
            'Казахстан' => ['+7 701 123 45 67', '77011234567'],
            'Беларусь' => ['+375 29 123-45-67', '375291234567'],
        ];
    }

    #[DataProvider('validPhones')]
    public function test_valid_phones_are_normalized(string $raw, string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($raw));
        $this->assertSame('+'.$expected, Phone::e164($raw));
    }

    public static function invalidPhones(): array
    {
        return [
            'фамилия вместо телефона (заказ 40703)' => ['Кочеткова Ольга'],
            'двойная семёрка (заказ 40767)' => ['+7 791 234 56 78 9'],
            'слишком короткий' => ['12345'],
            'пустая строка' => [''],
            'буквы в номере' => ['+7 985 353 12 2a'],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function test_invalid_phones_are_rejected(string $raw): void
    {
        $this->assertNull(Phone::normalize($raw));
        $this->assertFalse(Phone::isValid($raw));
    }

    public function test_null_is_invalid(): void
    {
        $this->assertNull(Phone::normalize(null));
        $this->assertNull(Phone::e164(null));
    }
}
