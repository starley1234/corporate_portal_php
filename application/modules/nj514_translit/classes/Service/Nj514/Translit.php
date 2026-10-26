<?php defined('SYSPATH') or die('No direct script access.');

/** Транслитерация русского текста по нескольким таблицам, без внешних служб. */
class Service_Nj514_Translit {

    public static function standards()
    {
        return (array) Kohana::$config->load('nj514_translit.standards');
    }

    public static function max_chars()
    {
        return (int) Kohana::$config->load('nj514_translit.max_chars');
    }

    public static function privacy_note()
    {
        return (string) Kohana::$config->load('nj514_translit.privacy_note');
    }

    /** Перевод текста. Вернёт NULL для неизвестного стандарта/направления или превышения лимита. */
    public static function convert($text, $standard, $direction = 'to_latin')
    {
        $standards = self::standards();
        if ( ! is_string($text) OR ! is_string($standard) OR ! isset($standards[$standard])) return NULL;
        if ( ! in_array($direction, ['to_latin', 'to_cyrillic'], TRUE)) return NULL;
        if ( ! mb_check_encoding($text, 'UTF-8') OR mb_strlen($text, 'UTF-8') > self::max_chars()) return NULL;

        $definition = $standards[$standard];
        $converted = $direction === 'to_latin'
            ? self::_to_latin($text, $definition)
            : self::_to_cyrillic($text, $definition);

        return [
            'standard' => $standard,
            'direction' => $direction,
            'text' => $converted['text'],
            'ambiguities' => $converted['ambiguities'],
            'characters' => mb_strlen($text, 'UTF-8'),
        ];
    }

    protected static function _to_latin($text, array $definition)
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($chars)) return ['text' => '', 'ambiguities' => []];
        $mapping = $definition['mapping'];
        $all_upper = mb_strtoupper($text, 'UTF-8') === $text;
        $out = '';
        $count = count($chars);

        for ($i = 0; $i < $count; $i++)
        {
            $char = $chars[$i];
            $lower = mb_strtolower($char, 'UTF-8');
            if ($char === ' ' AND ! empty($definition['mrz_spaces']))
            {
                $out .= '<';
                continue;
            }
            if ( ! isset($mapping[$lower]))
            {
                $out .= $char;
                continue;
            }

            $value = $mapping[$lower];
            if ($lower === 'ц' AND isset($mapping['ё']) AND $mapping['ё'] === 'yo')
            {
                // В системе Б ГОСТ Ц перед e/i/y/j записывается c, иначе cz.
                $next = isset($chars[$i + 1]) ? mb_strtolower($chars[$i + 1], 'UTF-8') : '';
                $next_token = isset($mapping[$next]) ? $mapping[$next] : $next;
                $value = strpos('eiyj', strtolower(substr($next_token, 0, 1))) !== FALSE ? 'c' : 'cz';
            }

            if ($value === '') continue;
            $is_upper = mb_strtoupper($char, 'UTF-8') === $char && mb_strtolower($char, 'UTF-8') !== $char;
            if ( ! empty($definition['uppercase']) OR $all_upper)
            {
                $out .= strtoupper($value);
            }
            elseif ($is_upper)
            {
                $out .= ucfirst($value);
            }
            else
            {
                $out .= $value;
            }
        }

        return ['text' => $out, 'ambiguities' => []];
    }

    protected static function _to_cyrillic($text, array $definition)
    {
        $mapping = $definition['mapping'];
        $reverse = [];
        foreach ($mapping as $letter => $roman)
        {
            if ($roman === '') continue;
            $token = strtolower($roman);
            if ( ! isset($reverse[$token])) $reverse[$token] = $letter;
        }
        if (isset($mapping['ц']) AND isset($mapping['ё']) AND $mapping['ё'] === 'yo') $reverse['cz'] = 'ц';
        uksort($reverse, function ($a, $b) { return strlen($b) <=> strlen($a); });

        if ( ! empty($definition['mrz_spaces'])) $text = str_replace('<', ' ', $text);
        $length = strlen($text);
        $out = '';
        $ambiguities = [];
        $pos = 0;
        $char_pos = 0;
        while ($pos < $length)
        {
            $matched = NULL;
            foreach ($reverse as $token => $letter)
            {
                $token_length = strlen($token);
                $candidate = substr($text, $pos, $token_length);
                if (strlen($candidate) === $token_length AND strtolower($candidate) === $token)
                {
                    $matched = [$candidate, $letter];
                    break;
                }
            }

            if ($matched === NULL)
            {
                if (ord($text[$pos]) >= 0x80)
                {
                    if (preg_match('/\\G./us', $text, $utf8_char, 0, $pos))
                    {
                        $out .= $utf8_char[0];
                        $pos += strlen($utf8_char[0]);
                    }
                    else
                    {
                        $out .= "�";
                        $pos++;
                    }
                }
                else
                {
                    $out .= $text[$pos];
                    $pos++;
                }
                $char_pos++;
                continue;
            }

            $candidate = $matched[0];
            $letter = $matched[1];
            $lower_token = strtolower($candidate);
            $choices = $definition['ambiguities'][$lower_token] ?? [];
            if ($choices)
            {
                $ambiguities[] = ['position' => $char_pos + 1, 'latin' => $candidate, 'choices' => $choices];
            }

            $all_upper = strtoupper($candidate) === $candidate && strtolower($candidate) !== $candidate;
            $starts_upper = strtoupper(substr($candidate, 0, 1)) === substr($candidate, 0, 1)
                && strtolower(substr($candidate, 0, 1)) !== substr($candidate, 0, 1);
            $out .= $all_upper ? mb_strtoupper($letter, 'UTF-8') : ($starts_upper ? mb_strtoupper($letter, 'UTF-8') : $letter);
            $pos += strlen($candidate);
            $char_pos += mb_strlen($candidate, 'UTF-8');
        }

        return ['text' => $out, 'ambiguities' => $ambiguities];
    }
}
