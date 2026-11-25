<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Запросы к prx_396_parts (пилот Д8): один публичный метод = один запрос,
 * только :param, без логики. Транзакцию владеет Service.
 */
class Model_Prx396_Parts {

	/**
	 * Диапазоны страниц частей: [part_no, page_from, page_to].
	 * VALUES-список собирается из уже приведённых к int значений.
	 */
	public static function insert_ranges(int $batch_id, array $ranges): void
	{
		if ( ! $ranges)
		{
			return;
		}

		$values = [];
		$params = [':batch_id' => $batch_id];

		foreach ($ranges as $i => [$no, $from, $to])
		{
			$values[] = "(:batch_id, :no$i, :from$i, :to$i, 'pending', now())";
			$params[":no$i"] = (int) $no;
			$params[":from$i"] = (int) $from;
			$params[":to$i"] = (int) $to;
		}

		Prx_Db::execute(
			'INSERT INTO prx_396_parts (batch_id, part_no, page_from, page_to, status, updated_at)
			 VALUES '.implode(',', $values).
			' ON CONFLICT (batch_id, part_no) DO NOTHING',
			$params);
	}

	/** Все части партии в порядке номеров (полезная нагрузка плана). */
	public static function find_by_batch(int $batch_id): array
	{
		return Prx_Db::select(
			'SELECT part_no, page_from, page_to, status, text_bytes, error_code
			 FROM prx_396_parts
			 WHERE batch_id = :id
			 ORDER BY part_no',
			[':id' => $batch_id]);
	}

	/** Части с готовыми текстами (выдача результата). */
	public static function find_result_rows(int $batch_id): array
	{
		return Prx_Db::select(
			'SELECT part_no, text_file_id, text_bytes, status
			 FROM prx_396_parts
			 WHERE batch_id = :id
			 ORDER BY part_no',
			[':id' => $batch_id]);
	}

	public static function find_part(int $batch_id, int $part_no): ?array
	{
		return Prx_Db::one(
			'SELECT part_no, page_from, page_to, status, text_file_id, text_bytes
			 FROM prx_396_parts
			 WHERE batch_id = :b AND part_no = :p',
			[':b' => $batch_id, ':p' => $part_no]);
	}

	/**
	 * Счётчики партии одним запросом: готово/всего, байты готового текста,
	 * первая неготовая часть. Потребители берут нужные ключи.
	 */
	public static function counts_by_batch(int $batch_id): ?array
	{
		return Prx_Db::one(
			"SELECT COUNT(*) FILTER (WHERE status = 'done') AS done,
			        COUNT(*) AS total,
			        COALESCE(SUM(text_bytes) FILTER (WHERE status = 'done'), 0) AS bytes,
			        MIN(part_no) FILTER (WHERE status IN ('pending','failed')) AS next_part
			 FROM prx_396_parts
			 WHERE batch_id = :id",
			[':id' => $batch_id]);
	}

	/** Суммарный объём готового текста (проверка лимита до записи). */
	public static function done_bytes(int $batch_id): int
	{
		$row = Prx_Db::one(
			"SELECT COALESCE(SUM(text_bytes), 0) AS total
			 FROM prx_396_parts
			 WHERE batch_id = :b AND status = 'done'",
			[':b' => $batch_id]);

		return (int) ($row['total'] ?? 0);
	}

	/** Отметка части готовой; возвращает число обновлённых строк (гонка = 0). */
	public static function mark_done(int $batch_id, int $part_no, int $file_id, int $text_bytes): int
	{
		return (int) Prx_Db::execute(
			"UPDATE prx_396_parts
			 SET status = 'done', text_file_id = :file, text_bytes = :bytes,
			     error_code = NULL, updated_at = now()
			 WHERE batch_id = :b AND part_no = :p AND status IN ('pending','failed','running')",
			[':file' => $file_id, ':bytes' => $text_bytes,
			 ':b' => $batch_id, ':p' => $part_no]);
	}

	/** Текстовые артефакты партии для уборки. */
	public static function find_text_files(int $batch_id): array
	{
		return Prx_Db::select(
			'SELECT text_file_id FROM prx_396_parts
			 WHERE batch_id = :id AND text_file_id IS NOT NULL',
			[':id' => $batch_id]);
	}
}
