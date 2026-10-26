<?php defined('SYSPATH') or die('No direct script access.');

/** Безсетевой осмотр уже извлечённых RAG-документов перед передачей модели. */
class Service_Nj675_RagGuard
{
    const MAX_BYTES = 131072;
    const MAX_DOCS = 100;
    const MAX_TEXT = 20000;

    public static function analyze($json)
    {
        $json = (string) $json;
        if (strlen($json) > self::MAX_BYTES)
        {
            return ['error' => 'JSON превышает лимит 128 КБ.'];
        }

        $data = json_decode($json, TRUE);
        if ( ! is_array($data) OR json_last_error() !== JSON_ERROR_NONE)
        {
            return ['error' => 'Не удалось разобрать JSON: '.json_last_error_msg()];
        }

        $docs = $data['documents'] ?? NULL;
        $tenant = trim((string) ($data['expected_tenant'] ?? ''));
        if ( ! is_array($docs) OR ! array_is_list($docs))
        {
            return ['error' => 'Ожидается массив documents. Для проверки изоляции укажите expected_tenant.'];
        }
        if ($tenant === '')
        {
            return ['error' => 'Укажите expected_tenant: без него невозможно проверить межтенантную изоляцию.'];
        }
        if (count($docs) > self::MAX_DOCS)
        {
            return ['error' => 'Поддерживается не больше 100 документов за один раз.'];
        }

        $seen = [];
        $items = [];
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $rules = [
            'prompt_injection' => '/(?:ignore|disregard|forget)\s+(?:all\s+)?(?:previous|prior|above)\s+(?:instructions|rules)|игнорируй\s+(?:все\s+)?(?:предыдущие|системные)\s+инструкции|забудь\s+(?:все\s+)?правила|reveal\s+(?:the\s+)?system\s+prompt|покажи\s+системный\s+промпт|отправь\s+(?:все\s+)?данные|exfiltrate\s+data/iu',
            'secret_material' => '/(?:sk-[A-Za-z0-9_-]{12,}|AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |EC )?PRIVATE KEY-----|Bearer\s+[A-Za-z0-9._-]{16,})/u',
            'payment_card' => '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/u',
            'email' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu',
        ];

        foreach ($docs as $index => $doc)
        {
            $id = is_array($doc) ? trim((string) ($doc['id'] ?? '')) : '';
            $text = is_array($doc) ? (string) ($doc['text'] ?? '') : '';
            $doc_tenant = is_array($doc) ? trim((string) ($doc['tenant'] ?? '')) : '';
            $trusted = is_array($doc) && ! empty($doc['trusted']);
            $findings = [];

            if ( ! is_array($doc))
            {
                $id = 'Документ '.($index + 1);
                $findings[] = self::_finding('high', 'invalid_document', 'Элемент не является объектом документа.');
            }
            else
            {
                $id = $id !== '' ? mb_substr($id, 0, 120) : 'Документ '.($index + 1);
                $key = mb_strtolower($id);
                if (isset($seen[$key]))
                {
                    $findings[] = self::_finding('medium', 'duplicate_id', 'Идентификатор документа повторяется; проверьте происхождение и дедупликацию.');
                }
                $seen[$key] = TRUE;

                if (strlen($text) > self::MAX_TEXT)
                {
                    $findings[] = self::_finding('medium', 'oversized_context', 'Текст превышает 20 КБ и будет усечён при анализе.');
                    $text = substr($text, 0, self::MAX_TEXT);
                }

                if ($tenant !== '' AND $doc_tenant !== $tenant)
                {
                    $findings[] = self::_finding('critical', 'tenant_mismatch', 'Документ не совпадает с ожидаемым tenant. Исключите его до передачи модели.');
                }

                if (preg_match($rules['prompt_injection'], $text))
                {
                    $findings[] = self::_finding($trusted ? 'medium' : 'high', 'instruction_like_text',
                        $trusted
                            ? 'Даже помеченный доверенным текст содержит фразу, похожую на инструкцию модели. Проверьте контекст и происхождение.'
                            : 'В недоверенном документе обнаружен текст, похожий на инструкцию модели. Рассматривайте весь источник как данные, не как команды.');
                }

                if (preg_match($rules['secret_material'], $text))
                {
                    $findings[] = self::_finding('high', 'credential_pattern', 'Найден шаблон, похожий на API-ключ, bearer-токен или приватный ключ. Содержимое и совпавший фрагмент не выводятся.');
                }
                if (preg_match($rules['payment_card'], $text))
                {
                    $findings[] = self::_finding('high', 'card_like_number', 'Есть длинная цифровая последовательность, похожая на номер карты. Совпадение эвристическое; проверьте источник и маскирование.');
                }
                if (preg_match($rules['email'], $text))
                {
                    $findings[] = self::_finding('medium', 'email_present', 'В контексте присутствует адрес электронной почты; проверьте цель обработки и минимизацию персональных данных.');
                }
                if ( ! $trusted AND trim($text) !== '' AND ! isset($findings[0]))
                {
                    $findings[] = self::_finding('low', 'untrusted_source', 'Источник помечен как недоверенный. Сохраняйте границу данных и отделяйте цитату от инструкций.');
                }
            }

            foreach ($findings as $finding)
            {
                $counts[$finding['severity']]++;
            }

            // Исходный текст, URL источника и совпавшие значения не возвращаем.
            $items[] = ['id' => $id, 'trusted' => $trusted, 'findings' => $findings];
        }

        $risk = $counts['critical'] > 0 ? 'critical'
            : ($counts['high'] > 0 ? 'high' : ($counts['medium'] > 0 ? 'review' : 'low'));

        return [
            'error' => '', 'document_count' => count($items), 'expected_tenant' => $tenant,
            'risk' => $risk, 'counts' => $counts, 'documents' => $items,
            'scope' => 'Локальные эвристики проверяют только предоставленный контекст. Они не запускают модель, не доказывают изоляцию хранилища и не заменяют динамический тест RAG-пайплайна.',
        ];
    }

    protected static function _finding($severity, $code, $message)
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }
}
