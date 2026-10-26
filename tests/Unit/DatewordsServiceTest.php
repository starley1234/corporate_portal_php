<?php

use PHPUnit\Framework\TestCase;

class DatewordsServiceTest extends TestCase {

    public function test_single_date_words_and_document_formats()
    {
        $result = Service_Nj513_Datewords::convert('2026-09-01');
        $this->assertSame('первого сентября две тысячи двадцать шестого года', $result['long']);
        $this->assertSame('«01» сентября 2026 г.', $result['quoted']);
        $this->assertSame('01.09.2026', $result['numeric']);
        $this->assertSame('пятого мая две тысячи года', Service_Nj513_Datewords::convert('2000-05-05')['long']);
    }

    public function test_dates_and_years_are_validated()
    {
        $this->assertNotNull(Service_Nj513_Datewords::convert('29.02.2024'));
        $this->assertNull(Service_Nj513_Datewords::convert('29.02.2025'));
        $this->assertNull(Service_Nj513_Datewords::convert('2026-13-01'));
        $this->assertNull(Service_Nj513_Datewords::convert('31.04.2026'));
        $this->assertNull(Service_Nj513_Datewords::convert('0000-01-01'));
        $this->assertSame('девятьсот девяносто девятого', Service_Nj513_Datewords::year_words(999));
    }

    public function test_ranges_use_short_form_in_same_month()
    {
        $same_month = Service_Nj513_Datewords::range('2026-09-01', '2026-09-05');
        $this->assertSame('с первого по пятое сентября две тысячи двадцать шестого года', $same_month['long']);
        $this->assertSame('с тридцать первого декабря две тысячи двадцать шестого года по второе января две тысячи двадцать седьмого года', Service_Nj513_Datewords::range('2026-12-31', '2027-01-02')['long']);
        $this->assertNull(Service_Nj513_Datewords::range('2026-09-10', '2026-09-01'));
    }

    public function test_day_forms_and_parser()
    {
        $this->assertSame('двадцать первого', Service_Nj513_Datewords::day_words(21));
        $this->assertSame('двадцать первое', Service_Nj513_Datewords::day_words(21, 'accusative'));
        $this->assertSame('тридцать первого', Service_Nj513_Datewords::day_words(31));
        $this->assertSame(['iso' => '2026-09-01', 'year' => 2026, 'month' => 9, 'day' => 1], Service_Nj513_Datewords::parse_date('1.9.2026'));
    }
}
