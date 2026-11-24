<?php
/** Every connected module with a page must declare its real entry in its own manifest. */
if (!defined('SYSPATH')) define('SYSPATH',dirname(__DIR__,2).'/system/');
define('APPPATH',dirname(__DIR__,2).'/application/');
define('MODPATH',dirname(__DIR__,2).'/modules/');
class Manifest {
 static function all() {
  $modules=require APPPATH.'config/modules.php';
  foreach (['prx390_intake','prx391_documents','prx392_analysis','prx393_workflow'] as $name) $modules[$name]=APPPATH.'modules/'.$name;
  $all=[];
  foreach ($modules as $name=>$path) {
   $file=rtrim($path,'/').'/config/prx_module.php';
   if (is_file($file)) $all[$name]=require $file;
  }
  return $all;
 }
}
require APPPATH.'modules/prx110_catalog/classes/Service/Prx110/Catalog.php';
$admin=['guest'=>FALSE,'logged'=>TRUE,'admin'=>TRUE,'agent'=>TRUE,'roles'=>['admin']];
$user=['guest'=>FALSE,'logged'=>TRUE,'admin'=>FALSE,'agent'=>FALSE,'roles'=>['login']];
$visible=array_column(Service_Prx110_Catalog::visible_modules($admin),NULL,'module_name');
$regular=array_column(Service_Prx110_Catalog::visible_modules($user),NULL,'module_name');
$no_ui=['llm','prx102_core','cookie_consent','ldap_auth'];
$count=0;
foreach (Manifest::all() as $name=>$manifest) {
 if (in_array($name,$no_ui,TRUE)) continue;
 if (empty($visible[$name]['href'])) throw new RuntimeException('Missing catalog entry: '.$name);
 $count++;
}
$links=['prx103_platform'=>'/admin','prx110_catalog'=>'/services','prx111_migrations'=>'/migrate_pg',
 'prx115_semantic'=>'/prx115','prx120_jobs'=>'/prx120/monitor','prx208_events'=>'/prx208_events',
 'prx212_file_storage'=>'/prx212','prx218_module_manager'=>'/prx218','prx595_useradmin'=>'/prx595/admin'];
foreach ($links as $name=>$href) {
 if ($visible[$name]['href']!==$href) throw new RuntimeException('Wrong catalog route: '.$name);
 if ($name!=='prx110_catalog' && isset($regular[$name])) throw new RuntimeException('Admin module leaked: '.$name);
}
$grouped=[];
foreach (Service_Prx110_Catalog::groups_summary($admin) as $group) {
 $items=Service_Prx110_Catalog::group_modules($group['slug'],$admin);
 if (count($items)!==$group['count']) throw new RuntimeException('Wrong group count');
 foreach ($items as $item) $grouped[]=$item['module_name'];
}
if (count($grouped)!==count($visible) || count(array_unique($grouped))!==count($visible)) throw new RuntimeException('Catalog grouping lost/duplicated entries');
echo "Catalog completeness: $count modules with pages, 9 platform links, audience and group coverage OK\n";
