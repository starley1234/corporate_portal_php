<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-673 «AI Watch» — сравнение последовательных аудитов nj672.
 *
 * Скоринг остаётся у nj672/воркера. Здесь только группируем готовые
 * результаты одного и того же эндпоинта и показываем изменения между
 * последними завершёнными прогонами; новый вердикт не вычисляем.
 */
class Service_Nj673_AiWatch
{
    /**
     * Сводка для наблюдаемого эндпоинта по истории nj672.
     *
     * Сравниваются только завершённые полные отчёты одной цели; записи без
     * адреса/модели, очередные сводки и ошибки не подмешиваются в baseline.
     *
     * @return array|null
     */
    public static function compare(array $runs)
    {
        $completed = [];

        foreach ($runs as $run)
        {
            $result = (array) ($run['result'] ?? []);
            $target = (array) ($result['target'] ?? []);
            $profile = (array) ($result['profile'] ?? []);
            $model  = trim((string) ($target['model'] ?? $run['model'] ?? ''));
            $url    = trim((string) ($target['base_url'] ?? ''));

            if ((string) ($run['status'] ?? '') !== 'done'
                OR $model === '' OR $url === ''
                OR ! is_array($result['owasp'] ?? NULL)
                OR ! is_array($result['summary'] ?? NULL)
                OR ! is_array($result['profile'] ?? NULL)
                // Индивидуальные тесты/системный промпт не имеют в отчёте
                // fingerprint: сравнивать их с другим пользовательским вводом
                // могло бы ложно приписать изменение модели регрессии.
                OR (int) ($profile['custom_tests'] ?? 0) > 0
                OR ! empty($profile['custom_system']))
            {
                continue;
            }

            $suites = array_values(array_unique(array_map('strval', (array) ($profile['suites'] ?? []))));
            sort($suites, SORT_STRING);
            $profile_key = hash('sha256', json_encode([
                $suites, (string) ($profile['stress'] ?? 'off'),
                ! empty($profile['quick']), ! empty($profile['judge']),
            ]));

            $completed[] = [
                'run'    => $run,
                'result' => $result,
                'target' => $target,
                'model'  => $model,
                'url'    => $url,
                'profile_key' => $profile_key,
            ];
        }

        if (count($completed) < 2)
        {
            return NULL;
        }

        // history() возвращает id DESC; берём два последних полных прогона
        // для той же пары endpoint+model, а не два соседних любых аудита.
        $current = $completed[0];
        $previous = NULL;

        foreach (array_slice($completed, 1) as $candidate)
        {
            if ($candidate['url'] === $current['url']
                AND $candidate['model'] === $current['model']
                AND $candidate['profile_key'] === $current['profile_key'])
            {
                $previous = $candidate;
                break;
            }
        }

        if ($previous === NULL)
        {
            return NULL;
        }

        $now_summary  = (array) $current['result']['summary'];
        $then_summary = (array) $previous['result']['summary'];
        $now_score    = self::_number($now_summary['security_score'] ?? NULL);
        $then_score   = self::_number($then_summary['security_score'] ?? NULL);
        $now_counts  = (array) ($now_summary['counts'] ?? []);
        $then_counts = (array) ($then_summary['counts'] ?? []);
        $now_fail    = (int) ($now_counts['fail'] ?? 0);
        $then_fail   = (int) ($then_counts['fail'] ?? 0);

        $now_owasp  = self::_owasp_status((array) $current['result']['owasp']);
        $then_owasp = self::_owasp_status((array) $previous['result']['owasp']);
        $regressed  = [];

        foreach ($now_owasp as $code => $status)
        {
            if (in_array($status, ['fail', 'critical'], TRUE)
                AND ! in_array($then_owasp[$code] ?? '', ['fail', 'critical'], TRUE))
            {
                $regressed[] = $code;
            }
        }

        return [
            'model'             => $current['model'],
            'endpoint'          => $current['url'],
            'current_id'        => (int) $current['run']['id'],
            'previous_id'       => (int) $previous['run']['id'],
            'current_created'   => (string) ($current['run']['created_at'] ?? ''),
            'previous_created'  => (string) ($previous['run']['created_at'] ?? ''),
            'current_score'     => $now_score,
            'previous_score'    => $then_score,
            'score_delta'       => ($now_score !== NULL AND $then_score !== NULL)
                ? $now_score - $then_score : NULL,
            'current_fail'      => $now_fail,
            'previous_fail'     => $then_fail,
            'fail_delta'        => $now_fail - $then_fail,
            'new_regressions'   => $regressed,
            'regression'        => $regressed !== [] OR ($now_score !== NULL AND $then_score !== NULL
                AND $now_score < $then_score) OR $now_fail > $then_fail,
        ];
    }

    /** nj672::brief() отдаёт risk как ['label' => ..., 'tone' => ...]. */
    public static function risk_label($risk)
    {
        if (is_array($risk))
        {
            $risk = $risk['label'] ?? '';
        }

        return is_scalar($risk) ? trim((string) $risk) : '';
    }

    /** Привести обе версии матрицы OWASP к code => status. */
    protected static function _owasp_status(array $rows)
    {
        $out = [];

        foreach ($rows as $key => $row)
        {
            if (is_array($row))
            {
                $code   = (string) ($row['id'] ?? $row['code'] ?? $key);
                $status = (string) ($row['status'] ?? '');
            }
            else
            {
                $code   = (string) $key;
                $status = (string) $row;
            }

            if ($code !== '' AND $status !== '')
            {
                $out[$code] = $status;
            }
        }

        return $out;
    }

    protected static function _number($value)
    {
        return is_numeric($value) ? (int) $value : NULL;
    }
}
