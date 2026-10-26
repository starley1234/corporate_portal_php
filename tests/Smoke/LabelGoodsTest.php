<?php

use PHPUnit\Framework\TestCase;

/**
 * Дым на живой БД: товары не двоятся, а очередь чистится (nj646_label).
 *
 * Запуск: php tests/mini_runner.php LabelGoods
 *
 * Повод — живой стенд 03.10.2026. Ключ товара считается от названия, и после
 * правки названия руками (или после того, как модель подставила настоящее имя
 * вместо рабочего) СЛЕДУЮЩИЙ разбор той же этикетки заводил ВТОРОЙ товар:
 * разборы, счётчик встреч и пищевая ценность оставались на первом, а человек
 * видел дубль и шёл чинить его «слиянием». Лечится псевдонимами ключа
 * (`key_aliases`), и проверять это надо на настоящей базе: тут и jsonb-поиск
 * `@>`, и уникальность ключа.
 *
 * Вторая половина — чистка очереди незнакомых от служебных строк этикетки:
 * удаление необратимо, поэтому проверяем, что удаляется ровно мусор и что
 * изученные строки (по ним есть карточка) остаются на месте.
 */
class LabelGoodsTest extends TestCase
{
    /** @var array созданные строки — для уборки */
    private $products = [];
    private $producers = [];
    private $unknown_names = [];
    private $ingredients = [];

    protected function tearDown(): void
    {
        $db = Database::instance();

        foreach ($this->products as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_products WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->producers as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_producers WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->unknown_names as $name)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_unknowns WHERE name_raw = '
                .$db->quote((string) $name), FALSE);
        }

        foreach ($this->ingredients as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_ingredients WHERE id = '.(int) $id, FALSE);
        }

        $this->products = [];
        $this->producers = [];
        $this->unknown_names = [];
        $this->ingredients = [];
    }

    private function uniq($prefix = 'тест')
    {
        return $prefix.'-'.bin2hex(random_bytes(4));
    }

    /** Товар, заведённый обычным путём разбора этикетки. */
    private function make_product(array $data)
    {
        $product = Service_Nj646_Store::product_upsert($data);

        $this->assertNotNull($product, 'Товар должен завестись');
        $this->products[] = (int) $product['id'];

        return $product;
    }

    public function test_renamed_product_is_found_again_instead_of_doubling()
    {
        $name = $this->uniq('Печенье овсяное');

        $first = $this->make_product(['name' => $name, 'kind' => 'food', 'product_kind' => 'cookies']);

        // Админ правит название (так делает человек в /nj646/admin/goods).
        $nicer = $name.' «Доброе утро»';
        $this->assertTrue(Service_Nj646_Store::product_save((int) $first['id'], ['name' => $nicer]));

        $renamed = Service_Nj646_Store::product_get((int) $first['id']);

        $this->assertSame($nicer, (string) $renamed['name']);
        $this->assertNotSame((string) $first['key'], (string) $renamed['key'], 'Ключ обязан пересчитаться');
        $this->assertContains((string) $first['key'], (array) $renamed['key_aliases'],
            'Прежний ключ должен остаться псевдонимом');

        // А теперь — ТОТ ЖЕ разбор, что и в первый раз: этикетка не изменилась.
        $again = Service_Nj646_Store::product_upsert(['name' => $name, 'kind' => 'food', 'product_kind' => 'cookies']);

        $this->assertSame((int) $first['id'], (int) $again['id'],
            'Разбор по старой этикетке обязан попасть в тот же товар, а не завести дубль');
        $this->assertSame($nicer, (string) $again['name'], 'Правка человека не откатывается');

        $count = Database::instance()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_646_products WHERE name LIKE '
            .Database::instance()->quote($name.'%'), FALSE)->current();

        $this->assertSame(1, (int) $count['c'], 'Товар должен остаться ровно один');
    }

    public function test_real_name_from_model_keeps_statistics_on_the_same_card()
    {
        // Рабочее имя («<Тип> без названия») + производитель в ключе — ровно
        // то, что заводит разбор фото, пока модель не прочитала название.
        $producer = Service_Nj646_Store::producer_upsert(['name' => $this->uniq('ООО Ромашка')], []);
        $this->producers[] = (int) $producer['id'];

        $auto = $this->uniq('Печенье и крекер без названия');
        $first = Service_Nj646_Store::product_upsert(
            ['name' => $auto, 'name_auto' => TRUE, 'kind' => 'food'], (int) $producer['id']);

        $this->products[] = (int) $first['id'];

        // Модель прочитала название (задача `title` → analysis_title_set →
        // product_name_from_model): карточка переименовывается на месте.
        $real = $this->uniq('Печенье «Юбилейное»');

        $this->assertTrue(Service_Nj646_Store::product_name_from_model((int) $first['id'], $real));

        $updated = Service_Nj646_Store::product_get((int) $first['id']);

        $this->assertSame((int) $first['id'], (int) $updated['id'], 'Это тот же товар, а не новый');
        $this->assertSame($real, (string) $updated['name']);

        // Разбор этикетки, где название уже читается, тоже попадает сюда.
        $same = Service_Nj646_Store::product_upsert(
            ['name' => $real, 'kind' => 'food'], (int) $producer['id']);

        $this->assertSame((int) $first['id'], (int) $same['id'], 'Настоящее имя ведёт в ту же карточку');
        $this->assertFalse(Service_Nj646_Store::flag($updated['name_auto']), 'Пометка «имя рабочее» снимается');

        // И следующий разбор ПО СТАРОЙ этикетке (имени на ней всё ещё нет)
        // снова попадает в ту же карточку — за это и отвечают псевдонимы.
        $again = Service_Nj646_Store::product_upsert(
            ['name' => $auto, 'name_auto' => TRUE, 'kind' => 'food'], (int) $producer['id']);

        $this->assertSame((int) $first['id'], (int) $again['id']);
    }

    public function test_nutrition_survives_rename_and_manual_edit()
    {
        $name = $this->uniq('Йогурт');

        $product = $this->make_product([
            'name' => $name, 'kind' => 'food',
            'nutrition' => ['basis' => '100g', 'values' => ['kcal' => 90, 'protein' => 4.2]],
        ]);

        $this->assertSame(90.0, $product['nutrition']['values']['kcal']);
        $this->assertSame('label', (string) $product['nutrition_source']);

        // Человек поправил цифры руками — этикетка их больше не перетирает.
        Service_Nj646_Store::product_nutrition_save((int) $product['id'],
            ['basis' => '100g', 'values' => ['kcal' => 95, 'protein' => 5]], 'manual');

        Service_Nj646_Store::product_upsert([
            'name' => $name, 'kind' => 'food',
            'nutrition' => ['basis' => '100g', 'values' => ['kcal' => 120, 'fat' => 3.2]],
        ]);

        $after = Service_Nj646_Store::product_get((int) $product['id']);

        $this->assertSame('manual', (string) $after['nutrition_source']);
        $this->assertSame(95.0, $after['nutrition']['values']['kcal'], 'Ручная цифра остаётся ручной');
        $this->assertFalse(isset($after['nutrition']['values']['fat']),
            'Этикетка не дописывает поля в таблицу, заполненную руками');

        // Пустая форма = «вернуть автоматический разбор».
        Service_Nj646_Store::product_nutrition_save((int) $product['id'], []);
        $cleared = Service_Nj646_Store::product_get((int) $product['id']);

        $this->assertSame([], $cleared['nutrition']);
        $this->assertSame('', (string) $cleared['nutrition_source']);
    }

    public function test_junk_cleanup_removes_label_prose_and_keeps_substances()
    {
        // Уникальность берём из самих цифр: приписать к строке случайный
        // «хвост» нельзя — он и сделает её непохожей на мусор.
        $junk  = random_int(100, 999).',' .random_int(10, 99).' г'; // число с единицей
        $gost  = 'ГОСТ 24901-'.random_int(1000, 9999);  // служебная строка
        $good  = $this->uniq('экстракт полезного');     // настоящее вещество

        foreach ([$junk, $gost, $good] as $name)
        {
            Service_Nj646_Store::unknown_touch($name, 'food', 1);
            $this->unknown_names[] = $name;
        }

        $found = [];

        foreach (Service_Nj646_Store::unknowns_junk(500) as $row)
        {
            $found[(string) $row['name']] = (int) $row['id'];
        }

        $this->assertArrayHasKey($junk, $found, 'Число с единицей — мусор');
        $this->assertArrayHasKey($gost, $found, 'ГОСТ — мусор');
        $this->assertArrayNotHasKey($good, $found, 'Настоящее вещество в находки попадать не должно');

        $gone = Service_Nj646_Store::unknowns_delete([$found[$junk], $found[$gost]]);

        $this->assertSame(2, $gone);

        $left = [];

        foreach (Service_Nj646_Store::unknowns(NULL, 100) as $row)
        {
            $left[] = (string) $row['name_raw'];
        }

        $this->assertNotContains($junk, $left);
        $this->assertContains($good, $left, 'Вещество осталось в очереди');
    }
}
