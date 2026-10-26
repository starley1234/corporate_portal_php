<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Подготовка до 001: переносит только опознанную раннюю установку Solid3D.
 * Чужие nj_669_revisions(spot_id,scene_json,...) не изменяются и не удаляются.
 * Номер 000 намеренный: выполняется и если 001/002 уже отмечены применёнными.
 */
class pgMigration_Nj_669_000_Solid3dNamespace extends pgMigration
{
	public function up()
	{
		$this->run_query(<<<'SQL'
DO $solid3d_namespace$
DECLARE
    ns text := current_schema();
    spec record;
    old_table regclass;
    specs jsonb := '{
        "projects": ["id","code","user_id","title","summary","photo","current_revision_id","published_revision_id","review_revision_id","publication","moderation_note","remix_of","created_at","updated_at","deleted_at"],
        "revisions": ["id","project_id","user_id","parent_id","prompt","source","status","job_id","run_token","model","vision_model","visual_ok","review","error","attempts","files","created_at","finished_at"],
        "events": ["id","project_id","user_id","kind","note","created_at"],
        "settings": ["key","value"],
        "quotas": ["user_id","projects","daily_runs"],
        "tasks": ["id","kind","project_id","user_id","job_id","run_token","status","model","context","result","error","created_at","finished_at"],
        "ideas": ["id","task_id","theme","body","created_at"]
    }';
BEGIN
    -- Одного совпадения имени недостаточно: оба корня должны иметь всю схему 001.
    FOR spec IN SELECT key, value FROM jsonb_each(specs) WHERE key IN ('projects','revisions') LOOP
        old_table := to_regclass(format('%I.%I', ns, 'nj_669_' || spec.key));
        IF old_table IS NULL THEN RETURN; END IF;
        IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid=old_table
            AND attname IN ('spot_id','scene_json') AND attnum>0 AND NOT attisdropped)
        THEN RETURN; END IF;
        IF EXISTS (
            SELECT 1 FROM jsonb_array_elements_text(spec.value) AS wanted(name)
            WHERE NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid=old_table
                AND attname=wanted.name AND attnum>0 AND NOT attisdropped)
        ) THEN RETURN; END IF;
    END LOOP;

    -- Сначала проверяем ВСЕ существующие таблицы/цели; только потом переносим.
    FOR spec IN SELECT key, value FROM jsonb_each(specs) LOOP
        old_table := to_regclass(format('%I.%I', ns, 'nj_669_' || spec.key));
        IF old_table IS NULL THEN CONTINUE; END IF;
        IF EXISTS (
            SELECT 1 FROM jsonb_array_elements_text(spec.value) AS wanted(name)
            WHERE NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid=old_table
                AND attname=wanted.name AND attnum>0 AND NOT attisdropped)
        ) THEN
            RAISE EXCEPTION 'Solid3D: неоднозначная старая таблица %.%; перенос остановлен без удаления данных', ns, 'nj_669_' || spec.key;
        END IF;
        IF to_regclass(format('%I.%I', ns, 'nj_669_solid3d_' || spec.key)) IS NOT NULL THEN
            RAISE EXCEPTION 'Solid3D: существуют обе таблицы % и %; автоматическое объединение запрещено', 'nj_669_' || spec.key, 'nj_669_solid3d_' || spec.key;
        END IF;
    END LOOP;
    FOR spec IN SELECT key, value FROM jsonb_each(specs) LOOP
        IF to_regclass(format('%I.%I', ns, 'nj_669_' || spec.key)) IS NOT NULL THEN
            EXECUTE format('ALTER TABLE %I.%I RENAME TO %I', ns, 'nj_669_' || spec.key, 'nj_669_solid3d_' || spec.key);
        END IF;
    END LOOP;
END;
$solid3d_namespace$
SQL
		);
	}

	/** Обратно в занятые имена не возвращаем: это могло бы повредить чужой сервис. */
	public function down() {}
}
