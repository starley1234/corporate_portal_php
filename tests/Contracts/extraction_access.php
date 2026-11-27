<?php
/** Настоящие principal/ACL/policy prx395, без БД и bootstrap. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
class ExtractionConfig {
    public function load($name) { return $this; }
    public function get($key) { global $root; $acl = require $root.'/application/config/acl.php'; return $acl[$key] ?? NULL; }
}
class Kohana { public static $config; }
Kohana::$config = new ExtractionConfig;
foreach (['Prx/Principal', 'Prx/Actor', 'Prx/Acl'] as $class) require $root.'/application/classes/'.$class.'.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Principal.php';
require_once $root.'/application/classes/Prx/DomainError.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
require $root.'/application/modules/prx150_api_hub/classes/Service/Prx150/Keys.php';
require $root.'/application/modules/prx395_document_extract/classes/Prx395/Error.php';
require $root.'/application/modules/prx395_document_extract/classes/Policy/Prx395/Extraction.php';
require $root.'/application/modules/prx395_document_extract/classes/Service/Prx395/Extraction.php';
$checks = 0;
function extraction_check($actual, $expected) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException('Unexpected access result');
    $checks++;
}
function extraction_denied(callable $operation, $status) {
    try { $operation(); } catch (Prx_DomainError $e) { extraction_check($e->getCode(), $status); return; }
    throw new RuntimeException('Expected denial');
}
$admin = new Prx_Actor(7, ['admin']);
$key = new Prx150_Principal(7, 'ERP', 2, ['prx395.extract']);
extraction_check(Policy_Prx395_Extraction::ownerId($admin), -7);
extraction_check(Policy_Prx395_Extraction::ownerId($key), 7);
foreach ([new Prx_Actor(0, ['admin']), new Prx150_Principal(0, 'invalid', NULL, ['*']), new Prx150_Principal(-7, 'invalid', NULL, ['*'])] as $guest)
    extraction_denied(fn() => Policy_Prx395_Extraction::ownerId($guest), 401);
foreach ([new Prx_Actor(7, ['login']), new Prx_Actor(7, ['hr_admin']), new Prx150_Principal(7, 'other', 2, ['prx450.parse']), new class implements Prx_Principal { public function organizationId(): ?int { return 2; } }] as $other)
    extraction_denied(fn() => Policy_Prx395_Extraction::ownerId($other), 403);
extraction_check(Policy_Prx395_Extraction::ownerId(new Prx150_Principal(7, 'internal', NULL, ['*'])), 7);
extraction_check($key->hasScope('prx395.extract'), TRUE);
extraction_check($key->hasScope('prx450.parse'), FALSE);
extraction_check((new Prx150_Principal(7, 'internal', NULL, ['*']))->hasScope(''), FALSE);
Service_Prx150_Keys::require_scope(['id' => 7, 'scopes' => '["prx395.extract"]'], 'prx395.extract');
foreach ([['id' => 7, 'scopes' => 'invalid'], ['id' => 0, 'scopes' => '["*"]'], ['id' => 7, 'scopes' => '["prx450.parse"]']] as $row)
    extraction_denied(fn() => Service_Prx150_Keys::require_scope($row, 'prx395.extract'), 403);
extraction_denied(fn() => Service_Prx150_Keys::require_scope(['id' => 7, 'scopes' => '["*"]'], ''), 403);
// Без конструктора: отказ должен произойти до обращения к БД/очереди/файлам.
$service = (new ReflectionClass(Service_Prx395_Extraction::class))->newInstanceWithoutConstructor();
extraction_denied(fn() => $service->submit(Prx_Actor::guest(), 'x', NULL), 401);
extraction_denied(fn() => $service->status(new Prx_Actor(7, ['login']), 1), 403);
foreach ([7, -7, ['id' => 7]] as $forged) {
    try { $service->status($forged, 1); throw new RuntimeException('Scalar owner accepted'); }
    catch (TypeError $e) { $checks++; }
}
try { $service->submit_sample($key, 'x'); throw new RuntimeException('Machine sample accepted'); }
catch (TypeError $e) { $checks++; }
echo "Extraction access: $checks checks OK\n";
