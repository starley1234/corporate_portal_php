<?php

use PHPUnit\Framework\TestCase;

class UnitsServiceTest extends TestCase {

    public function test_metric_and_traditional_conversions()
    {
        $result = Service_Nj506_Units::calculate('length', '1', 'km', 'm');
        $this->assertSame(1000.0, $result['converted']);
        $this->assertSame(100.0, Service_Nj506_Units::calculate('area', 1, 'ha', 'are')['converted']);
        $this->assertSame(10000.0, Service_Nj506_Units::calculate('area', 1, 'ha', 'm2')['converted']);
        $this->assertEqualsWithDelta(16.3804964, Service_Nj506_Units::calculate('mass', 1, 'pud', 'kg')['converted'], 0.0000001);
        $this->assertEqualsWithDelta(1066.8, Service_Nj506_Units::calculate('length', 1, 'verst', 'm')['converted'], 0.000001);
    }

    public function test_temperature_uses_affine_conversion()
    {
        $this->assertEqualsWithDelta(0, Service_Nj506_Units::calculate('temperature', 32, 'f', 'c')['converted'], 0.000001);
        $this->assertEqualsWithDelta(100, Service_Nj506_Units::calculate('temperature', 212, 'f', 'c')['converted'], 0.000001);
        $this->assertEqualsWithDelta(273.15, Service_Nj506_Units::calculate('temperature', 0, 'c', 'k')['converted'], 0.000001);
        $this->assertNull(Service_Nj506_Units::calculate('temperature', -274, 'c', 'k'));
    }

    public function test_number_parsing_and_bounds()
    {
        $this->assertSame(1234.5, Service_Nj506_Units::parse_number('1 234,5'));
        $this->assertSame(-7.25, Service_Nj506_Units::parse_number('−7,25'));
        $this->assertNull(Service_Nj506_Units::parse_number('1,2,3'));
        $this->assertNull(Service_Nj506_Units::parse_number(['2']));
        $this->assertNull(Service_Nj506_Units::calculate('length', -1, 'm', 'cm'));
        $this->assertNull(Service_Nj506_Units::calculate('unknown', 1, 'm', 'cm'));
        $this->assertNull(Service_Nj506_Units::calculate('length', 1, 'm', 'kg'));
    }

    public function test_every_category_has_units_and_examples()
    {
        foreach (Service_Nj506_Units::catalog() as $category => $definition)
        {
            $this->assertNotEmpty($definition['units'], $category.' units');
            $this->assertNotEmpty($definition['examples'], $category.' examples');
            foreach ($definition['examples'] as $example)
            {
                $this->assertNotNull(Service_Nj506_Units::calculate($category, $example['value'], $example['from'], $example['to']), $category.' '.$example['label']);
            }
        }
    }
}
