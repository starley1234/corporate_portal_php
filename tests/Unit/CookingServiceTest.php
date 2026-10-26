<?php

use PHPUnit\Framework\TestCase;

class CookingServiceTest extends TestCase {

    public function test_cup_mass_ranges_use_product_density()
    {
        $flour = Service_Nj507_Cooking::calculate('wheat-flour', 'cup200', 1);
        $this->assertSame(100.0, $flour['min']);
        $this->assertSame(128.0, $flour['max']);
        $this->assertSame('100–128 г', $flour['formatted']);

        $water = Service_Nj507_Cooking::calculate('water', 'cup200', 1);
        $this->assertSame(199.4, $water['min']);
        $this->assertSame(200.0, $water['max']);
    }

    public function test_reverse_conversion_and_portion_scaling()
    {
        $result = Service_Nj507_Cooking::calculate('granulated-sugar', 'cup250', 1, 'to_mass', 2, 4);
        $this->assertSame(380.0, $result['min']);
        $this->assertSame(440.0, $result['max']);

        $reverse = Service_Nj507_Cooking::calculate('water', 'cup200', 200, 'to_measure');
        $this->assertEqualsWithDelta(1, $reverse['min'], 0.01);
        $this->assertEqualsWithDelta(1, $reverse['max'], 0.01);
        $this->assertSame('1 стакан 200 мл', $reverse['formatted']);
        $this->assertNull(Service_Nj507_Cooking::calculate('water', 'cup200', 10, 'to_mass', 0, 2));
    }

    public function test_form_factor_uses_surface_area()
    {
        $result = Service_Nj507_Cooking::form_factor('round', 24, 0, 'square', 20, 0);
        $this->assertSame(0.8842, $result['factor']);
        $this->assertSame('уменьшить', $result['change']);
        $this->assertNull(Service_Nj507_Cooking::form_factor('round', 0, 0, 'square', 20, 0));
        $this->assertNull(Service_Nj507_Cooking::form_factor('triangle', 24, 0, 'square', 20, 0));
    }

    public function test_catalog_has_product_and_measure_data()
    {
        $this->assertGreaterThan(30, count(Service_Nj507_Cooking::products()));
        $this->assertCount(6, Service_Nj507_Cooking::measures());
        foreach (Service_Nj507_Cooking::products() as $slug => $product)
        {
            $this->assertTrue($product['density'][0] > 0 AND $product['density'][0] <= $product['density'][1], $slug.' density range');
            $this->assertNotNull(Service_Nj507_Cooking::calculate($slug, 'cup200', 1), $slug.' sample');
        }
    }
}
