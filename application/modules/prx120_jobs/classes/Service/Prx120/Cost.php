<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: себестоимость LLM — токены → рубли по config/prx120_prices.php.
 *
 * Цена ищется так: «провайдер::модель» → «провайдер::*» → «модель» →
 * default (если не 0/0). Не нашлась — модель «не оценена»: её токены
 * считаются отдельно (unpriced_tokens) и не превращаются в 0 ₽.
 */
class Service_Prx120_Cost {

	/** @var array|null для тестов: подменённый прайс */
	protected static $_prices = NULL;

	public static function prices(): array
	{
		if (self::$_prices !== NULL)
		{
			return self::$_prices;
		}

		return (array) Kohana::$config->load('prx120_prices')->as_array();
	}

	/** Подменить прайс (тесты); NULL — вернуть конфиг. */
	public static function override(?array $prices): void
	{
		self::$_prices = $prices;
	}

	public static function currency(): string
	{
		return (string) (self::prices()['currency'] ?? '₽');
	}

	/**
	 * @return float[]|null [за 1 млн промпта, за 1 млн ответа] или NULL — цены нет
	 */
	public static function price(?string $provider, ?string $model): ?array
	{
		$prices   = self::prices();
		$table    = (array) ($prices['per_million'] ?? []);
		$provider = strtolower(trim((string) $provider));
		$model    = strtolower(trim((string) $model));

		$lower = [];

		foreach ($table as $k => $v)
		{
			$lower[strtolower((string) $k)] = $v;
		}

		foreach ([$provider.'::'.$model, $provider.'::*', $model] as $key)
		{
			if ($key !== '::' AND $key !== '' AND isset($lower[$key]) AND is_array($lower[$key]))
			{
				return [(float) ($lower[$key][0] ?? 0), (float) ($lower[$key][1] ?? 0)];
			}
		}

		$default = (array) ($prices['default'] ?? []);
		$p = (float) ($default[0] ?? 0);
		$c = (float) ($default[1] ?? 0);

		return ($p > 0 OR $c > 0) ? [$p, $c] : NULL;
	}

	/**
	 * Токены по моделям за последние $hours часов — для чужих потребителей
	 * (метрики prx103): SQL к prx_120_usage живёт у владельца таблицы.
	 *
	 * @return array[] provider, model, ptokens, ctokens
	 */
	public static function tokens_by_model(int $hours = 24): array
	{
		return Prx_Db::select("SELECT m->>'provider' AS provider, m->>'model' AS model,
				sum((m->>'prompt_tokens')::bigint) AS ptokens,
				sum((m->>'completion_tokens')::bigint) AS ctokens
			FROM prx_120_usage u, jsonb_array_elements(u.models) m
			WHERE u.created_at > now() - make_interval(hours => :h)
			GROUP BY 1, 2", [':h' => max(1, $hours)]);
	}

	/** Рубли за вызовы модели или NULL — цена не задана. */
	public static function rub(?string $provider, ?string $model, int $prompt_tokens, int $completion_tokens): ?float
	{
		$price = self::price($provider, $model);

		if ($price === NULL)
		{
			return NULL;
		}

		return ($prompt_tokens * $price[0] + $completion_tokens * $price[1]) / 1000000;
	}

	/**
	 * Свести строки «ключ × модель» в стоимость по ключу.
	 *
	 * @param  array  $rows [ключ => …, provider, model, ptokens, ctokens]
	 * @param  string $key  имя поля-ключа ('channel', 'visitor_id'; '' — всё в один итог)
	 * @return array ключ => ['rub' => float, 'unpriced_tokens' => int, 'priced' => bool]
	 */
	public static function aggregate(array $rows, string $key = ''): array
	{
		$out = [];

		foreach ($rows as $r)
		{
			$k  = $key === '' ? '' : (string) ($r[$key] ?? '');
			$pt = (int) ($r['ptokens'] ?? 0);
			$ct = (int) ($r['ctokens'] ?? 0);

			if ( ! isset($out[$k]))
			{
				$out[$k] = ['rub' => 0.0, 'unpriced_tokens' => 0, 'priced' => FALSE];
			}

			$rub = self::rub($r['provider'] ?? NULL, $r['model'] ?? NULL, $pt, $ct);

			if ($rub === NULL)
			{
				$out[$k]['unpriced_tokens'] += $pt + $ct;
			}
			else
			{
				$out[$k]['rub']   += $rub;
				$out[$k]['priced'] = TRUE;
			}
		}

		return $out;
	}

	/** «1 234 ₽» / «0,04 ₽» / «—» (цены нет); «+» — часть токенов не оценена. */
	public static function fmt(?array $agg): string
	{
		if ( ! $agg OR empty($agg['priced']))
		{
			return '—';
		}

		$rub = (float) $agg['rub'];
		$txt = number_format($rub, $rub < 10 ? 2 : 0, ',', ' ').' '.self::currency();

		return $agg['unpriced_tokens'] > 0 ? $txt.' +' : $txt;
	}
}
