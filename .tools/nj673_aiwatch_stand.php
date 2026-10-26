<?php
/** NJ-673 AI Watch: сравнение двух полных аудитов, без БД/сети. */
if ( ! defined('SYSPATH')) { define('SYSPATH', '/app/'); }
require '/app/application/modules/nj673_aiwatch/classes/Service/Nj673/AiWatch.php';

$checks = 0;
function check_aiwatch($name, $got, $expected)
{
    global $checks;
    $checks++;
    if ($got !== $expected) {
        echo 'ФЕЙЛ: '.$name.'; got='.json_encode($got, JSON_UNESCAPED_UNICODE)
            .' expected='.json_encode($expected, JSON_UNESCAPED_UNICODE)."\n";
        return FALSE;
    }
    return TRUE;
}

function run_aiwatch($id, $model, $score, $fail, array $owasp, $url = 'https://llm.example.test/v1')
{
    return [
        'id' => $id, 'status' => 'done', 'model' => $model,
        'created_at' => '2026-10-23 10:00:00+00',
        'result' => [
            'target' => ['model' => $model, 'base_url' => $url],
            'summary' => ['security_score' => $score, 'counts' => ['fail' => $fail]],
            'profile' => ['suites' => ['standard'], 'stress' => 'off', 'quick' => FALSE, 'judge' => FALSE, 'custom_tests' => 0, 'custom_system' => FALSE],
            'owasp' => $owasp,
        ],
    ];
}

$latest = run_aiwatch(12, 'model-a', 61, 2, [
    ['id' => 'LLM01', 'status' => 'fail'],
    ['id' => 'LLM02', 'status' => 'pass'],
]);
$other_target = run_aiwatch(11, 'model-b', 100, 0, [
    ['id' => 'LLM01', 'status' => 'pass'],
]);
$previous = run_aiwatch(10, 'model-a', 84, 0, [
    ['id' => 'LLM01', 'status' => 'warn'],
    ['id' => 'LLM02', 'status' => 'pass'],
]);
$comparison = Service_Nj673_AiWatch::compare([$latest, $other_target, $previous]);

$ok = TRUE;
$ok = check_aiwatch('risk label adapts nj672 label/tone shape', Service_Nj673_AiWatch::risk_label(['label' => 'Высокий', 'tone' => 'amber']), 'Высокий') AND $ok;
$ok = check_aiwatch('risk label accepts legacy scalar', Service_Nj673_AiWatch::risk_label('Низкий'), 'Низкий') AND $ok;
$ok = check_aiwatch('risk label ignores non-displayable values', Service_Nj673_AiWatch::risk_label(['tone' => 'gray']), '') AND $ok;
$ok = check_aiwatch('same target/model pair selected', [$comparison['current_id'], $comparison['previous_id']], [12, 10]) AND $ok;
$ok = check_aiwatch('score delta retained without recalculation', $comparison['score_delta'], -23) AND $ok;
$ok = check_aiwatch('fail delta retained', $comparison['fail_delta'], 2) AND $ok;
$ok = check_aiwatch('new OWASP failure identified', $comparison['new_regressions'], ['LLM01']) AND $ok;
$ok = check_aiwatch('regression flag true', $comparison['regression'], TRUE) AND $ok;
$ok = check_aiwatch('different endpoint is not compared', Service_Nj673_AiWatch::compare([
    $latest, run_aiwatch(9, 'model-a', 90, 0, [], 'https://other.example.test/v1'),
]), NULL) AND $ok;
$ok = check_aiwatch('single report is not presented as a trend', Service_Nj673_AiWatch::compare([$latest]), NULL) AND $ok;
$different_profile = $previous;
$different_profile['result']['profile']['suites'] = ['different-suite'];
$ok = check_aiwatch('different test profiles are not compared', Service_Nj673_AiWatch::compare([$latest, $different_profile]), NULL) AND $ok;
$custom_profile = $previous;
$custom_profile['result']['profile']['custom_tests'] = 1;
$ok = check_aiwatch('custom prompt runs without fingerprint are excluded', Service_Nj673_AiWatch::compare([$latest, $custom_profile]), NULL) AND $ok;

if ( ! $ok) { exit(1); }
echo "AI Watch: $checks checks passed\n";
