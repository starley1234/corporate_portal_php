<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ввод/вывод воркера: задание на дельту, результат дельты, задание и
 * результат риск-аудита. Вызывается ТОЛЬКО из Controller_Prx399_Api
 * (X-Worker-Token); браузер сюда не попадает. Всё идемпотентно: повторная
 * доставка результата того же файла просто перезаписывает его данные.
 */
class Service_Prx399_Pipeline {

    const ERROR = 'Prx399_Error';

    private $_config;

    public function __construct()
    {
        $this->_config = Kohana::$config->load('prx399_docmerge');
    }

    // ------------------------------------------------- doc.merge.delta

    /**
     * Задание на один файл. done=TRUE — файл уже разобран (повтор после
     * сбоя доставки), воркер не должен работать второй раз.
     */
    public function delta_input($file_id)
    {
        $file = Model_Prx399_File::get($file_id);
        if ( ! $file)
        {
            throw new self::ERROR('not_found', 404);
        }
        if (in_array($file['status'], ['parsed', 'failed'], TRUE))
        {
            return ['done' => TRUE, 'status' => $file['status'], 'file_id' => (int) $file_id];
        }

        $contract = Model_Prx399_Contract::get($file['contract_id']);

        // Isolated analysis: в промпт дельты уходит ТОЛЬКО карточка сделки
        // (200–300 токенов), а не весь договор — модели не нужен весь текст.
        $card = isset($contract['deal_card']) && is_string($contract['deal_card'])
            ? json_decode($contract['deal_card'], TRUE) : NULL;

        return [
            'done' => FALSE,
            'file_id' => (int) $file_id,
            'contract_id' => (int) $file['contract_id'],
            'kind' => (string) $file['kind'],
            'ordinal' => (int) $file['ordinal'],
            'original_name' => (string) $file['original_name'],
            'deal_card' => is_array($card) ? $card : NULL,
            'model' => (string) $this->_config['model'],
            'temperature' => (float) $this->_config['temperature'],
            'max_tokens' => (int) $this->_config['max_tokens'],
        ];
    }

    /**
     * Результат дельты. base → AST + карточка сделки; ds → инструкции.
     * После последнего разобранного файла — детерминированная сборка и
     * постановка риск-аудита в очередь (advisory-лок против гонки параллельных
     * результатов двух ДС: соберёт тот, кто закрыл последний файл).
     */
    public function delta_result($file_id, array $body)
    {
        $file = Model_Prx399_File::get($file_id);
        if ( ! $file)
        {
            throw new self::ERROR('not_found', 404);
        }
        if ($file['status'] === 'parsed')
        {
            return ['ok' => TRUE, 'duplicate' => TRUE];
        }

        $pages = (int) ($body['pages'] ?? 0);
        $sha = (string) ($body['text_sha256'] ?? '');
        $merge = new Service_Prx399_Merge;

        if ($file['kind'] === 'base')
        {
            if (empty($body['ast']['nodes']) OR ! is_array($body['ast']['nodes']))
            {
                throw new self::ERROR('invalid_ast', 400);
            }
            $card = isset($body['deal_card']) && is_array($body['deal_card']) ? $body['deal_card'] : [];
            Prx_Db::transaction(function () use ($file, $pages, $sha, $body, $card)
            {
                Model_Prx399_File::set_base_result($file['id'], $pages, $sha, $body['ast']);
                if ($card !== [])
                {
                    Model_Prx399_Contract::set_deal_card($file['contract_id'], $card);
                }
            });
        }
        else
        {
            $ops = $body['ops'] ?? NULL;
            if ( ! is_array($ops))
            {
                throw new self::ERROR('invalid_ops', 400);
            }
            $rows = [];
            foreach (array_values($ops) as $seq => $op)
            {
                $clean = $merge->validate_op(is_array($op) ? $op : []);
                $clean['contract_id'] = $file['contract_id'];
                $clean['file_id'] = $file['id'];
                $clean['seq'] = $seq;
                $rows[] = $clean;
            }

            Prx_Db::transaction(function () use ($file, $pages, $sha, $rows)
            {
                Model_Prx399_Delta::delete_for_file($file['id']);
                foreach ($rows as $row)
                {
                    Model_Prx399_Delta::insert($row);
                }
                Model_Prx399_File::set_ds_result($file['id'], $pages, $sha);
            });
        }

        return ['ok' => TRUE] + $this->_maybe_build((int) $file['contract_id']);
    }

    // ------------------------------------------------- doc.merge.audit

    public function audit_input($contract_id)
    {
        $contract = Model_Prx399_Contract::get($contract_id);
        if ( ! $contract)
        {
            throw new self::ERROR('not_found', 404);
        }
        if (in_array($contract['status'], ['audited', 'failed'], TRUE))
        {
            return ['done' => TRUE, 'status' => $contract['status'], 'contract_id' => (int) $contract_id];
        }
        if (empty($contract['doc_tree']))
        {
            throw new self::ERROR('not_built', 409);
        }

        $tree = json_decode((string) $contract['doc_tree'], TRUE);
        $card = isset($contract['deal_card']) ? json_decode((string) $contract['deal_card'], TRUE) : NULL;
        $policy = isset($contract['policy']) ? json_decode((string) $contract['policy'], TRUE) : [];

        return [
            'done' => FALSE,
            'contract_id' => (int) $contract_id,
            'title' => (string) $contract['title'],
            'focus' => (string) $contract['focus'],
            'policy' => (string) ($policy['policy'] ?? ''),
            'deal_card' => is_array($card) ? $card : NULL,
            'nodes' => $tree['nodes'] ?? [],
            'model' => (string) $this->_config['model'],
            'temperature' => (float) $this->_config['temperature'],
            'max_tokens' => (int) $this->_config['max_tokens'],
        ];
    }

    public function audit_result($contract_id, array $body)
    {
        $contract = Model_Prx399_Contract::get($contract_id);
        if ( ! $contract)
        {
            throw new self::ERROR('not_found', 404);
        }
        if ($contract['status'] === 'audited')
        {
            return ['ok' => TRUE, 'duplicate' => TRUE];
        }

        $summary = trim((string) ($body['summary'] ?? ''));
        if ($summary === '')
        {
            throw new self::ERROR('invalid_risks', 400);
        }
        $risks = [];
        foreach (($body['risks'] ?? []) as $risk)
        {
            if ( ! is_array($risk)) continue;
            $severity = strtolower((string) ($risk['severity'] ?? ''));
            if ( ! in_array($severity, ['high', 'medium', 'low'], TRUE))
            {
                throw new self::ERROR('invalid_risks', 400);
            }
            $title = trim((string) ($risk['title'] ?? ''));
            if ($title === '')
            {
                throw new self::ERROR('invalid_risks', 400);
            }
            $risks[] = [
                'contract_id' => $contract_id,
                'severity' => $severity,
                'area' => isset($risk['area']) ? mb_substr(trim((string) $risk['area']), 0, 48) : NULL,
                'chunk_path' => isset($risk['chunk_path']) ? mb_substr((string) $risk['chunk_path'], 0, 64) : NULL,
                'title' => mb_substr($title, 0, 300),
                'detail' => isset($risk['detail']) ? mb_substr((string) $risk['detail'], 0, 4000) : NULL,
                'recommendation' => isset($risk['recommendation']) ? mb_substr((string) $risk['recommendation'], 0, 2000) : NULL,
                'source' => 'llm',
            ];
        }

        Prx_Db::transaction(function () use ($contract_id, $risks, $summary)
        {
            Model_Prx399_Risk::delete_llm($contract_id);
            foreach ($risks as $row)
            {
                Model_Prx399_Risk::insert($row);
            }
            Model_Prx399_Contract::set_audited($contract_id, $summary);
        });

        return ['ok' => TRUE, 'risks' => count($risks)];
    }

    // -------------------------------------------------------- internal

    /**
     * Все файлы разобраны? → сборка (детерминированная) → очередь аудита.
     * Один сбойный файл переводит контракт в failed с перечнем — «частично
     * собранный договор» опаснее честной ошибки.
     */
    private function _maybe_build($contract_id)
    {
        Prx_Db::transaction(function () use ($contract_id)
        {
            Prx_Db::exec('SELECT pg_advisory_xact_lock(399, :id)', [':id' => $contract_id]);

            $contract = Model_Prx399_Contract::get($contract_id);
            if ( ! $contract OR ! in_array($contract['status'], ['parsing'], TRUE))
            {
                return ['built' => FALSE];
            }

            $files = Model_Prx399_File::for_contract($contract_id);
            $pending = array_filter($files, static fn ($f) => in_array($f['status'], ['queued', 'working'], TRUE));
            $failed = array_filter($files, static fn ($f) => $f['status'] === 'failed');
            if ($pending)
            {
                return ['built' => FALSE];
            }
            if ($failed)
            {
                $names = implode(', ', array_map(static fn ($f) => $f['original_name'], $failed));
                Model_Prx399_Contract::set_status($contract_id, 'failed', 'Не разобраны файлы: '.$names);

                return ['built' => FALSE, 'failed' => TRUE];
            }

            [$nodes, $broken] = (new Service_Prx399_Merge)->build(
                $contract, $files, Model_Prx399_Delta::for_contract($contract_id));
            Model_Prx399_Contract::set_built($contract_id, ['nodes' => $nodes], $broken);

            // Статические риски (битые ссылки) пишутся сразу — их видит человек
            // до LLM-аудита; llm-риски допишутся после doc.merge.audit.
            Model_Prx399_Risk::delete_for_contract($contract_id);
            foreach ($broken as $link)
            {
                Model_Prx399_Risk::insert([
                    'contract_id' => $contract_id,
                    'severity' => 'high',
                    'area' => 'Коллизии',
                    'chunk_path' => $link['where'],
                    'title' => 'Недействительная ссылка: «'.$link['excerpt'].'»',
                    'detail' => 'Ссылка указывает на '.$link['ref'].' — пункт не существует или исключён допсоглашением.',
                    'recommendation' => 'Проверить редакцию пункта и исправить ссылку на актуальный номер.',
                    'source' => 'static',
                ]);
            }

            $job_id = Service_Prx120_JobQueue::push('doc.merge.audit', [
                'contract_id' => $contract_id,
            ], [
                'module' => 'prx399',
                'priority' => 45,
                'max_attempts' => 2,
                'timeout_sec' => 3600,
                'created_by' => 0,
            ]);
            Model_Prx399_Contract::set_audit_job($contract_id, $job_id);
            Model_Prx399_Contract::set_status($contract_id, 'auditing', NULL);

            return ['built' => TRUE];
        });

        $contract = Model_Prx399_Contract::get($contract_id);

        return ['built' => $contract['status'] !== 'parsing', 'status' => (string) $contract['status']];
    }
}
