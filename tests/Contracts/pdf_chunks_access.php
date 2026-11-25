<?php
/** Настоящие principal/ACL/policy prx396, без БД и bootstrap. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
class PdfChunksAccessConfig {
    public function load($name) { return $this; }
    public function get($key) { global $root; $acl = require $root.'/application/config/acl.php'; return $acl[$key] ?? NULL; }
}
class Kohana { public static $config; }
Kohana::$config = new PdfChunksAccessConfig;
foreach (['Prx/Principal', 'Prx/Actor', 'Prx/Acl'] as $class) require $root.'/application/classes/'.$class.'.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Principal.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
require $root.'/application/modules/prx150_api_hub/classes/Service/Prx150/Keys.php';
require $root.'/application/modules/prx396_pdf_chunks/classes/Prx396/Error.php';
require $root.'/application/modules/prx396_pdf_chunks/classes/Service/Prx396/Extraction.php';
require $root.'/application/modules/prx396_pdf_chunks/classes/Policy/Prx396/Extraction.php';
$checks = 0;
function pdf_chunks_check($actual, $expected) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException('Unexpected access result: '.var_export($actual, TRUE).' !== '.var_export($expected, TRUE));
    $checks++;
}
function pdf_chunks_denied(callable $operation, $status) {
    try { $operation(); } catch (Prx150_Error $e) { pdf_chunks_check($e->getCode(), $status); return; }
    throw new RuntimeException('Expected denial');
}
// Ключ prx396.ui объявлен в acl.php — иначе UI-доступ молча пропадает.
pdf_chunks_check(Kohana::$config->get('prx396.ui'), ['admin']);
pdf_chunks_check(Service_Prx396_Extraction::SCOPE, 'prx396.extract');
pdf_chunks_check(Service_Prx396_Extraction::CHANNEL, 'pdf.chunks');
$admin = new Prx_Actor(7, ['admin']);
$key = new Prx150_Principal(7, 'ERP', 2, ['prx396.extract']);
pdf_chunks_check(Policy_Prx396_Extraction::ownerId($admin), -7);
pdf_chunks_check(Policy_Prx396_Extraction::ownerId($key), 7);
foreach ([new Prx_Actor(0, ['admin']), new Prx150_Principal(0, 'invalid', NULL, ['*']), new Prx150_Principal(-7, 'invalid', NULL, ['*'])] as $guest)
    pdf_chunks_denied(fn() => Policy_Prx396_Extraction::ownerId($guest), 401);
foreach ([new Prx_Actor(7, ['login']), new Prx_Actor(7, ['hr_admin']), new Prx150_Principal(7, 'other', 2, ['prx450.parse']), new class implements Prx_Principal { public function organizationId(): ?int { return 2; } }] as $other)
    pdf_chunks_denied(fn() => Policy_Prx396_Extraction::ownerId($other), 403);
pdf_chunks_check(Policy_Prx396_Extraction::ownerId(new Prx150_Principal(7, 'internal', NULL, ['*'])), 7);
pdf_chunks_check($key->hasScope('prx396.extract'), TRUE);
pdf_chunks_check($key->hasScope('prx450.parse'), FALSE);
Service_Prx150_Keys::require_scope(['id' => 7, 'scopes' => '["prx396.extract"]'], 'prx396.extract');
foreach ([['id' => 7, 'scopes' => 'invalid'], ['id' => 0, 'scopes' => '["*"]'], ['id' => 7, 'scopes' => '["prx450.parse"]']] as $row)
    pdf_chunks_denied(fn() => Service_Prx150_Keys::require_scope($row, 'prx396.extract'), 403);
// Без конструктора: отказ должен произойти до обращения к БД/очереди/файлам.
$service = (new ReflectionClass(Service_Prx396_Extraction::class))->newInstanceWithoutConstructor();
pdf_chunks_denied(fn() => $service->submit(Prx_Actor::guest(), 'x', NULL), 401);
pdf_chunks_denied(fn() => $service->status(new Prx_Actor(7, ['login']), 1), 403);
foreach ([7, -7, ['id' => 7]] as $forged) {
    try { $service->status($forged, 1); throw new RuntimeException('Scalar owner accepted'); }
    catch (TypeError $e) { $checks++; }
}
echo "PDF chunks access: $checks checks OK\n";
