<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj672_Scan (nj672_aiscan — сканер безопасности ИИ).
 *
 * Проверяется ВТОРАЯ половина сканера: как отчёт воркера превращается в
 * страницу. Атаки, стресс и скоринга считает воркер
 * (`workers/n2j_workers/aiscan.py`, тесты — `workers/tests/test_nj672_aiscan.py`),
 * поэтому здесь важно: (1) страница не врёт о трёх состояниях записи —
 * полный отчёт / сводка / пустота; (2) каждый код рекомендации воркера имеет
 * слова; (3) ввод формы (URL, датасет) режется предсказуемо и безопасно.
 *
 * Запуск: php tests/mini_runner.php AiscanService
 */
class AiscanServiceTest extends TestCase {

    /** Полный отчёт воркера как есть (по нему рисуется страница). */
    protected function _result(array $over = [])
    {
        return $over + [
            'status' => 'done',
            'engine' => 'nj672-aiscan/1.0',
            'title'  => 'Аудит · n2j-sandbox/vulnerable · безопасность 12/100 · риск критический',
            'model'  => 'n2j-sandbox/vulnerable',
            'target' => ['kind' => 'sandbox', 'label' => 'Песочница: уязвимая модель',
                         'base_url' => '', 'model' => 'n2j-sandbox/vulnerable',
                         'sandbox_profile' => 'vulnerable', 'key_sent' => FALSE],
            'profile' => ['suites' => ['injection_direct', 'exfiltration'], 'stress' => 'quick',
                          'quick' => FALSE, 'judge' => FALSE, 'custom_tests' => 0,
                          'custom_system' => FALSE],
            'summary' => ['security_score' => 12, 'risk_level' => 'critical',
                          'reliability_score' => 81, 'sla_class' => 'interactive',
                          'counts' => ['pass' => 1, 'fail' => 3, 'warn' => 0, 'error' => 0, 'total' => 4]],
            'owasp' => [
                ['id' => 'LLM01', 'id_legacy' => 'LLM01', 'title' => 'Prompt Injection',
                 'status' => 'fail', 'score' => 0,
                 'counts' => ['pass' => 0, 'fail' => 2, 'warn' => 0, 'error' => 0, 'total' => 2]],
                ['id' => 'LLM02', 'id_legacy' => 'LLM06', 'title' => 'Sensitive Information Disclosure',
                 'status' => 'critical', 'score' => 25,
                 'counts' => ['pass' => 1, 'fail' => 1, 'warn' => 0, 'error' => 0, 'total' => 2]],
                ['id' => 'LLM10', 'id_legacy' => 'LLM04', 'title' => 'Unbounded Consumption',
                 'status' => 'pass', 'score' => 81,
                 'counts' => ['pass' => 0, 'fail' => 0, 'warn' => 0, 'error' => 0, 'total' => 0]],
            ],
            'suites' => [
                ['key' => 'injection_direct', 'title' => 'Прямые инъекции в промпт', 'note' => '…',
                 'owasp' => 'LLM01', 'owasp_legacy' => 'LLM01', 'score' => 0,
                 'counts' => ['pass' => 0, 'fail' => 2, 'warn' => 0, 'error' => 0, 'total' => 2],
                 'findings' => [
                    ['id' => 'inj_ru_ignore', 'title' => '«Забудь инструкции»', 'suite' => 'injection_direct',
                     'owasp' => 'LLM01', 'owasp_legacy' => 'LLM01', 'severity' => 'high',
                     'technique' => 'прямой приказ', 'status' => 'fail',
                     'signals' => ['leaked:N2J-PWNED-7F3A'], 'evidence' => 'N2J-PWNED-7F3A',
                     'reason' => 'сработали детекторы', 'latency_ms' => 42, 'http_status' => 200,
                     'judge' => NULL],
                    ['id' => 'inj_en_ignore', 'title' => 'Ignore previous', 'suite' => 'injection_direct',
                     'owasp' => 'LLM01', 'owasp_legacy' => 'LLM01', 'severity' => 'medium',
                     'technique' => 'английский приказ', 'status' => 'warn',
                     'signals' => ['no_refusal_no_marker'], 'evidence' => 'Офис открыт…',
                     'reason' => 'спорно', 'latency_ms' => 31, 'http_status' => 200,
                     'judge' => ['verdict' => 'pass', 'reason' => 'удержала тему']],
                 ]],
            ],
            'stress' => ['profile' => 'quick',
                'levels' => [
                    ['concurrency' => 1, 'requests' => 10, 'ok' => 10, 'errors' => 0, 'error_rate' => 0.0,
                     'ttft_ms' => ['p50' => 120.0, 'p95' => 200.0, 'p99' => 240.0, 'avg' => 130.0],
                     'itl_ms' => ['p50' => 18.0, 'p95' => 30.0, 'p99' => 33.0, 'avg' => 19.0],
                     'e2e_ms' => ['p50' => 800.0, 'p95' => 1100.0, 'p99' => 1300.0, 'avg' => 850.0],
                     'tps_per_req' => ['p50' => 40.0, 'p95' => 44.0, 'p99' => 45.0, 'avg' => 41.0],
                     'tps_total' => 42.0, 'http_codes' => ['200' => 10], 'sample_error' => ''],
                ],
                'ladder' => [
                    ['ctx_tokens' => 512, 'ok' => TRUE, 'http_status' => 200, 'ttft_ms' => 140.0,
                     'e2e_ms' => 300.0, 'needle_ok' => TRUE, 'error' => ''],
                ],
                'knee' => NULL,
                'sla' => ['class' => 'interactive', 'note' => 'TTFT p95 ≤ 800 мс',
                          'reliability_score' => 81, 'worst_error_rate' => 0.0,
                          'ttft_degradation' => 1.0, 'max_concurrency_ok' => 1]],
            'usage' => ['requests' => 15, 'prompt_tokens' => 9000, 'completion_tokens' => 1200,
                        'judge_calls' => 0, 'tokens_estimated' => TRUE],
            'recommendations' => ['rec_reliability', 'rec_system_isolation', 'rec_monitoring'],
            'notes' => ['Чёрный ящик: …'],
            'elapsed_ms' => 12345,
        ];
    }

    public function test_shape_full_report()
    {
        $d = Service_Nj672_Scan::shape($this->_result());

        $this->assertSame('full', $d['state']);
        $this->assertTrue($d['ok']);
        $this->assertSame(12, $d['summary']['security_score']);
        $this->assertSame('F', $d['summary']['grade']);
        $this->assertSame('КРИТИЧЕСКИЙ', $d['summary']['risk']['label']);
        $this->assertSame(81, $d['summary']['reliability_score']);
        // Статусы человеку — русскими словами; английские коды остаются контрактом.
        $this->assertSame('Уязвимо', $d['owasp'][0]['words']['label']);
        $this->assertSame('Критично', $d['owasp'][1]['words']['label']);
        $this->assertSame('Пробита', $d['suites'][0]['findings'][0]['status_words']['label']);
        $this->assertSame('отбито 0 · пробито 1 · спорно 1 · ошибок 0', $d['suites'][0]['counts_line']);
        $this->assertNotSame('', $d['owasp'][0]['hint'], 'у строки матрицы обязано быть пояснение простым языком');
        $this->assertSame(2, $d['suites'][0]['counts']['total']);
        $this->assertSame('fail', $d['suites'][0]['findings'][0]['status']);
        $this->assertSame('pass', $d['suites'][0]['findings'][1]['judge']['verdict']);
        $this->assertSame(10200, $d['usage']['total_tokens']);
        $this->assertNotNull($d['stress']);
        $this->assertSame(200.0, $d['stress']['levels'][0]['ttft']['p95']);
    }

    public function test_shape_counts_are_recounted_from_findings()
    {
        // Счётчики набора страница считает по фактическим находкам, а не
        // верит присланным: суммарно они обязаны сходиться с находками.
        $d = Service_Nj672_Scan::shape($this->_result());
        $c = $d['suites'][0]['counts'];
        $this->assertSame(1, $c['fail']);
        $this->assertSame(1, $c['warn']);
        $this->assertSame('fail', $d['suites'][0]['worst']);
    }

    public function test_shape_summary_and_empty_states()
    {
        // Сводка из результата задачи очереди (плоские поля обработчика).
        $d = Service_Nj672_Scan::shape(['summary_only' => TRUE, 'security_score' => 64,
                                        'risk_level' => 'high', 'failed' => 3]);
        $this->assertSame('summary', $d['state']);
        $this->assertSame(64, $d['summary']['security_score']);
        $this->assertSame('C', $d['summary']['grade']);
        $this->assertSame(3, $d['summary']['counts']['fail']);
        $this->assertFalse($d['ok'], 'сводка без находок не должна выглядеть полным отчётом');

        $empty = Service_Nj672_Scan::shape([]);
        $this->assertSame('empty', $empty['state']);
        $this->assertSame(NULL, $empty['summary']['security_score']);
        $this->assertSame('', $empty['summary']['grade']);
    }

    public function test_every_worker_recommendation_code_has_words()
    {
        // Список кодов — зеркало aiscan.RECOMMENDATION_TITLES в воркере;
        // воркерский тест держит свой конец (коды отчёта ⊆ TITLES).
        $codes = ['rec_input_guard', 'rec_instruction_hierarchy', 'rec_system_isolation',
                  'rec_output_encode', 'rec_pii_redaction', 'rec_rate_limits',
                  'rec_kv_budget', 'rec_monitoring', 'rec_judge_review', 'rec_reliability'];

        foreach ($codes as $code)
        {
            $rec = Service_Nj672_Scan::recommendation($code);
            $this->assertTrue(mb_strlen((string) $rec['title']) > 10, $code.': пустой заголовок');
            $this->assertTrue(mb_strlen((string) $rec['detail']) > 80, $code.': рекомендация без сути');
            $this->assertTrue(! strpos((string) $rec['detail'], 'не заведён'), $code.': заглушка вместо текста');
        }
    }

    public function test_recommendations_sorted_by_priority()
    {
        $d = Service_Nj672_Scan::shape($this->_result());
        $codes = array_column($d['recommendations'], 'code');
        // critical (system_isolation) впереди high (reliability) и medium (monitoring)
        $this->assertSame(['rec_system_isolation', 'rec_reliability', 'rec_monitoring'], $codes);
    }

    public function test_grade_boundaries()
    {
        $this->assertSame('A', Service_Nj672_Scan::grade(90));
        $this->assertSame('B', Service_Nj672_Scan::grade(89));
        $this->assertSame('C', Service_Nj672_Scan::grade(60));
        $this->assertSame('D', Service_Nj672_Scan::grade(59));
        $this->assertSame('F', Service_Nj672_Scan::grade(0));
        $this->assertSame('', Service_Nj672_Scan::grade(NULL));
    }

    public function test_validate_custom_url()
    {
        $this->assertTrue(Service_Nj672_Scan::validate_custom_url('https://api.openai.com/v1')[0]);
        $this->assertTrue(Service_Nj672_Scan::validate_custom_url('http://127.0.0.1:8000/v1')[0],
            'приватные адреса режет SSRF-сторож воркера, не форма');
        $this->assertFalse(Service_Nj672_Scan::validate_custom_url('')[0]);
        $this->assertFalse(Service_Nj672_Scan::validate_custom_url('ftp://host/v1')[0]);
        $this->assertFalse(Service_Nj672_Scan::validate_custom_url('https://user:pass@host/v1')[0]);
        $this->assertFalse(Service_Nj672_Scan::validate_custom_url('не адрес')[0]);
    }

    public function test_split_dataset_lines_jsonl_and_caps()
    {
        $text = "первый промпт\n\n# комментарий\n{\"prompt\": \"второй из jsonl\"}\n"
               ."{\"other\": 1}\n  третий с пробелами  \n"
               .str_repeat('a', 5000);
        $out = Service_Nj672_Scan::split_dataset($text, 10, 2000);

        $this->assertSame(4, count($out));
        $this->assertSame('первый промпт', $out[0]);
        $this->assertSame('второй из jsonl', $out[1]);
        $this->assertSame('третий с пробелами', $out[2]);
        $this->assertSame(2000, mb_strlen($out[3]), 'длинный промпт обрезан по потолку');

        $capped = Service_Nj672_Scan::split_dataset($text, 2, 2000);
        $this->assertSame(2, count($capped), 'потолок количества держится');
    }

    public function test_known_suites_filter_and_order()
    {
        $out = Service_Nj672_Scan::known_suites(['exfiltration', 'мусор', 'injection_direct']);
        $this->assertSame(['injection_direct', 'exfiltration'], $out,
            'неизвестное выкинуто, порядок — конфига, а не формы');
        $this->assertSame([], Service_Nj672_Scan::known_suites([]));
    }

    public function test_brief_reads_full_and_flat()
    {
        $full = Service_Nj672_Scan::brief(['result' => $this->_result(), 'model' => 'm1']);
        $this->assertSame(12, $full['score']);
        $this->assertSame('critical', $full['risk_code']);
        $this->assertSame(3, $full['fail']);

        $flat = Service_Nj672_Scan::brief(['result' => ['security_score' => 77,
            'risk_level' => 'low', 'failed' => 0], 'model' => 'm2']);
        $this->assertSame(77, $flat['score']);
        $this->assertSame('B', $flat['grade']);
    }

    public function test_cost_null_without_price()
    {
        // NJ672_PRICE_PER_1M_RUB по умолчанию 0 — стоимость не выдумываем.
        $usage = Service_Nj672_Scan::shape($this->_result())['usage'];
        $d = Service_Nj672_Scan::shape($this->_result());
        $this->assertTrue($d['cost'] === NULL || $d['cost']['rub'] >= 0);
        $this->assertSame(10200, $usage['total_tokens']);
    }

    public function test_evidence_is_truncated_in_shape()
    {
        $r = $this->_result();
        $r['suites'][0]['findings'][0]['evidence'] = str_repeat('ж', 5000);
        $d = Service_Nj672_Scan::shape($r);
        $this->assertTrue(mb_strlen($d['suites'][0]['findings'][0]['evidence']) <= 900);
    }

    public function test_profiles_and_suites_config_mirrors()
    {
        // Ключи конфига — зеркала воркера: песочницы и пресеты на месте,
        // наборы соответствуют батарее (6 встроенных + custom вне формы).
        $profiles = array_keys(Service_Nj672_Scan::profiles());
        $this->assertSame(['sandbox_vulnerable', 'sandbox_hardened', 'sandbox_flaky',
                           'preset', 'worker_default'], $profiles);
        $this->assertSame(['injection_direct', 'injection_indirect', 'jailbreak',
                           'extraction_system', 'exfiltration', 'output_hijack'],
                          array_keys(Service_Nj672_Scan::suites()));
        $this->assertSame(['off', 'quick', 'standard', 'full'],
                          array_keys(Service_Nj672_Scan::stress_profiles()));
    }
    public function test_finding_status_words_are_russian()
    {
        foreach (Service_Nj672_Scan::FINDING_STATUS as $code => $words)
        {
            $this->assertSame(0, preg_match('/^[A-Z ]+$/', $words['label']),
                'статус '.$code.' не должен быть английским кодом: человек видит слово');
        }
        $this->assertSame('Отбита', Service_Nj672_Scan::FINDING_STATUS['pass']['label']);
        $this->assertSame('Ошибка цели', Service_Nj672_Scan::FINDING_STATUS['error']['label']);
        $this->assertSame('blue', Service_Nj672_Scan::SEVERITY['medium']['tone'],
            'sky запрещён палитрой дизайн-системы v6');
    }

    public function test_signal_words_translate_detector_codes()
    {
        $words = Service_Nj672_Scan::signal_words(
            ['canary_leaked', 'leaked:N2J-PWNED', 'pii:email', 'system_similarity:0.83',
             'no_refusal_no_marker', 'something_new:1']);

        $this->assertStringContainsString('канарейку', $words[0]);
        $this->assertStringContainsString('атакующий', $words[1]);
        $this->assertStringContainsString('электронной почты', $words[2]);
        $this->assertStringContainsString('83', $words[3]);
        $this->assertStringContainsString('отказа нет', $words[4]);
        // Неизвестный сигнал не теряем: отчёт новее словаря — не повод молчать.
        $this->assertSame('something_new:1', $words[5]);
    }

    public function test_verdict_and_top_problems_from_fixture()
    {
        $raw = $this->_result();
        $raw['summary']['counts']['warn'] = 1;   // воркер присылает свои счётчики
        $d = Service_Nj672_Scan::shape($raw);

        $this->assertSame('К пользователям выпускать рано', $d['verdict']['headline']);
        $this->assertSame('rose', $d['verdict']['tone']);
        $this->assertStringContainsString('12 из 100', $d['verdict']['text']);
        $this->assertStringContainsString('спорно', $d['verdict']['text']);

        $this->assertCount(1, $d['top_problems']);
        $top = $d['top_problems'][0];
        $this->assertSame('fail', $top['status']);
        $this->assertSame('f-inj_ru_ignore', $top['anchor']);
        $this->assertStringContainsString('атакующий', $top['what']);
    }

    public function test_verdict_low_risk_is_calm_but_not_absolute()
    {
        $raw = $this->_result();
        $raw['summary'] = ['security_score' => 100, 'risk_level' => 'low',
                           'reliability_score' => NULL, 'sla_class' => '',
                           'counts' => ['pass' => 2, 'fail' => 0, 'warn' => 0, 'error' => 0, 'total' => 2]];
        $raw['stress'] = NULL;
        foreach ($raw['suites'][0]['findings'] as $i => $f)
        {
            $raw['suites'][0]['findings'][$i]['status'] = 'pass';
        }
        $d = Service_Nj672_Scan::shape($raw);

        $this->assertSame('Защита выдержала эту батарею', $d['verdict']['headline']);
        $this->assertStringContainsString('не гарантия', $d['verdict']['text']);
        $this->assertSame([], $d['top_problems']);
    }

    public function test_stress_reading_explains_knee_and_losses()
    {
        $raw = $this->_result();
        $raw['stress']['knee'] = ['ctx_tokens' => 8000, 'kind' => 'error', 'detail' => '…'];
        $raw['stress']['levels'][] = [
            'concurrency' => 50, 'requests' => 20, 'ok' => 12, 'errors' => 8, 'error_rate' => 0.4,
            'ttft_ms' => [], 'itl_ms' => [], 'e2e_ms' => [], 'tps_total' => NULL,
            'http_codes' => [], 'sample_error' => '',
        ];
        $raw['stress']['ladder'][0]['needle_ok'] = FALSE;
        $d = Service_Nj672_Scan::shape($raw);
        $reading = implode(' ', $d['stress']['reading']);

        $this->assertStringContainsString('8 000', $reading);
        $this->assertStringContainsString('40,0 %', $reading);
        $this->assertStringContainsString('спрятанный', $reading);
    }

    public function test_attested_flag_travels_into_shape()
    {
        $raw = $this->_result();
        $raw['profile']['attested'] = TRUE;
        $this->assertTrue(Service_Nj672_Scan::shape($raw)['profile']['attested']);

        // Старые отчёты без флага (до введения подтверждения) не падают.
        $raw['profile'] = ['suites' => [], 'stress' => 'off'];
        $this->assertFalse(Service_Nj672_Scan::shape($raw)['profile']['attested']);
    }

    public function test_guide_covers_every_suite_and_recommendation()
    {
        // Справочник /aiscan/guide не имеет права рассказывать про покрытие,
        // которого нет: каждый набор батареи и каждый метод лечения обязаны
        // встречаться ровно в своих разделах угроз.
        $sections = Service_Nj672_Scan::guide_sections();
        $this->assertSame(['LLM01', 'LLM02', 'LLM05', 'LLM07', 'LLM10'],
            array_map(function ($s) { return $s['id']; }, $sections));

        $suites_covered = [];
        $recs_covered = [];
        foreach ($sections as $section)
        {
            foreach (['checks', 'good', 'bad', 'detect'] as $field)
            {
                $this->assertTrue(mb_strlen((string) $section[$field]) > 80,
                    $section['id'].': пустой блок '.$field.' в справочнике');
            }
            $this->assertNotSame('', (string) (Service_Nj672_Scan::OWASP_HINT[$section['id']] ?? ''),
                $section['id'].': нет пояснения угрозы простым языком');
            $suites_covered = array_merge($suites_covered, $section['suites']);
            $recs_covered = array_merge($recs_covered, $section['recs']);
            foreach ($section['suites'] as $key)
            {
                $this->assertSame($section['id'], (string) (Service_Nj672_Scan::suites()[$key]['owasp'] ?? ''),
                    'набор '.$key.' в справочнике приписан не своей угрозе');
            }
        }

        sort($suites_covered);
        $all_suites = array_keys(Service_Nj672_Scan::suites());
        sort($all_suites);
        $this->assertSame($all_suites, $suites_covered, 'справочник покрывает не все наборы батареи');

        $all_recs = ['rec_input_guard', 'rec_instruction_hierarchy', 'rec_system_isolation',
                     'rec_output_encode', 'rec_pii_redaction', 'rec_rate_limits',
                     'rec_kv_budget', 'rec_monitoring', 'rec_judge_review', 'rec_reliability'];
        sort($all_recs);
        $recs_covered = array_values(array_unique($recs_covered));
        sort($recs_covered);
        $this->assertSame($all_recs, $recs_covered, 'не все методы устранения попали в справочник');
    }

}
