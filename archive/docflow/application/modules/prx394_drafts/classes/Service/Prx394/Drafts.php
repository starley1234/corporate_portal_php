<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Черновики ответов.
 *
 * Генерация — в воркере (`doc.draft`), потому что это LLM-операция.
 * Отправка — только вручную и только после «Утвердить»; автоматической
 * отправки в коде нет.
 */
class Service_Prx394_Drafts
{
    protected static $_config;

    public static function config($key = NULL, $default = NULL)
    {
        if (self::$_config === NULL)
        {
            self::$_config = Kohana::$config->load('prx394_drafts')->as_array();
        }

        if ($key === NULL)
        {
            return self::$_config;
        }

        return array_key_exists($key, self::$_config) ? self::$_config[$key] : $default;
    }

    public static function templates()
    {
        return self::_all("SELECT * FROM prx_394_templates WHERE is_active = 1 ORDER BY title");
    }

    public static function template($id)
    {
        return self::_row("SELECT * FROM prx_394_templates WHERE id = ".(int) $id);
    }

    public static function template_for_type($type_code)
    {
        $row = self::_row("SELECT * FROM prx_394_templates WHERE is_active = 1 AND doc_type = ".self::_q($type_code)." ORDER BY id LIMIT 1");
        if ( ! $row)
        {
            $row = self::_row("SELECT * FROM prx_394_templates WHERE is_active = 1 AND code = 'info_reply'");
        }

        return $row;
    }

    // ------------------------------------------------------------------
    // Создание и генерация
    // ------------------------------------------------------------------

    public static function queue_draft($document_id, $template_id = NULL, array $actor = [])
    {
        $document = Service_Prx391_Documents::document($document_id, self::_system_actor($actor));
        if ( ! $document)
        {
            throw new InvalidArgumentException('Документ не найден');
        }
        if (in_array($document['status'], ['archived'], TRUE))
        {
            throw new RuntimeException('Документ в архиве');
        }

        $template = $template_id ? self::template($template_id) : NULL;
        if ( ! $template)
        {
            $template = self::template_for_type((string) ($document['type_code'] ?? 'letter'));
        }
        if ( ! $template)
        {
            throw new RuntimeException('Шаблон ответа не найден');
        }

        $profile = self::config('profile');
        self::provider_for_model($profile['model']);

        $draft = self::_row("
            INSERT INTO prx_394_drafts
                (document_id, reg_no, subject, template_id, profile, model, prompt_version, status, created_by)
            VALUES (
                ".(int) $document_id.", ".self::_q($document['reg_no']).", ".self::_q($document['subject']).",
                ".(int) $template['id'].", ".self::_q($profile['code']).", ".self::_q($profile['model']).",
                ".self::_q($profile['prompt_version']).", 'generating', ".self::_int_or_null($actor['user_id'] ?? NULL)."
            )
            RETURNING id
        ");

        $draft_id = (int) $draft['id'];

        try
        {
            Service_Prx120_JobQueue::push('doc.draft', [
                'draft_id' => $draft_id,
                'document_id' => (int) $document_id,
            ], [
                'module' => 'prx394',
                'priority' => 50,
                'max_attempts' => 2,
                'timeout_sec' => 900,
                'created_by' => (int) ($actor['user_id'] ?? 0),
            ]);
        }
        catch (Exception $e)
        {
            self::_exec("UPDATE prx_394_drafts SET status = 'failed', error = ".self::_q($e->getMessage())." WHERE id = ".$draft_id);
            throw $e;
        }

        return $draft_id;
    }

    public static function provider_for_model($model)
    {
        $model = trim((string) $model);
        $pos = strpos($model, '::');
        $provider = $pos === FALSE ? '' : substr($model, 0, $pos);
        $allowed = self::config('allowed_providers', ['local']);

        if ($provider === '' OR ! in_array($provider, $allowed, TRUE))
        {
            throw new RuntimeException('Провайдер модели "'.$provider.'" не разрешён для черновиков (только '.implode(', ', $allowed).')');
        }

        return $provider;
    }

    public static function input_for_worker($draft_id)
    {
        $draft = self::draft($draft_id);
        if ( ! $draft)
        {
            throw new InvalidArgumentException('Черновик не найден');
        }
        if (in_array($draft['status'], ['approved', 'sent', 'rejected'], TRUE) OR trim((string) $draft['body']) !== '')
        {
            return ['done' => TRUE, 'status' => $draft['status']];
        }

        $document = Service_Prx391_Documents::document((int) $draft['document_id'], self::_system_actor());
        if ( ! $document)
        {
            throw new RuntimeException('Документ не найден');
        }

        $template = $draft['template_id'] ? self::template((int) $draft['template_id']) : NULL;
        if ( ! $template)
        {
            throw new RuntimeException('Шаблон черновика не найден');
        }

        $profile = self::config('profile');
        self::provider_for_model($draft['model'] ?: $profile['model']);

        $text = '';
        if ( ! empty($document['message']['text']['text']))
        {
            $text = (string) $document['message']['text']['text'];
            $max = (int) self::config('max_context_chars', 30000);
            if (mb_strlen($text) > $max)
            {
                $text = mb_substr($text, 0, $max)."\n\n[... текст обрезан ...]";
            }
        }

        $vars = [
            '{{correspondent}}' => $document['correspondent_name'] ?: ($document['sender_name'] ?: 'Коллеги'),
            '{{outbound_no}}' => $document['outbound_no'] ?: '[номер входящего]',
            '{{outbound_date}}' => $document['outbound_date'] ?: '[дата входящего]',
            '{{contract_no}}' => $document['contract_no'] ?: '[номер договора]',
            '{{summary}}' => $document['summary'] ?: '[краткое содержание]',
            '{{organization}}' => 'ООО «Компания»',
            '{{reg_no}}' => $document['reg_no'],
        ];

        $template_body = strtr((string) $template['body'], $vars);

        $user = "Шаблон ответа (структура, которую нужно соблюсти):\n<template>\n".$template_body."\n</template>\n\n"
            ."Карточка документа:\n"
            .'Рег. №: '.$document['reg_no']."\n"
            .'Тема: '.$document['subject']."\n"
            .'Тип: '.($document['type_title'] ?: 'не определён')."\n"
            .'Корреспондент: '.($document['correspondent_name'] ?: ($document['sender_name'] ?: 'не определён'))."\n"
            .'Исходящий №: '.($document['outbound_no'] ?: '—')."\n"
            .'Дата: '.($document['outbound_date'] ?: '—')."\n"
            .'Договор: '.($document['contract_no'] ?: '—')."\n"
            .'Сумма: '.($document['amount'] !== NULL ? $document['amount'].' RUB' : '—')."\n"
            .'Краткое содержание: '.($document['summary'] ?: '—')."\n\n"
            ."Текст входящего:\n<document>\n".$text."\n</document>\n\n"
            ."Составь проект ответа. Не добавляй факты, которых нет выше. Если чего-то не хватает — оставь [квадратные скобки].";

        return [
            'done' => FALSE,
            'draft_id' => (int) $draft['id'],
            'model' => $draft['model'] ?: $profile['model'],
            'temperature' => (float) $profile['temperature'],
            'max_tokens' => (int) $profile['max_tokens'],
            'messages' => [
                ['role' => 'system', 'content' => $profile['system']],
                ['role' => 'user', 'content' => $user],
            ],
            'citations' => [
                ['kind' => 'document', 'ref' => $document['reg_no'], 'href' => '/documents/'.(int) $document['id']],
                ['kind' => 'template', 'ref' => $template['code']],
            ],
        ];
    }

    public static function store_result($draft_id, array $data)
    {
        $draft = self::draft($draft_id);
        if ( ! $draft)
        {
            throw new InvalidArgumentException('Черновик не найден');
        }
        // Задача могла прийти повторно после того, как человек уже утвердил
        // или отправил текст. Чужой ответ модели не должен затереть решение.
        if (in_array($draft['status'], ['approved', 'sent', 'rejected'], TRUE))
        {
            return $draft;
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' AND isset($data['result']['body']))
        {
            $body = trim((string) $data['result']['body']);
        }
        if (mb_strlen($body) < 40)
        {
            self::_exec("UPDATE prx_394_drafts SET status = 'failed', error = 'Модель вернула пустой ответ' WHERE id = ".(int) $draft_id);

            throw new InvalidArgumentException('Пустой ответ модели');
        }

        $citations = $data['citations'] ?? [];
        if ( ! is_array($citations))
        {
            $citations = [];
        }
        $body = mb_substr($body, 0, 100000);
        $citations = array_slice(array_values($citations), 0, 20);

        self::_exec("
            UPDATE prx_394_drafts
            SET body = ".self::_q($body).", citations = ".self::_q(json_encode($citations, JSON_UNESCAPED_UNICODE))."::jsonb,
                status = 'draft', error = NULL, updated_at = now()
            WHERE id = ".(int) $draft_id."
        ");

        Event::fire('prx394.draft.ready', [
            'draft_id' => (int) $draft_id,
            'document_id' => (int) $draft['document_id'],
            'reg_no' => $draft['reg_no'],
            'title' => 'Черновик ответа: '.$draft['reg_no'],
            'href' => '/replies/document/'.(int) $draft['document_id'],
        ]);

        return self::draft($draft_id);
    }

    // ------------------------------------------------------------------
    // Проверка, утверждение, отправка
    // ------------------------------------------------------------------

    public static function save_body($draft_id, $body, array $actor = [])
    {
        $draft = self::draft($draft_id);
        if ( ! $draft)
        {
            throw new InvalidArgumentException('Черновик не найден');
        }
        if (in_array($draft['status'], ['sent'], TRUE))
        {
            throw new RuntimeException('Отправленный черновик нельзя менять');
        }

        // Текст храним как есть: представление экранирует его через HTML::chars.
        // strip_tags здесь удалял бы всё после «<» в обычной фразе («срок < 10 дней»).
        $body = mb_substr(trim((string) $body), 0, 100000);

        self::_exec("
            UPDATE prx_394_drafts
            SET body = ".self::_q($body).", status = 'draft', error = NULL, updated_at = now()
            WHERE id = ".(int) $draft_id."
        ");

        return TRUE;
    }

    public static function approve($draft_id, array $actor = [])
    {
        $draft = self::draft($draft_id);
        if ( ! $draft)
        {
            throw new InvalidArgumentException('Черновик не найден');
        }
        if ($draft['status'] === 'sent')
        {
            throw new RuntimeException('Письмо уже отправлено');
        }
        if (trim((string) $draft['body']) === '')
        {
            throw new RuntimeException('Пустой черновик нельзя утвердить');
        }

        self::_exec("
            UPDATE prx_394_drafts
            SET status = 'approved', approved_by = ".self::_int_or_null($actor['user_id'] ?? NULL).",
                approved_at = now(), updated_at = now()
            WHERE id = ".(int) $draft_id."
        ");

        return TRUE;
    }

    public static function reject($draft_id, array $actor = [])
    {
        $draft = self::draft($draft_id);
        if ( ! $draft)
        {
            throw new InvalidArgumentException('Черновик не найден');
        }
        if ($draft['status'] === 'sent')
        {
            throw new RuntimeException('Отправленный черновик нельзя отклонить');
        }

        self::_exec("UPDATE prx_394_drafts SET status = 'rejected', updated_at = now() WHERE id = ".(int) $draft_id);

        return TRUE;
    }

    public static function send($draft_id, array $actor = [])
    {
        $draft = self::draft($draft_id);
        if ( ! $draft)
        {
            throw new InvalidArgumentException('Черновик не найден');
        }
        if ($draft['status'] !== 'approved')
        {
            throw new RuntimeException('Сначала утвердите черновик');
        }
        if ( ! self::config('email_enabled', FALSE))
        {
            throw new RuntimeException('Отправка писем выключена: задайте PRX394_EMAIL_ENABLED=1 и EMAIL_DSN');
        }
        if ( ! class_exists('Service_Prx597_Notify'))
        {
            throw new RuntimeException('Центр уведомлений prx597 не подключён');
        }

        $document = Service_Prx391_Documents::document((int) $draft['document_id'], self::_system_actor($actor));
        $to = $document['correspondent_email'] ?? NULL;
        if ( ! $to AND ! empty($document['message']['sender_email']))
        {
            $to = $document['message']['sender_email'];
        }
        if ( ! $to)
        {
            throw new RuntimeException('У корреспондента нет email — отправка невозможна');
        }

        $result = Service_Prx597_Notify::send_email(
            $to,
            'Ответ на '.$document['reg_no'].': '.$document['subject'],
            $draft['body']
        );

        if ( ! empty($result['error']))
        {
            throw new RuntimeException($result['error']);
        }

        self::_exec("UPDATE prx_394_drafts SET status = 'sent', sent_at = now(), updated_at = now() WHERE id = ".(int) $draft_id);

        Event::fire('prx394.draft.sent', [
            'draft_id' => (int) $draft_id,
            'document_id' => (int) $draft['document_id'],
            'reg_no' => $draft['reg_no'],
            'to' => $to,
        ]);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function draft($id)
    {
        $row = self::_row("
            SELECT dr.*, t.title AS template_title, t.code AS template_code
            FROM prx_394_drafts dr
            LEFT JOIN prx_394_templates t ON t.id = dr.template_id
            WHERE dr.id = ".(int) $id."
        ");
        if ( ! $row)
        {
            return NULL;
        }
        $row['citations'] = json_decode($row['citations'] ?: '[]', TRUE) ?: [];

        return $row;
    }

    public static function drafts_for_document($document_id)
    {
        return self::_all("
            SELECT dr.*, t.title AS template_title
            FROM prx_394_drafts dr
            LEFT JOIN prx_394_templates t ON t.id = dr.template_id
            WHERE dr.document_id = ".(int) $document_id."
            ORDER BY dr.created_at DESC, dr.id DESC
        ");
    }

    public static function drafts($limit = 100)
    {
        return self::_all("
            SELECT dr.*, t.title AS template_title
            FROM prx_394_drafts dr
            LEFT JOIN prx_394_templates t ON t.id = dr.template_id
            ORDER BY dr.updated_at DESC, dr.id DESC LIMIT ".(int) $limit."
        ");
    }

    public static function stats()
    {
        $row = self::_row("
            SELECT
                count(*) AS total,
                count(*) FILTER (WHERE status = 'draft') AS draft_count,
                count(*) FILTER (WHERE status = 'approved') AS approved,
                count(*) FILTER (WHERE status = 'sent') AS sent,
                count(*) FILTER (WHERE status = 'generating') AS generating
            FROM prx_394_drafts
        ");
        foreach ($row as $k => $v)
        {
            $row[$k] = (int) $v;
        }

        return $row;
    }

    protected static function _system_actor(array $actor = [])
    {
        return $actor ? $actor : ['user_id' => 0, 'roles' => ['system'], 'is_system' => TRUE];
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _q($value)
    {
        return self::_db()->quote((string) $value);
    }

    protected static function _int_or_null($value)
    {
        return ($value === NULL OR $value === '') ? 'NULL' : (string) (int) $value;
    }

    protected static function _row($sql)
    {
        $res = self::_db()->query(Database::SELECT, $sql, FALSE);
        $row = $res->current();

        return $row ?: NULL;
    }

    protected static function _all($sql)
    {
        $out = [];
        foreach (self::_db()->query(Database::SELECT, $sql, FALSE) as $row)
        {
            $out[] = $row;
        }

        return $out;
    }

    protected static function _exec($sql)
    {
        return self::_db()->query(Database::UPDATE, $sql, FALSE);
    }
}
