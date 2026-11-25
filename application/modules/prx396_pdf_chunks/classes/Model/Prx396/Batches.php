<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Запросы к prx_396_batches (пилот Д8): один публичный метод = один запрос,
 * только :param, без логики. Транзакцию владеет Service.
 */
class Model_Prx396_Batches {

	public static function insert_queued(int $owner, string $key_hash, string $sha, int $file_id, int $chunk_pages, int $ttl_hours, string $snapshot_json): int
	{
		$row = Prx_Db::one(
			'INSERT INTO prx_396_batches
			 (job_id, owner_id, request_key, file_sha256, file_id, chunk_pages, status, config_snapshot, expires_at)
			 VALUES (0, :owner, :key_hash, :sha, :file_id, :chunk_pages, \'queued\', :snapshot::jsonb,
			         now() + (:ttl * interval \'1 hour\'))
			 RETURNING id',
			[':owner' => $owner, ':key_hash' => $key_hash, ':sha' => $sha,
			 ':file_id' => $file_id, ':chunk_pages' => $chunk_pages,
			 ':snapshot' => $snapshot_json, ':ttl' => $ttl_hours]);

		return $row ? (int) $row['id'] : 0;
	}

	public static function set_job(int $batch_id, int $job_id): void
	{
		Prx_Db::execute(
			'UPDATE prx_396_batches SET job_id = :job WHERE id = :id',
			[':job' => $job_id, ':id' => $batch_id]);
	}

	/** Строка партии владельца с непротёкшим сроком (статус, счётчики, expiry). */
	public static function find_for_owner(int $batch_id, int $owner): ?array
	{
		return Prx_Db::one(
			'SELECT id, job_id, status, pages_total, error_code, text_bytes, expires_at
			 FROM prx_396_batches
			 WHERE id = :id AND owner_id = :owner AND expires_at > now()',
			[':id' => $batch_id, ':owner' => $owner]);
	}

	/** Краткая строка для полезной нагрузки плана. */
	public static function find_summary(int $batch_id): ?array
	{
		return Prx_Db::one(
			'SELECT pages_total, text_bytes, error_code, status
			 FROM prx_396_batches
			 WHERE id = :id',
			[':id' => $batch_id]);
	}

	/** Строка для lease-проверки воркера (включая снимок конфига). */
	public static function find_lease(int $batch_id): ?array
	{
		return Prx_Db::one(
			'SELECT id, job_id, owner_id, file_id, file_sha256, pages_total, status, error_code, config_snapshot
			 FROM prx_396_batches
			 WHERE id = :id AND expires_at > now()',
			[':id' => $batch_id]);
	}

	public static function mark_running(int $batch_id): void
	{
		Prx_Db::execute(
			"UPDATE prx_396_batches SET status = 'running', error_code = NULL
			 WHERE id = :id AND status IN ('queued','running')",
			[':id' => $batch_id]);
	}

	/** Общее число страниц известно впервые: фиксируем и переводим в running. */
	public static function set_pages_total(int $batch_id, int $pages_total): void
	{
		Prx_Db::execute(
			"UPDATE prx_396_batches SET pages_total = :pages, status = 'running'
			 WHERE id = :id AND pages_total IS NULL",
			[':pages' => $pages_total, ':id' => $batch_id]);
	}

	public static function mark_done(int $batch_id): void
	{
		Prx_Db::execute(
			"UPDATE prx_396_batches SET status = 'done', error_code = NULL
			 WHERE id = :id AND status IN ('running','done')",
			[':id' => $batch_id]);
	}

	public static function fail(int $batch_id, string $code): void
	{
		Prx_Db::execute(
			"UPDATE prx_396_batches SET status = 'failed', error_code = :code
			 WHERE id = :id AND status IN ('queued','running','failed')",
			[':code' => $code, ':id' => $batch_id]);
	}

	/** Прогресс текста; при done снимаем код ошибки (без логики — статус приходит из Service). */
	public static function update_progress(int $batch_id, int $text_bytes, string $status): void
	{
		Prx_Db::execute(
			"UPDATE prx_396_batches
			 SET text_bytes = :bytes,
			     status = :status,
			     error_code = CASE WHEN :status = 'done' THEN NULL ELSE error_code END
			 WHERE id = :id AND status IN ('queued','running','done')",
			[':bytes' => $text_bytes, ':status' => $status, ':id' => $batch_id]);
	}

	/** Порция истёкших партий для уборки. */
	public static function find_expired(int $limit): array
	{
		return Prx_Db::select(
			'SELECT id FROM prx_396_batches
			 WHERE expires_at <= now()
			 ORDER BY expires_at, id
			 LIMIT :limit',
			[':limit' => $limit]);
	}

	/** Блокирующая строка уборки: партия истекла? */
	public static function lock_expired(int $batch_id): ?array
	{
		return Prx_Db::one(
			'SELECT file_id FROM prx_396_batches
			 WHERE id = :id AND expires_at <= now()
			 FOR UPDATE',
			[':id' => $batch_id]);
	}

	public static function delete(int $batch_id): void
	{
		Prx_Db::execute(
			'DELETE FROM prx_396_batches WHERE id = :id',
			[':id' => $batch_id]);
	}

	/** Есть ли ссылка на файл артефакта в batches или parts (сироты при уборке). */
	public static function has_file(int $file_id): bool
	{
		return (bool) Prx_Db::one(
			'SELECT 1 FROM prx_396_batches WHERE file_id = :f
			 UNION ALL
			 SELECT 1 FROM prx_396_parts WHERE text_file_id = :f
			 LIMIT 1',
			[':f' => $file_id]);
	}
}
