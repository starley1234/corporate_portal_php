<?php

use PHPUnit\Framework\TestCase;

class SizeServiceTest extends TestCase {

    public function test_clothing_sizes_from_chest_measure()
    {
        $women = Service_Nj511_Size::calculate('women', 92);
        $this->assertSame('46', $women['sizes']['RU']);
        $this->assertSame('40', $women['sizes']['EU']);
        $this->assertSame('8', $women['sizes']['US']);
        $this->assertSame('100 см', $women['details']['Обхват бёдер']);

        $men = Service_Nj511_Size::calculate('men', 100);
        $this->assertSame('50', $men['sizes']['RU']);
        $this->assertSame('40', $men['sizes']['US']);
    }

    public function test_children_shoes_gloves_and_ring_tables()
    {
        $this->assertSame('122', Service_Nj511_Size::calculate('children', 121)['sizes']['Ростовой размер']);
        $this->assertSame('40', Service_Nj511_Size::calculate('shoes', '25,5')['sizes']['EU / Россия (ориентир)']);
        $this->assertSame('8', Service_Nj511_Size::calculate('gloves', 20.3)['sizes']['Числовой размер']);
        $this->assertSame('7', Service_Nj511_Size::calculate('rings', 17.5)['sizes']['US (примерно)']);
    }

    public function test_nearest_row_is_reported_and_input_is_bounded()
    {
        $result = Service_Nj511_Size::calculate('women', 93);
        $this->assertSame(92.0, $result['matched_measure']);
        $this->assertSame(1.0, $result['difference']);
        $this->assertNull(Service_Nj511_Size::calculate('nope', 90));
        $this->assertNull(Service_Nj511_Size::calculate('women', 0));
        $this->assertNull(Service_Nj511_Size::calculate('women', 'abc'));
        $this->assertNull(Service_Nj511_Size::calculate('women', 400));
    }

    public function test_every_table_row_has_a_conversion()
    {
        foreach (Service_Nj511_Size::categories() as $category => $definition)
        {
            $this->assertNotEmpty($definition['rows'], $category.' table');
            foreach ($definition['rows'] as $row)
            {
                $result = Service_Nj511_Size::calculate($category, $row['measure']);
                $this->assertNotNull($result, $category.' value '.$row['measure']);
                $this->assertNotEmpty($result['sizes']);
            }
        }
    }
}
