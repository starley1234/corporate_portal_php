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
            throw new Prx399_Error('not_found', 404);
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
            throw new Prx399_Error('not_found', 404);
        }
        if ($file['status'] === 'parsed')
        {
            return ['ok' => TRUE, 'duplicate' => TRUE];
        }
        if ($file['status'] === 'failed')
        {
            // Задача файла уже признана погибшей (самолечение увидело
            // failed/cancelled в очереди), человек решает: «повторить» или
            // «запустить пайплайн». Опоздавший воркер не воскрешает файл
            // в обход этого решения; ответ ok — повторять доставку незачем.
            return ['ok' => TRUE, 'stale' => TRUE];
        }

        $pages = (int) ($body['pages'] ?? 0);
        $sha = (string) ($body['text_sha256'] ?? '');
        $merge = new Service_Prx399_Merge;

        if ($file['kind'] === 'base')
        {
            if (empty($body['ast']['nodes']) OR ! is_array($body['ast']['nodes']))
            {
                throw new Prx399_Error('invalid_ast', 400);
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
                throw new Prx399_Error('invalid_ops', 400);
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

            // Текст ДС — для side-by-side ревью на карточке (у сканов без
            // текстового слоя сайту его не достать); 1 МБ — страховка сверх
            // лимита воркера.
            $ds_text = isset($body['text']) && is_string($body['text']) ? mb_substr($body['text'], 0, 1048576) : NULL;

            Prx_Db::transaction(function () use ($file, $pages, $sha, $rows, $ds_text)
            {
                Model_Prx399_Delta::delete_for_file($file['id']);
                foreach ($rows as $row)
                {
                    Model_Prx399_Delta::insert($row);
                }
                Model_Prx399_File::set_ds_result($file['id'], $pages, $sha, $ds_text);
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
            throw new Prx399_Error('not_found', 404);
        }
        if (in_array($contract['status'], ['audited', 'failed'], TRUE))
        {
            return ['done' => TRUE, 'status' => $contract['status'], 'contract_id' => (int) $contract_id];
        }
        if (empty($contract['doc_tree']))
        {
            throw new Prx399_Error('not_built', 409);
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
            throw new Prx399_Error('not_found', 404);
        }
        if ($contract['status'] === 'audited')
        {
            return ['ok' => TRUE, 'duplicate' => TRUE];
        }

        $summary = is_string($body['summary'] ?? NULL) ? trim($body['summary']) : '';
        if ($summary === '' OR ! is_array($body['risks'] ?? []))
        {
            throw new Prx399_Error('invalid_risks', 400);
        }
        $risks = [];
        foreach (($body['risks'] ?? []) as $risk)
        {
            if ( ! is_array($risk)) continue;
            $severity = strtolower((string) ($risk['severity'] ?? ''));
            if ( ! in_array($severity, ['high', 'medium', 'low'], TRUE))
            {
                throw new Prx399_Error('invalid_risks', 400);
            }
            $title = trim((string) ($risk['title'] ?? ''));
            if ($title === '')
            {
                throw new Prx399_Error('invalid_risks', 400);
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

        // Под локом пакета и только для идущего аудита. Раньше llm-риски
        // заменялись при ЛЮБОМ статусе, а set_audited просто не срабатывал:
        // результат опоздавшего аудита (задачу уже сочли погибшей, пакет
        // пересобран) переписывал карту рисков новой редакции рисками старой.
        $stale = Prx_Db::transaction(function () use ($contract_id, $risks, $summary)
        {
            Model_Prx399_Contract::lock($contract_id);
            $current = Model_Prx399_Contract::get($contract_id);
            if ( ! $current OR (string) $current['status'] !== 'auditing')
            {
                return TRUE;
            }
            Model_Prx399_Risk::delete_llm($contract_id);
            foreach ($risks as $row)
            {
                Model_Prx399_Risk::insert($row);
            }
            Model_Prx399_Contract::set_audited($contract_id, $summary);

            return FALSE;
        });
        if ($stale)
        {
            return ['ok' => TRUE, 'stale' => TRUE];
        }

        Event::publish('prx399.contract.audited', [
            'contract_id' => $contract_id,
            'risks' => count($risks),
            'high_risks' => count(array_filter($risks, static fn ($r) => $r['severity'] === 'high')),
        ], NULL);

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
            Model_Prx399_Contract::lock($contract_id);

            $contract = Model_Prx399_Contract::get($contract_id);
            if ( ! $contract OR ! in_array($contract['status'], ['parsing'], TRUE))
            {
                return;
            }

            $this->_assemble($contract_id, $contract);
        });

        $contract = Model_Prx399_Contract::get($contract_id);

        return ['built' => $contract !== NULL AND $contract['status'] !== 'parsing', 'status' => $contract !== NULL ? (string) $contract['status'] : ''];
    }

    /**
     * Дозапуск сборки, когда все файлы уже разобраны, а сборка не случилась
     * (потерянный результат последнего файла). Идемпотентен: advisory-лок
     * (399, contract_id) и статус-стражи внутри _maybe_build.
     */
    public function ensure_built($contract_id)
    {
        return $this->_maybe_build((int) $contract_id);
    }

    /**
     * Пересборка по явному требованию человека: после правки состава дельт
     * (исключение/возврат) или повторно после сбоя. Разрешена, когда
     * предыдущий цикл завершён (audited/failed): пересобираем, перезаписываем
     * статические риски и ставим риск-аудит заново.
     */
    public function rebuild($contract_id)
    {
        $contract_id = (int) $contract_id;

        Prx_Db::transaction(function () use ($contract_id)
        {
            Model_Prx399_Contract::lock($contract_id);

            $contract = Model_Prx399_Contract::get($contract_id);
            if ( ! $contract)
            {
                throw new Prx399_Error('not_found', 404);
            }
            if ( ! in_array($contract['status'], ['audited', 'failed'], TRUE))
            {
                throw new Prx399_Error('wrong_state', 409);
            }

            $this->_assemble($contract_id, $contract);
        });

        return ['ok' => TRUE];
    }

    /**
     * Сборка + статические риски + постановка аудита. Вызывается ТОЛЬКО
     * внутри транзакции с advisory-локом (399, contract_id). FALSE — собрать
     * нельзя (есть pending/failed файлы); TRUE — сборка выполнена.
     */
    private function _assemble($contract_id, $contract)
    {
        $files = Model_Prx399_File::for_contract($contract_id);
        $pending = array_filter($files, static fn ($f) => in_array($f['status'], ['queued', 'working'], TRUE));
        if ($pending)
        {
            return FALSE;
        }
        $failed = array_filter($files, static fn ($f) => $f['status'] === 'failed');
        if ($failed)
        {
            $names = implode(', ', array_map(static fn ($f) => $f['original_name'], $failed));
            Model_Prx399_Contract::set_status($contract_id, 'failed', 'Не разобраны файлы: '.$names);

            return FALSE;
        }

        // Состав сборки решает человек (ADR-003): excluded=no не попадает.
        $deltas = array_filter(
            Model_Prx399_Delta::for_contract($contract_id),
            static fn ($d) => (string) ($d['included'] ?? 'yes') === 'yes');

        [$nodes, $broken] = (new Service_Prx399_Merge)->build($contract, $files, $deltas);
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

        // Метрики качества — в шину событий: лента «Использование модулей»
        // (/prx100/usage) показывает тренды по пакетам без отдельной статистики.
        Event::publish('prx399.contract.built', [
            'contract_id' => $contract_id,
            'nodes' => count($nodes),
            'deltas' => count($deltas),
            'broken_links' => count($broken),
        ], NULL);

        return TRUE;
    }
}
