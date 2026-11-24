<?php
/** Every connected module with a page must declare its real entry in its own manifest. */
if (!defined('SYSPATH')) define('SYSPATH',dirname(__DIR__,2).'/system/');
define('APPPATH',dirname(__DIR__,2).'/application/');
define('MODPATH',dirname(__DIR__,2).'/modules/');
class Kohana { public static $config; }
class CatalogTestConfig {
 public function load($name) { return $this; }
 public function get($ability) { return (require APPPATH.'config/acl.php')[$ability] ?? NULL; }
}
Kohana::$config = new CatalogTestConfig();
require APPPATH.'classes/Prx/Acl.php';
require APPPATH.'classes/Prx/Principal.php';
require APPPATH.'classes/Prx/Actor.php';
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
$admin=['guest'=>FALSE,'logged'=>TRUE,'user_id'=>1,'admin'=>TRUE,'agent'=>TRUE,'roles'=>['admin']];
$user=['guest'=>FALSE,'logged'=>TRUE,'user_id'=>1,'admin'=>FALSE,'agent'=>FALSE,'roles'=>['login']];
$visible=array_column(Service_Prx110_Catalog::visible_modules($admin),NULL,'module_name');
$regular=array_column(Service_Prx110_Catalog::visible_modules($user),NULL,'module_name');
$plan=array_column(Service_Prx110_Catalog::plan_modules($admin),NULL,'module_name');
$no_ui=['llm','prx102_core','cookie_consent','ldap_auth'];
$count=0;
$wip=0;
foreach (Manifest::all() as $name=>$manifest) {
 if (($manifest['catalog'] ?? TRUE) === FALSE) {
  if (isset($visible[$name]) || isset($regular[$name])) throw new RuntimeException('Unpublished module leaked: '.$name);
  if (!isset($plan[$name]) || $plan[$name]['state']!=='unpublished') throw new RuntimeException('Unpublished module missing from the plan: '.$name);
  continue;
 }
 if (($manifest['kind'] ?? '')==='library' || in_array($name,$no_ui,TRUE)) continue;
 $state=Service_Prx110_Catalog::resolve_state($manifest);
 if ($state==='wip') {
  if (isset($visible[$name]) || isset($regular[$name])) throw new RuntimeException('WIP module leaked into /services: '.$name);
  if (!isset($plan[$name])) throw new RuntimeException('WIP module missing from the plan: '.$name);
  $wip++;
  continue;
 }
 if ($state!=='approved') continue;
 if (empty($visible[$name]['href'])) throw new RuntimeException('Missing catalog entry: '.$name);
 $count++;
}
$links=['prx103_platform'=>'/admin','prx110_catalog'=>'/services','prx111_migrations'=>'/migrate_pg',
 'prx115_semantic'=>'/prx115','prx120_jobs'=>'/prx120/monitor','prx208_events'=>'/prx208_events',
 'prx212_file_storage'=>'/prx212_file_storage','prx218_module_manager'=>'/prx218_module_manager','prx595_useradmin'=>'/prx595/admin'];
foreach ($links as $name=>$href) {
 if ($visible[$name]['href']!==$href) throw new RuntimeException('Wrong catalog route: '.$name);
 if ($name!=='prx110_catalog' && isset($regular[$name])) throw new RuntimeException('Admin module leaked: '.$name);
}
$acl_probe = ['audience'=>'auth', 'status'=>'stable', 'acl'=>'prx120.monitor'];
if (!Service_Prx110_Catalog::can_see($acl_probe,$admin) || Service_Prx110_Catalog::can_see($acl_probe,$user)) throw new RuntimeException('Catalog does not enforce manifest ACL');
if (Service_Prx110_Catalog::can_see(['status'=>'draft','audience'=>'admin'],$admin)) throw new RuntimeException('Draft leaked into the working catalog');
$preview=$admin;
$preview['preview']=TRUE;
$preview_visible=array_column(Service_Prx110_Catalog::visible_modules($preview),NULL,'module_name');
if (isset($visible['prx101_architecture']) || !isset($preview_visible['prx101_architecture'])) throw new RuntimeException('Agent preview does not reveal WIP modules');
if (Service_Prx110_Catalog::can_see(['status'=>'beta','audience'=>'admin'],$user+['preview'=>TRUE])) throw new RuntimeException('Preview bypasses audience/ACL');
$grouped=[];
foreach (Service_Prx110_Catalog::groups_summary($admin) as $group) {
 $items=Service_Prx110_Catalog::group_modules($group['slug'],$admin);
 if (count($items)!==$group['count']) throw new RuntimeException('Wrong group count');
 foreach ($items as $item) $grouped[]=$item['module_name'];
}
if (count($grouped)!==count($visible) || count(array_unique($grouped))!==count($visible)) throw new RuntimeException('Catalog grouping lost/duplicated entries');
echo "Catalog completeness: $count approved modules, $wip in progress, unpublished and planning OK\n";

class HTTP_Exception extends RuntimeException
{
 public static function factory($status, $message) { return new self($message, $status); }
}
class Prx_Db
{
 public static $approved = [];
 public static function select($sql) { return array_values(self::$approved); }
 public static function execute($sql, $params) {
  if (strpos($sql, 'ON CONFLICT (module_name) DO NOTHING') === FALSE) throw new RuntimeException('Approval insert is not idempotent');
  self::$approved[$params[':name']] ??= ['module_name' => $params[':name'], 'approved_by' => $params[':actor']];
 }
}
require APPPATH.'modules/prx110_catalog/classes/Service/Prx110/Publication.php';
$publisher = Prx_Actor::fromArray($admin);
try {
 Service_Prx110_Publication::approve('prx395_document_extract', Prx_Actor::fromArray($user));
 throw new RuntimeException('Non-admin could approve a module');
} catch (HTTP_Exception $e) {
 if ($e->getCode() !== 403 || Prx_Db::$approved) throw $e;
}
try {
 Service_Prx110_Publication::approve('prx301_onboarding', $publisher);
 throw new RuntimeException('A plan-only service was approved');
} catch (HTTP_Exception $e) {
 if ($e->getCode() !== 404 || Prx_Db::$approved) throw $e;
}
Service_Prx110_Publication::approve('prx395_document_extract', $publisher);
Service_Prx110_Publication::approve('prx395_document_extract', new Prx_Actor(2, ['admin']));
$approved_pdf = Service_Prx110_Catalog::get('prx395_document_extract');
if (Service_Prx110_Catalog::resolve_manifest_state($approved_pdf) !== 'wip'
 || !Service_Prx110_Catalog::can_see($approved_pdf, $admin)
 || Service_Prx110_Catalog::can_see($approved_pdf, $user)
 || Prx_Db::$approved['prx395_document_extract']['approved_by'] !== 1) {
 throw new RuntimeException('Approval mutated the manifest, ACL or original approver');
}
$approved_pdf['status'] = 'deprecated';
if (Service_Prx110_Catalog::can_see($approved_pdf, $admin)) throw new RuntimeException('Archived module retained visibility');
Service_Prx110_Publication::approve('prx300_employee', $publisher);
if (!in_array('/employee', array_column(Service_Prx110_Catalog::search_json($user)['items'], 'href'), TRUE)) {
 throw new RuntimeException('Approved employee profile did not enter regular catalog');
}
echo "Publication: admin-only, connected services only, manifest and ACL intact\n";
