<?php

use PHPUnit\Framework\TestCase;

/**
 * Спрос агентов: нормализация запросов (nj676_mcphub).
 *
 * Зачем тест. По журналу запросов каталог сам решает, что искать дальше:
 * запрос без ответа становится темой автосборки, а повтор одной темы —
 * потраченным прогоном. Поэтому «Погода!», «погода» и «  Погода  » обязаны
 * сходиться в одну строку, а «курс валют» и «курс валют цб» — оставаться
 * разными задачами: чрезмерная нормализация склеит разные запросы и соберёт
 * каталог не под тот спрос.
 *
 * Проверяем только чистую функцию: SQL-часть живёт на стенде (там есть
 * заглушка базы) и проверяется в .tools/nj676_mcphub_stand.php.
 */
class McpDemandTest extends TestCase {

    public function test_case_and_punctuation_are_normalized()
    {
        $this->assertSame('погода', Service_Nj676_Demand::norm('  Погода!  '));
        $this->assertSame('курс валют цб', Service_Nj676_Demand::norm('Курс   ВАЛЮТ ЦБ'));
        $this->assertSame('погода', Service_Nj676_Demand::norm('«погода»'));
    }

    public function test_yo_and_quotes_do_not_split_the_same_demand()
    {
        // Агенты пишут «ё» и «е» вперемешку: без склейки одна тема собиралась
        // бы дважды, а половина спроса терялась.
        $this->assertSame(Service_Nj676_Demand::norm('Ёжик в тумане'), Service_Nj676_Demand::norm('ежик в тумане'));
    }

    public function test_technical_names_survive()
    {
        // Точка, плюс, решётка и дефис значимы: node.js и c++ — разные запросы,
        // и выкидывать из них символы нельзя.
        $this->assertSame('node.js', Service_Nj676_Demand::norm('Node.js'));
        $this->assertSame('c++', Service_Nj676_Demand::norm('C++'));
        $this->assertSame('c#', Service_Nj676_Demand::norm('C#'));
        $this->assertSame('open-meteo', Service_Nj676_Demand::norm('Open-Meteo'));
    }

    public function test_different_tasks_are_not_merged()
    {
        $this->assertTrue(
            Service_Nj676_Demand::norm('курс валют') !== Service_Nj676_Demand::norm('курс валют цб'),
            'разные задачи склеились в одну тему автосборки'
        );
    }

    public function test_empty_and_noise_become_empty_string()
    {
        $this->assertSame('', Service_Nj676_Demand::norm(''));
        $this->assertSame('', Service_Nj676_Demand::norm('   '));
        $this->assertSame('', Service_Nj676_Demand::norm('!!! ??? ...'));
        $this->assertSame('', Service_Nj676_Demand::norm(NULL));
    }

    public function test_result_is_capped()
    {
        $long = Service_Nj676_Demand::norm(str_repeat('очень длинный запрос ', 40));

        $this->assertTrue(mb_strlen($long, 'UTF-8') <= 200, 'нормализованный запрос длиннее 200 символов');
    }
}
