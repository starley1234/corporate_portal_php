<?php
use PHPUnit\Framework\TestCase;

class ModuleHomeAliasTest extends TestCase
{
    public function test_every_connected_numbered_ui_has_a_predictable_alias(): void
    {
        $route=Route::get('module_home_alias');
        $count=0;
        foreach (Manifest::all() as $module) {
            if (empty($module['href']) || empty($module['id'])) continue;
            $id=sprintf('%03d',$module['id']);
            $match=$route->matches(Request::factory('/prx'.$id.'_index'));
            $this->assertIsArray($match);
            $this->assertSame('ModuleHome',$match['controller']);
            $this->assertSame($id,$match['module_id']);
            $count++;
        }
        $this->assertGreaterThan(15,$count);
    }

    public function test_manager_canonical_home_and_legacy_root_are_registered(): void
    {
        $match=Route::get('prx218_module_manager_home')->matches(Request::factory('/prx218_module_manager'));
        $this->assertSame('Index',$match['controller']);
        $this->assertSame('Prx218',$match['directory']);
        $legacy=Route::get('prx218_module_manager_legacy_home')->matches(Request::factory('/prx218'));
        $this->assertSame('ModuleHome',$legacy['controller']);
        $this->assertSame('218',$legacy['module_id']);
        $this->assertSame('/prx218_module_manager',Service_Prx110_Catalog::get(218)['href']);
    }
}
