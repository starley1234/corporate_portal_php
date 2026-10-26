<?php
/** Реальные Service/Controller/views/Route, БД и сессия — управляемые дублёры.
 * N2J_ROOT=$PWD node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj669_solid3d_stand.php
 * N2J_DUMP=1 отдаёт миграции и SQL-трассу для PostgreSQL/PGlite-проверки.
 */
$root = is_dir('/app/application') ? '/app' : dirname(__DIR__);
define('APPPATH', $root.'/application/');
if ( ! defined('SYSPATH')) { define('SYSPATH', $root.'/system/'); }
set_error_handler(function ($level, $message, $file, $line) {
	if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); }
});
require SYSPATH.'classes/Kohana/Arr.php'; require SYSPATH.'classes/Arr.php';
class Kohana { public static $config; public static $log; public static $charset = 'UTF-8'; }
class Config { public function load($key) { $parts = explode('.', $key, 2); $values = require APPPATH.'modules/nj669_solid3d/config/'.$parts[0].'.php'; return $parts[1] ? $values[$parts[1]] : $values; } }
Kohana::$config = new Config;
class Log { const ERROR = 4; const WARNING = 3; }
class Log_Stub { public function add($level, $message, $values = []) { Log_Plain::$events[] = [$message, $values]; } }
class Log_Plain { public static $events = []; public static function event($l, $m, $p=[]) { self::$events[] = [$m,$p]; } }
Kohana::$log = new Log_Stub;
class HTML { public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
class Security { public static function token() { return 'csrf-ok'; } public static function check($v) { return $v === 'csrf-ok'; } }
class Gate { public static function wall($slug, $opt) { return 'Вход обязателен'; } }
class HTTP_Exception extends Exception { public static function factory($status, $message='') { return new self($message, $status); } }
class HTTP { public static $location; public static function redirect($url, $status=302) { self::$location = $url; throw HTTP_Exception::factory($status); } }
class Request
{
	const POST='POST'; public $verb='GET'; public $params=[]; public $q=[]; public $p=[]; public $h=[];
	public function method() { return $this->verb; }
	public function param($k) { return $this->params[$k] ?? NULL; }
	public function query($k) { return $this->q[$k] ?? NULL; }
	public function headers($k) { return $this->h[$k] ?? ''; }
	public function post($k=NULL) { return $k === NULL ? $this->p : ($this->p[$k] ?? NULL); }
}
class Response
{
	public $code=200; public $text=''; public $headers=[];
	public function status($v=NULL) { if ($v !== NULL) { $this->code=$v; } return $this->code; }
	public function headers($k,$v) { $this->headers[$k]=$v; }
	public function body($v=NULL) { if ($v !== NULL) { $this->text=$v; } return $this->text; }
}
class View
{
	public static $snapshots=[]; public $name; public $data;
	public static function factory($name,$data=[]) { $v=new self; $v->name=$name; $v->data=$data; return $v; }
	public function __toString() { extract($this->data); ob_start(); include APPPATH.'modules/nj669_solid3d/views/'.$this->name.'.php'; $html=ob_get_clean(); self::$snapshots[$this->name]=$html; return $html; }
}
class Controller_Erp100_Base
{
	public $request; public $response; public $template; public $auto_render=TRUE; protected $_current_user; public $abilities=[];
	public function __construct($uid=0, $moderator=FALSE) { $this->_current_user=$uid ? (object) ['id'=>$uid] : NULL; $this->request=new Request; $this->response=new Response; $this->template=(object) ['content'=>'']; $this->abilities=['nj669.start'=>$uid>0,'nj669.moderate'=>$moderator]; }
	public function before() {} public function can($key) { return $this->abilities[$key] ?? FALSE; }
	public function set_title($s) {} public function set_description($s) {} public function set_module_nav($s) {}
}
class Controller_Nj120_Api
{
	public $request; protected $_halt=FALSE; public $denied=FALSE;
	protected function _auth_fail($m) { $this->denied=TRUE; }
}
class TestResult { public $rows; public function __construct($r) { $this->rows=$r; } public function as_array() { return array_values($this->rows); } }
class TestQuery
{
	public $sql; public $type; public $params=[];
	public function __construct($type,$sql) { $this->sql=$sql; $this->type=$type; }
	public function parameters($p) { $this->params=$p; return $this; }
	public function execute() { $rows=Store::query($this->sql,$this->params); return $this->type === Database::SELECT ? new TestResult($rows) : 1; }
}
class DB { public static function query($type,$sql) { return new TestQuery($type,$sql); } }
class Database
{
	const SELECT=1; const UPDATE=3; public static $instance; public $snapshot;
	public static function instance() { if ( ! self::$instance) { self::$instance=new self; } return self::$instance; }
	public function begin() { if ($this->snapshot !== NULL) { throw new RuntimeException('Вложенная транзакция'); } $this->snapshot=[Store::$p,Store::$r,Store::$settings,Store::$quotas,Store::$tasks,Store::$ideas,Store::$events]; }
	public function commit() { $this->snapshot=NULL; }
	public function rollback() { [Store::$p,Store::$r,Store::$settings,Store::$quotas,Store::$tasks,Store::$ideas,Store::$events]=$this->snapshot; $this->snapshot=NULL; }
}
class Service_Nj120_JobQueue
{
	public static $jobs=[]; public static $refuse=FALSE; public static $states=[];
	public static function instance() { return new self; }
	public function status_of($id) { return self::$states[$id] ?? []; }
	public function cancel($id,$force=FALSE) { self::$states[$id]=['status'=>'cancelled']; return TRUE; }
	public static function push($channel,$payload,$options) { if (self::$refuse) { throw new RuntimeException('Тест: очередь недоступна'); } self::$jobs[]=[$channel,$payload,$options]; return count(self::$jobs); }
}
class Service_Nj597_Notify { public static $sent=[]; public static function emit($event,$data) { self::$sent[]=[$event,$data]; } }
class Store
{
	public static $p=[]; public static $r=[]; public static $tasks=[]; public static $ideas=[]; public static $settings=[]; public static $quotas=[]; public static $trace=[]; public static $events=[]; public static $x=[];
	public static function query($raw,$v)
	{
		$sql=preg_replace('/\s+/', ' ', trim($raw)); self::$trace[]=[$raw,$v];

		if (str_starts_with($sql,'SELECT * FROM nj_669_solid3d_ideas')) { return array_reverse(array_values(self::$ideas)); }
		if (str_starts_with($sql,'SELECT id FROM nj_669_solid3d_projects WHERE id=')) { $p=self::$p[$v[':p']]??NULL; return ($p AND ! $p['deleted_at']) ? [['id'=>$p['id']]] : []; }
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_tasks'))
		{
			$id=count(self::$tasks)+1; self::$tasks[$id]=['id'=>$id,'kind'=>$v[':k'],'project_id'=>$v[':p'],'user_id'=>$v[':u'],'run_token'=>$v[':token'],'model'=>$v[':m'],'context'=>$v[':c'],'result'=>'{}','status'=>'queued','error'=>'','job_id'=>0,'created_at'=>gmdate('Y-m-d H:i:s+00'),'finished_at'=>NULL]; return [self::$tasks[$id]];
		}
		if (str_starts_with($sql,'SELECT * FROM nj_669_solid3d_tasks WHERE id='))
		{
			$t=self::$tasks[$v[':id']]??NULL;
			if ($t AND isset($v[':p']) AND ($t['project_id']!=$v[':p'] OR $t['kind']!=='caption' OR $t['status']!=='done')) { return []; }
			return $t ? [$t] : [];
		}
		if (str_starts_with($sql,'SELECT * FROM nj_669_solid3d_tasks WHERE kind=') OR str_starts_with($sql,'SELECT id FROM nj_669_solid3d_tasks WHERE kind='))
		{
			return array_reverse(array_values(array_filter(self::$tasks,function($t)use($v,$sql){return $t['kind']===$v[':k'] AND (!isset($v[':p']) OR $t['project_id']==$v[':p']) AND (!str_starts_with($sql,'SELECT id') OR in_array($t['status'],['queued','running'],TRUE));})));
		}
		if (str_starts_with($sql,'DELETE FROM nj_669_solid3d_ideas WHERE')) { unset(self::$ideas[$v[':id']]); return []; }
		if (str_starts_with($sql,'UPDATE nj_669_solid3d_ideas SET')) { if(isset(self::$ideas[$v[':id']])){self::$ideas[$v[':id']]['body']=$v[':b'];self::$ideas[$v[':id']]['theme']=$v[':t'];} return []; }
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_ideas')) { $id=count(self::$ideas)+1; self::$ideas[$id]=['id'=>$id,'theme'=>$v[':t'],'body'=>$v[':b'],'task_id'=>$v[':id']??NULL]; return []; }
		if (str_starts_with($sql,'UPDATE nj_669_solid3d_tasks') AND str_contains($sql,"interval '10 minutes'")) { foreach(self::$tasks as &$t){if(in_array($t['status'],['queued','running'],TRUE) AND strtotime($t['created_at'])<time()-600){$t['status']='error';$t['error']='Истекло время помощника';}} return []; }
		if (str_starts_with($sql,'SELECT pg_advisory')) { return [[]]; }
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_exports'))
		{
			$id=count(self::$x)+1; self::$x[$id]=['id'=>$id,'code'=>$v[':c'],'project_id'=>$v[':p'],'revision_id'=>$v[':r'],'user_id'=>$v[':u'],'run_token'=>$v[':t'],
				'params'=>$v[':pp'],'source'=>$v[':src'],'status'=>'queued','job_id'=>0,'error'=>'','detail'=>'','size_bytes'=>0,
				'created_at'=>'2026-10-16 12:00:00+00','expires_at'=>'2026-10-16 13:00:00+00','finished_at'=>NULL];
			return [['id'=>$id]];
		}
		if (str_starts_with($sql,'SELECT * FROM nj_669_solid3d_exports WHERE id='))
		{
			$x=self::$x[$v[':id']]??NULL;
			if ($x AND isset($v[':p']) AND ($x['project_id']!=$v[':p'] OR $x['status']!=='done' OR $x['expires_at']<='2026-10-16 12:00:00+00')) { return []; }
			return $x ? [$x] : [];
		}
		if (str_starts_with($sql,'SELECT *, (expires_at > now()) AS alive FROM nj_669_solid3d_exports'))
		{
			$rows=array_reverse(array_values(array_filter(self::$x,function($x)use($v){return $x['project_id']==$v[':p'] AND $x['user_id']==$v[':u'];})));
			return $rows ? [$rows[0]+['alive'=>$rows[0]['expires_at']>'2026-10-16 12:00:00+00']] : [];
		}
		if (str_starts_with($sql,'SELECT id FROM nj_669_solid3d_exports'))
		{
			return array_values(array_filter(self::$x,function($x)use($v){return $x['project_id']==$v[':p'] AND $x['user_id']==$v[':u'] AND in_array($x['status'],['queued','running'],TRUE);}));
		}
		if (str_starts_with($sql,'SELECT id, code FROM nj_669_solid3d_exports WHERE expires_at <='))
		{
			return array_values(array_filter(self::$x,function($x){return $x['expires_at']<='2026-10-16 12:00:00+00';}));
		}
		if (str_starts_with($sql,'DELETE FROM nj_669_solid3d_exports'))
		{
			foreach (self::$x as $id=>$x) { if ($x['expires_at']<='2026-10-16 12:00:00+00') { unset(self::$x[$id]); } }
			return [];
		}
		if (str_starts_with($sql,'UPDATE nj_669_solid3d_exports'))
		{
			// Чистка зависших сборок (порог 20 минут от 12:00 — раньше 11:40) и выбор полей по id.
			if (str_contains($sql,"WHERE status IN ('queued','running') AND created_at"))
			{
				foreach (self::$x as &$x) { if (in_array($x['status'],['queued','running'],TRUE) AND $x['created_at']<'2026-10-16 11:40:00+00') { $x['status']='error'; $x['error']='Сборка не завершилась за отведённое время. Попробуйте ещё раз.'; $x['expires_at']='2026-10-16 13:00:00+00'; } }
				unset($x); return [];
			}
			preg_match('/^UPDATE nj_669_solid3d_exports SET (.+) WHERE id=:id$/',$sql,$m);
			if (empty($m)) { throw new RuntimeException('Стэнд не знает UPDATE экспорта: '.$sql); }
			preg_match_all("/([a-z_]+)\\s*=\\s*('[^']*'|:[a-z]+|now\\(\\)\\+interval '1 hour'|now\\(\\)|NULL)/i",$m[1],$sets,PREG_SET_ORDER);
			foreach (self::$x as &$x)
			{
				if ($x['id']!=$v[':id']) { continue; }
				foreach ($sets as $set)
				{
					$expr=$set[2];
					if ($expr[0]===':') { $value=$v[$expr]; }
					elseif ($expr[0]==="'") { $value=substr($expr,1,-1); }
					elseif (str_starts_with($expr,'now()+')) { $value='2026-10-16 13:00:00+00'; }
					elseif ($expr==='now()') { $value='2026-10-16 12:00:00+00'; }
					else { $value=NULL; }
					$x[$set[1]]=$value;
				}
			}
			unset($x); return [];
		}
		if ($sql === 'SELECT key, value FROM nj_669_solid3d_settings') { $r=[]; foreach (self::$settings as $key=>$value) { $r[]=['key'=>$key,'value'=>$value]; } return $r; }
		if (str_starts_with($sql,'SELECT projects,daily_runs FROM nj_669_solid3d_quotas')) { return isset(self::$quotas[$v[':u']]) ? [self::$quotas[$v[':u']]] : []; }
		if (str_starts_with($sql,'SELECT (SELECT count(*)'))
		{
			return [['projects_used'=>count(array_filter(self::$p,function($p)use($v){return $p['user_id']==$v[':u'] AND ! $p['deleted_at'];})), 'runs_used'=>count(array_filter(self::$r,function($r)use($v){return $r['user_id']==$v[':u'] AND $r['job_id']>0 AND ($r['llm_run'] ?? TRUE);}))+count(array_filter(self::$tasks,function($t)use($v){return $t['user_id']==$v[':u'] AND in_array($t['kind'],['caption','ideas'],TRUE) AND $t['job_id']>0;}))]];
		}
		if (str_starts_with($sql,'SELECT * FROM nj_669_solid3d_projects WHERE code=')) { foreach (self::$p as $p) { if ($p['code']===$v[':c'] AND ! $p['deleted_at']) { return [$p]; } } return []; }
		if (str_starts_with($sql,'SELECT code FROM nj_669_solid3d_projects WHERE id=')) { return isset(self::$p[$v[':p']]) ? [['code'=>self::$p[$v[':p']]['code']]] : []; }
		if (str_starts_with($sql,'SELECT p.code FROM')) { $r=self::$r[$v[':r']]??NULL; $p=$r ? self::$p[$r['project_id']] : NULL; return ($p AND ! $p['deleted_at']) ? [['code'=>$p['code']]] : []; }
		if (str_starts_with($sql,'SELECT * FROM nj_669_solid3d_revisions WHERE id=')) { $r=self::$r[$v[':id']]??NULL; return ($r AND $r['project_id']==$v[':p']) ? [$r] : []; }
		if (str_starts_with($sql,'SELECT id FROM nj_669_solid3d_revisions')) { return array_filter(self::$r,function($r)use($v){return $r['project_id']==$v[':p'] AND in_array($r['status'],['queued','running'],TRUE);}); }
		if (str_starts_with($sql,'SELECT id,parent_id,')) { return array_reverse(array_values(array_filter(self::$r,function($r)use($v){return $r['project_id']==$v[':p'];}))); }
		if (str_starts_with($sql,'SELECT p.* FROM')) { return array_values(self::$p); }
		if (str_starts_with($sql,'SELECT p.*, rv.')) { return array_values(array_map(function($p){ $rv=self::$r[$p['review_revision_id']]??[]; return $p+['review_visual_ok'=>$rv['visual_ok']??NULL,'review_vlm_status'=>$rv['vlm_status']??NULL,'review_vlm_checked_at'=>NULL,'review_tokens_in'=>$rv['tokens_in']??0,'review_tokens_out'=>$rv['tokens_out']??0,'review_vlm_tokens_in'=>$rv['vlm_tokens_in']??0,'review_vlm_tokens_out'=>$rv['vlm_tokens_out']??0]; }, array_values(self::$p))); }
		if (str_starts_with($sql,'SELECT COUNT(*) AS runs') AND str_contains($sql,'FROM nj_669_solid3d_revisions r'))
		{
			$sum=['runs'=>0,'tokens_in'=>0,'tokens_out'=>0,'vlm_tokens_in'=>0,'vlm_tokens_out'=>0];
			foreach (self::$r as $r) { $p=self::$p[$r['project_id']]??NULL; if ( ! $p OR $p['deleted_at'] OR (isset($v[':u']) AND $r['user_id']!=$v[':u'])) { continue; } $sum['runs']++; foreach (['tokens_in','tokens_out','vlm_tokens_in','vlm_tokens_out'] as $k) { $sum[$k]+=(int)($r[$k]??0); } }
			return [$sum];
		}
		if (str_starts_with($sql,'SELECT COUNT(*) AS runs') AND str_contains($sql,'FROM nj_669_solid3d_tasks'))
		{
			$sum=['runs'=>0,'tokens_in'=>0,'tokens_out'=>0];
			foreach (self::$tasks as $t) { if (isset($v[':u']) AND $t['user_id']!=$v[':u']) { continue; } $sum['runs']++; $sum['tokens_in']+=(int)($t['tokens_in']??0); $sum['tokens_out']+=(int)($t['tokens_out']??0); }
			return [$sum];
		}
		if (str_starts_with($sql,'SELECT r.id,r.project_id,'))
		{
			$rows=[];
			foreach (array_reverse(self::$r, TRUE) as $r) { $p=self::$p[$r['project_id']]??NULL; if ( ! $p OR $p['deleted_at'] OR (isset($v[':u']) AND $r['user_id']!=$v[':u'])) { continue; } $rows[]=['id'=>$r['id'],'project_id'=>$r['project_id'],'prompt'=>$r['prompt'],'status'=>$r['status'],'job_id'=>$r['job_id']??0,'model'=>$r['model']??'','vision_model'=>$r['vision_model']??'','attempts'=>$r['attempts']??0,'error'=>$r['error']??'','created_at'=>$r['created_at']??'2026-10-16 12:00:00+00','vlm_status'=>$r['vlm_status']??'none','visual_ok'=>$r['visual_ok']??NULL,'tokens_in'=>$r['tokens_in']??0,'tokens_out'=>$r['tokens_out']??0,'vlm_tokens_in'=>$r['vlm_tokens_in']??0,'vlm_tokens_out'=>$r['vlm_tokens_out']??0,'code'=>$p['code'],'title'=>$p['title']]; }
			return $rows;
		}
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_projects'))
		{
			$id=count(self::$p)+1; self::$p[$id]=['id'=>$id,'code'=>$v[':c'],'user_id'=>$v[':u'],'title'=>$v[':t'],'summary'=>$v[':s'],'photo'=>$v[':ph'],'remix_of'=>$v[':r'],
				'current_revision_id'=>0,'published_revision_id'=>0,'review_revision_id'=>0,'publication'=>'draft','moderation_note'=>'','tags'=>'','published_meta'=>'{}','review_meta'=>'{}','created_at'=>'2026-10-16 12:00:00+00','updated_at'=>'2026-10-16 12:00:00+00','deleted_at'=>NULL]; return [self::$p[$id]];
		}
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_revisions'))
		{
			$id=count(self::$r)+1; $template=str_contains($sql,"'Использовать как шаблон'"); self::$r[$id]=['id'=>$id,'project_id'=>$v[':p'],'user_id'=>$v[':u'],'parent_id'=>$v[':parent']??0,'tokens_in'=>0,'tokens_out'=>0,'vlm_tokens_in'=>0,'vlm_tokens_out'=>0,
				'prompt'=>$v[':prompt']??'Использовать как шаблон','source'=>$v[':source']??($v[':s']??''),'run_token'=>$v[':token'],'status'=>$template?'error':'queued','job_id'=>0,'model'=>$v[':model']??'','vision_model'=>$v[':vision']??'',
				'label'=>'','milestone'=>FALSE,'params'=>'{}','pending_source'=>NULL,'pending_params'=>NULL,'queued_at'=>'2026-10-16 12:00:00+00','build_log'=>'','visual_ok'=>FALSE,'review'=>'','error'=>'','attempts'=>0,'files'=>'{}','created_at'=>'2026-10-16 12:00:00+00','finished_at'=>NULL,'vlm_status'=>'none','vlm_run_token'=>'','vlm_job_id'=>0,'vlm_error'=>'','vlm_checked_at'=>NULL,'vlm_by'=>0,'llm_run'=>($v[':llm']??'true')==='true']; return [self::$r[$id]];
		}
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_events')) { self::$events[]=['project_id'=>$v[':p'],'user_id'=>$v[':u'],'kind'=>$v[':k']]; return []; }
		if (str_starts_with($sql,'DELETE FROM nj_669_solid3d_revisions')) { unset(self::$r[$v[':r']]); return []; }
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_settings')) { self::$settings[$v[':key']]=$v[':value']; return []; }
		if (str_starts_with($sql,'INSERT INTO nj_669_solid3d_quotas')) { self::$quotas[$v[':u']]=['projects'=>$v[':projects'],'daily_runs'=>$v[':daily_runs']]; return []; }
		if (str_starts_with($sql,'UPDATE nj_669_solid3d_revisions') AND str_contains($sql, "interval '15 minutes'"))
		{
			$rows=[]; foreach (self::$r as &$r) { if ($r['project_id']==$v[':p'] AND ! empty($r['_vlm_expired']) AND in_array($r['vlm_status'],['queued','running'],TRUE)) { $r['vlm_status']='error'; $r['vlm_error']='Проверка не завершилась за отведённое время. Запустите её снова.'; $rows[]=['id'=>$r['id']]; } } unset($r); return $rows;
		}
		if (str_starts_with($sql,'UPDATE nj_669_solid3d_revisions') AND str_contains($sql, "interval '35 minutes'"))
		{
			$rows=[]; foreach (self::$r as &$r) { if ($r['project_id']==$v[':p'] AND ! empty($r['_expired']) AND in_array($r['status'],['queued','running'],TRUE)) { $resize=$r['pending_source'] !== NULL; $r['status']=$resize ? 'done' : 'error'; $r['error']=$resize ? 'Пересчёт не завершился за отведённое время. Прежние размеры сохранены.' : 'Истекло время задачи. Последняя удачная версия сохранена.'; $rows[]=['id'=>$r['id'],'pending_source'=>$r['pending_source']]; } } return $rows;
		}
		if (preg_match('/^UPDATE nj_669_solid3d_(projects|revisions|tasks) SET (.+) WHERE /',$sql,$m))
		{
			$table = $m[1]==='projects' ? 'p' : ($m[1]==='tasks' ? 'tasks' : 'r');
			foreach (self::${$table} as &$r)
			{
				$match=$table==='tasks' ? $r['id']==$v[':id'] : ($table==='p' ? $r['id']==$v[':p'] : (str_contains($sql,'WHERE project_id=') ? $r['project_id']==$v[':p'] : $r['id']==($v[':r']??-1)));
				if ( ! $match) { continue; }
				if (str_contains($m[2], 'status=CASE')) { if (in_array($r['status'],['queued','running'],TRUE)) { $r['status']='error'; } }
				preg_match_all('/([a-z_]+)\s*=\s*(CAST\((:[a-z]+) AS jsonb\)|:[a-z]+|\x27[^\x27]*\x27|[0-9]+|now\(\)|NULL|FALSE|TRUE)/i',$m[2],$sets,PREG_SET_ORDER);
				foreach ($sets as $set)
				{
					$key=$set[1]; $expr=$set[2];
					if (str_starts_with($expr,'CAST(')) { $value=$v[$set[3]]; }
					elseif ($expr[0]===':') { $value=$v[$expr]; }
					elseif ($expr[0]==="'") { $value=substr($expr,1,-1); }
					elseif ($expr==='now()') { $value='2026-10-16 12:00:00+00'; }
					elseif ($expr==='NULL') { $value=NULL; }
					elseif ($expr==='FALSE') { $value=FALSE; }
					elseif ($expr==='TRUE') { $value=TRUE; }
					else { $value=(int)$expr; }
					$r[$key]=$value;
				}
			}
			return [];
		}
		throw new RuntimeException('Стэнд не знает SQL: '.$sql);
	}
}
abstract class pgMigration { public static $sql=[]; protected function run_query($sql) { self::$sql[]=$sql; } abstract public function up(); abstract public function down(); }
require APPPATH.'modules/nj669_solid3d/classes/Service/Nj669/Solid3d.php';
require APPPATH.'modules/nj669_solid3d/classes/Service/Nj669/Workspace.php';
require APPPATH.'modules/nj669_solid3d/classes/Service/Nj669/Assist.php';
require APPPATH.'modules/nj669_solid3d/classes/Service/Nj669/Parameters.php';
require APPPATH.'modules/nj669_solid3d/classes/Service/Nj669/Export.php';
require APPPATH.'modules/nj669_solid3d/classes/Controller/Nj669/Solid3d.php';
require APPPATH.'modules/nj669_solid3d/classes/Controller/Nj669/Solid3dWorker.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_000_Solid3dNamespace.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_001_Solid3d.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_002_Workspace.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_003_VlmCheck.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_004_Params.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_005_LlmRun.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_006_Exports.php';
require APPPATH.'modules/nj669_solid3d/migrations_pg/pgMigration_Nj_669_007_Tokens.php';
class Controller_Nj669_Worker {}
class WorkerStand extends Controller_Nj669_Solid3dWorker { public function auth($given) { $this->request=new Request; $this->request->h=['x-worker-token'=>$given]; return $this->_authorize(); } }
$count=0;
function ok($value,$note) { global $count; $count++; if ( ! $value) { throw new RuntimeException('ФЕЙЛ: '.$note); } }
function refuses($call,$note,$class=DomainException::class) { try { $call(); } catch (Throwable $e) { ok($e instanceof $class,$note.' ('.get_class($e).': '.$e->getMessage().')'); return; } ok(FALSE,$note); }
function controller($uid=0,$mod=FALSE) { $c=new Controller_Nj669_Solid3d($uid,$mod); $c->before(); return $c; }
function render_page($action,$uid=0,$mod=FALSE,$params=[],$query=[]) { $c=controller($uid,$mod); $c->request->params=$params; $c->request->q=$query; $c->{'action_'.$action}(); $html=(string)$c->template->content; View::$snapshots[$action.'_'.$uid.'_'.($params['mode']??'')]=$html; return $html; }
$s='Service_Nj669_Solid3d';
ok($s::effective_limits(['projects'=>20,'daily_runs'=>5],['projects'=>NULL,'daily_runs'=>0])===['projects'=>20,'daily_runs'=>0], 'NULL наследуется, 0 запрещает');
foreach (['include <x.scad> cube(1);','a=1;use/*comment*/ <x.scad>','import("x");','surface(file="x");',''] as $src) { refuses(function()use($src,$s){$s::validate_source($src);}, 'запрет внешних файлов'); }
foreach (['/* import("x") */ cube(1);','text("include use import surface");','cube(1);// use <x>'] as $src) { $s::validate_source($src); ok(TRUE,'строки и комментарии безопасны'); }
refuses(function()use($s){$s::dir('../../etc');},'путь не выходит из хранилища');
refuses(function()use($s){$s::validate_file('glb','glTF');},'обрезанный GLB');
refuses(function()use($s){$s::validate_file('stl',str_repeat("\0",84));},'пустой STL');
$p=$s::create(7,'Кронштейн <script>','Кронштейн 30 мм'); $code=$p['code'];
ok($s::can_read($p,7,FALSE),'владелец читает'); ok( ! $s::can_read($p,8,FALSE),'чужой не читает'); ok( ! $s::can_read($p,0,FALSE,TRUE),'черновик не публичный');
$rid=$s::queue($code,7,'Сделай отверстие 5 мм');
ok($rid===1 AND Store::$p[1]['current_revision_id']===0,'постановка не заменяет модель');
ok(Service_Nj120_JobQueue::$jobs[0][1]['revision_id']===1,'id ревизии в payload');
ok( ! isset(Service_Nj120_JobQueue::$jobs[0][1]['photo']),'фото не в payload');
refuses(function()use($s,$code){$s::queue($code,8,'Правка');},'нет записи в чужой проект');
refuses(function()use($s,$code){$s::queue($code,7,'Второй запуск');},'один активный запуск');
$token=Store::$r[1]['run_token']; $body=['revision_id'=>1,'run_token'=>$token];
refuses(function()use($s,$body){$s::worker(array_replace($body,['run_token'=>'wrong']),'context');},'неверный токен версии');
$ctx=$s::worker($body,'context'); ok($ctx['prompt']==='Сделай отверстие 5 мм' AND Store::$r[1]['status']==='running','контекст и начало');
refuses(function()use($s,$body){$s::worker($body+['source'=>'cube(30);'],'result');},'нет файлов — нет готовой версии');
// Загруженные файлы формируем непосредственно: is_uploaded_file намеренно не отключается тестовым флагом в production.
$stl=str_repeat("\0",80).pack('V',1).str_repeat("\0",50);
$glb=base64_decode('Z2xURgIAAAAABAAAlAMAAEpTT057ImFzc2V0Ijp7InZlcnNpb24iOiIyLjAiLCJnZW5lcmF0b3IiOiJuMmoucnUgbmo2NjcgKFNUTC9WUk1MXHUyMTkyR0xCKSJ9LCJzY2VuZSI6MCwic2NlbmVzIjpbeyJub2RlcyI6WzBdLCJuYW1lIjoibmo2NjctYXNzZW1ibHkifV0sIm5vZGVzIjpbeyJtZXNoIjowLCJuYW1lIjoibmo2NjctYXNzZW1ibHkifV0sIm1lc2hlcyI6W3sibmFtZSI6Im5qNjY3LWFzc2VtYmx5IiwicHJpbWl0aXZlcyI6W3siYXR0cmlidXRlcyI6eyJQT1NJVElPTiI6MCwiTk9STUFMIjoxfSwiaW5kaWNlcyI6MiwibWF0ZXJpYWwiOjB9XX1dLCJtYXRlcmlhbHMiOlt7Im5hbWUiOiJwcmludCIsInBick1ldGFsbGljUm91Z2huZXNzIjp7ImJhc2VDb2xvckZhY3RvciI6WzAuNjQ2MjM1LDAuNDg1NDM1LDAuMjY4NDEsMS4wXSwibWV0YWxsaWNGYWN0b3IiOjAuMCwicm91Z2huZXNzRmFjdG9yIjowLjg1fX1dLCJhY2Nlc3NvcnMiOlt7ImJ1ZmZlclZpZXciOjAsImNvbXBvbmVudFR5cGUiOjUxMjYsImNvdW50IjozLCJ0eXBlIjoiVkVDMyIsIm1pbiI6WzAuMCwwLjAsMC4wXSwibWF4IjpbMTAuMCwxMC4wLDAuMF19LHsiYnVmZmVyVmlldyI6MSwiY29tcG9uZW50VHlwZSI6NTEyNiwiY291bnQiOjMsInR5cGUiOiJWRUMzIn0seyJidWZmZXJWaWV3IjoyLCJjb21wb25lbnRUeXBlIjo1MTIzLCJjb3VudCI6MywidHlwZSI6IlNDQUxBUiJ9XSwiYnVmZmVyVmlld3MiOlt7ImJ1ZmZlciI6MCwiYnl0ZU9mZnNldCI6MCwiYnl0ZUxlbmd0aCI6MzYsInRhcmdldCI6MzQ5NjJ9LHsiYnVmZmVyIjowLCJieXRlT2Zmc2V0IjozNiwiYnl0ZUxlbmd0aCI6MzYsInRhcmdldCI6MzQ5NjJ9LHsiYnVmZmVyIjowLCJieXRlT2Zmc2V0Ijo3MiwiYnl0ZUxlbmd0aCI6OCwidGFyZ2V0IjozNDk2M31dLCJidWZmZXJzIjpbeyJieXRlTGVuZ3RoIjo4MH1dfSAgUAAAAEJJTgAAAAAAAAAAAAAAAAAAACBBAAAAAAAAAAAAAAAAAAAgQQAAAAAAAAAAAAAAAAAAgD8AAAAAAAAAAAAAgD8AAAAAAAAAAAAAgD8AAAEAAgAAAA==');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9n8AAAAASUVORK5CYII=');
$dir=$s::dir($code); mkdir($dir,0700,TRUE);
foreach (['stl'=>$stl,'glb'=>$glb,'png'=>$png] as $kind=>$bytes) { $s::validate_file($kind,$bytes); file_put_contents($s::file($code,1,$kind),$bytes); }
$s::worker($body+['source'=>'size=30; cube(size);','visual_ok'=>TRUE,'review'=>'Соответствует','attempts'=>1],'result');
ok(Store::$p[1]['current_revision_id']===1 AND Store::$r[1]['status']==='done','удачная приёмка делает версию текущей');
ok($s::worker($body,'result')['already_done'],'повтор приёмки идемпотентен');
ok($s::worker($body,'fail')['already_done'],'поздний fail не портит done');
$s::change($code,7,FALSE,'submit',[]); ok(Store::$p[1]['review_revision_id']===1,'закрепление review');
refuses(function()use($s,$code){$s::change($code,8,FALSE,'publish',[]);},'чужой не публикует');
refuses(function()use($s,$code){$s::change($code,8,FALSE,'delete',['armed'=>'1']);},'чужой не-модератор не удаляет проект');
refuses(function()use($s,$code){$s::change($code,8,TRUE,'delete',['armed'=>'0']);},'модератор удаляет проект только подтверждённо');
$s::change($code,8,TRUE,'publish',[]); ok(Store::$p[1]['published_revision_id']===1,'модератор публикует');
$rid2=$s::queue($code,7,'Новая приватная правка');
ok($rid2===2 AND Store::$p[1]['published_revision_id']===0 AND Store::$p[1]['publication']==='draft','новая задача снимает деталь с каталога');
ok( ! $s::can_read_revision(Store::$p[1],2,0,FALSE),'новая ревизия закрыта гостю');
ok( ! $s::can_read_revision(Store::$p[1],1,0,FALSE),'снятая по правке версия закрыта гостю');
$s::worker(['revision_id'=>2,'run_token'=>Store::$r[2]['run_token'],'error'=>'Ошибка рендера'],'fail');
ok(Store::$p[1]['current_revision_id']===1 AND Store::$r[2]['status']==='error','ошибка не заменяет удачную модель');
ok($s::worker(['revision_id'=>2,'run_token'=>Store::$r[2]['run_token']],'fail')['already_failed'],'повтор fail идемпотентен');
$s::save_limits(7,['projects'=>'','daily_runs'=>'2']); ok($s::limits(7)['runs_used']===3,'неудача учитывается; авто-карточка первой сборки тоже списывает квоту');
refuses(function()use($s,$code){$s::queue($code,7,'Квота кончилась');},'суточный лимит');
$s::save_limits(7,['projects'=>'20','daily_runs'=>'5']);
Service_Nj120_JobQueue::$refuse=TRUE;
refuses(function()use($s,$code){$s::queue($code,7,'Сбой очереди');},'rollback при сбое очереди',RuntimeException::class);
ok(count(Store::$r)===2,'сбой очереди не расходует квоту'); Service_Nj120_JobQueue::$refuse=FALSE;
$rid3=$s::queue($code,7,'Просроченная попытка'); Store::$r[$rid3]['_expired']=TRUE;
$s::revisions(1); ok(Store::$r[$rid3]['status']==='error','смерть воркера закрывается по дедлайну');
refuses(function()use($s,$rid3){$s::worker(['revision_id'=>$rid3,'run_token'=>Store::$r[$rid3]['run_token']],'context');},'запоздалый callback не воскрешает версию');
// Параметры — литералы, а не исполняемые выражения или доверенные имена клиента.
$param = 'Service_Nj669_Parameters'; $workspace = 'Service_Nj669_Workspace'; $assist = 'Service_Nj669_Assist';
$annotated = "// @param 1 100 1 Ширина <img>\nwidth=30;\n// @param -10 20 .5 Смещение\noffset=-2.5;\n\$fn=48;\ncube([width,10,offset+10]);";
$fields = $param::parse($annotated); ok(array_keys($fields)===['width','offset'],'размеченные числовые поля заголовка');
$edited = $param::apply($annotated,['width'=>'42','offset'=>'1e1']);
ok(str_contains($edited,'width=42.0;') AND str_contains($edited,'offset=10.0;') AND str_contains($edited,'cube([width,10,offset+10]);'),'применение не меняет геометрию/комментарии');
foreach ([['width'=>'30; import("x");'],['width'=>['30']],['width'=>'NaN'],['width'=>'1e300'],['width'=>'0'],['evil'=>'20'],[]] as $values)
{ refuses(function()use($param,$annotated,$values){$param::apply($annotated,$values);},'некорректные/поддельные параметры'); }
ok($param::parse("// @param 1 100 1 Размер\nx=10; x=20; cube(x);")===[],'повторное объявление не редактируется');
ok($param::parse("// @param 1 100 1 Размер\nx=1+2; cube(x);")===[],'выражение не литерал');
ok($param::parse("cube(1);\n// @param 1 100 1 Размер\nx=10;")===[],'не трогаем тело геометрии');
ok($param::parse("// @param 1 1e309 1 Размер\nx=10;")===[],'бесконечный диапазон отклонён');
$many=''; for($i=0;$i<25;$i++){$many.="// @param 1 100 1 Размер\nx$i=10;\n";}
ok(count($param::parse($many))===24 AND ! isset($param::parse($many."x0=20;")['x0']),'лимит 24 и повтор после лимита');
// Типы полей: bool, choice, text — разбор, нормализация, запись литералов, восстановление из JSON.
$typed = "// Коробка\n// @param 20 200 1 Длина, мм\nlen=80;\n// @bool Крышка\nlid=false;\n// @choice Крепёж | 1:M3 | 2:M4\nfit=2;\n// @text Надпись\nlabel_txt=\"Вт-1\";\n\$fn=48;\ncube([len,10,lid?2:1]);";
$tf = $param::parse($typed);
ok(array_keys($tf)===['len','lid','fit','label_txt'],'типы полей в порядке объявления');
ok($tf['lid']['type']==='bool' AND $tf['lid']['value']===FALSE AND $tf['fit']['type']==='choice' AND count($tf['fit']['options'])===2 AND $tf['label_txt']['type']==='text' AND $tf['label_txt']['value']==='Вт-1','типизированные значения разбора');
ok($param::normalize($typed,['len'=>'120','lid'=>'1','fit'=>'1','label_txt'=>'Вт-2'])===['len'=>120.0,'lid'=>TRUE,'fit'=>1.0,'label_txt'=>'Вт-2'],'normalize приводит значения к типам');
$typedEdit = $param::apply($typed,['len'=>'120','lid'=>'1','fit'=>'2','label_txt'=>'Вт-2']);
ok(str_contains($typedEdit,'len=120.0;') AND str_contains($typedEdit,'lid=true;') AND str_contains($typedEdit,'fit=2.0;') AND str_contains($typedEdit,'label_txt="Вт-2";') AND str_contains($typedEdit,'cube([len,10,lid?2:1]);'),'apply пишет литералы по типам, тело не меняется');
foreach ([['lid'=>'yes'],['fit'=>'3'],['fit'=>'abc'],['label_txt'=>'a"b'],['label_txt'=>str_repeat('a',41)],['label_txt'=>'a;b'],['len'=>'abc'],['len'=>'19'],['nope'=>'1']] as $values)
{ refuses(function()use($param,$typed,$values){$param::normalize($typed,$values);},'отказ по типу/диапазону/варианту/тексту'); }
$restored = $param::restore($tf,['len'=>150.0,'lid'=>TRUE,'fit'=>1.0,'label_txt'=>'Х']);
ok($restored['len']['value']===150.0 AND $restored['lid']['value']===TRUE AND $restored['fit']['value']===1.0 AND $restored['label_txt']['value']==='Х','restore берёт сохранённые значения нужного типа');
$restored2 = $param::restore($tf,['len'=>'999','lid'=>1,'fit'=>3.0,'label_txt'=>'a"b']);
ok($restored2['len']['value']===80.0 AND $restored2['lid']['value']===FALSE AND $restored2['fit']['value']===2.0 AND $restored2['label_txt']['value']==='Вт-1','restore отбрасывает чужой тип, вне диапазона и не из списка');
// Карточки-снимки: и публикация, и заявка устойчивы к последующим приватным правкам.
$publishedTitle = Store::$p[1]['title'];
refuses(function()use($workspace,$code){$workspace::edit($code,8,['title'=>'Чужая']);},'карточку меняет владелец');
foreach ([['title'=>''],['title'=>str_repeat('я',201)],['title'=>'Тест','tags'=>['x']]] as $bad)
{ refuses(function()use($workspace,$bad){$workspace::metadata($bad);},'валидация карточки'); }
$workspace::edit($code,7,['title'=>'Секретный черновик','summary'=>'Не публиковать','tags'=>'скрытый']);
ok(Store::$p[1]['published_revision_id']===0 AND Store::$p[1]['publication']==='draft','правка карточки снимает деталь с каталога');
ok(!str_contains(render_page('catalog',0),'Секретный черновик'),'каталог не содержит draft');
refuses(function()use($code){render_page('project',0,FALSE,['code'=>$code,'mode'=>'c']);},'снятая деталь закрыта публике',HTTP_Exception::class);
$s::change($code,7,FALSE,'submit',[]);
ok(str_contains(Store::$p[1]['review_meta'],'Секретный черновик'),'отправка берёт снимок текущей карточки');
$s::change($code,8,TRUE,'publish',[]);
ok($workspace::public_project(Store::$p[1])['title']==='Секретный черновик','publish публикует снимок отправки');
$workspace::edit($code,7,['title'=>'Более новый черновик','summary'=>'Следующая карточка']);
ok(Store::$p[1]['publication']==='draft' AND Store::$p[1]['title']==='Более новый черновик','новая правка снимает публикацию');
// Восстанавливаем ожидаемый в baseline заголовок; затем ещё раз публикуем снимок.
$workspace::edit($code,7,['title'=>$publishedTitle,'summary'=>'Сделай отверстие 5 мм','tags'=>'печать']);
$s::change($code,7,FALSE,'submit',[]); $s::change($code,8,TRUE,'publish',[]);
$workspace::checkpoint($code,7,1,'Первый вариант <script>',TRUE);
ok(Store::$r[1]['label']==='Первый вариант <script>' AND Store::$r[1]['milestone']==='true','контрольная точка готовой версии');
refuses(function()use($workspace,$code){$workspace::checkpoint($code,8,1,'Чужая',TRUE);},'чужая отметка запрещена');
refuses(function()use($workspace,$code){$workspace::checkpoint($code,7,2,'Ошибка',TRUE);},'ошибочная версия не checkpoint');
refuses(function()use($workspace,$code){$workspace::checkpoint($code,7,1,'',TRUE);},'точке нужно имя');
// Отмена и терминальный статус очереди: прежняя версия и публикация сохраняются.
$s::save_limits(7,['projects'=>'20','daily_runs'=>'100']);
$cancel=$s::queue($code,7,'Отменяемая попытка'); $cb=['revision_id'=>$cancel,'run_token'=>Store::$r[$cancel]['run_token']];
file_put_contents($s::file($code,$cancel,'png3'),$png);
refuses(function()use($workspace,$code,$cancel){$workspace::close_revision($code,$cancel,'x',8);},'отменять чужую задачу нельзя');
$workspace::close_revision($code,$cancel,'Остановлено владельцем',7);
ok(Store::$r[$cancel]['status']==='error' AND ! is_file($s::file($code,$cancel,'png3')) AND Store::$p[1]['current_revision_id']===1,'отмена очищает staging, не меняет current');
refuses(function()use($s,$cb){$s::worker($cb+['source'=>'cube(2);'],'result');},'поздний result после отмены запрещён');
$dead=$s::queue($code,7,'Задача без callback'); $jid=Store::$r[$dead]['job_id'];
Service_Nj120_JobQueue::$states[$jid]=['status'=>'processing','stage'=>'Проверяю ракурсы <script>','silent_sec'=>120,'elapsed_sec'=>90];
$live=$workspace::live(Store::$p[1],$s::revisions(1));
ok($live['busy'] AND $live['stage']==='Проверяю ракурсы <script>' AND $live['silent_sec']===120,'этап и heartbeat через публичный JobQueue API');
Service_Nj120_JobQueue::$states[$jid]=['status'=>'failed','error'=>'Воркер потерян'];
ok(!$workspace::live(Store::$p[1],$s::revisions(1))['busy'] AND Store::$r[$dead]['status']==='error','смерть очереди закрывает ревизию сразу');
// Подсказки карточек: снимок исходных данных, общий лимит, явное применение.
$oldTitle=Store::$p[1]['title']; $tid=0;
foreach (Store::$tasks as $k=>$t) { if ($t['kind']==='caption' AND (int) $t['project_id']===1 AND $t['status']==='queued') { $tid=$k; } }
ok($tid>0,'первая удачная сборка сама ставит авто-карточку'); $task=Store::$tasks[$tid];
ok($s::limits(7)['runs_used']===6 AND Service_Nj120_JobQueue::$jobs[$task['job_id']-1][0]==='nj669.caption','caption учитывается в общей квоте');
refuses(function()use($assist,$code){$assist::queue('caption',8,$code,'',FALSE,TRUE);},'чужой помощник запрещён');
refuses(function()use($assist,$code){$assist::queue('caption',7,$code);},'владелец не запускает ручную перегенерацию');
refuses(function()use($assist,$code){$assist::queue('caption',7,$code,'',FALSE,TRUE);},'один активный caption');
$tb=['task_id'=>$tid,'run_token'=>$task['run_token']];
refuses(function()use($assist,$tb){$assist::worker(array_replace($tb,['run_token'=>'wrong']),'assist_context');},'токен помощника обязателен');
$context=$assist::worker($tb,'assist_context');
ok($context['kind']==='caption' AND $context['context']['title']===$oldTitle,'помощник получает исходный снимок');
$proposal=['title'=>'Рекомендация <img>','summary'=>'Описание','tags'=>'крепление, мм'];
$assist::worker($tb+['result'=>$proposal],'assist_result');
ok(Store::$p[1]['title']==='Рекомендация <img>' AND Store::$p[1]['tags']==='крепление, мм' AND $workspace::public_project(Store::$p[1])['title']===$oldTitle,'автокарточка пишется в черновик, публикация не меняется');
ok($assist::worker($tb+['result'=>$proposal],'assist_result')['already_done'],'callback помощника идемпотентен');
$workspace::edit($code,7,['title'=>$oldTitle,'summary'=>'Сделай отверстие 5 мм']);
$failTask=$assist::queue('caption',7,$code,'',FALSE,TRUE); $fb=['task_id'=>$failTask,'run_token'=>Store::$tasks[$failTask]['run_token']];
$assist::worker($fb+['error'=>'LLM недоступна'],'assist_fail');
ok($assist::worker($fb,'assist_fail')['ok'],'повтор fail помощника безопасен');
refuses(function()use($assist,$fb,$proposal){$assist::worker($fb+['result'=>$proposal],'assist_result');},'fail не воскресить результатом');
$s::save_limits(7,['projects'=>'20','daily_runs'=>'7']);
refuses(function()use($assist,$code){$assist::queue('caption',7,$code,'',FALSE,TRUE);},'caption ограничен квотой');
refuses(function()use($s,$code){$s::queue($code,7,'Нет квоты после caption');},'геометрия учитывает caption');
refuses(function()use($assist){$assist::queue('ideas',7,'','Квота идей');},'идеи тоже списывают общую квоту');
$s::save_limits(7,['projects'=>'20','daily_runs'=>'100']);
Service_Nj120_JobQueue::$refuse=TRUE; $before=count(Store::$tasks);
refuses(function()use($assist,$code){$assist::queue('caption',7,$code,'',FALSE,TRUE);},'rollback помощника при отказе очереди',RuntimeException::class);
ok(count(Store::$tasks)===$before,'отказ очереди не расходует запуск caption'); Service_Nj120_JobQueue::$refuse=FALSE;
// Идеи доступны для управления только модератору, готовый ответ сохраняется один раз.
refuses(function(){render_page('ideas',7);},'обычный пользователь не администрирует идеи',HTTP_Exception::class);
$it=$assist::queue('ideas',8,'','Мастерская'); $ib=['task_id'=>$it,'run_token'=>Store::$tasks[$it]['run_token']];
refuses(function()use($assist){$assist::queue('ideas',8,'','Ещё тема');},'одна пачка идей одновременно');
refuses(function()use($assist,$ib){$assist::worker($ib+['result'=>['ideas'=>['Одна',[1]]]],'assist_result');},'нет частичного сохранения некорректной пачки');
ok(count(Store::$ideas)===0,'rollback всей пачки');
$assist::worker($ib+['result'=>['ideas'=>['Крючок 20 мм','Крючок 20 мм','Заглушка <script>']]],'assist_result');
$assist::worker($ib+['result'=>['ideas'=>['Лишняя']]],'assist_result');
ok(count(Store::$ideas)===2,'идеи дедуплицированы, повторы не размножаются');
ok(str_contains(render_page('ideas',8,TRUE),'Заглушка &lt;script&gt;'),'копилка рендерится с экранированием');
ok(str_contains(render_page('create',7),'Крючок 20 мм'),'копилка подключена к форме создания');
// Очереди генерации выше снимают публикацию (правило автоснятия); для проверки ACL восстанавливаем её напрямую.
Store::$p[1]['published_revision_id']=1; Store::$p[1]['publication']='published';
// Отдельные ракурсы проходят такую же проверку формата и ACL, как основное превью.
Store::$r[1]['source']=$annotated;
foreach(['png2','png3'] as $kind){$s::validate_file($kind,$png);file_put_contents($s::file($code,1,$kind),$png);}
Store::$r[1]['files']=json_encode(['png'=>strlen($png),'png2'=>strlen($png),'png3'=>strlen($png)]);
foreach(['png2','png3'] as $kind)
{
	refuses(function()use($s,$kind){$s::validate_file($kind,'not PNG');},'некорректный дополнительный PNG');
	$c=controller(0);$c->request->params=['code'=>$code,'revision'=>1,'kind'=>$kind];$c->action_file();ok($c->response->body()===$png AND $c->response->headers['Content-Type']==='image/png','ракурс выдан с image/png');
}
ok(count(Service_Nj597_Notify::$sent)===2,'по одному уведомлению на результат/ошибку, не на повтор');
// SQL-ветки поиска/пагинации попадут в настоящий EXPLAIN, дублёр не эмулирует PostgreSQL ILIKE.
$s::listing('moderate',8,'печать',2); $s::listing('catalog',0,'печать',2); $s::listing('projects',7,'draft',1); $s::usage(7,'отверстие','error',2);$s::revisions(1,2);

// Контроллеры действительно рендерят собственные view: никакого $this или необъявленных значений.
$html=render_page('project',7,FALSE,['code'=>$code,'mode'=>'p']);
ok(str_contains($html,'/assets/js/nj667-stl.js') AND str_contains($html,'data-state="done"'),'контракт GLB-viewer');
View::$snapshots['nj669/project-owner']=$html;
ok(str_contains($html,'Кронштейн &lt;script&gt;') AND ! str_contains($html,'<script>Кронштейн'),'HTML экранирован');
$html=render_page('project',0,FALSE,['code'=>$code,'mode'=>'c'],['revision'=>2]);
View::$snapshots['nj669/project-public']=$html;
ok(str_contains($html,'Версия №1') AND ! str_contains($html,'Новая приватная правка'),'гость видит только закреплённую версию');
refuses(function()use($code){render_page('project',8,FALSE,['code'=>$code,'mode'=>'p']);},'чужой workspace 404',HTTP_Exception::class);
refuses(function(){render_page('settings',7);},'настройки не для обычного пользователя',HTTP_Exception::class);
foreach (['index','catalog','projects','create','usage','limits'] as $action) { ok(strlen(render_page($action,7))>100,'view '.$action); }
foreach (['settings','moderate','limits'] as $action) { ok(strlen(render_page($action,8,TRUE))>100,'moderator view '.$action); }
ok(render_page('create')==='Вход обязателен','гость видит замок');
$c=controller(7); $c->request->verb='POST'; $c->request->params=['code'=>$code,'mode'=>'p']; $c->request->p=['op'=>'unpublish','csrf'=>'bad']; refuses(function()use($c){$c->action_project();},'CSRF обязателен',HTTP_Exception::class);
// Приёмка пяти файлов совместима с прежним воркером, присылавшим одно превью.
$five=$s::queue($code,7,'Проверка сохранения трёх ракурсов');
foreach(['stl'=>$stl,'glb'=>$glb,'png'=>$png,'png2'=>$png,'png3'=>$png] as $kind=>$bytes){file_put_contents($s::file($code,$five,$kind),$bytes);}
$s::worker(['revision_id'=>$five,'run_token'=>Store::$r[$five]['run_token'],'source'=>$annotated,'visual_ok'=>TRUE],'result');
ok(count(json_decode(Store::$r[$five]['files'],TRUE))===5,'приёмка сохраняет пять проверенных производных');
$s::change($code,7,FALSE,'restore',['revision'=>1]);
// Настоящие POST-контроллеры: формы связаны с сервисами и завершаются PRG, не только рисуются.
function post_page($action,$uid,$mod,$params,$data)
{
	$c=controller($uid,$mod);$c->request->verb='POST';$c->request->params=$params;$c->request->p=$data+['csrf'=>'csrf-ok'];
	try {$c->{'action_'.$action}();}catch(HTTP_Exception $e){ok($e->getCode()===303,'успешный POST → 303');return $c;}
	ok(FALSE,'POST не завершился редиректом: '.json_encode(end(Log_Plain::$events)));
}
$pp=['code'=>$code,'mode'=>'p'];
ok(!str_contains(render_page('project',7,FALSE,$pp),'name=\"title\"'),'владелец не получает поля карточки');
refuses(function()use($pp){$c=controller(7);$c->request->verb='POST';$c->request->params=$pp;$c->request->p=['op'=>'caption','csrf'=>'csrf-ok'];$c->action_project();},'владелец не перегенерирует карточку (403)',HTTP_Exception::class);
post_page('project',7,FALSE,$pp,['op'=>'checkpoint','revision'=>1,'label'=>'Точка из формы','milestone'=>'1']);
ok(Store::$r[1]['label']==='Точка из формы','POST контрольной точки');
// Очередь проверки ракурсов и восстановление выше сняли публикацию; возвращаем состояние стенда напрямую.
Store::$p[1]['published_revision_id']=1; Store::$p[1]['review_revision_id']=1; Store::$p[1]['publication']='published';
ok(Store::$p[1]['publication']==='published' AND Store::$p[1]['published_revision_id']===1,'до ползунков версия опубликована');
post_page('project',7,FALSE,$pp,['op'=>'export','revision'=>1,'params'=>['width'=>'44']]);
ok(Store::$p[1]['published_revision_id']===1 AND Store::$p[1]['review_revision_id']===1 AND Store::$p[1]['publication']==='published','ползунки владельца собирают временный STL и не снимают версию с каталога и модерации');
Store::$r[1]['status']='done'; Store::$r[1]['pending_source']=NULL; Store::$r[1]['pending_params']=NULL; // пересчёт здесь проверен; сбрасываем ожидание для следующих шагов
$s::change($code,7,FALSE,'unpublish',[]);
$rc_before=count(Store::$r);
post_page('project',7,FALSE,$pp,['op'=>'parameters','revision'=>1,'params'=>['width'=>'44','offset'=>'-1']]);
$prid=1;ok(count(Store::$r)===$rc_before AND Store::$r[1]['status']==='queued' AND str_contains(Store::$r[1]['pending_source'],'width=44.0;') AND end(Service_Nj120_JobQueue::$jobs)[1]['code_only'],'POST параметров пересчитывает ту же версию без новой ревизии');
$busyHtml=render_page('project',7,FALSE,$pp);View::$snapshots['nj669/project-busy']=$busyHtml;
ok(str_contains($busyHtml,'data-solid-stage') AND str_contains($busyHtml,'value="cancel"'),'рабочая страница показывает этап/отмену');
$poll=controller(7);$poll->request->params=$pp;$poll->request->q=['state'=>'1'];$poll->action_project();$state=json_decode($poll->response->text,TRUE);
ok($state['busy'] AND ! str_contains($poll->response->text,'run_token'),'поллинг не выдаёт токены или контекст');
post_page('project',7,FALSE,$pp,['op'=>'cancel','revision'=>$prid]);
ok(Store::$r[$prid]['status']==='done' AND Store::$r[$prid]['pending_source']===NULL AND Store::$p[1]['current_revision_id']===1,'отмена пересчёта оставляет прежнюю версию готовой');
$s::change($code,7,FALSE,'submit',[]); $s::change($code,8,TRUE,'publish',['note'=>'']);
ok(Store::$p[1]['published_revision_id']===1, 'версия снова опубликована после проверки ползунков');
post_page('project',8,TRUE,$pp,['op'=>'caption']);$postTid=count(Store::$tasks);
ok(Store::$tasks[$postTid]['kind']==='caption' AND $postTid>0,'администратор перегенерирует карточку');
$regen=['title'=>'Регенерация','summary'=>'Новое описание','tags'=>'тест'];
$assist::worker(['task_id'=>$postTid,'run_token'=>Store::$tasks[$postTid]['run_token'],'result'=>$regen],'assist_result');
ok(Store::$p[1]['title']==='Регенерация','перегенерация заменяет черновик');
$workspace::edit($code,7,['title'=>$publishedTitle,'summary'=>'Сделай отверстие 5 мм']);
post_page('ideas',8,TRUE,[],['op'=>'save','idea'=>0,'theme'=>'Вручную','body'=>'Втулка 8 мм']);$manual=count(Store::$ideas);
post_page('ideas',8,TRUE,[],['op'=>'save','idea'=>$manual,'theme'=>'Редактура','body'=>'Втулка 9 мм']);
ok(Store::$ideas[$manual]['body']==='Втулка 9 мм','ручная правка идеи');
post_page('ideas',8,TRUE,[],['op'=>'delete','idea'=>$manual,'armed'=>'1']);ok(!isset(Store::$ideas[$manual]),'подтверждённое удаление идеи');
$stale=$assist::queue('caption',7,$code,'',FALSE,TRUE);Store::$tasks[$stale]['created_at']='2000-01-01 00:00:00+00';
refuses(function()use($assist,$stale){$assist::worker(['task_id'=>$stale,'run_token'=>Store::$tasks[$stale]['run_token']],'assist_context');},'просроченный помощник отклоняется при callback');
$assist::recent('caption',1);ok(Store::$tasks[$stale]['status']==='error','дедлайн помощника снимает блокировку');
post_page('ideas',8,TRUE,[],['op'=>'generate','theme'=>'Органайзеры']);
$pendingHtml=render_page('ideas',8,TRUE);View::$snapshots['nj669/ideas-pending']=$pendingHtml;
ok(str_contains($pendingHtml,'data-solid-poll="/solid3d/ideas?state=1"'),'опрос идей связан с endpoint');
$ic=controller(8,TRUE);$ic->request->q=['state'=>'1'];$ic->action_ideas();$istate=json_decode($ic->response->text,TRUE);
ok($istate['busy'] AND !str_contains($ic->response->text,'run_token'),'JSON идей содержит лишь состояние');

// Проверяем strict header авторизацию независимо от окружения development.
putenv('WORKER_API_TOKEN='); ok( ! (new WorkerStand)->auth(''),'пустой worker token закрыт');
putenv('WORKER_API_TOKEN=secret'); ok( ! (new WorkerStand)->auth('wrong'),'неверный worker token закрыт'); ok((new WorkerStand)->auth('secret'),'правильный worker token'); putenv('WORKER_API_TOKEN=');
// Реальный route engine проверяет порядок и доступность всех адресов.
require SYSPATH.'classes/Kohana/Route.php'; require SYSPATH.'classes/Route.php';
require APPPATH.'modules/nj669_solid3d/init.php';
foreach (['solid3d','solid3d/create','solid3d/catalog','solid3d/projects','solid3d/limits','solid3d/settings','solid3d/usage','solid3d/moderate','solid3d/p/'.$code,'solid3d/c/'.$code,'solid3d/f/'.$code.'/1/glb','solid3d/w/context','solid3d/w/upload','solid3d/w/result','solid3d/w/fail','solid3d/ideas','solid3d/w/assist_context','solid3d/w/assist_result','solid3d/w/assist_fail','solid3d/f/'.$code.'/1/png2','solid3d/f/'.$code.'/1/png3'] as $uri)
{
	$found=FALSE; foreach (Route::all() as $route) { if ($route->matches(new class($uri) extends Request { public $uri; public function __construct($u){$this->uri=$u;} public function uri(){return $this->uri;} public function method(){return 'GET';} public function headers($k){return '';} })) { $found=TRUE; break; } } ok($found,'route '.$uri);
}
// Пересчёты выше снимают публикацию; для выдачи публичных файлов восстанавливаем опубликованную версию.
Store::$p[1]['published_revision_id']=1; Store::$p[1]['publication']='published';
// Выдача файлов тоже проходит через реальный контроллер и проверку revision-id.
$c=controller(0); $c->request->params=['code'=>$code,'revision'=>1,'kind'=>'glb']; $c->action_file();
ok($c->response->body()===$glb AND $c->response->headers['Content-Type']==='model/gltf-binary','публичный GLB выдаётся через контроллер');
$c=controller(0); $c->request->params=['code'=>$code,'revision'=>2,'kind'=>'stl'];
refuses(function()use($c){$c->action_file();},'приватные файлы не открыты гостю',HTTP_Exception::class);
$c=controller(8); $c->request->params=['code'=>$code,'revision'=>1,'kind'=>'glb'];
$c->action_file(); ok($c->response->body()===$glb,'публичную версию читает и другой аккаунт');
$c=controller(7); $c->abilities['nj669.start']=FALSE; $c->before();
refuses(function()use($c){$c->action_create();},'запуск проверяет ACL',HTTP_Exception::class);
$s::change($code,7,FALSE,'unpublish',[]); ok( ! $s::can_read_revision(Store::$p[1],1,0,FALSE),'снятая публикация закрывает файлы');
refuses(function()use($s,$code){$s::change($code,7,FALSE,'delete',[]);},'удаление требует вооружённой кнопки');
// Название необязательно: пустое поле — начало описания; без описания — нейтральная подпись.
ok($s::title_for('  Кронштейн   для  полки ','текст',NULL)==='Кронштейн для полки','название: пробелы схлопываются');
ok($s::title_for('','Кронштейн 60 мм',NULL)==='Кронштейн 60 мм','без названия берётся короткое описание целиком');
$longText=str_repeat('кронштейн для полки с четырьмя отверстиями ',4); $lt=$s::title_for('',$longText,NULL);
ok(str_ends_with($lt,'…') AND mb_strlen($lt)<=81 AND str_starts_with(trim($longText),rtrim($lt,'…')) AND ! str_ends_with(rtrim($lt,'…'),'и'),'длинное описание обрезается по слову с многоточием');
ok($s::title_for('','',NULL)==='Новая деталь' AND $s::title_for('','',['ext'=>'jpg'])==='Деталь по фото','пустое название без текста и с фото');
$np=$s::create(7,'','Пластина 40 мм, отверстие 5 мм',NULL);
ok($np['title']==='Пластина 40 мм, отверстие 5 мм','создание без названия берёт описание');
$ch=render_page('create',7); preg_match('/<input id="solid-title"[^>]*>/',$ch,$tm);
ok(isset($tm[0]) AND ! str_contains($tm[0],'required') AND str_contains($ch,'необязательно'),'поле названия в форме создания не обязательное');

// ИИ-проверка ракурсов: отдельная задача по запросу администратора; квота владельца и текущая деталь не меняются.
$quotaBefore=$s::limits(7)['runs_used']; $jobsBefore=count(Service_Nj120_JobQueue::$jobs); $currentBefore=Store::$p[1]['current_revision_id'];
refuses(function()use($s,$code){$s::queue_verify($code,7,1,FALSE);},'владелец не запускает проверку ИИ');
refuses(function()use($s,$code){$s::queue_verify($code,9,999,TRUE);},'проверяется только существующая готовая версия');
ok(count(Service_Nj120_JobQueue::$jobs)===$jobsBefore AND Store::$r[1]['vlm_status']==='none','отказы не ставят задачу и не меняют статус');
ok($s::queue_verify($code,9,1,TRUE)===1,'администратор ставит проверку версии');
$vj=Service_Nj120_JobQueue::$jobs[$jobsBefore]; $vo=$vj[2];
ok($vj[0]==='nj669.verify' AND $vo['max_attempts']===1 AND $vo['timeout_sec']===600 AND $vo['created_by']===9 AND $vo['visitor_id']==='u:9','проверка — отдельная задача nj669.verify: одна попытка, 10 минут, автор — администратор');
ok( ! isset($vj[1]['photo']) AND ! isset($vj[1]['source']) AND $vj[1]['revision_id']===1 AND strlen($vj[1]['run_token'])===48,'в payload проверки только id версии и токен');
ok($s::limits(7)['runs_used']===$quotaBefore AND Store::$p[1]['current_revision_id']===$currentBefore,'проверка не тратит квоту владельца и не меняет текущую деталь');
refuses(function()use($s,$code){$s::queue_verify($code,9,1,TRUE);},'вторая проверка той же версии не ставится, пока первая идёт');

$vb=['revision_id'=>1,'run_token'=>Store::$r[1]['vlm_run_token']];
refuses(function()use($s){$s::verify_worker(['revision_id'=>1,'run_token'=>'wrong'],'verify_context');},'чужой токен проверки отклонён');
$vctx=$s::verify_worker($vb,'verify_context');
ok(count($vctx['views'])===3 AND base64_decode($vctx['views'][0])===$png AND $vctx['photo']===NULL AND Store::$r[1]['vlm_status']==='running','контекст проверки: три ракурса, без фото, статус running');
$s::verify_worker($vb+['visual_ok'=>TRUE,'review'=>'Ракурсы совпадают с описанием'],'verify_result');
ok(Store::$r[1]['vlm_status']==='done' AND Store::$r[1]['visual_ok']==='true' AND Store::$r[1]['review']==='Ракурсы совпадают с описанием' AND Store::$r[1]['status']==='done' AND Store::$p[1]['current_revision_id']===$currentBefore,'вердикт записан, версия и текущая деталь не изменились');
ok(($s::verify_worker($vb,'verify_result')['already_closed'] ?? FALSE)===TRUE,'повтор вердикта идемпотентен');
refuses(function()use($s,$vb){$s::verify_worker($vb,'verify_context');},'закрытую проверку нельзя открыть заново');

// Новая проверка не стирает прежний вердикт до ответа; сбой — отдельное состояние с экранированием.
$s::queue_verify($code,9,1,TRUE);
ok(Store::$r[1]['vlm_status']==='queued' AND Store::$r[1]['visual_ok']==='true','новая проверка не стирает прежний вердикт до ответа');
$fb=['revision_id'=>1,'run_token'=>Store::$r[1]['vlm_run_token']];
$s::verify_worker($fb+['error'=>'Модель не ответила <b>'],'verify_fail');
ok(Store::$r[1]['vlm_status']==='error' AND Store::$r[1]['vlm_error']==='Модель не ответила <b>' AND Store::$r[1]['visual_ok']==='true','сбой проверки не стирает прежний вердикт');
$pp=['code'=>$code,'mode'=>'p'];
$vhtml=render_page('project',9,TRUE,$pp);
ok(str_contains($vhtml,'Модель не ответила &lt;b&gt;') AND str_contains($vhtml,'Проверить снова') AND str_contains($vhtml,'value="vlm"'),'администратор видит ошибку (экранированную) и кнопку повтора');
$ohtml=render_page('project',7,FALSE,$pp);
ok(! str_contains($ohtml,'Проверка ракурсов') AND ! str_contains($ohtml,'value="vlm"') AND ! str_contains($ohtml,'Модель не ответила'),'владелец не видит проверку ракурсов и её кнопку');
// POST op=vlm от владельца: отказ показывается на странице, статус не меняется.
$c=controller(7,FALSE); $c->request->verb='POST'; $c->request->params=$pp; $c->request->p=['op'=>'vlm','revision'=>1,'csrf'=>'csrf-ok'];
$before=Store::$r[1]['vlm_status']; $c->action_project();
ok(str_contains((string)$c->template->content,'Проверку запускает администратор') AND Store::$r[1]['vlm_status']===$before,'POST op=vlm от владельца отклонён без изменения версии');

// Зависшая проверка закрывается через 15 минут, а не 35: потерянный воркер не держит страницу в занятом состоянии.
$s::queue_verify($code,9,1,TRUE); Store::$r[1]['_vlm_expired']=TRUE; $s::expire(1);
ok(Store::$r[1]['vlm_status']==='error' AND str_contains(Store::$r[1]['vlm_error'],'Запустите её снова'),'зависшая проверка закрывается в error');
Store::$r[1]['vlm_status']='done'; unset(Store::$r[1]['_vlm_expired']);
// Администратор запускает проверку через POST: задача ставится, редирект на версию.
$c=controller(9,TRUE); $c->request->verb='POST'; $c->request->params=$pp; $c->request->p=['op'=>'vlm','revision'=>1,'csrf'=>'csrf-ok'];
try { $c->action_project(); ok(FALSE,'POST администратора должен редиректить'); } catch (HTTP_Exception $e) { ok($e->getCode()===303 AND Store::$r[1]['vlm_status']==='queued','администратор ставит проверку через POST и получает 303'); }

// Состояние страницы: занятость проверки видна в JSON и на странице, но не блокирует владельца.
ok(Store::$r[1]['vlm_status']==='queued' AND Controller_Nj669_Solid3d::verifying($s::revisions(1)),'список версий показывает идущую проверку');

// Безопасность разметки: все поля и формы модуля — без автозаполнения браузера.
$fields=0;
foreach (View::$snapshots as $name=>$html)
{
	preg_match_all('/<(input|textarea|select)\b[^>]*>/',$html,$mm);
	foreach ($mm[0] as $tag) { if (str_contains($tag,'type="hidden"')) { continue; } $fields++; ok(str_contains($tag,'autocomplete="off"'),'автозаполнение выключено: '.$name.' '.substr($tag,0,80)); }
	preg_match_all('/<form\b[^>]*>/',$html,$ff);
	foreach ($ff[0] as $tag) { ok(str_contains($tag,'autocomplete="off"'),'форма без autocomplete=off: '.$name.' '.substr($tag,0,80)); }
}
ok($fields>50,'проверка полей охватила реальные страницы ('.$fields.')');
// ---- v5: ползунки меняют ту же версию, удаление версий, скрытие модели и кода ----
$s::save_limits(7,['projects'=>'','daily_runs'=>'50']);
$zp_code=$s::create(7,'Пластина','Пластина с двумя отверстиями')['code']; $zp=$s::project($zp_code)['id'];
$zr=$s::queue($zp_code,7,'Пластина 80 на 40 мм с двумя отверстиями');
$zsrc="// Пластина\n// @param 20 200 1 Длина, мм\nlen = 80;\n// @param 10 100 1 Ширина, мм\nwid = 40;\n\$fn = 48;\ncube([len, wid, 4]);\n";
$zb=['revision_id'=>$zr,'run_token'=>Store::$r[$zr]['run_token']];
$zb['run_token']=Store::$r[$zr]['run_token']; $zb['run_token']=Store::$r[$zr]['run_token']; $s::worker($zb,'context'); mkdir($s::dir($zp_code),0700,TRUE);
foreach (['stl'=>$stl,'glb'=>$glb,'png'=>$png] as $kind=>$bytes) { file_put_contents($s::file($zp_code,$zr,$kind),$bytes); }
$zb['run_token']=Store::$r[$zr]['run_token']; $zb['run_token']=Store::$r[$zr]['run_token']; $s::worker($zb+['source'=>$zsrc,'attempts'=>1],'result');
ok(Store::$p[$zp]['current_revision_id']===$zr AND Store::$r[$zr]['status']==='done','первая версия текущая');
ok(Service_Nj669_Parameters::parse(Store::$r[$zr]['source'])['len']['value']===80.0, 'параметры читаются из кода');
$s::save_limits(7,['projects'=>'20','daily_runs'=>'0']);
$runs_render=$s::limits(7)['runs_used'];
$zr3=$s::queue($zp_code,7,'Ручной пересчёт кода',$zsrc);
ok(Store::$r[$zr3]['llm_run']===FALSE AND $s::limits(7)['runs_used']===$runs_render, 'ручной пересчёт кода без модели не списывает квоту даже при нулевой');
$s::worker(['revision_id'=>$zr3,'run_token'=>Store::$r[$zr3]['run_token']],'fail');
$s::save_limits(7,['projects'=>'','daily_runs'=>'50']);
$count_before=count(Store::$r); $jobs_before=count(Service_Nj120_JobQueue::$jobs); $runs_before=$s::limits(7)['runs_used'];
Store::$r[$zr]['visual_ok']=TRUE;
$s::save_limits(7,['projects'=>'20','daily_runs'=>'0']);
$s::resize($zp_code,7,$zr,['len'=>120,'wid'=>60]);
$s::save_limits(7,['projects'=>'','daily_runs'=>'50']);
ok(count(Store::$r)===$count_before, 'ползунки не создают новую ревизию');
ok(Store::$r[$zr]['status']==='queued' AND str_contains(Store::$r[$zr]['pending_source'],'len = 120.0;') AND str_contains(Store::$r[$zr]['pending_source'],'wid = 60.0;'), 'ожидающий код несёт новые размеры');
ok(str_contains(Store::$r[$zr]['source'],'len = 80;'), 'до сборки прежний код не меняется');
ok(json_decode(Store::$r[$zr]['pending_params'],TRUE)==['len'=>120,'wid'=>60], 'JSON размеров сохранён в БД');
$last=end(Service_Nj120_JobQueue::$jobs); ok(count(Service_Nj120_JobQueue::$jobs)===$jobs_before+1 AND $last[1]['code_only']===TRUE AND $last[1]['repairs']===0 AND $last[1]['revision_id']===$zr, 'пересчёт — задача без LLM на ту же версию');
ok($s::limits(7)['runs_used']===$runs_before, 'пересчёт размеров квоту не тратит');
refuses(function()use($s,$zp_code,$zr){$s::resize($zp_code,7,$zr,['len'=>100]);},'во время сборки новые размеры не ставятся');
$zb['run_token']=Store::$r[$zr]['run_token']; $ctx=$s::worker($zb,'context');
ok($ctx['resize']===TRUE AND str_contains($ctx['source'],'len = 120.0;'), 'контекст пересчёта отдаёт новый код');
$zb['run_token']=Store::$r[$zr]['run_token']; $s::worker($zb+['error'=>'Модель не собралась. Измените описание или размеры и повторите.','detail'=>'ERROR: Parser error in line 4'],'fail');
ok(Store::$r[$zr]['status']==='done' AND str_contains(Store::$r[$zr]['error'],'не собралась') AND Store::$r[$zr]['pending_source']===NULL, 'неудачный пересчёт возвращает версию в done с ошибкой');
ok(str_contains(Store::$r[$zr]['source'],'len = 80;') AND is_file($s::file($zp_code,$zr,'glb')) AND is_file($s::file($zp_code,$zr,'stl')), 'неудачный пересчёт не трогает прежний код и файлы');
ok(Store::$r[$zr]['build_log']==='ERROR: Parser error in line 4', 'технический лог сохранён для модератора');
refuses(function()use($s,$zp_code,$zr){$s::resize($zp_code,7,$zr,['len'=>80,'wid'=>40]);},'набор, совпадающий с текущим, не ставится в очередь');
refuses(function()use($s,$zp_code,$zr){$s::resize($zp_code,7,$zr,['len'=>500]);},'значение вне диапазона отвергается');
refuses(function()use($s,$zp_code,$zr){$s::resize($zp_code,7,$zr,['foo'=>1]);},'неизвестный параметр отвергается');
refuses(function()use($s,$zp_code,$zr){$s::resize($zp_code,8,$zr,['len'=>90]);},'чужой пользователь не меняет размеры');
Store::$p[$zp]['published_revision_id']=$zr; Store::$p[$zp]['publication']='published';
$s::resize($zp_code,7,$zr,['len'=>90]);
ok(Store::$p[$zp]['published_revision_id']===0 AND Store::$p[$zp]['publication']==='draft','пересчёт размеров в коде снимает опубликованную версию с каталога');
Store::$r[$zr]['status']='done'; Store::$r[$zr]['pending_source']=NULL; Store::$r[$zr]['pending_params']=NULL;
$s::resize($zp_code,7,$zr,['len'=>120,'wid'=>60]);
$zb['run_token']=Store::$r[$zr]['run_token']; $s::worker($zb,'context');
$zb['run_token']=Store::$r[$zr]['run_token']; $s::worker($zb+['source'=>str_replace(['len = 80;','wid = 40;'],['len = 120.0;','wid = 60.0;'],$zsrc),'attempts'=>1],'result');
ok(Store::$r[$zr]['status']==='done' AND Store::$r[$zr]['pending_source']===NULL AND str_contains(Store::$r[$zr]['source'],'len = 120.0;'), 'удачный пересчёт заменяет код и файлы той же версии');
ok(json_decode(Store::$r[$zr]['params'],TRUE)==['len'=>120,'wid'=>60] AND count(Store::$r)===$count_before, 'params обновлён, ревизия та же');
ok(Store::$r[$zr]['visual_ok']===FALSE AND Store::$r[$zr]['review']==='', 'старый вердикт проверки сброшен после смены геометрии');
// Истёкший пересчёт и отмена не удаляют прежнюю модель.
$s::resize($zp_code,7,$zr,['len'=>100,'wid'=>60]); Store::$r[$zr]['_expired']=TRUE; $s::expire($zp);
ok(Store::$r[$zr]['status']==='done' AND str_contains(Store::$r[$zr]['error'],'Прежние размеры сохранены') AND is_file($s::file($zp_code,$zr,'glb')), 'истёкший пересчёт не удаляет модель');
$s::resize($zp_code,7,$zr,['len'=>90,'wid'=>60]);
Service_Nj669_Workspace::close_revision($zp_code,$zr,'Остановлено владельцем.',7);
ok(Store::$r[$zr]['status']==='done' AND is_file($s::file($zp_code,$zr,'stl')), 'отмена пересчёта оставляет модель');
// Исходный код и модели — только модератору.
$c=controller(7); $c->request->params=['code'=>$zp_code,'revision'=>$zr,'kind'=>'scad'];
refuses(function()use($c){$c->action_file();},'владелец не скачивает SCAD',HTTP_Exception::class);
$cm=controller(8,TRUE); $cm->request->params=['code'=>$zp_code,'revision'=>$zr,'kind'=>'scad']; $cm->action_file();
ok(str_contains($cm->response->body(),'len = 120.0;'),'модератор скачивает исходный код');
Store::$r[$zr]['model']='secret-llm-7'; Store::$r[$zr]['vision_model']='secret-vlm-7';
$html=render_page('project',7,FALSE,['code'=>$zp_code,'mode'=>''],['revision'=>$zr]);
ok(stripos($html,'openscad')===FALSE AND ! str_contains($html,'secret-llm-7') AND ! str_contains($html,'secret-vlm-7') AND ! str_contains($html,'len = 120.0;'), 'владельцу не видны движок, модели и код');
ok(str_contains($html,'data-param') OR str_contains($html,'type="range"'), 'ползунки выведены для владельца');
$html_mod=render_page('project',8,TRUE,['code'=>$zp_code,'mode'=>''],['revision'=>$zr]);
ok(str_contains($html_mod,'secret-llm-7') AND str_contains($html_mod,'len = 120.0;'), 'модератору видны модель и код');
$html_usage=render_page('usage',7);
ok(stripos($html_usage,'openscad')===FALSE AND ! str_contains($html_usage,'secret-llm-7'), 'журнал генераций не показывает модели пользователю');
ok(Service_Nj669_Solid3d::public_error('ERROR: Parser error in line 3',FALSE)==='Модель не собралась. Измените описание или размеры и повторите.', 'ошибка компилятора не уходит пользователю');
ok(Service_Nj669_Solid3d::public_error('ERROR: Parser error in line 3',TRUE)==='ERROR: Parser error in line 3', 'модератор видит ошибку как есть');
// Удаление версий: владелец и модератор, текущую и активную нельзя.
$zr2=$s::queue($zp_code,7,'Вариант для удаления');
refuses(function()use($s,$zp_code,$zr2){$s::change($zp_code,7,FALSE,'delete_revision',['revision'=>$zr2,'armed'=>'1']);},'активная версия не удаляется');
$s::worker(['revision_id'=>$zr2,'run_token'=>Store::$r[$zr2]['run_token']],'fail');
refuses(function()use($s,$zp_code,$zr){$s::change($zp_code,7,FALSE,'delete_revision',['revision'=>$zr,'armed'=>'1']);},'текущая версия не удаляется');
refuses(function()use($s,$zp_code,$zr2){$s::change($zp_code,7,FALSE,'delete_revision',['revision'=>$zr2,'armed'=>'0']);},'удаление версии требует подтверждения');
refuses(function()use($s,$zp_code,$zr2){$s::change($zp_code,9,FALSE,'delete_revision',['revision'=>$zr2,'armed'=>'1']);},'чужой не удаляет версию');
$s::change($zp_code,7,FALSE,'delete_revision',['revision'=>$zr2,'armed'=>'1']);
ok(! isset(Store::$r[$zr2]) AND ! is_file($s::file($zp_code,$zr2,'stl')), 'владелец удаляет неактуальную версию');
$zr3=$s::queue($zp_code,7,'Модераторская чистка'); $s::worker(['revision_id'=>$zr3,'run_token'=>Store::$r[$zr3]['run_token']],'fail');
$s::change($zp_code,8,TRUE,'delete_revision',['revision'=>$zr3,'armed'=>'1']);
ok(! isset(Store::$r[$zr3]), 'модератор удаляет неактуальную версию');
$zdel=$s::create(7,'Для модератора','Удаление проекта')['code'];
refuses(function()use($s,$zdel){$s::change($zdel,7,FALSE,'delete',['armed'=>'0']);},'владелец подтверждает удаление проекта');
$s::change($zdel,8,TRUE,'delete',['armed'=>'1']);
ok($s::project($zdel)===NULL, 'модератор удаляет проект');
$dt=$assist::queue('caption',7,$code,'',FALSE,TRUE); $dbody=['task_id'=>$dt,'run_token'=>Store::$tasks[$dt]['run_token']];
$s::change($code,7,FALSE,'delete',['armed'=>'1']); ok($s::project($code)===NULL AND ! is_dir($dir),'удаление закрывает доступ и удаляет файлы');
refuses(function()use($s,$body){$s::worker($body,'context');},'удалённый проект не воскресить');
refuses(function()use($assist,$dbody,$proposal){$assist::worker($dbody+['result'=>$proposal],'assist_result');},'удалённый проект не принимает рекомендацию');
// ---- v6: временный STL под свои размеры: не версия, не каталог, не квота, живёт час ----
$xsnap=View::$snapshots; // снимки для UI-стенда не должны смениться гостевыми/чужими рендерами этого блока
$xa='Service_Nj669_Export';
$s::save_limits(7,['projects'=>'','daily_runs'=>'50']);
$xc=$s::create(7,'Вкладыш','Вкладыш с ползунками')['code']; $xp=$s::project($xc)['id'];
$xr=$s::queue($xc,7,'Вкладыш 50 мм');
$xsrc="// Вкладыш\n// @param 20 200 1 Длина, мм\nlen = 50;\n// @bool Сквозное отверстие\nthrough = true;\n// @choice Посадка | 1:Плотная | 2:Свободная\nfit = 2;\n\$fn = 32;\ncube([len, 20, 4]);\n";
$xb=['revision_id'=>$xr,'run_token'=>Store::$r[$xr]['run_token']];
$s::worker($xb,'context'); mkdir($s::dir($xc),0700,TRUE);
foreach (['stl'=>$stl,'glb'=>$glb,'png'=>$png] as $kind=>$bytes) { file_put_contents($s::file($xc,$xr,$kind),$bytes); }
$s::worker($xb+['source'=>$xsrc,'attempts'=>1],'result');
ok(Store::$r[$xr]['status']==='done', 'версия для проверки экспорта собрана');
$xrevs=count(Store::$r); $xcur=Store::$p[$xp]['current_revision_id']; $xruns=$s::limits(7)['runs_used']; $xjobs=count(Service_Nj120_JobQueue::$jobs);
refuses(function()use($xa,$xc,$xr){$xa::queue($xc,0,$xr,['len'=>'90']);},'гость не собирает STL под свои размеры');
refuses(function()use($xa,$xc,$xr){$xa::queue($xc,8,$xr,['len'=>'90']);},'чужая непубликованная версия недоступна для STL');
$e1=$xa::queue($xc,7,$xr,['len'=>'90','through'=>'0','fit'=>'1'],FALSE);
ok(Store::$x[$e1]['status']==='queued' AND str_contains(Store::$x[$e1]['source'],'len = 90.0;') AND str_contains(Store::$x[$e1]['source'],'through = false;'), 'сборка несёт значения пользователя');
ok(Store::$r[$xr]['source']===$xsrc AND Store::$r[$xr]['pending_source']===NULL AND count(Store::$r)===$xrevs AND Store::$p[$xp]['current_revision_id']===$xcur, 'версия, каталог и история не меняются');
ok($s::limits(7)['runs_used']===$xruns, 'STL под свои размеры квоту не тратит');
$lastx=end(Service_Nj120_JobQueue::$jobs);
ok(count(Service_Nj120_JobQueue::$jobs)===$xjobs+1 AND $lastx[0]==='nj669.export' AND $lastx[1]['export_id']===$e1 AND $lastx[2]['timeout_sec']===900, 'в очередь уходит отдельная задача nj669.export');
refuses(function()use($xa,$xc,$xr){$xa::queue($xc,7,$xr,['len'=>'70'],FALSE);},'вторая сборка того же пользователя ждёт первую');
$xtok=Store::$x[$e1]['run_token'];
refuses(function()use($xa,$e1){$xa::worker(['export_id'=>$e1,'run_token'=>'wrong'],'export_context');},'неверный токен сборки STL отклонён');
$xctx=$xa::worker(['export_id'=>$e1,'run_token'=>$xtok],'export_context');
ok(str_contains($xctx['source'],'len = 90.0;') AND Store::$x[$e1]['status']==='running', 'контекст отдаёт код с размерами пользователя');
refuses(function()use($xa,$e1,$xtok){$xa::worker(['export_id'=>$e1,'run_token'=>$xtok],'export_upload');},'загрузка без файла отвергается');
refuses(function()use($xa,$e1,$xtok){$xa::worker(['export_id'=>$e1,'run_token'=>$xtok],'export_result');},'результат без файла не принимается');
file_put_contents($xa::path($xc,$e1),$stl);
$xa::worker(['export_id'=>$e1,'run_token'=>$xtok],'export_result');
ok(Store::$x[$e1]['status']==='done' AND Store::$x[$e1]['source']==='' AND Store::$x[$e1]['expires_at']==='2026-10-16 13:00:00+00', 'готовый STL: done, код из строки убран, срок час');
ok($xa::worker(['export_id'=>$e1,'run_token'=>$xtok],'export_result')['already_done'], 'повтор приёмки STL безопасен');
refuses(function()use($xa,$e1,$xtok){$xa::worker(['export_id'=>$e1,'run_token'=>$xtok],'export_context');},'закрытую сборку нельзя открыть снова');
ok($xa::download($xc,$e1,7,FALSE)===$xa::path($xc,$e1), 'создатель скачивает свой STL');
ok($xa::download($xc,$e1,8,FALSE)===NULL, 'чужой пользователь STL не получает');
ok($xa::download($xc,$e1,8,TRUE)===$xa::path($xc,$e1), 'модератор получает STL');
ok($xa::download($xc,$e1+99,7,FALSE)===NULL, 'несуществующая сборка не отдаётся');
$xct=controller(7); $xct->request->params=['code'=>$xc,'export'=>$e1]; $xct->action_export_file();
ok($xct->response->body()===$stl AND str_contains($xct->response->headers['Content-Disposition'],'attachment'), 'контроллер отдаёт STL attachment');
$xalive=$xa::latest($xp,7);
ok($xalive['id']===$e1 AND $xalive['alive'] === TRUE, 'последняя сборка видна владельцу с признаком срока');
$xb2=$xa::latest($xp,8); ok($xb2===NULL, 'чужая сборка не видна');
// Отказ сборки: сохраняется для модератора и не блокирует следующую
$e2=$xa::queue($xc,7,$xr,['len'=>'60','through'=>'1','fit'=>'2'],FALSE); $t2=Store::$x[$e2]['run_token'];
$xa::worker(['export_id'=>$e2,'run_token'=>$t2],'export_context');
$xa::worker(['export_id'=>$e2,'run_token'=>$t2,'error'=>'Не удалось собрать STL с этими размерами. Измените значения и повторите.','detail'=>'ERROR: line 3'],'export_fail');
ok(Store::$x[$e2]['status']==='error' AND Store::$x[$e2]['detail']==='ERROR: line 3', 'неудача сборки сохраняется для модератора');
ok($xa::worker(['export_id'=>$e2,'run_token'=>$t2],'export_fail')['already_failed'], 'повтор fail безопасен');
// Зависшая сборка закрывается по времени и освобождает пользователя
$e3=$xa::queue($xc,7,$xr,['len'=>'55','through'=>'0','fit'=>'1'],FALSE);
Store::$x[$e3]['created_at']='2026-10-16 11:00:00+00';
$xa::purge();
ok(Store::$x[$e3]['status']==='error' AND str_contains(Store::$x[$e3]['error'],'отведённое время'), 'зависшая сборка закрывается по времени');
// Истечение срока: файл и строка удаляются чисткой
Store::$x[$e1]['expires_at']='2026-10-16 11:00:00+00';
ok($xa::download($xc,$e1,7,FALSE)===NULL, 'истёкший STL не выдаётся');
$xa::purge();
ok(! isset(Store::$x[$e1]) AND ! is_file($xa::path($xc,$e1)), 'чистка удаляет истёкший файл и строку');
// Страницы: владелец на черновой версии меняет размеры; на опубликованной — собирает STL; гость — закрыто
$xdraft=render_page('project',7,FALSE,['code'=>$xc]);
ok(str_contains($xdraft,'data-param-mode="export"') AND str_contains($xdraft,'Собрать STL') AND ! str_contains($xdraft,'name="op" value="parameters"'), 'владелец черновой версии собирает временный STL; версия не меняется');
Store::$p[$xp]['published_revision_id']=$xr;
$xfrozen=render_page('project',7,FALSE,['code'=>$xc]);
ok(str_contains($xfrozen,'data-param-mode="export"') AND str_contains($xfrozen,'Собрать STL'), 'опубликованная версия: владелец собирает временный STL');
ok(! str_contains($xfrozen,'Пересчёт снимет её с публикации') AND ! str_contains($xfrozen,'name="op" value="parameters"'), 'ползунки не предупреждают о снятии и не пишут версию');
ok(! str_contains($xfrozen,'Снимите её с публикации'), 'старый блок «снимите с публикации» убран');
$xguest=render_page('project',0,FALSE,['mode'=>'c','code'=>$xc]);
ok(str_contains($xguest,'data-param-mode="export"') AND str_contains($xguest,'Войдите в аккаунт, чтобы собрать STL') AND str_contains($xguest,'<fieldset class="contents" data-lock-panel disabled>'), 'гость на опубликованной версии видит размеры закрытыми с подсказкой входа');
$xother=render_page('project',8,FALSE,['mode'=>'c','code'=>$xc]);
ok(str_contains($xother,'data-param-mode="export"') AND str_contains($xother,'Собрать STL') AND ! str_contains($xother,'data-lock-panel disabled'), 'другой пользователь собирает STL на опубликованной версии');
ok(! str_contains($xother,'name="op" value="parameters"') AND str_contains($xother,'name="op" value="export"'), 'форма другого пользователя шлёт только op=export');
ok(! str_contains($xother,'data-solid-form') OR str_contains($xother,'autocomplete="off"'), 'форма экспорта без автозаполнения');
$xct2=controller(8); $xct2->request->params=['mode'=>'c','code'=>$xc]; $xct2->request->verb='POST';
$xct2->request->p=['csrf'=>'csrf-ok','op'=>'export','revision'=>(string)$xr,'params'=>['len'=>'85','through'=>'1','fit'=>'2']];
try { $xct2->action_project(); } catch (HTTP_Exception $ex) { ok(TRUE,'публичная страница принимает op=export'); }
ok(HTTP::$location==='/solid3d/c/'.$xc.'?revision='.$xr AND count(array_filter(Store::$x,function($x){return $x['user_id']==8 AND $x['status']==='queued';}))===1, 'POST с публичной страницы ставит сборку и возвращает туда же');
$xct3=controller(8); $xct3->request->params=['mode'=>'c','code'=>$xc]; $xct3->request->verb='POST';
$xct3->request->p=['csrf'=>'csrf-ok','op'=>'parameters','revision'=>(string)$xr,'params'=>['len'=>'85']];
refuses(function()use($xct3){$xct3->action_project();},'публичная страница не меняет версию (op=parameters)', HTTP_Exception::class);
View::$snapshots=$xsnap;
View::$snapshots['nj669/project-owner-draft']=$xdraft; View::$snapshots['nj669/project-public-user']=$xother;
// ---- Этап 2: токены, автоснятие с каталога, модерация из каталога, вёрстка ----
// Отдельная деталь: основной проект к этому моменту уже удалён более ранними проверками.
$keepProject=View::$snapshots['nj669/project'] ?? NULL;
$s::save_limits(7,['projects'=>'20','daily_runs'=>'100']);
ok(Service_Nj669_Solid3d::when('2026-10-16 12:00:00+00')==='16.10.2026 15:00' AND Service_Nj669_Solid3d::when('не дата')==='' AND Service_Nj669_Solid3d::when('')==='', 'дата показывается по Москве, мусор не ломает страницу');
ok(Service_Nj669_Solid3d::tokens_text(0,0)==='—' AND Service_Nj669_Solid3d::tokens_text(1200,340)==="1\u{00A0}200 → 340", 'расход: пусто — «—», иначе «вход → выход»');
$tp=$s::create(7,'Токены','Проверка расхода токенов'); $tcode=$tp['code']; $tid=(int) $tp['id'];
$tq=$s::queue($tcode,7,'Расход при ошибке');
$s::worker(['revision_id'=>$tq,'run_token'=>Store::$r[$tq]['run_token'],'error'=>'Модель вернула пустой ответ','tokens'=>['in'=>1200,'out'=>340]],'fail');
ok(Store::$r[$tq]['tokens_in']===1200 AND Store::$r[$tq]['tokens_out']===340 AND Store::$r[$tq]['status']==='error','ошибка генерации сохраняет расход токенов');
Store::$r[$tq]['status']='done'; Store::$r[$tq]['visual_ok']=TRUE; Store::$p[$tid]['current_revision_id']=$tq;
mkdir($s::dir($tcode),0700,TRUE); foreach (['png','png2','png3'] as $k) { file_put_contents($s::file($tcode,$tq,$k),$png); }
$s::queue_verify($tcode,8,$tq,TRUE);
$s::verify_worker(['revision_id'=>$tq,'run_token'=>Store::$r[$tq]['vlm_run_token'],'error'=>'Нет ответа','tokens'=>['in'=>11,'out'=>22]],'verify_fail');
ok(Store::$r[$tq]['vlm_tokens_in']===11 AND Store::$r[$tq]['vlm_tokens_out']===22 AND Store::$r[$tq]['tokens_in']===1200,'проверка ракурсов пишет свой расход, генерация не меняется');
$at=$assist::queue('caption',7,$tcode,'',FALSE,TRUE);
$assist::worker(['task_id'=>$at,'run_token'=>Store::$tasks[$at]['run_token'],'error'=>'Пустой ответ','tokens'=>['in'=>4,'out'=>9]],'assist_fail');
ok(Store::$tasks[$at]['tokens_in']===4 AND Store::$tasks[$at]['tokens_out']===9 AND Store::$tasks[$at]['status']==='error','помощник сохраняет расход даже при неудаче');
$tot=$s::usage_totals(7);
ok($tot['tokens_in']>=1200 AND $tot['tokens_out']>=340 AND $tot['vlm_tokens_out']>=22 AND $tot['assist_tokens_in']>=4 AND $tot['runs']>=1,'итоги пользователя: генерация, проверка и помощник');
$all=$s::usage_totals();
ok($all['tokens_in']>=$tot['tokens_in'] AND $all['assist_runs']>=1,'итоги по всем пользователям для модератора');
$uh=render_page('usage',7);
ok(str_contains($uh,'Генерация деталей') AND str_contains($uh,'16.10.2026 15:00') AND ! str_contains($uh,'min-w-[720px]'),'журнал: итоги, читаемая дата, таблица без фиксированной ширины');
Store::$p[$tid]['publication']='published'; Store::$p[$tid]['published_revision_id']=$tq; Store::$p[$tid]['review_revision_id']=0;
$cat=render_page('catalog',0);
ok(! str_contains($cat,'Поиск') AND str_contains($cat,'id="solid-search"') AND str_contains($cat,'xl:grid-cols-4'),'каталог: слова «Поиск» нет, форма на месте, до четырёх в ряд');
ok(str_contains($cat,'h-36') AND ! str_contains($cat,'value="unpublish"'),'карточка компактная; гость не видит снятие с публикации');
$cm=render_page('catalog',8,TRUE);
ok(str_contains($cm,'view=moderate') AND str_contains($cm,'value="unpublish"'),'модератор видит переключатель и снятие в каталоге');
$oc=controller(7); $oc->request->verb='POST'; $oc->request->p=['op'=>'unpublish','code'=>$tcode,'note'=>'','csrf'=>'csrf-ok'];
refuses(function()use($oc){$oc->action_catalog();},'владелец не снимает деталь через модераторский POST',HTTP_Exception::class);
ok(Store::$p[$tid]['publication']==='published','отказ владельцу не меняет публикацию');
post_page('catalog',8,TRUE,[],['op'=>'unpublish','code'=>$tcode,'note'=>'']);
ok(Store::$p[$tid]['publication']==='draft' AND Store::$p[$tid]['published_revision_id']===0 AND HTTP::$location==='/solid3d/catalog','модератор снимает деталь с каталога (POST)');
ok(in_array('unpublish',array_column(Store::$events,'kind')),'снятие модератором записано в журнал');
Store::$p[$tid]['publication']='review'; Store::$p[$tid]['review_revision_id']=$tq;
$mh=render_page('catalog',8,TRUE,[],['view'=>'moderate']);
ok(str_contains($mh,'Опубликовать') AND str_contains($mh,'name="note"') AND str_contains($mh,'Модерация деталей'),'очередь модерации: публикация и причина отказа на одном экране');
$rj=controller(8,TRUE); $rj->request->verb='POST'; $rj->request->q=['view'=>'moderate']; $rj->request->p=['op'=>'reject','code'=>$tcode,'note'=>'','csrf'=>'csrf-ok'];
$rj->action_catalog();
ok(str_contains((string)$rj->template->content,'Укажите причину отклонения') AND Store::$p[$tid]['publication']==='review','отказ без причины не проходит, ошибка на той же странице');
post_page('moderate',8,TRUE,[],['op'=>'publish','code'=>$tcode,'note'=>'']);
ok(Store::$p[$tid]['publication']==='published' AND Store::$p[$tid]['published_revision_id']===$tq,'модератор публикует из очереди (POST)');
$workspace::edit($tcode,7,['title'=>Store::$p[$tid]['title'],'summary'=>'Меняем описание, чтобы снять с каталога']);
ok(Store::$p[$tid]['publication']==='draft' AND Store::$p[$tid]['published_revision_id']===0 AND in_array('withdraw',array_column(Store::$events,'kind')),'редактирование карточки снимает деталь с каталога');
$ownHtml=render_page('project',7,FALSE,['code'=>$tcode,'mode'=>'p']);
ok(str_contains($ownHtml,'токены'),'владелец видит расход версии в истории');
Store::$p[$tid]['publication']='published'; Store::$p[$tid]['published_revision_id']=$tq;
$pubHtml=render_page('project',0,FALSE,['code'=>$tcode,'mode'=>'c']);
ok(! str_contains($pubHtml,'токены'),'гость не видит расход');
// Снимки для UI-стенда: прежний nj669/project сохраняем, новые страницы этапа 2 — под своими именами.
if ($keepProject !== NULL) { View::$snapshots['nj669/project'] = $keepProject; }
View::$snapshots['nj669/catalog-guest']=$cat; View::$snapshots['nj669/catalog-moderator']=$cm; View::$snapshots['nj669/catalog-moderate']=$mh; View::$snapshots['nj669/usage-tokens']=$uh; View::$snapshots['nj669/project-owner-tokens']=$ownHtml;
$m0=new pgMigration_Nj_669_000_Solid3dNamespace; $m0->up(); $m=new pgMigration_Nj_669_001_Solid3d; $m->up(); $m2=new pgMigration_Nj_669_002_Workspace; $m2->up(); $m3=new pgMigration_Nj_669_003_VlmCheck; $m3->up(); $m4=new pgMigration_Nj_669_004_Params; $m4->up(); $m5=new pgMigration_Nj_669_005_LlmRun; $m5->up(); $m6=new pgMigration_Nj_669_006_Exports; $m6->up(); $m7=new pgMigration_Nj_669_007_Tokens; $m7->up(); $up=pgMigration::$sql; pgMigration::$sql=[]; $m7->down(); $m5->down(); $m4->down(); $m3->down(); $m2->down(); $m->down(); $m0->down(); $down=pgMigration::$sql;
ok(str_contains(json_encode($up),'tokens_in INTEGER NOT NULL DEFAULT 0') AND str_contains(json_encode($up),'vlm_tokens_out') AND str_contains(json_encode($up),'nj_669_solid3d_tasks'),'миграция 007 добавляет колонки токенов в версии и помощника');
ok(str_contains(json_encode($down),'DROP COLUMN IF EXISTS tokens_in'),'миграция 007 откатывается');
if (getenv('N2J_DUMP')) { echo json_encode(['checks'=>$count,'up'=>$up,'down'=>$down,'trace'=>Store::$trace,'views'=>View::$snapshots], JSON_UNESCAPED_UNICODE); }
else { echo $count." проверок ОК\n"; }
