<?php defined('SYSPATH') or die('No direct script access.');

/**
 * documents:auto — автоматизация документооборота для Crontab.
 *
 *   bin/console documents:auto
 *
 * Два шага, каждый включается своим флагом в .env (по умолчанию ВЫКЛ —
 * владелец решает сам, когда конвейер становится автоматическим):
 *
 *   PRX390_AUTO_SCAN=1   — сканирование папки входящих prx390: новые файлы
 *                          попадают в реестр без кнопки (scan_folder_automatically);
 *   PRX391_AUTO_AI=1     — разбор ИИ (prx392) запускается сам для документов
 *                          реестра без прогонов; ручная кнопка остаётся.
 *
 * Задача идемпотентна: скан переименовывает принятые файлы, прогон фиксирует
 * advisory-локом и input_hash — повторный вызов не плодит дублей.
 * Крон: раз в минуту, рядом с portal:maintain (см. AGENTS модуля).
 */
class Task_Documents_Auto extends Minion_Task {

    protected $_options = [
        'limit' => NULL, // документов на проход ИИ (по умолчанию 10)
    ];

    protected function _execute(array $params)
    {
        $report = ['scan' => NULL, 'ai' => NULL];

        // --- 1. Входящие prx390 → реестр --------------------------------
        if (getenv('PRX390_AUTO_SCAN') === '1')
        {
            try
            {
                $report['scan'] = Service_Prx390_Intake::scan_folder_automatically();
            }
            catch (Throwable $e)
            {
                $report['scan'] = ['error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        // --- 2. Разбор ИИ для документов без прогонов -------------------
        if (getenv('PRX391_AUTO_AI') === '1')
        {
            $limit = max(1, (int) ($params['limit'] ?: 10));
            $queued = 0;
            $skipped_text = 0;
            $errors = 0;
            $items = [];

            try
            {
                // Хозяин таблиц — prx391 (Д7): реестр читаем своей таблицей,
                // «у кого нет прогонов» — доверенным чтением prx392.
                $candidates = Prx_Db::select(
                    "SELECT d.id, d.source_message_id
                     FROM prx_391_documents d
                     WHERE d.source_message_id IS NOT NULL
                       AND d.status NOT IN ('closed', 'archived')
                     ORDER BY d.id
                     LIMIT 200");

                $message_by_id = [];
                foreach ($candidates as $row)
                {
                    $message_by_id[(int) $row['id']] = (int) $row['source_message_id'];
                }
                $targets = array_slice(
                    Service_Prx392_Runs::documents_without_runs(array_keys($message_by_id)),
                    0, $limit);

                foreach ($targets as $document_id)
                {
                    try
                    {
                        $run_id = Service_Prx392_Analysis::queue_automatically(
                            $document_id, $message_by_id[$document_id]);
                        $queued++;
                        $items[] = ['document_id' => (int) $document_id, 'run_id' => (int) $run_id];
                    }
                    catch (RuntimeException $e)
                    {
                        // «Нет распознанного текста» — doc.normalize ещё не дошёл:
                        // не ошибка, документ будет взят на следующем проходе.
                        $skipped_text++;
                    }
                    catch (Throwable $e)
                    {
                        $errors++;
                        $items[] = ['document_id' => (int) $row['id'], 'error' => mb_substr($e->getMessage(), 0, 200)];
                    }
                }

                $report['ai'] = ['queued' => $queued, 'waiting_text' => $skipped_text, 'errors' => $errors, 'items' => $items];
            }
            catch (Throwable $e)
            {
                $report['ai'] = ['error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        Minion_CLI::write(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
