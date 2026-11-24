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
class HTTP_Exception extends RuntimeException
{
 public static function factory($status, $message) { return new self($message, $status); }
}
class ORM
{
 public static int $calls = 0;
 public static function factory($name) { self::$calls++; return new self; }
 public function where(...$args) { return $this; }
 public function find_all() {
  return [(object) ['system_name' => 'prx987_scanned', 'status' => 'alpha', 'is_published' => 0]];
 }
}
class Prx_Db
{
 public static array $rows = [];
 public static function select($sql) { return array_values(self::$rows); }
 public static function insert($sql, $params) {
  if (!str_contains($sql, 'ON CONFLICT (module_name) DO NOTHING')) throw new RuntimeException('Backfill must preserve decisions');
  return [NULL, isset(self::$rows[$params[':name']]) ? 0 : self::write($params)];
 }
 public static function execute($sql, $params) {
  if (!str_contains($sql, 'state IS DISTINCT FROM EXCLUDED.state') || !str_contains($sql, 'note IS DISTINCT FROM EXCLUDED.note')) {
   throw new RuntimeException('Decision write is not idempotent');
  }
  $old = self::$rows[$params[':name']] ?? [];
  return ($old['state'] ?? NULL) === $params[':state'] && ($old['note'] ?? NULL) === $params[':note'] ? 0 : self::write($params);
 }
 private static function write($params) {
  $name = $params[':name'];
  self::$rows[$name] = ['module_name' => $name, 'state' => $params[':state'], 'decided_by' => $params[':actor'] ?? NULL,
   'note' => $params[':note'], 'decided_at' => '2026-11-24 11:25:00+00', 'decided_by_name' => 'admin'];
  return 1;
 }
}
class Service_Prx208_EventBus
{
 public static array $events = [];
 public static function instance() { return new self; }
 public function publish($name, $payload, $actor) { self::$events[] = [$name, $payload, $actor]; }
}
require APPPATH.'classes/Event.php';
require APPPATH.'modules/prx110_catalog/classes/Service/Prx110/Publication.php';
$admin=['guest'=>FALSE,'logged'=>TRUE,'user_id'=>1,'admin'=>TRUE,'agent'=>TRUE,'roles'=>['admin']];
$user=['guest'=>FALSE,'logged'=>TRUE,'user_id'=>1,'admin'=>FALSE,'agent'=>FALSE,'roles'=>['login']];
$stable = Service_Prx110_Catalog::get('prx597_notifications');
$scanned = ['module_name'=>'prx987_scanned', 'href'=>'/scanned', 'title'=>'Scanned'];
if (Service_Prx110_Catalog::resolve_state($stable) !== 'wip'
 || Service_Prx110_Catalog::can_see($stable, $admin)
 || Service_Prx110_Catalog::resolve_status($scanned) !== 'draft'
 || Service_Prx110_Catalog::resolve_state($scanned) !== 'wip'
 || Service_Prx110_Catalog::visible_modules($admin) !== [] || ORM::$calls !== 0) {
 throw new RuntimeException('Publication inferred from a stable manifest or inventory');
}
$unpublished = Service_Prx110_Catalog::get('prx100_search');
if (Service_Prx110_Catalog::resolve_state($unpublished) !== 'unpublished') throw new RuntimeException('Missing no-decision fallback');
$dry = Service_Prx110_Publication::backfill();
if (Prx_Db::$rows || !$dry['insert'] || $dry['inserted']) throw new RuntimeException('Dry run wrote decisions or omitted the plan');
$applied = Service_Prx110_Publication::backfill(TRUE);
$repeated = Service_Prx110_Publication::backfill(TRUE);
if ($applied['inserted'] !== array_keys($dry['insert']) || $repeated['insert'] || $repeated['inserted']
 || Service_Prx110_Catalog::resolve_state($stable) !== 'approved') {
 throw new RuntimeException('Backfill must publish previous stable services once');
}
$visible=array_column(Service_Prx110_Catalog::visible_modules($admin),NULL,'module_name');
$regular=array_column(Service_Prx110_Catalog::visible_modules($user),NULL,'module_name');
$plan=array_column(Service_Prx110_Catalog::plan_modules($admin),NULL,'module_name');
if (($plan['prx100_search']['catalog'] ?? TRUE) !== FALSE || ($plan['prx100_search']['state'] ?? NULL) !== 'unpublished') {
 throw new RuntimeException('Global search must exercise the catalog:false branch');
}
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
 $state=$plan[$name]['state'] ?? Service_Prx110_Catalog::resolve_state($manifest);
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
$acl_probe = ['audience'=>'auth', 'acl'=>'prx120.monitor'] + $stable;
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

$publisher = Prx_Actor::fromArray($admin);
$before = Prx_Db::$rows;
try {
 Service_Prx110_Publication::approve('prx395_document_extract', Prx_Actor::fromArray($user));
 throw new RuntimeException('Non-admin could approve a module');
} catch (HTTP_Exception $e) {
 if ($e->getCode() !== 403 || Prx_Db::$rows !== $before) throw $e;
}
try {
 Service_Prx110_Publication::approve('prx301_onboarding', $publisher);
 throw new RuntimeException('A plan-only service was approved');
} catch (HTTP_Exception $e) {
 if ($e->getCode() !== 404 || Prx_Db::$rows !== $before) throw $e;
}
Service_Prx110_Publication::approve('prx395_document_extract', $publisher);
Service_Prx110_Publication::approve('prx395_document_extract', new Prx_Actor(2, ['admin']));
$approved_pdf = Service_Prx110_Catalog::get('prx395_document_extract');
if (Service_Prx110_Catalog::resolve_manifest_state($approved_pdf) !== 'wip'
 || !Service_Prx110_Catalog::can_see($approved_pdf, $admin)
 || Service_Prx110_Catalog::can_see($approved_pdf, $user)
 || Prx_Db::$rows['prx395_document_extract']['decided_by'] !== 1) {
 throw new RuntimeException('Approval mutated the manifest, ACL or original approver');
}
$approved_pdf['status'] = 'deprecated';
if (Service_Prx110_Catalog::resolve_state($approved_pdf) !== 'archived' || Service_Prx110_Catalog::can_see($approved_pdf, $admin)) throw new RuntimeException('Archived module retained visibility');
Service_Prx110_Publication::approve('prx300_employee', $publisher);
if (!in_array('/employee', array_column(Service_Prx110_Catalog::search_json($user)['items'], 'href'), TRUE)) {
 throw new RuntimeException('Approved employee profile did not enter regular catalog');
}
echo "Publication: admin-only, connected services only, manifest and ACL intact\n";

$notifications = Service_Prx110_Catalog::get('prx597_notifications');
try {
 Service_Prx110_Publication::set_state('prx597_notifications', 'archived', Prx_Actor::fromArray($user));
 throw new RuntimeException('Non-admin could archive a module');
} catch (HTTP_Exception $e) {
 if ($e->getCode() !== 403) throw $e;
}
Service_Prx110_Publication::set_state('prx597_notifications', 'wip', $publisher);
if (Service_Prx110_Catalog::resolve_manifest_state($notifications) !== 'approved'
 || Service_Prx110_Catalog::resolve_state($notifications) !== 'wip'
 || Service_Prx110_Catalog::can_see($notifications, $user)
 || !Service_Prx110_Catalog::can_see($notifications, $preview)) {
 throw new RuntimeException('Work state did not override publication while preserving preview');
}
Service_Prx110_Publication::approve('prx597_notifications', $publisher);
if (!Service_Prx110_Catalog::can_see($notifications, $user)) throw new RuntimeException('Reapproval did not restore a stable service');

foreach (['prx100_search', 'prx110_catalog', 'prx112_portal_plan'] as $name) {
 try {
  Service_Prx110_Publication::set_state($name, 'archived', $publisher);
  throw new RuntimeException('Non-manageable module accepted a publication decision: '.$name);
 } catch (HTTP_Exception $e) {
  if ($e->getCode() !== ($name === 'prx100_search' ? 404 : 403)) throw $e;
 }
}
try {
 Service_Prx110_Publication::set_state('prx597_notifications', 'unknown', $publisher);
 throw new RuntimeException('Unknown publication state was accepted');
} catch (HTTP_Exception $e) {
 if ($e->getCode() !== 400) throw $e;
}
Service_Prx110_Publication::set_state('prx597_notifications', 'archived', $publisher);
if (Service_Prx110_Catalog::resolve_state($notifications) !== 'archived'
 || Service_Prx110_Catalog::can_see($notifications, $user)
 || Service_Prx110_Catalog::can_see($notifications, $preview)
 || in_array($notifications['href'], array_column(Service_Prx110_Catalog::search_json($user)['items'], 'href'), TRUE)) {
 throw new RuntimeException('Archived publication did not disappear from the catalog');
}
Service_Prx110_Publication::set_state('prx597_notifications', 'wip', $publisher);
if (Service_Prx110_Catalog::resolve_state($notifications) !== 'wip'
 || Service_Prx110_Catalog::can_see($notifications, $user)) {
 throw new RuntimeException('Restored service was not returned to work');
}
Service_Prx110_Publication::set_state('prx597_notifications', 'approved', $publisher);
if (Service_Prx110_Catalog::resolve_state($notifications) !== 'approved'
 || !Service_Prx110_Catalog::can_see($notifications, $user)) {
 throw new RuntimeException('Restored service could not be republished');
}
echo "Publication archive: hidden from the catalog and preview, reversible through work\n";

$events_before = count(Service_Prx208_EventBus::$events);
Service_Prx110_Publication::set_state('prx597_notifications', 'unpublished', $publisher, 'На проверке');
Service_Prx110_Publication::set_state('prx597_notifications', 'unpublished', new Prx_Actor(2, ['admin']), 'На проверке');
if (Service_Prx110_Catalog::resolve_state($notifications) !== 'unpublished'
 || Service_Prx110_Catalog::can_see($notifications, $preview)
 || Service_Prx110_Publication::decision_meta('prx597_notifications')['note'] !== 'На проверке'
 || Prx_Db::$rows['prx597_notifications']['decided_by'] !== 1
 || array_slice(Service_Prx208_EventBus::$events, $events_before) !== [
  ['prx110.publication.changed', ['module' => 'prx597_notifications', 'state' => 'unpublished'], 1],
 ]) throw new RuntimeException('Unpublished decision or change-only audit is incorrect');
Service_Prx110_Publication::set_state('prx597_notifications', 'unpublished', $publisher, 'Новый комментарий');
if (count(Service_Prx208_EventBus::$events) !== $events_before + 2) throw new RuntimeException('Note-only change did not publish an event');
