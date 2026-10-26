<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты Service_Nj502_Words (nj502_words).
 *
 * Запуск (на машине с PHP): phpunit --filter WordsService
 * В песочнице без composer: php tests/mini_runner.php WordsService
 *
 * Контракт: rubles($amount, $mode) — $amount это СУММА В РУБЛЯХ (строка или
 * число, «1234,56» и 1234.56 равнозначны), а не копейки; второй аргумент —
 * режим вывода копеек (KOPECKS_DIGITS по умолчанию / KOPECKS_WORDS).
 * Эталоны сверены с фактическим выводом сервиса и правилами русского языка
 * (склонение «один рубль / две тысячи / двадцать одна тысяча / два миллиона»).
 */
class WordsServiceTest extends TestCase {

	// ---------- основной режим: копейки цифрами ----------

	public function test_basic()
	{
		$cases = [
			['0',             'ноль рублей 00 копеек'],
			['1',             'один рубль 00 копеек'],
			['2',             'два рубля 00 копеек'],
			['5',             'пять рублей 00 копеек'],
			['11',            'одиннадцать рублей 00 копеек'],
			['19',            'девятнадцать рублей 00 копеек'],
			['21',            'двадцать один рубль 00 копеек'],
			['22',            'двадцать два рубля 00 копеек'],
			['100',           'сто рублей 00 копеек'],
			['101',           'сто один рубль 00 копеек'],
			['111',           'сто одиннадцать рублей 00 копеек'],
			['1000',          'одна тысяча рублей 00 копеек'],
			['4000',          'четыре тысячи рублей 00 копеек'],
			['9000',          'девять тысяч рублей 00 копеек'],
			['20000',         'двадцать тысяч рублей 00 копеек'],
			['40000',         'сорок тысяч рублей 00 копеек'],
			['2212200',       'два миллиона двести двенадцать тысяч двести рублей 00 копеек'],
			['1234567.89',    'один миллион двести тридцать четыре тысячи пятьсот шестьдесят семь рублей 89 копеек'],
			['123456789.12',  'сто двадцать три миллиона четыреста пятьдесят шесть тысяч семьсот восемьдесят девять рублей 12 копеек'],
		];
		foreach ($cases as [$amount, $expected])
		{
			$this->assertSame($expected, Service_Nj502_Words::rubles($amount),
				"rubles('$amount')");
		}
	}

	public function test_thousands_feminine()
	{
		$this->assertSame('одна тысяча рублей 00 копеек',              Service_Nj502_Words::rubles('1000'));
		$this->assertSame('две тысячи рублей 00 копеек',               Service_Nj502_Words::rubles('2000'));
		$this->assertSame('пять тысяч рублей 00 копеек',               Service_Nj502_Words::rubles('5000'));
		$this->assertSame('одиннадцать тысяч рублей 00 копеек',        Service_Nj502_Words::rubles('11000'));
		$this->assertSame('двадцать одна тысяча рублей 00 копеек',     Service_Nj502_Words::rubles('21000'));
	}

	public function test_millions()
	{
		$this->assertSame('один миллион рублей 00 копеек',             Service_Nj502_Words::rubles('1000000'));
		$this->assertSame('два миллиона рублей 00 копеек',             Service_Nj502_Words::rubles('2000000'));
		$this->assertSame('пять миллионов рублей 00 копеек',           Service_Nj502_Words::rubles('5000000'));
		$this->assertSame('двадцать один миллион рублей 00 копеек',    Service_Nj502_Words::rubles('21000000'));
		$this->assertSame('ноль рублей 50 копеек',                     Service_Nj502_Words::rubles('0,50')); // 0,50 ₽
	}

	public function test_kopecks_words()
	{
		$m = Service_Nj502_Words::KOPECKS_WORDS;
		$this->assertSame('один рубль одна копейка',                   Service_Nj502_Words::rubles('1,01', $m));
		$this->assertSame('два рубля две копейки',                     Service_Nj502_Words::rubles('2,02', $m));
		$this->assertSame('пять рублей пять копеек',                   Service_Nj502_Words::rubles('5,05', $m));
		$this->assertSame('одиннадцать рублей одиннадцать копеек',     Service_Nj502_Words::rubles('11,11', $m));
		$this->assertSame('двадцать один рубль двадцать одна копейка', Service_Nj502_Words::rubles('21,21', $m));
		$this->assertSame('ноль рублей одна копейка',                  Service_Nj502_Words::rubles('0,01', $m));
	}

	public function test_convert_string_input()
	{
		// пробел-разделители и nbsp как в поле ввода
		$this->assertSame(Service_Nj502_Words::rubles('1234,56'),
			Service_Nj502_Words::rubles("1\xC2\xA0234,56"));
		$this->assertSame(Service_Nj502_Words::rubles('1234,56'),
			Service_Nj502_Words::rubles('1234.56'));
	}

	public function test_invalid()
	{
		$this->assertNull(Service_Nj502_Words::rubles(''));
		$this->assertNull(Service_Nj502_Words::rubles('abc'));
		$this->assertNull(Service_Nj502_Words::rubles('-5'));
		$this->assertNull(Service_Nj502_Words::rubles('10.123')); // 3 знака — не деньги
	}

	public function test_plural()
	{
		$this->assertSame('рубль',   Service_Nj502_Words::plural(1,   'рубль', 'рубля', 'рублей'));
		$this->assertSame('рубля',   Service_Nj502_Words::plural(2,   'рубль', 'рубля', 'рублей'));
		$this->assertSame('рубля',   Service_Nj502_Words::plural(4,   'рубль', 'рубля', 'рублей'));
		$this->assertSame('рублей',  Service_Nj502_Words::plural(5,   'рубль', 'рубля', 'рублей'));
		$this->assertSame('рублей',  Service_Nj502_Words::plural(11,  'рубль', 'рубля', 'рублей'));
		$this->assertSame('рублей',  Service_Nj502_Words::plural(12,  'рубль', 'рубля', 'рублей'));
		$this->assertSame('рублей',  Service_Nj502_Words::plural(14,  'рубль', 'рубля', 'рублей'));
		$this->assertSame('рубль',   Service_Nj502_Words::plural(21,  'рубль', 'рубля', 'рублей'));
		$this->assertSame('рублей',  Service_Nj502_Words::plural(100, 'рубль', 'рубля', 'рублей'));
		$this->assertSame('рубль',   Service_Nj502_Words::plural(101, 'рубль', 'рубля', 'рублей'));
	}
}
