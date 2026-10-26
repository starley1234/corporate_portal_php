<?php

use PHPUnit\Framework\TestCase;

/**
 * Чистая логика сервиса «Разбор состава» (nj646_label): разбор строки состава,
 * E-коды, сопоставление с базой, скоринг, советы. База и ИИ не нужны —
 * Service_Nj646_Label специально построен без запросов (см. класс).
 */
class LabelServiceTest extends TestCase {

    private function card(array $over = [])
    {
        return array_merge([
            'slug'      => 'sugar',
            'name_ru'   => 'Сахар',
            'name_int'  => 'Sugar',
            'kind'      => 'food',
            'risk'      => 2,
            'verdict'   => 'caution',
            'category'  => 'сахар и сиропы',
            'e_code'    => '',
            'summary'   => '',
            'details'   => '',
            'synonyms'  => [],
            'avoid'     => [],
            'sources'   => [],
            'source'    => 'seed',
            'status'    => 'published',
            'hits'      => 0,
        ], $over);
    }

    /** Индекс вида, который строит Store::index(). */
    private function index()
    {
        $sugar = $this->card(['slug' => 'sugar', 'name_ru' => 'Сахар', 'keys' => ['сахар', 'sugar', 'сахароза']]);
        $e211  = $this->card(['slug' => 'e211', 'name_ru' => 'Бензоат натрия', 'e_code' => 'E211',
            'risk' => 3, 'verdict' => 'risky', 'category' => 'консервант', 'keys' => ['бензоат натрия', 'e211']]);
        $water = $this->card(['slug' => 'aqua', 'name_ru' => 'Вода', 'kind' => 'cosmetic', 'risk' => 0,
            'verdict' => 'useful', 'category' => 'основа', 'keys' => ['вода', 'aqua', 'water']]);

        return [
            'rows' => ['sugar' => $sugar, 'e211' => $e211, 'aqua' => $water],
            'map'  => ['сахар' => 'sugar', 'sugar' => 'sugar', 'сахароза' => 'sugar',
                       'бензоат натрия' => 'e211', 'e211' => 'e211',
                       'вода' => 'aqua', 'aqua' => 'aqua', 'water' => 'aqua'],
        ];
    }

    public function test_parse_splits_items_and_strips_prefix()
    {
        $items = Service_Nj646_Label::parse('Состав: сахар, соль, вода.');
        $names = array_column($items, 'name');

        $this->assertSame(['сахар', 'соль', 'вода'], $names);
        $this->assertCount(3, $items);
    }

    public function test_parse_handles_percent_parentheses_and_multibyte_tail()
    {
        $items = Service_Nj646_Label::parse('вода (Aqua), глицерин 5%, экстракт лаванды');

        $this->assertSame('вода', $items[0]['name']);
        $this->assertSame(['Aqua'], $items[0]['syn']);
        $this->assertNull($items[0]['percent']);

        $this->assertSame('глицерин', $items[1]['name']);
        $this->assertSame(5.0, $items[1]['percent']);

        // Последний multibyte-символ не должен портиться (баг байтового trim).
        $this->assertSame('экстракт лаванды', $items[2]['name']);
        $this->assertSame('лаванды', mb_substr($items[2]['name'], -7));
    }

    public function test_parse_skips_service_words()
    {
        $items = Service_Nj646_Label::parse("сахар, и др.\nсоль; прочее");
        $this->assertSame(['сахар', 'соль'], array_column($items, 'name'));
    }

    public function test_normalize_and_ecode()
    {
        $this->assertSame('бензоат натрия', Service_Nj646_Label::normalize('Бензоат  Натрия'));
        $this->assertSame('е 211', Service_Nj646_Label::normalize('Е—211'));
        $this->assertSame('e211', Service_Nj646_Label::ecode('E 211'));
        $this->assertSame('e211', Service_Nj646_Label::ecode('Е211'));
        $this->assertSame('e150d', Service_Nj646_Label::ecode('краситель INS 150d'));
        $this->assertSame('', Service_Nj646_Label::ecode('сахар'));
    }

    public function test_find_prefers_e_code_then_exact_then_synonym()
    {
        $index = $this->index();

        $this->assertSame('e-code', Service_Nj646_Label::find('консервант Е211', $index)['how']);
        $this->assertSame('sugar', Service_Nj646_Label::find('САХАР', $index)['slug']);
        $this->assertSame('sugar', Service_Nj646_Label::find('сахароза', $index)['slug']);
        $this->assertNull(Service_Nj646_Label::find('невиданное вещество', $index));
    }

    public function test_match_separates_known_and_unknown()
    {
        $parsed = Service_Nj646_Label::parse('Состав: сахар, Е211, вода, загадочный экстракт.');
        $out    = Service_Nj646_Label::match($parsed, $this->index());

        $this->assertCount(3, $out['matched']);
        $this->assertSame(['загадочный экстракт'], $out['unknown']);

        $slugs = array_column($out['matched'], 'slug');
        $this->assertContains('sugar', $slugs);
        $this->assertContains('e211', $slugs);
        $this->assertContains('aqua', $slugs);
    }

    public function test_score_penalises_risk_and_unknown()
    {
        $safe = Service_Nj646_Label::score([$this->card(['risk' => 0, 'verdict' => 'useful'])]);
        $this->assertSame(100, $safe['score']);
        $this->assertSame('useful', $safe['verdict']);

        // Штраф за средний риск 4.0 — 5.0 × 18 = 90 баллов.
        $risky = Service_Nj646_Label::score([$this->card(['risk' => 4, 'verdict' => 'avoid'])]);
        $this->assertSame(10, $risky['score']);
        $this->assertSame('avoid', $risky['verdict']);

        $mixed = Service_Nj646_Label::score([
            $this->card(['risk' => 0, 'verdict' => 'useful']),
            $this->card(['risk' => 4, 'verdict' => 'avoid']),
        ], ['неизвестное 1', 'неизвестное 2']);

        // Средний риск 2.5 и половина состава неизвестна — между крайностями.
        $this->assertGreaterThan($risky['score'], $mixed['score']);
        $this->assertLessThan($safe['score'], $mixed['score']);
        $this->assertSame(1, $mixed['counts']['useful']);
        $this->assertSame(1, $mixed['counts']['avoid']);

        $empty = Service_Nj646_Label::score([], []);
        $this->assertSame(100, $empty['score']);
        $this->assertSame('useful', $empty['verdict']);
    }

    public function test_labels_and_kind_guess()
    {
        $this->assertSame('полезно', Service_Nj646_Label::verdict_label('useful'));
        $this->assertSame('избегать', Service_Nj646_Label::verdict_label('avoid'));
        $this->assertSame('спокойный состав', Service_Nj646_Label::score_label(90));
        $this->assertSame('умеренный состав', Service_Nj646_Label::score_label(60));
        $this->assertSame('лучше избегать', Service_Nj646_Label::score_label(10));

        $this->assertSame('food', Service_Nj646_Label::kind_guess('Состав: сахар, Е211, ароматизатор'));
        $this->assertSame('cosmetic', Service_Nj646_Label::kind_guess('Aqua, Glycerin, Sodium Laureth Sulfate'));
    }

    public function test_advice_uses_profile_and_risky_items()
    {
        $reasons = ['allergy' => 'у вас отмечена аллергия на этот ингредиент'];

        $matched = [
            $this->card(['slug' => 'milk', 'name_ru' => 'Молоко', 'risk' => 1, 'verdict' => 'neutral',
                'category' => 'молочное', 'avoid' => ['allergy'], 'keys' => ['молоко']]),
            $this->card(['slug' => 'e211', 'name_ru' => 'Бензоат натрия', 'risk' => 3, 'verdict' => 'risky',
                'e_code' => 'E211', 'category' => 'консервант', 'keys' => ['бензоат натрия']]),
        ];

        $advice = Service_Nj646_Label::advice($matched, ['неизвестное'], ['conditions' => ['allergy']], $reasons);
        $levels = array_column($advice, 'level');

        $this->assertContains('danger', $levels);
        $this->assertContains('warn', $levels);

        $danger_index = array_search('danger', $levels, TRUE);
        $this->assertStringContainsString('Молоко', $advice[$danger_index]['text']);

        $sugar_first = Service_Nj646_Label::advice(
            [$this->card(['risk' => 2, 'verdict' => 'caution'])], [], [], []
        );

        $this->assertSame('Сахар в начале списка', $sugar_first[0]['title']);
    }

    public function test_image_helpers()
    {
        $this->assertTrue(Service_Nj646_Label::looks_like_image("\xFF\xD8\xFF\xE0" . str_repeat('x', 32)));
        $this->assertTrue(Service_Nj646_Label::looks_like_image("\x89PNG\r\n\x1a\n" . str_repeat('x', 32)));
        $this->assertFalse(Service_Nj646_Label::looks_like_image('<html>'));

        $this->assertSame('image/jpeg', Service_Nj646_Label::mime("\xFF\xD8\xFF\xE0" . str_repeat('x', 32)));
        $this->assertSame('image/png', Service_Nj646_Label::mime("\x89PNG\r\n\x1a\n" . str_repeat('x', 32)));
    }

    public function test_words_drop_short_and_stopwords()
    {
        $words = Service_Nj646_Label::words('экстракт алоэ вера и сок');

        // «экстракт» — стоп-слово, «и»/«сок» — короче четырёх символов.
        $this->assertSame(['алоэ', 'вера'], $words);
    }

    // ------------------------------------------------------------------
    // Из текста упаковки разбирается только блок состава
    // ------------------------------------------------------------------

    public function test_composition_block_cuts_label_prose()
    {
        $label = "Печенье овсяное «Доброе утро». Изготовитель: ООО «Хлебный дом», Россия.\n"
            ."Состав: мука пшеничная, сахар, масло подсолнечное, соль.\n"
            ."Пищевая ценность на 100 г: белки 7,5 г, жиры 24 г.\n"
            ."Масса нетто 300 г. ГОСТ 24901-2014.";

        $block = Service_Nj646_Label::composition_block($label);

        $this->assertSame('мука пшеничная, сахар, масло подсолнечное, соль', $block);

        // Главное — чем это кончается: в «неизвестные» (а оттуда в очередь
        // изучения моделью) больше не попадают «5 г», «ГОСТ 24901-2014».
        $names = array_column(Service_Nj646_Label::parse($block), 'name');

        $this->assertSame(['мука пшеничная', 'сахар', 'масло подсолнечное', 'соль'], $names);
    }

    public function test_composition_block_keeps_plain_list_as_is()
    {
        $this->assertSame('сахар, соль, вода',
            Service_Nj646_Label::composition_block('сахар, соль, вода'));

        // Текст без состава, зато с таблицей — отдаём как есть: пустой разбор
        // человеку не поможет.
        $only_table = 'Пищевая ценность на 100 г: белки 7,5 г';
        $this->assertSame($only_table, Service_Nj646_Label::composition_block($only_table));
    }

    public function test_composition_block_handles_english_and_gost_without_number()
    {
        $this->assertSame('Aqua, Glycerin, Panthenol', Service_Nj646_Label::composition_block(
            "PAMPAS Shampoo\nIngredients: Aqua, Glycerin, Panthenol\nNet weight 170 ml"));

        // «ту» внутри слова не должно обрывать состав.
        $this->assertSame('экстракт туи, вода',
            Service_Nj646_Label::composition_block('Состав: экстракт туи, вода'));
    }

    public function test_service_lines_are_recognised_as_junk()
    {
        // Ровно то, что живой разбор сложил в очередь изучения до
        // composition_block() — каждая такая строка стоила бы запроса к модели.
        foreach (['5 г', '0,4', '2014', 'соль 0', 'белки 7', 'углеводы 58 г',
                  'ГОСТ 24901-2014', 'Масса нетто 300 г', 'Изготовитель: ООО «Хлебный дом»',
                  'Срок годности 12 месяцев', 'тел. 8 800 100 20 30', 'www.example.ru',
                  '4 г. Энергетическая ценность 480 ккал', 'Условия хранения: при температуре не выше 18',
                  ''] as $junk)
        {
            $this->assertTrue(Service_Nj646_Label::looks_like_service($junk),
                'должно считаться мусором: '.$junk);
        }
    }

    public function test_real_ingredients_survive_the_junk_filter()
    {
        // Ошибочное удаление дороже пропуска: вещество из очереди не вернуть.
        foreach (['вода', 'сахар', 'Е211', 'масло подсолнечное', 'патока',
                  'экстракт листьев гинкго билоба', 'кокамидопропил бетаин',
                  'лаурет сульфат натрия', 'соль морская', 'мука пшеничная высшего сорта',
                  'натрия бензоат', 'вода (Aqua)', 'молочная кислота'] as $name)
        {
            $this->assertFalse(Service_Nj646_Label::looks_like_service($name),
                'настоящее вещество удалять нельзя: '.$name);
        }
    }

    // ------------------------------------------------------------------
    // Пищевая ценность: разбор этикетки без модели (Service_Nj646_About)
    // ------------------------------------------------------------------

    public function test_nutrition_reads_russian_table()
    {
        $text = "Печенье овсяное\n"
            ."Состав: мука пшеничная, сахар, масло подсолнечное.\n"
            ."Пищевая ценность на 100 г: белки 7,5 г, жиры 24,0 г, "
            ."в том числе насыщенные жирные кислоты 10 г, углеводы 58 г, "
            ."в том числе сахара 22 г, пищевые волокна 3,2 г, соль 0,4 г.\n"
            ."Энергетическая ценность 480 ккал / 2010 кДж.";

        $n = Service_Nj646_About::nutrition($text);

        $this->assertSame('100g', $n['basis']);
        $this->assertSame(7.5, $n['values']['protein']);
        $this->assertSame(24.0, $n['values']['fat']);
        // Частное вперёд общего: «в том числе насыщенные» не должно утечь в «жиры».
        $this->assertSame(10.0, $n['values']['fat_sat']);
        $this->assertSame(58.0, $n['values']['carbs']);
        $this->assertSame(22.0, $n['values']['sugars']);
        $this->assertSame(3.2, $n['values']['fiber']);
        $this->assertSame(0.4, $n['values']['salt']);
        $this->assertSame(480.0, $n['values']['kcal']);
        $this->assertSame(2010.0, $n['values']['kj']);
    }

    public function test_nutrition_reads_english_table_and_millilitres()
    {
        $n = Service_Nj646_About::nutrition(
            'Nutrition per 100 ml: Energy 42 kcal, Fat 1,5 g, of which saturates 0,9 g, '
            .'Carbohydrate 4,8 g, of which sugars 4,8 g, Protein 3,0 g, Salt 0,1 g');

        $this->assertSame('100ml', $n['basis']);
        $this->assertSame(42.0, $n['values']['kcal']);
        $this->assertSame(1.5, $n['values']['fat']);
        $this->assertSame(0.9, $n['values']['fat_sat']);
        $this->assertSame(3.0, $n['values']['protein']);
    }

    public function test_nutrition_needs_anchor_and_skips_foreign_numbers()
    {
        // Ни одного слова о пищевой ценности — числа этикетки трогать нельзя
        // (масса, ГОСТ, телефон превратились бы в «белки»).
        $this->assertSame([], Service_Nj646_About::nutrition(
            'Масса нетто 100 г. ГОСТ 24901-2014. Тел. 8 800 100 20 30'));

        // Колонка «белки/жиры/углеводы — 7,5/24/58»: между названием и числом
        // стоят ЧУЖИЕ названия, значит число не прочитано ни для кого.
        $n = Service_Nj646_About::nutrition('Пищевая ценность на 100 г: белки/жиры/углеводы — 7,5/24/58');
        $this->assertSame([], $n);
    }

    public function test_nutrition_drops_impossible_values_and_converts_milligrams()
    {
        $n = Service_Nj646_About::nutrition(
            'Пищевая ценность на 100 г: белки 755 г, жиры 10 г, натрий 120 мг');

        // 755 г белка в 100 г продукта не бывает — это склейка цифр при OCR.
        $this->assertFalse(isset($n['values']['protein']));
        $this->assertSame(10.0, $n['values']['fat']);
        // Миллиграммы приводятся к граммам: в базе одна единица на поле.
        $this->assertSame(0.12, $n['values']['sodium']);
    }

    public function test_nutrition_sanitize_cleans_form_input()
    {
        $clean = Service_Nj646_About::nutrition_sanitize([
            'basis'   => 'взял с потолка',
            'portion' => '  30 г  ',
            'values'  => ['protein' => ' 7,5 ', 'fat' => '', 'carbs' => 'много', 'sugars' => -3, 'kcal' => '480'],
        ]);

        $this->assertSame('100g', $clean['basis']);          // неизвестная основа → на 100 г
        $this->assertSame('30 г', $clean['portion']);
        // Порядок — как в nutrition_fields(): таблица показывается одинаково везде.
        $this->assertSame(['kcal' => 480.0, 'protein' => 7.5], $clean['values']);
        $this->assertSame([], Service_Nj646_About::nutrition_sanitize(['values' => ['fat' => '']]));
    }

    public function test_nutrition_flags_speak_with_thresholds()
    {
        $flags = Service_Nj646_About::nutrition_flags([
            'basis'  => '100g',
            'values' => ['sugars' => 45, 'salt' => 0.1, 'fat_sat' => 12],
        ]);

        $texts = array_column($flags, 'text');

        $this->assertStringContainsString('много сахаров', implode(' | ', $texts));
        $this->assertStringContainsString('мало соли', implode(' | ', $texts));
        $this->assertStringContainsString('22,5', implode(' | ', $texts), 'порог назван прямо в тексте');

        // «На порцию» порогов нет: порция у каждого своя.
        $this->assertSame([], Service_Nj646_About::nutrition_flags([
            'basis' => 'portion', 'values' => ['sugars' => 45]]));
    }

    public function test_nutrition_number_is_human_readable()
    {
        $this->assertSame('7,5', Service_Nj646_About::nutrition_number(7.5));
        $this->assertSame('24', Service_Nj646_About::nutrition_number(24.0));
        $this->assertSame('0,12', Service_Nj646_About::nutrition_number(0.12));
    }

    public function test_portion_table_is_recalculated_to_100g()
    {
        $per100 = Service_Nj646_About::nutrition_per100([
            'basis' => 'portion', 'portion' => '30 г',
            'values' => ['kcal' => 144, 'protein' => 2.25, 'sugars' => 6.6],
        ]);

        $this->assertSame('100g', $per100['basis']);
        $this->assertTrue($per100['recalculated']);
        $this->assertSame('30 г', $per100['from_portion']);
        $this->assertSame(480.0, $per100['values']['kcal']);
        $this->assertSame(7.5, $per100['values']['protein']);
        $this->assertSame(22.0, $per100['values']['sugars']);

        // Миллилитры дают основу «на 100 мл», а не «на 100 г».
        $ml = Service_Nj646_About::nutrition_per100([
            'basis' => 'portion', 'portion' => '250 мл', 'values' => ['kcal' => 105]]);

        $this->assertSame('100ml', $ml['basis']);
        $this->assertSame(42.0, $ml['values']['kcal']);
    }

    public function test_recalculation_refuses_when_portion_is_not_a_weight()
    {
        // «1 батончик» в граммы не перевести — молчим, а не выдумываем.
        $this->assertSame([], Service_Nj646_About::nutrition_per100([
            'basis' => 'portion', 'portion' => '1 батончик', 'values' => ['kcal' => 200]]));

        // Порция в 2 г — скорее опечатка, чем порция: умножать на 50 нельзя.
        $this->assertSame([], Service_Nj646_About::nutrition_per100([
            'basis' => 'portion', 'portion' => '2 г', 'values' => ['kcal' => 20]]));

        // Таблица «на 100 г» возвращается как есть и помечена как НЕ пересчитанная.
        $same = Service_Nj646_About::nutrition_per100([
            'basis' => '100g', 'values' => ['kcal' => 480]]);

        $this->assertFalse($same['recalculated']);
        $this->assertSame(480.0, $same['values']['kcal']);
    }

    // ------------------------------------------------------------------
    // Статусы по-русски (i18n модуля)
    // ------------------------------------------------------------------

    public function test_status_labels_translate_by_group()
    {
        I18n::lang('ru-ru');

        $this->assertSame('в очереди', Service_Nj646_Label::status_label('pending', 'job'));
        $this->assertSame('ошибка', Service_Nj646_Label::status_label('failed', 'job'));
        // Одно слово — разный смысл у разных сущностей.
        $this->assertSame('в очереди', Service_Nj646_Label::status_label('queued', 'run'));
        $this->assertSame('ждёт изучения', Service_Nj646_Label::status_label('queued', 'unknown'));
        $this->assertSame('черновик', Service_Nj646_Label::status_label('draft', 'card'));

        // Перевода нет — показываем сам статус, а не пустоту.
        $this->assertSame('teleported', Service_Nj646_Label::status_label('teleported', 'run'));
        $this->assertSame('', Service_Nj646_Label::status_label('', 'run'));

        $map = Service_Nj646_Label::status_labels('card');
        $this->assertSame(['published', 'draft', 'hidden'], array_keys($map));
        $this->assertSame('опубликовано', $map['published']);
    }
}
