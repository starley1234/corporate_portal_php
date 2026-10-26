<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочники онтологии ProcessHub.
 *
 * Один справочник на рабочее пространство: роли, системы, артефакты, события,
 * метрики, правила, подразделения. Код сущности — стабильный ключ графа,
 * поэтому переименование меняет только name; code не меняется никогда.
 *
 * Первый заход в пустое пространство получает базовый набор (роли/системы/
 * артефакты), иначе синтез не к чему привязывать шаги. Набор помечен
 * is_base = TRUE: интерфейс показывает его отдельной группой.
 */
class Service_Nj670_Ontology extends Service_Nj670_Repo
{
	/** Индекс справочников в пределах запроса: workspace_id => индекс. */
	protected static $_index = [];

	public static function index($workspace_id, $fresh = FALSE)
	{
		$workspace_id = (int) $workspace_id;

		if ( ! $fresh AND isset(self::$_index[$workspace_id]))
		{
			return self::$_index[$workspace_id];
		}

		$rows = self::_all('SELECT * FROM nj_670_entities
			WHERE workspace_id = '.$workspace_id.'
			ORDER BY class_type, name');

		$index = [
			'by_code' => [],
			'by_name' => [],
			'list'    => [],
		];

		foreach ($rows as $row)
		{
			$type = (string) $row['class_type'];
			$code = (string) $row['code'];
			$name = (string) $row['name'];

			$row['attributes'] = self::json_decode($row['attributes']);

			$index['by_code'][$type][$code] = $row;
			$index['by_name'][$type][mb_strtolower($name)] = $code;
			$index['list'][$type][] = $row;
		}

		return self::$_index[$workspace_id] = $index;
	}

	public static function reset($workspace_id = NULL)
	{
		if ($workspace_id === NULL)
		{
			self::$_index = [];
		}
		else
		{
			unset(self::$_index[(int) $workspace_id]);
		}
	}

	public static function grouped($workspace_id)
	{
		$index = self::index($workspace_id);
		$out = [];

		foreach (self::entity_types() as $type => $label)
		{
			$out[$type] = [
				'label' => $label,
				'items' => (array) Arr::get($index['list'], $type, []),
			];
		}

		return $out;
	}

	/**
	 * Базовый набор: минимальные роли и системы, без которых схема «висит в
	 * воздухе». Не перезаписывает существующие коды.
	 */
	public static function ensure_defaults($workspace_id)
	{
		$workspace_id = (int) $workspace_id;

		if ($workspace_id <= 0)
		{
			return;
		}

		$existing = (int) self::_value('SELECT count(*) FROM nj_670_entities
			WHERE workspace_id = '.$workspace_id);

		if ($existing > 0)
		{
			return;
		}

		$rows = [
			['role', 'initiator', 'Инициатор', ['rate_per_hour' => 900]],
			['role', 'manager', 'Руководитель', ['rate_per_hour' => 1800]],
			['role', 'lawyer', 'Юрист', ['rate_per_hour' => 2000]],
			['role', 'accountant', 'Бухгалтер', ['rate_per_hour' => 1500]],
			['role', 'security', 'Служба безопасности', ['rate_per_hour' => 1900]],
			['system', '1c', '1C:ERP', []],
			['system', 'outlook', 'Почта Outlook', []],
			['system', 'telegram', 'Чат Telegram', []],
			['system', 'excel', 'Excel', []],
			['artifact', 'request', 'Заявка', []],
			['artifact', 'contract', 'Проект договора', []],
			['artifact', 'invoice', 'Счёт на оплату', []],
		];

		foreach ($rows as $row)
		{
			self::create($workspace_id, [
				'class_type' => $row[0],
				'code'       => $row[1],
				'name'       => $row[2],
				'attributes' => $row[3],
				'is_base'    => TRUE,
			]);
		}

		self::reset($workspace_id);
	}

	public static function create($workspace_id, array $data)
	{
		$workspace_id = (int) $workspace_id;
		$type = (string) Arr::get($data, 'class_type', '');
		$name = trim((string) Arr::get($data, 'name', ''));
		$code = trim((string) Arr::get($data, 'code', ''));

		if ( ! array_key_exists($type, self::entity_types()))
		{
			return ['ok' => FALSE, 'error' => 'Неизвестный класс сущности.'];
		}

		if ($name === '')
		{
			return ['ok' => FALSE, 'error' => 'Укажите название.'];
		}

		if ($code === '')
		{
			$code = self::code_from_name($name);
		}

		$code = self::normalize_code($code);

		if ($code === '')
		{
			$code = self::code_new(8);
		}

		$exists = (int) self::_value('SELECT count(*) FROM nj_670_entities
			WHERE workspace_id = '.$workspace_id.'
			  AND class_type = '.self::_q($type).'
			  AND code = '.self::_q($code));

		if ($exists > 0)
		{
			return ['ok' => FALSE, 'error' => 'Код «'.$code.'» уже занят в этом классе.'];
		}

		$attributes = self::attributes_from($type, $data);

		$id = self::_insert('nj_670_entities', [
			'workspace_id' => $workspace_id,
			'class_type'   => $type,
			'code'         => UTF8::substr($code, 0, 100),
			'name'         => UTF8::substr($name, 0, 255),
			'attributes'   => $attributes,
			'is_base'      => ! empty($data['is_base']),
		], ['attributes']);

		self::reset($workspace_id);

		return $id > 0
			? ['ok' => TRUE, 'id' => $id, 'code' => $code]
			: ['ok' => FALSE, 'error' => 'Не удалось сохранить сущность.'];
	}

	/**
	 * Создать или обновить сущность по коду (upsert). Общая точка для API
	 * и MCP: агент присылает kind+name+code, повторный вызов не плодит дубли.
	 */
	public static function upsert($workspace_id, array $data)
	{
		$workspace_id = (int) $workspace_id;
		$type = (string) Arr::get($data, 'class_type', Arr::get($data, 'kind', ''));
		$id = (int) Arr::get($data, 'id', 0);
		$code = trim((string) Arr::get($data, 'code', ''));
		$data['class_type'] = $type;

		if ( ! array_key_exists($type, self::entity_types()))
		{
			return ['ok' => FALSE, 'error' => 'Неизвестный класс сущности.'];
		}

		if ($id <= 0 AND $code !== '')
		{
			$index = self::index($workspace_id);
			$existing = (array) Arr::path($index['by_code'], $type.'.'.$code, []);
			$id = (int) Arr::get($existing, 'id', 0);
		}

		if ($id > 0)
		{
			$done = self::update($workspace_id, $id, $data);

			return Arr::get($done, 'ok')
				? ['ok' => TRUE, 'id' => $id, 'updated' => TRUE]
				: $done;
		}

		$done = self::create($workspace_id, $data);

		if ( ! Arr::get($done, 'ok'))
		{
			return $done;
		}

		return ['ok' => TRUE, 'id' => (int) $done['id'], 'code' => (string) $done['code'], 'updated' => FALSE];
	}

	public static function update($workspace_id, $id, array $data)
	{
		$workspace_id = (int) $workspace_id;
		$id = (int) $id;

		$row = self::_one('SELECT * FROM nj_670_entities
			WHERE id = '.$id.' AND workspace_id = '.$workspace_id);

		if ( ! $row)
		{
			return ['ok' => FALSE, 'error' => 'Сущность не найдена.'];
		}

		$name = trim((string) Arr::get($data, 'name', $row['name']));
		$name = UTF8::substr($name, 0, 255);

		if ($name === '')
		{
			return ['ok' => FALSE, 'error' => 'Название не может быть пустым.'];
		}

		$attributes = self::attributes_from((string) $row['class_type'], $data,
			self::json_decode($row['attributes']));

		self::_update('nj_670_entities', [
			'name'       => $name,
			'attributes' => $attributes,
		], $id, ['attributes']);

		self::reset($workspace_id);

		return ['ok' => TRUE];
	}

	public static function delete($workspace_id, $id)
	{
		$workspace_id = (int) $workspace_id;
		$id = (int) $id;

		$row = self::_one('SELECT * FROM nj_670_entities
			WHERE id = '.$id.' AND workspace_id = '.$workspace_id);

		if ( ! $row)
		{
			return ['ok' => FALSE, 'error' => 'Сущность не найдена.'];
		}

		$usage = self::usage_count($workspace_id, $id, (string) $row['class_type']);

		if ($usage > 0)
		{
			return ['ok' => FALSE, 'error' => 'Сущность используется в '.$usage.' мест(ах). Сначала уберите ссылки.'];
		}

		self::_exec('DELETE FROM nj_670_entities WHERE id = '.$id.'
			AND workspace_id = '.$workspace_id);

		self::reset($workspace_id);

		return ['ok' => TRUE];
	}

	public static function usage_count($workspace_id, $id, $type)
	{
		$id = (int) $id;

		if ($id <= 0)
		{
			return 0;
		}

		$scope = 'v.process_id IN (SELECT p.id FROM nj_670_processes p
			WHERE p.workspace_id = '.(int) $workspace_id.')';

		if ($type === 'role')
		{
			return (int) self::_value('SELECT (
				SELECT count(*) FROM nj_670_nodes n
					JOIN nj_670_versions v ON v.id = n.version_id
					WHERE n.role_id = '.$id.' AND '.$scope.'
			) + (
				SELECT count(*) FROM nj_670_processes p
					WHERE p.owner_role_id = '.$id.'
					  AND p.workspace_id = '.(int) $workspace_id.'
			)');
		}

		if ($type === 'system')
		{
			return (int) self::_value('SELECT count(*) FROM nj_670_nodes n
				JOIN nj_670_versions v ON v.id = n.version_id
				WHERE n.system_id = '.$id.' AND '.$scope);
		}

		if ($type === 'artifact')
		{
			return (int) self::_value('SELECT count(*) FROM nj_670_nodes n
				JOIN nj_670_versions v ON v.id = n.version_id
				WHERE (n.input_artifact_id = '.$id.' OR n.output_artifact_id = '.$id.')
				  AND '.$scope);
		}

		return 0;
	}

	/**
	 * Атрибуты из плоской формы и/или вложенного массива.
	 *
	 * Форма справочника присылает rate_per_hour, notes, system_kind и unit
	 * верхним уровнем. Раньше они молча терялись: create() читал только
	 * attributes[], и ставка роли не сохранялась. Теперь оба способа равны,
	 * а вложенные attributes главнее.
	 */
	public static function attributes_from($type, array $data, array $base = [])
	{
		$explicit = Arr::get($data, 'attributes', NULL);
		$explicit = is_array($explicit) ? $explicit : NULL;
		$attributes = $explicit !== NULL ? $explicit : $base;

		$map = [
			'rate_per_hour', 'sla_minutes', 'criticality', 'description', 'owner',
			'url', 'unit', 'target', 'category', 'note', 'system_kind', 'aliases',
		];

		foreach ($map as $key)
		{
			// Вложенный attributes — структурированные данные: он главнее
			// одноимённого поля формы. Иначе плоская форма правит справочник.
			if ($explicit !== NULL AND array_key_exists($key, $explicit))
			{
				continue;
			}

			if (array_key_exists($key, $data) AND $data[$key] !== '' AND $data[$key] !== NULL)
			{
				$attributes[$key] = $data[$key];
			}
		}

		// Поле формы «Описание / атрибуты» называется notes.
		if (empty($attributes['description']) AND ! empty($data['notes']))
		{
			$attributes['description'] = (string) $data['notes'];
		}

		return self::clean_attributes($type, $attributes);
	}

	public static function clean_attributes($type, array $attributes)
	{
		$out = [];

		$allowed = [
			'rate_per_hour', 'sla_minutes', 'criticality', 'description', 'owner',
			'url', 'unit', 'target', 'category', 'note', 'system_kind', 'aliases',
		];

		foreach ($allowed as $key)
		{
			if ( ! array_key_exists($key, $attributes))
			{
				continue;
			}

			$value = $attributes[$key];

			if (is_array($value))
			{
				$clean = [];

				foreach (array_slice($value, 0, 12) as $item)
				{
					if (is_scalar($item))
					{
						$clean[] = UTF8::substr(trim((string) $item), 0, 80);
					}
				}

				$out[$key] = $clean;
			}
			elseif (is_numeric($value))
			{
				$out[$key] = $value + 0;
			}
			elseif (is_scalar($value))
			{
				$out[$key] = UTF8::substr(trim((string) $value), 0, 500);
			}
		}

		return $out;
	}

	public static function normalize_code($code)
	{
		$code = mb_strtolower(trim((string) $code));
		$code = preg_replace('~[^a-z0-9_\-]+~u', '_', $code);
		$code = str_replace('-', '_', (string) $code);
		$code = preg_replace('~_+~', '_', (string) $code);

		return trim((string) $code, '_');
	}

	public static function code_from_name($name)
	{
		$code = self::slug_code($name, '');
		$code = str_replace('-', '_', $code);
		$code = preg_replace('~_+~', '_', $code);

		return trim((string) $code, '_');
	}

	/**
	 * Найти сущность по тексту (имя, код, алиас). Нужно синтезу и разбору
	 * журнала: модель пишет «Юрист», а в справочнике code lawyer.
	 */
	public static function match($workspace_id, $type, $text)
	{
		return self::match_index(self::index($workspace_id), $type, $text);
	}

	/**
	 * Тот же поиск, но по готовому индексу: импорт BPMN получает справочник
	 * одним массивом и не должен ходить в БД на каждую дорожку.
	 */
	public static function match_index(array $index, $type, $text)
	{
		$text = mb_strtolower(trim((string) $text));

		if ($text === '')
		{
			return '';
		}

		// Здесь именно path: Arr::get не понимает точку в ключе, и поиск
		// молча возвращал пустой словарь — роли из BPMN не сопоставлялись.
		$by_code = (array) Arr::path($index, 'by_code.'.$type, []);
		$by_name = (array) Arr::path($index, 'by_name.'.$type, []);

		if (isset($by_code[$text]))
		{
			return $text;
		}

		if (isset($by_name[$text]))
		{
			return $by_name[$text];
		}

		foreach ($by_code as $code => $row)
		{
			$name = mb_strtolower((string) $row['name']);

			if ($name !== '' AND (mb_strpos($text, $name) !== FALSE OR mb_strpos($name, $text) !== FALSE))
			{
				return $code;
			}

			foreach ((array) Arr::path($row, 'attributes.aliases', []) as $alias)
			{
				$alias = mb_strtolower((string) $alias);

				if ($alias !== '' AND mb_strpos($text, $alias) !== FALSE)
				{
					return $code;
				}
			}
		}

		return '';
	}

	/**
	 * Сериализуемая подсказка для промпта модели: только код и имя, без
	 * служебных полей. Ставки и id в промпт не попадают.
	 */
	public static function prompt_dictionary($workspace_id, $max_per_type = 80)
	{
		$index = self::index($workspace_id);
		$out = [];

		foreach (['role', 'system', 'artifact', 'event', 'rule'] as $type)
		{
			$out[$type] = [];

			foreach ((array) Arr::get($index['list'], $type, []) as $row)
			{
				$item = [
					'code' => (string) $row['code'],
					'name' => (string) $row['name'],
				];

				// Ставка роли нужна воркеру для правилового расчёта потерь,
				// когда модель недоступна (nj670.diff): без неё аудит считает
				// стоимость по одной средней ставке.
				$rate = (float) Arr::get((array) Arr::get($row, 'attributes', []), 'rate_per_hour', 0);

				if ($type === 'role' AND $rate > 0)
				{
					$item['rate_per_hour'] = $rate;
				}

				$out[$type][] = $item;

				if (count($out[$type]) >= $max_per_type)
				{
					break;
				}
			}
		}

		return $out;
	}
}
