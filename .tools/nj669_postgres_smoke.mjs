/** Реальный PostgreSQL-in-WASM (PGlite): up×2/down/up, ограничения и SQL сервиса.
 * N2J_PGLITE_MODULE=/tmp/pgtest/node_modules/@electric-sql/pglite/dist/index.js
 * node .tools/nj669_postgres_smoke.mjs /tmp/nj669-fixtures.json
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { PGlite } = await import(process.env.N2J_PGLITE_MODULE || '@electric-sql/pglite');
const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
// Проверяем не только пустую установку, но и переход 001 → 002 с живой публикацией.
const legacy = new PGlite();
const split = fixture.up.findIndex((sql) => sql.startsWith('ALTER TABLE nj_669_solid3d_projects ADD COLUMN'));
assert.ok(split > 0);
const namespaceSql = fixture.up[0];
assert.match(namespaceSql, /DO \$solid3d_namespace\$/);
const baseSql = fixture.up.slice(1, split);
const workspaceSql = fixture.up.slice(split);
const oldNames = (sql) => sql.replaceAll('nj_669_solid3d_', 'nj_669_');
for (const sql of fixture.up.slice(0, split)) { await legacy.exec(sql); }
await legacy.exec("INSERT INTO nj_669_solid3d_projects(code,user_id,title,summary,published_revision_id,review_revision_id) VALUES ('bbbbbbbbbbbbbbbbbbbb',7,'Старое название','Старое описание',1,1)");
for (const sql of fixture.up.slice(split)) { await legacy.exec(sql); }
let snapshot = (await legacy.query('SELECT published_meta,review_meta FROM nj_669_solid3d_projects')).rows[0];
assert.equal(snapshot.published_meta.title, 'Старое название');
assert.equal(snapshot.review_meta.summary, 'Старое описание');
await legacy.exec("UPDATE nj_669_solid3d_projects SET title='Новый черновик'");
for (const sql of fixture.up.slice(split)) { await legacy.exec(sql); }
snapshot = (await legacy.query('SELECT published_meta FROM nj_669_solid3d_projects')).rows[0];
assert.equal(snapshot.published_meta.title, 'Старое название');
await legacy.close();
// Регрессия реального инцидента: чужая таблица сцен уже заняла короткое имя.
const db = new PGlite();
await db.exec(`CREATE TABLE nj_669_spots (id BIGSERIAL PRIMARY KEY);
  INSERT INTO nj_669_spots DEFAULT VALUES;
  CREATE TABLE nj_669_revisions (
    id BIGSERIAL PRIMARY KEY,
    spot_id BIGINT NOT NULL REFERENCES nj_669_spots(id),
    scene_json JSONB NOT NULL, note VARCHAR(1000) NOT NULL,
    author_id INTEGER NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now());
  INSERT INTO nj_669_revisions(spot_id,scene_json,note,author_id)
    VALUES (1,'{"objects":[{"id":"keep-me"}]}','Существующая сцена',42)`);
const foreignState = async () => ({
  table: (await db.query("SELECT 'nj_669_revisions'::regclass::oid AS oid")).rows,
  columns: (await db.query("SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='nj_669_revisions' ORDER BY ordinal_position")).rows,
  rows: (await db.query('SELECT * FROM nj_669_revisions')).rows,
  constraints: (await db.query("SELECT conname,contype,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='nj_669_revisions'::regclass ORDER BY conname")).rows,
});
const foreignBefore = await foreignState();
// Старая 001 действительно падала, а rollback сохранял согласованное состояние.
await db.exec('BEGIN');
await assert.rejects(async () => {
  for (const sql of baseSql) { await db.exec(oldNames(sql)); }
}, /column "project_id" does not exist/);
await db.exec('ROLLBACK');
assert.deepEqual(await foreignState(), foreignBefore);
assert.equal((await db.query("SELECT to_regclass('nj_669_projects') AS name")).rows[0].name, null);
for (let i = 0; i < 2; i++) { for (const sql of fixture.up) { await db.exec(sql); } }
assert.deepEqual(await foreignState(), foreignBefore, 'чужие строки, колонки, OID и ограничения сохранены после up×2');
assert.ok(fixture.trace.every(([sql]) => !/nj_669_(?!solid3d_)/.test(sql)), 'runtime SQL никогда не обращается к коротким именам');
let checked = 0;
const value = (v) => v === null ? 'NULL' : typeof v === 'number' ? String(v) : "'" + String(v).replaceAll("'", "''") + "'";
for (const [query, params] of fixture.trace) {
	const sql = query.replace(/:[a-z_]+\b/gi, (key) => {
		assert.ok(Object.hasOwn(params, key), 'Незаполненный SQL-параметр: ' + key);
		return value(params[key]);
	});
	try { await db.exec('EXPLAIN ' + sql); checked++; }
	catch (error) { console.error(sql); throw error; }
}
await db.exec("INSERT INTO nj_669_solid3d_projects(code,user_id,title) VALUES ('aaaaaaaaaaaaaaaaaaaa',7,'Тест')");
await db.exec("INSERT INTO nj_669_solid3d_revisions(project_id,user_id,prompt,run_token) VALUES (1,7,'Тест','token')");
await assert.rejects(db.exec("INSERT INTO nj_669_solid3d_revisions(project_id,user_id,prompt,run_token) VALUES (999,7,'Тест','token')"), /foreign key/i);
await assert.rejects(db.exec("UPDATE nj_669_solid3d_revisions SET status='published'"), /check constraint/i);
await assert.rejects(db.exec("INSERT INTO nj_669_solid3d_quotas(user_id,projects) VALUES (7,-1)"), /check constraint/i);
await db.exec("UPDATE nj_669_solid3d_revisions SET status='done',source='cube(30);' WHERE id=1");
await db.exec("UPDATE nj_669_solid3d_projects SET current_revision_id=1,review_revision_id=1 WHERE id=1");
await db.exec("INSERT INTO nj_669_solid3d_revisions(project_id,user_id,prompt,run_token,parent_id) VALUES (1,7,'Приватная правка','next',1)");
await db.exec("UPDATE nj_669_solid3d_projects SET published_revision_id=review_revision_id,publication='published' WHERE id=1");
await db.exec("UPDATE nj_669_solid3d_projects SET current_revision_id=2 WHERE id=1");
const p = (await db.query('SELECT current_revision_id,published_revision_id FROM nj_669_solid3d_projects WHERE id=1')).rows[0];
assert.equal(p.current_revision_id, 2);
assert.equal(p.published_revision_id, 1);
// Снимок карточки утверждается вместе с review_revision_id, не с текущим draft.
await db.exec(`UPDATE nj_669_solid3d_projects SET title='На модерацию',tags='печать',
  review_meta=jsonb_build_object('title','Утверждённая карточка','summary','Описание','tags','печать') WHERE id=1`);
await db.exec("UPDATE nj_669_solid3d_projects SET title='Неопубликованная правка',published_meta=review_meta WHERE id=1");
const meta = (await db.query("SELECT published_meta->>'title' AS title FROM nj_669_solid3d_projects")).rows[0];
assert.equal(meta.title, 'Утверждённая карточка');
assert.equal((await db.query("SELECT count(*) FROM nj_669_solid3d_projects WHERE published_meta->>'title' ILIKE '%Неопубликованная%'")).rows[0].count, 0);
await db.exec("UPDATE nj_669_solid3d_revisions SET label='Контрольная точка',milestone=true,job_id=123 WHERE id=1");
await db.exec("INSERT INTO nj_669_solid3d_tasks(kind,project_id,user_id,job_id,run_token) VALUES ('caption',1,7,124,'token')");
await db.exec("INSERT INTO nj_669_solid3d_tasks(kind,user_id,job_id,run_token) VALUES ('ideas',7,125,'token')");
await assert.rejects(db.exec("INSERT INTO nj_669_solid3d_tasks(kind,user_id,run_token) VALUES ('unknown',7,'token')"), /check constraint/i);
await assert.rejects(db.exec("UPDATE nj_669_solid3d_tasks SET status='published'"), /check constraint/i);
const quotaSql = fixture.trace.find(([sql]) => sql.includes('AS projects_used'))[0].replaceAll(':u', '7');
const quota = (await db.query(quotaSql)).rows[0];
assert.equal(quota.runs_used, 3, 'job_id=0 не тратит квоту; caption и идеи тратят');
// Ревизия без модели (ручной пересчёт кода, пересчёт размеров) квоту не тратит.
await db.exec('UPDATE nj_669_solid3d_revisions SET llm_run=FALSE WHERE id=1');
assert.equal((await db.query(quotaSql)).rows[0].runs_used, 2, 'llm_run=FALSE не списывает квоту');
await db.exec('UPDATE nj_669_solid3d_revisions SET llm_run=TRUE WHERE id=1');
await db.exec("INSERT INTO nj_669_solid3d_ideas(task_id,body) VALUES (1,'Идея не теряется при очистке задачи')");
await db.exec('DELETE FROM nj_669_solid3d_tasks WHERE id=1');
assert.equal((await db.query('SELECT task_id FROM nj_669_solid3d_ideas')).rows[0].task_id, null);
for (const sql of fixture.down) { await db.exec(sql); }
assert.deepEqual(await foreignState(), foreignBefore, 'down не удаляет таблицу сцен');
for (const sql of fixture.up) { await db.exec(sql); }
assert.equal((await db.query('SELECT count(*) FROM nj_669_solid3d_projects')).rows[0].count, 0);
assert.deepEqual(await foreignState(), foreignBefore, 'повторная установка не меняет сцену');
console.log(`PostgreSQL/PGlite: up×2/down/up с чужой таблицей сцен, backfill карточек, ограничения/квоты/снимки, ${checked} запросов сервиса — ОК`);
await db.close();

// Совместимость: ранний Solid3D с успешно применённой 001, с 002 и без неё.
for (const full of [false, true]) {
  const upgrade = new PGlite();
  for (const sql of baseSql) { await upgrade.exec(oldNames(sql)); }
  await upgrade.exec(`INSERT INTO nj_669_projects(code,user_id,title) VALUES ('cccccccccccccccccccc',7,'Сохранённая деталь');
    INSERT INTO nj_669_revisions(project_id,user_id,prompt,source,status,job_id,run_token,files)
      VALUES (1,7,'Старый запрос','cube(30);','done',55,'saved-token','{"stl":100,"glb":200}');
    UPDATE nj_669_projects SET current_revision_id=1,published_revision_id=1,review_revision_id=1,publication='published';
    INSERT INTO nj_669_settings(key,value) VALUES ('model','saved::model');
    INSERT INTO nj_669_quotas(user_id,projects,daily_runs) VALUES (7,30,9);
    INSERT INTO nj_669_events(project_id,user_id,kind,note) VALUES (1,7,'publish','Сохранённое событие')`);
  if (full) {
    for (const sql of workspaceSql) { await upgrade.exec(oldNames(sql)); }
    await upgrade.exec(`UPDATE nj_669_projects SET title='Приватный черновик',tags='приватный';
      UPDATE nj_669_revisions SET label='Контрольная точка',milestone=true;
      INSERT INTO nj_669_tasks(kind,project_id,user_id,job_id,run_token,status,result)
        VALUES ('caption',1,7,56,'task-token','done','{"title":"Рекомендация"}');
      INSERT INTO nj_669_ideas(task_id,theme,body) VALUES (1,'Тема','Сохранённая идея')`);
  }
  const before = (await upgrade.query("SELECT 'nj_669_projects'::regclass::oid AS projects,'nj_669_revisions'::regclass::oid AS revisions")).rows;
  // 000 применяется даже если 001/002 уже записаны мигратором как выполненные.
  await upgrade.exec(namespaceSql);
  await upgrade.exec(namespaceSql);
  const after = (await upgrade.query("SELECT 'nj_669_solid3d_projects'::regclass::oid AS projects,'nj_669_solid3d_revisions'::regclass::oid AS revisions")).rows;
  assert.deepEqual(after, before, 'переносит сами таблицы с OID/FK, а не создаёт пустые копии');
  if (!full) { for (const sql of workspaceSql) { await upgrade.exec(sql); } }
  const project = (await upgrade.query('SELECT * FROM nj_669_solid3d_projects')).rows[0];
  const revision = (await upgrade.query('SELECT * FROM nj_669_solid3d_revisions')).rows[0];
  assert.equal(project.published_meta.title, 'Сохранённая деталь');
  assert.equal(project.current_revision_id, 1);
  assert.equal(project.published_revision_id, 1);
  assert.equal(revision.run_token, 'saved-token');
  assert.equal(revision.source, 'cube(30);');
  assert.equal(revision.job_id, 55);
  assert.deepEqual(revision.files, {stl:100,glb:200});
  assert.equal((await upgrade.query('SELECT value FROM nj_669_solid3d_settings')).rows[0].value, 'saved::model');
  assert.equal((await upgrade.query('SELECT daily_runs FROM nj_669_solid3d_quotas')).rows[0].daily_runs, 9);
  assert.equal((await upgrade.query('SELECT note FROM nj_669_solid3d_events')).rows[0].note, 'Сохранённое событие');
  if (full) {
    assert.equal(revision.label, 'Контрольная точка'); assert.equal(revision.milestone, true);
    assert.equal((await upgrade.query('SELECT run_token FROM nj_669_solid3d_tasks')).rows[0].run_token, 'task-token');
    assert.equal((await upgrade.query('SELECT body FROM nj_669_solid3d_ideas')).rows[0].body, 'Сохранённая идея');
  }
  // Повторный up безопасен после переименования, последовательности не сброшены.
  for (const sql of fixture.up) { await upgrade.exec(sql); }
  assert.equal((await upgrade.query("INSERT INTO nj_669_solid3d_projects(code,user_id,title) VALUES ('dddddddddddddddddddd',7,'Следующая') RETURNING id")).rows[0].id, 2);
  await upgrade.exec('DELETE FROM nj_669_solid3d_projects WHERE id=1');
  assert.equal((await upgrade.query('SELECT count(*) FROM nj_669_solid3d_revisions')).rows[0].count, 0, 'FK каскад сохранён');
  if (full) {
    assert.equal((await upgrade.query('SELECT count(*) FROM nj_669_solid3d_tasks')).rows[0].count, 0);
    assert.equal((await upgrade.query('SELECT task_id FROM nj_669_solid3d_ideas')).rows[0].task_id, null);
  }
  await upgrade.close();
}
// Ни слияния двух баз, ни частичного переименования при неоднозначности.
for (const both of [false, true]) {
  const conflict = new PGlite();
  for (const sql of baseSql) { await conflict.exec(oldNames(sql)); }
  await conflict.exec("INSERT INTO nj_669_projects(code,user_id,title) VALUES ('eeeeeeeeeeeeeeeeeeee',7,'Не потерять')");
  if (both) { for (const sql of baseSql) { await conflict.exec(sql); } }
  else { await conflict.exec('ALTER TABLE nj_669_settings DROP COLUMN value'); }
  await assert.rejects(conflict.exec(namespaceSql), both ? /обе таблицы/ : /неоднозначная/);
  assert.equal((await conflict.query('SELECT title FROM nj_669_projects')).rows[0].title, 'Не потерять');
  assert.ok((await conflict.query("SELECT to_regclass('nj_669_revisions') AS name")).rows[0].name);
  await conflict.close();
}
console.log('PostgreSQL/PGlite: изоляция сцен, перенос раннего Solid3D 001/002 с данными/FK/sequence, остановка при неоднозначности — ОК');
