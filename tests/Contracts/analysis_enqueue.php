<?php
// Regression: a stale run must not masquerade as a newly queued prx120 job.
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
class Prx_Actor { function require($permission) {} function id() { return 1; } }
class Service_Prx391_Documents { static function document($id, $actor) { return ['id'=>$id]; } }
class Service_Prx390_Intake { static function text_for_message($id) { return ['chars'=>80, 'text'=>str_repeat('x',80)]; } }
class Kohana { public static $config; }
Kohana::$config = new class {
 function load($name) { return $this; }
 function as_array() { return ['profiles'=>['classify_extract_v1'=>['model'=>'local::test','prompt_version'=>'1','schema_version'=>'doc_card_v1']]]; }
};
class Database {
 const SELECT=1, UPDATE=2;
 static $db;
 public $insert_id=1, $existing, $queries=[], $commits=0, $rollbacks=0;
 static function instance() { return self::$db ?? (self::$db=new self); }
 function begin() { $this->queries=[]; }
 function commit() { $this->commits++; }
 function rollback() { $this->rollbacks++; }
 function quote($value) { return "'".str_replace("'","''",$value)."'"; }
 function query($type,$sql,$objects) {
  $this->queries[]=$sql;
  $row = strpos($sql,'SELECT * FROM prx_392_runs') !== false ? $this->existing : ['id'=>$this->insert_id];
  return new class($row) { private $row; function __construct($row) {$this->row=$row;} function current() {return $this->row;} };
 }
}
class Service_Prx120_JobQueue {
 static $job, $pushes=0, $fail=false;
 static function instance() {return new self;}
 function get($id) {return self::$job;}
 static function push($channel,$payload,$options) {
  if (self::$fail) throw new RuntimeException('queue unavailable');
  if ($channel!=='doc.analyze' || $payload['run_id']<1) throw new RuntimeException('bad payload');
  self::$pushes++; return 99;
 }
}
require dirname(__DIR__,2).'/application/modules/prx392_analysis/classes/Service/Prx392/Analysis.php';
$checks=0;
function check($ok) {global $checks; $checks++; if (!$ok) throw new RuntimeException('enqueue regression');}
$db=Database::instance(); $actor=new Prx_Actor;
$db->existing=['id'=>1,'job_id'=>8,'status'=>'queued'];
check(Service_Prx392_Analysis::queue(1,2,$actor)===1);
check(Service_Prx120_JobQueue::$pushes===1); // missing job repaired
check(strpos(implode(' ', $db->queries),'job_id = 99')!==false);
Service_Prx120_JobQueue::$job=['channel'=>'doc.analyze','payload'=>['run_id'=>1],'status'=>'pending'];
Service_Prx392_Analysis::queue(1,2,$actor);
check(Service_Prx120_JobQueue::$pushes===1); // active task deduplicated
Service_Prx120_JobQueue::$job['status']='failed';
Service_Prx392_Analysis::queue(1,2,$actor);
check(Service_Prx120_JobQueue::$pushes===2);
$db->existing['status']='failed';
Service_Prx392_Analysis::queue(1,2,$actor);
check(Service_Prx120_JobQueue::$pushes===3); // reuse unique hash, no duplicate INSERT
check(strpos(implode(' ', $db->queries),'INSERT INTO prx_392_runs')===false);
$db->existing['status']='ready';
Service_Prx120_JobQueue::$job['status']='done';
Service_Prx392_Analysis::queue(1,2,$actor);
check(Service_Prx120_JobQueue::$pushes===3); // implicit call retains ready run
$db->insert_id=2;
check(Service_Prx392_Analysis::queue(1,2,$actor,'classify_extract_v1',TRUE)===2);
check(Service_Prx120_JobQueue::$pushes===4);
$sql=implode(' ', $db->queries);
check(strpos($sql,'INSERT INTO prx_392_runs')!==false);
check(strpos($sql,'UPDATE prx_392_runs SET status')===false); // old result preserved
check(strpos($sql,md5(str_repeat('x',80)."\nclassify_extract_v1|local::test|1|doc_card_v1"))===false);
$db->existing=['id'=>2,'job_id'=>99,'status'=>'queued'];
Service_Prx120_JobQueue::$job=['channel'=>'doc.analyze','payload'=>['run_id'=>2],'status'=>'pending'];
check(Service_Prx392_Analysis::queue(1,2,$actor,'classify_extract_v1',TRUE)===2);
check(Service_Prx120_JobQueue::$pushes===4); // double click doesn't create a third run
Service_Prx120_JobQueue::$job['status']='failed';
Service_Prx120_JobQueue::$fail=true;
try {Service_Prx392_Analysis::queue(1,2,$actor); throw new LogicException('must fail');}
catch (RuntimeException $e) {check($e->getMessage()==='queue unavailable');}
check($db->rollbacks===1);
echo "Analysis enqueue: $checks checks OK\n";
