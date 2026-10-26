<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Параметры детали — только размеченные присваивания в начале исходника. SCAD не исполняется.
 *
 *   // @param 1 100 0.5 Толщина, мм   → wall = 4;        число в диапазоне и с шагом
 *   // @bool Сквозное отверстие       → through = true;  да/нет
 *   // @choice Посадка | 1:Плотная | 2:Свободная        → fit = 2;   выбор из списка чисел
 *   // @text Надпись                  → label_txt = "Вт-1";  строка до 40 знаков без кавычек
 *
 * Имя и позиция литерала берутся только из серверного исходника; значения пользователя
 * проходят через normalize() и не попадают в код иначе, как литералом нужного типа.
 */
class Service_Nj669_Parameters
{
	const NUMBER = '[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?';
	const TEXT_MAX = 40;
	const TEXT_RE = '~\A[\p{L}\p{N} ._\-]{0,40}\z~u';

	public static function parse($source)
	{
		$offset = 0; $pending = NULL; $result = []; $seen = [];
		$literal = '(?:'.self::NUMBER.'|true|false|"[^"\\\\\r\n]{0,200}")';
		while ($offset < strlen($source))
		{
			if (preg_match('~\G\s+~', $source, $m, 0, $offset)) { $offset += strlen($m[0]); continue; }
			if (preg_match('~\G(?://[^\r\n]*|/\*.*?\*/)~s', $source, $m, 0, $offset))
			{
				$pending = self::meta($m[0]);
				$offset += strlen($m[0]); continue;
			}
			if ( ! preg_match('~\G(\$?[A-Za-z_][A-Za-z0-9_]*)\s*=\s*('.$literal.')\s*;~', $source, $m, PREG_OFFSET_CAPTURE, $offset)) { break; }
			$name = $m[1][0];
			// Повторное присваивание меняет семантику SCAD: такое поле не показываем.
			if (isset($seen[$name])) { unset($result[$name]); $pending = NULL; }
			if ($pending AND count($result) < 24 AND ! isset($seen[$name]) AND $name[0] !== '$')
			{
				$field = self::field($pending, $m[2][0]);
				if ($field !== NULL) { $result[$name] = $field + ['name' => $name, 'offset' => $m[2][1], 'length' => strlen($m[2][0])]; }
			}
			$seen[$name] = TRUE; $pending = NULL; $offset += strlen($m[0][0]);
		}
		return $result;
	}

	/** Разбор комментария-разметки. Любой другой комментарий сбрасывает ожидание. */
	private static function meta($comment)
	{
		if (preg_match('~^//\s*@param\s+('.self::NUMBER.')\s+('.self::NUMBER.')\s+('.self::NUMBER.')\s+(.{1,160})$~u', $comment, $meta))
		{
			return ['type' => 'number', 'min' => (float) $meta[1], 'max' => (float) $meta[2], 'step' => (float) $meta[3], 'label' => trim($meta[4])];
		}
		if (preg_match('~^//\s*@bool\s+(.{1,160})$~u', $comment, $meta)) { return ['type' => 'bool', 'label' => trim($meta[1])]; }
		if (preg_match('~^//\s*@text\s+(.{1,160})$~u', $comment, $meta)) { return ['type' => 'text', 'label' => trim($meta[1])]; }
		if (preg_match('~^//\s*@choice\s+(.{1,400})$~u', $comment, $meta))
		{
			$parts = array_map('trim', explode('|', $meta[1]));
			$label = array_shift($parts); $options = [];
			foreach ($parts as $part)
			{
				if ( ! preg_match('~^('.self::NUMBER.')\s*:\s*(.{1,60})$~u', $part, $o)) { return NULL; }
				$options[] = ['value' => (float) $o[1], 'label' => trim($o[2])];
			}
			if ($label === '' OR count($options) < 2 OR count($options) > 8) { return NULL; }
			return ['type' => 'choice', 'label' => $label, 'options' => $options];
		}
		return NULL;
	}

	/** Поле из разметки и литерала исходника; NULL — литерал не подходит типу или вне диапазона. */
	private static function field(array $meta, $literal)
	{
		if ($meta['type'] === 'number')
		{
			if ( ! preg_match('~\A'.self::NUMBER.'\z~', $literal)) { return NULL; }
			$value = (float) $literal;
			if ( ! is_finite($meta['min']) OR ! is_finite($meta['max']) OR ! is_finite($meta['step'])
				OR abs($meta['min']) > 1000000 OR abs($meta['max']) > 1000000
				OR $meta['min'] >= $meta['max'] OR $meta['step'] <= 0 OR $meta['step'] > $meta['max'] - $meta['min']
				OR $value < $meta['min'] OR $value > $meta['max']) { return NULL; }
			return $meta + ['value' => $value];
		}
		if ($meta['type'] === 'bool')
		{
			if ($literal !== 'true' AND $literal !== 'false') { return NULL; }
			return $meta + ['value' => $literal === 'true'];
		}
		if ($meta['type'] === 'choice')
		{
			if ( ! preg_match('~\A'.self::NUMBER.'\z~', $literal)) { return NULL; }
			$value = (float) $literal;
			foreach ($meta['options'] as $option) { if ($option['value'] === $value) { return $meta + ['value' => $value]; } }
			return NULL;
		}
		if ($meta['type'] === 'text')
		{
			if ( ! preg_match('~\A"([^"\\\\\r\n]*)"\z~', $literal, $t) OR ! preg_match(self::TEXT_RE, $t[1])) { return NULL; }
			return $meta + ['value' => $t[1], 'maxlength' => self::TEXT_MAX];
		}
		return NULL;
	}

	/**
	 * Значения пользователя → типизированные значения. Бросает DomainException при любом
	 * нарушении: тип, диапазон, неизвестный вариант, символы в тексте.
	 */
	public static function normalize($source, array $values)
	{
		$fields = self::parse($source);
		if ( ! $fields OR array_diff(array_keys($values), array_keys($fields))) { throw new DomainException('Параметры этой версии изменились. Обновите страницу.'); }
		$out = [];
		foreach ($values as $name => $raw)
		{
			$field = $fields[$name];
			if ( ! is_scalar($raw)) { throw new DomainException('Некорректное значение параметра.'); }
			$raw = is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw;
			if ($field['type'] === 'number')
			{
				if ( ! preg_match('~\A'.self::NUMBER.'\z~D', $raw)) { throw new DomainException('Параметр должен быть числом.'); }
				$value = (float) $raw;
				if ( ! is_finite($value) OR $value < $field['min'] OR $value > $field['max']) { throw new DomainException('Параметр «'.$field['label'].'» вне допустимого диапазона.'); }
			}
			elseif ($field['type'] === 'bool')
			{
				if ( ! in_array($raw, ['0', '1', 'true', 'false'], TRUE)) { throw new DomainException('Параметр «'.$field['label'].'»: да или нет.'); }
				$value = in_array($raw, ['1', 'true'], TRUE);
			}
			elseif ($field['type'] === 'choice')
			{
				$value = (float) $raw; $ok = FALSE;
				if (preg_match('~\A'.self::NUMBER.'\z~D', $raw)) { foreach ($field['options'] as $option) { if ($option['value'] === $value) { $ok = TRUE; } } }
				if ( ! $ok) { throw new DomainException('Параметр «'.$field['label'].'»: такого варианта нет.'); }
			}
			else
			{
				if ( ! preg_match(self::TEXT_RE, $raw)) { throw new DomainException('Параметр «'.$field['label'].'»: до 40 букв, цифр, пробелов и знаков . _ -'); }
				$value = $raw;
			}
			$out[$name] = $value;
		}
		if ( ! $out) { throw new DomainException('Укажите новые значения параметров.'); }
		return $out;
	}

	/** Текст SCAD-литерала для типизированного значения. */
	public static function literal($type, $value)
	{
		if ($type === 'bool') { return $value ? 'true' : 'false'; }
		if ($type === 'text') { return '"'.$value.'"'; }
		return json_encode((float) $value, JSON_PRESERVE_ZERO_FRACTION);
	}

	public static function apply($source, array $values)
	{
		$fields = self::parse($source);
		$replacements = [];
		foreach (self::normalize($source, $values) as $name => $value)
		{
			$field = $fields[$name];
			$replacements[] = ['offset' => $field['offset'], 'length' => $field['length'], 'replacement' => self::literal($field['type'], $value)];
		}
		usort($replacements, function ($a, $b) { return $b['offset'] <=> $a['offset']; });
		foreach ($replacements as $r) { $source = substr_replace($source, $r['replacement'], $r['offset'], $r['length']); }
		return $source;
	}

	/** Сохранённые в JSON значения → значения полей (только подходящего типа). */
	public static function restore(array $fields, array $saved)
	{
		foreach ($fields as $name => &$field)
		{
			if ( ! array_key_exists($name, $saved)) { continue; }
			$v = $saved[$name];
			if ($field['type'] === 'bool' AND is_bool($v)) { $field['value'] = $v; }
			elseif ($field['type'] === 'text' AND is_string($v) AND preg_match(self::TEXT_RE, $v)) { $field['value'] = $v; }
			elseif ($field['type'] === 'number' AND is_numeric($v) AND (float) $v >= $field['min'] AND (float) $v <= $field['max']) { $field['value'] = (float) $v; }
			elseif ($field['type'] === 'choice' AND is_numeric($v))
			{
				foreach ($field['options'] as $option) { if ($option['value'] === (float) $v) { $field['value'] = (float) $v; } }
			}
		}
		return $fields;
	}
}
