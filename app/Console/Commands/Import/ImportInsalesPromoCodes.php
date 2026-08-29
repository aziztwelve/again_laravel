<?php

namespace App\Console\Commands\Import;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ImportInsalesPromoCodes extends Command
{
    protected $signature = 'import:insales-promo-codes
        {file : Путь к XLSX-выгрузке купонов InSales}
        {--dry-run : Проверить файл и показать результат без записи в БД}';

    protected $description = 'Импортировать промокоды из XLSX-выгрузки InSales';

    private const REQUIRED_HEADERS = [
        'id', 'code', 'discount', 'type_id', 'disabled', 'act_once',
        'act_once_for_client', 'expired_at', 'orders_count', 'description', 'order_numbers',
    ];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Файл не найден или недоступен: {$file}");

            return self::FAILURE;
        }

        try {
            $rows = $this->readRows($file);
            $codes = $this->deduplicate($rows);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $existingCodes = DB::table('promo_codes')
            ->pluck('code')
            ->mapWithKeys(fn (string $code) => [mb_strtolower($code) => true])
            ->all();

        $toInsert = [];
        $skippedExisting = 0;
        foreach ($codes as $code => $row) {
            if (isset($existingCodes[mb_strtolower($code)])) {
                $skippedExisting++;
                continue;
            }

            $toInsert[] = $this->mapRow($row);
        }

        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($toInsert): void {
                foreach (array_chunk($toInsert, 500) as $chunk) {
                    DB::table('promo_codes')->insert($chunk);
                }
            });
        }

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Строк в файле', count($rows)],
                ['Уникальных кодов', count($codes)],
                ['Дубликатов в файле', count($rows) - count($codes)],
                ['Уже есть в БД (включая удалённые)', $skippedExisting],
                [$this->option('dry-run') ? 'Будет импортировано' : 'Импортировано', count($toInsert)],
            ],
        );

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> */
    private function readRows(string $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP-расширение ZipArchive не установлено.');
        }

        $archive = new ZipArchive();
        if ($archive->open($file) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX-файл.');
        }

        try {
            $sharedStrings = $this->readSharedStrings((string) $archive->getFromName('xl/sharedStrings.xml'));
            $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            $archive->close();
        }

        if (! is_string($sheetXml) || $sheetXml === '') {
            throw new RuntimeException('В XLSX не найден первый лист с купонами.');
        }

        $sheet = @simplexml_load_string($sheetXml);
        if (! $sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('Не удалось прочитать лист XLSX.');
        }

        $namespace = $sheet->getNamespaces(true)[''] ?? '';
        $rows = $sheet->children($namespace)->sheetData->row ?? [];
        $parsed = [];
        $headers = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($row->children($namespace)->c as $cell) {
                $attributes = $cell->attributes();
                $reference = (string) $attributes->r;
                preg_match('/^[A-Z]+/', $reference, $matches);
                $column = $matches[0] ?? '';
                $raw = (string) $cell->children($namespace)->v;
                $values[$column] = (string) $attributes->t === 's'
                    ? ($sharedStrings[(int) $raw] ?? '')
                    : $raw;
            }

            // В XLSX ключ строки — её номер из Excel (первая строка имеет ключ
            // 1), поэтому определяем заголовок по его фактическому отсутствию,
            // а не по индексу итератора.
            if ($headers === []) {
                $headers = array_flip($values);
                $missing = array_diff(self::REQUIRED_HEADERS, array_keys($headers));
                if ($missing !== []) {
                    throw new RuntimeException('В файле отсутствуют поля: '.implode(', ', $missing));
                }
                continue;
            }

            $parsed[] = collect($headers)
                ->map(fn (string $column) => trim((string) ($values[$column] ?? '')))
                ->all();
        }

        if ($parsed === []) {
            throw new RuntimeException('В файле нет строк для импорта.');
        }

        return $parsed;
    }

    /** @return array<int, string> */
    private function readSharedStrings(string $xml): array
    {
        $strings = @simplexml_load_string($xml);
        if (! $strings instanceof SimpleXMLElement) {
            throw new RuntimeException('Не удалось прочитать словарь строк XLSX.');
        }

        $namespace = $strings->getNamespaces(true)[''] ?? '';

        $result = [];
        foreach ($strings->children($namespace)->si as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $result[] = implode('', array_map(
                static fn (SimpleXMLElement $text): string => (string) $text,
                $parts,
            ));
        }

        return $result;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<string, array<string, string>>
     */
    private function deduplicate(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $code = $row['code'];
            if ($code === '') {
                throw new RuntimeException("Пустой код в строке InSales ID {$row['id']}.");
            }

            // В выгрузке есть два `gorelova`. Оставляем запись с большим числом
            // исторических использований, как согласовано для миграции.
            if (! isset($unique[$code]) || (int) $row['orders_count'] > (int) $unique[$code]['orders_count']) {
                $unique[$code] = $row;
            }
        }

        return $unique;
    }

    /** @param array<string, string> $row */
    private function mapRow(array $row): array
    {
        $discountType = match ($row['type_id']) {
            '1' => 'percentage',
            '2' => 'fixed',
            default => throw new RuntimeException("Неизвестный type_id={$row['type_id']} у кода {$row['code']}"),
        };

        if (! is_numeric($row['discount']) || (float) $row['discount'] < 0) {
            throw new RuntimeException("Некорректная скидка у кода {$row['code']}");
        }

        $expiresAt = $row['expired_at'] === '' ? null : CarbonImmutable::parse($row['expired_at'])->endOfDay();
        $now = now();

        return [
            'code' => $row['code'],
            'description' => $row['description'] ?: null,
            'discount_amount' => number_format((float) $row['discount'], 2, '.', ''),
            'discount_type' => $discountType,
            'discount_behavior' => 'stack',
            'starts_at' => null,
            'expires_at' => $expiresAt,
            'max_uses' => $row['act_once'] === 'true' ? 1 : null,
            // Исторические заказы относятся к InSales и в новой БД отсутствуют.
            'times_used' => 0,
            'is_active' => $row['disabled'] !== 'true',
            'customer_type' => 'all',
            'type' => 'all',
            'applies_to_all_products' => true,
            'applies_to_all_clients' => true,
            'template_type' => 'regular',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
