<?php
use PHPUnit\Framework\TestCase;

/**
 * Импорт справочника НСИ prx400 на настоящем PostgreSQL: проверка без записи,
 * применение (создано/обновлено/без изменений), «всё или ничего» при ошибке в
 * строке, номера строк в отчёте, русские заголовки, круговая выгрузка→загрузка,
 * права. Свои коды с уникальным префиксом — уборка в finally.
 */
class SpecflowCatalogImportTest extends TestCase
{
    private string $prefix = '';
    private array $files = [];

    private function csv(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prxnsi');
        file_put_contents($path, $text);
        $this->files[] = $path;

        return $path;
    }

    private function row(string $suffix): ?array
    {
        return Model_Prx400_Catalog::by_code($this->prefix.$suffix);
    }

    public function test_preview_apply_all_or_nothing_and_roundtrip(): void
    {
        $this->prefix = 'SMK'.bin2hex(random_bytes(4)).'-';
        $p = $this->prefix;
        try
        {
            Model_Prx400_Catalog::insert(['code' => $p.'OLD', 'name' => 'Старое имя', 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => '']);
            Model_Prx400_Catalog::insert(['code' => $p.'SAME', 'name' => 'Шайба 12', 'unit' => 'шт.', 'okei_code' => '', 'keywords' => '']);

            $file = $this->csv("\xEF\xBB\xBF\"Код НСИ\";Наименование;Ед. изм.;ОКЕИ;Цена;Ключевые слова\r\n"
                ."{$p}NEW;Болт   М12х40;;6;10,5;болт метиз\r\n"
                ."{$p}OLD;Новое имя;кг;166;;\r\n"
                ."{$p}SAME;Шайба 12;шт.;;;\r\n");

            // «Проверить»: счётчики есть, база не тронута.
            $preview = Service_Prx400_CatalogImport::run($file, FALSE);
            $this->assertFalse($preview['applied']);
            $this->assertSame([3, 1, 1, 1, 0], [$preview['total'], $preview['created'], $preview['updated'], $preview['unchanged'], $preview['error_count']]);
            $this->assertSame(['Цена'], $preview['ignored']);
            $this->assertNull($this->row('NEW'));
            $this->assertSame('Старое имя', $this->row('OLD')['name']);

            // Применение: upsert, ведущие нули ОКЕИ, ед. изм. по умолчанию, схлопнутые пробелы.
            $applied = Service_Prx400_CatalogImport::run($file, TRUE);
            $this->assertTrue($applied['applied']);
            $this->assertSame(2, $applied['applied_rows']);
            $new = $this->row('NEW');
            $this->assertSame(['Болт М12х40', 'шт.', '006', 'болт метиз'], [$new['name'], $new['unit'], $new['okei_code'], $new['keywords']]);
            $this->assertSame(['Новое имя', 'кг', '166'], [$this->row('OLD')['name'], $this->row('OLD')['unit'], $this->row('OLD')['okei_code']]);

            // Повтор того же файла — ничего не меняется.
            $again = Service_Prx400_CatalogImport::run($file, TRUE);
            $this->assertSame([0, 0, 3, 0], [$again['created'], $again['updated'], $again['unchanged'], $again['applied_rows']]);

            // Ошибка в одной строке — файл не применяется целиком; номера строк как в файле.
            $bad = $this->csv("code;name;okei\n{$p}A;Есть\n\n{$p}B;\n{$p}A;Дубль\n{$p}C;Ок;12AB\n".str_repeat('x', 65).";Длинный код\n");
            $report = Service_Prx400_CatalogImport::run($bad, TRUE);
            $this->assertFalse($report['applied']);
            $this->assertNull($this->row('A'));
            $this->assertSame([
                [4, 'catalog_name_required', 'name'],
                [5, 'catalog_code_duplicate', 'code'],
                [6, 'catalog_okei_invalid', 'okei_code'],
                [7, 'catalog_value_too_long', 'code'],
            ], array_map(static fn (array $e) => [$e['line'], $e['code'], $e['field']], $report['errors']));
            $this->assertSame(2, $report['errors'][1]['first_line']);

            // Заголовок без обязательных столбцов и пустой файл — понятные коды.
            foreach (["артикул;цена\nA;1\n" => 'catalog_columns_missing', "код;код;наименование\n" => 'catalog_columns_duplicate',
                      "код;наименование\n" => 'catalog_spreadsheet_empty', '' => 'catalog_spreadsheet_empty'] as $text => $code)
            {
                try {
                    Service_Prx400_CatalogImport::run($this->csv($text), FALSE);
                    $this->fail('ожидалась ошибка '.$code);
                } catch (Prx400_Error $e) {
                    $this->assertSame($code, $e->getMessage());
                }
            }

            // Выгрузка принимается импортом обратно без изменений (включая экран формул).
            Model_Prx400_Catalog::insert(['code' => $p.'FORMULA', 'name' => '=HYPERLINK("x")', 'unit' => 'шт.', 'okei_code' => '', 'keywords' => '@tag; "кавычки"']);
            $export = Service_Prx400_CatalogImport::export_csv();
            $this->assertStringContainsString("'=HYPERLINK", $export);
            $roundtrip = Service_Prx400_CatalogImport::run($this->csv($export), FALSE);
            $this->assertSame(0, $roundtrip['error_count']);
            $this->assertSame(0, $roundtrip['created'] + $roundtrip['updated']);
            $this->assertSame($roundtrip['total'], $roundtrip['unchanged']);

            // Поиск по странице справочника: подстрока, % и _ — буквально.
            $page = Model_Prx400_Catalog::page($p, 10, 0);
            $this->assertSame(4, $page['total']);
            $this->assertSame(0, Model_Prx400_Catalog::page($p.'%', 10, 0)['total']);

            // Права: без prx400.run импорт закрыт до чтения файла.
            try {
                Service_Prx400_CatalogImport::from_upload(new Prx_Actor(1, ['login']), ['error' => UPLOAD_ERR_OK, 'tmp_name' => $file], TRUE);
                $this->fail('ожидался forbidden');
            } catch (Prx400_Error $e) {
                $this->assertSame('forbidden', $e->getMessage());
            }
        }
        finally
        {
            Prx_Db::execute('DELETE FROM prx_400_catalog WHERE code LIKE :p', [':p' => $this->prefix.'%']);
            foreach ($this->files as $f) @unlink($f);
        }
    }
}
