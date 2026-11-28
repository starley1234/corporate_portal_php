<?php
use PHPUnit\Framework\TestCase;

/**
 * prx218: история сканирований и признак «папка найдена» — на временном
 * каталоге модулей (реальный реестр не трогаем).
 */
class ModuleRegistryScanIntegrationTest extends TestCase
{
    public function test_scan_records_runs_and_marks_vanished_folders_without_deleting(): void
    {
        $config = Kohana::$config->load('prx218_module_manager');
        $saved  = $config->get('scanner');
        $root   = sys_get_temp_dir().'/prx218_scan_'.bin2hex(random_bytes(4));
        $names  = ['zz_scan_a_'.bin2hex(random_bytes(3)), 'zz_scan_b_'.bin2hex(random_bytes(3))];
        $runs   = [];

        foreach ($names as $n)
        {
            mkdir($root.'/'.$n, 0777, TRUE);
        }

        $config->set('scanner', ['path' => $root] + (array) $saved);

        try
        {
            $first  = Service_Prx218_Scanner::instance()->scan_directory(0);
            $runs[] = $first['run_id'];
            $this->assertSame(2, $first['found']);
            $this->assertSame(2, $first['created']);
            $this->assertSame(0, $first['missing']);

            rmdir($root.'/'.$names[1]);
            $second = Service_Prx218_Scanner::instance()->scan_directory(0);
            $runs[] = $second['run_id'];
            $this->assertSame(1, $second['found']);
            $this->assertSame(1, $second['missing']);

            $rows = [];
            foreach (Prx_Db::select('SELECT system_name, dir_found::int AS dir_found, last_seen_at, is_deleted
                    FROM prx_218_modules WHERE system_name IN (:a, :b)', [':a' => $names[0], ':b' => $names[1]]) as $r)
            {
                $rows[$r['system_name']] = $r;
            }

            $this->assertSame(1, (int) $rows[$names[0]]['dir_found']);
            $this->assertSame(0, (int) $rows[$names[1]]['dir_found'], 'папки нет — признак снят');
            $this->assertSame(0, (int) $rows[$names[1]]['is_deleted'], 'запись реестра не удалена');
            $this->assertNotEmpty($rows[$names[0]]['last_seen_at']);

            $history = [];
            foreach (Service_Prx218_Scanner::runs(50) as $r)
            {
                $history[(int) $r['id']] = $r;
            }
            $this->assertSame('ok', $history[$first['run_id']]['status']);
            $this->assertNotEmpty($history[$first['run_id']]['finished_at']);
            $this->assertSame(1, (int) $history[$second['run_id']]['missing']);

            $flags = Service_Prx218_Scanner::flags('prx120_jobs', FALSE);
            $this->assertFalse($flags['dir'], 'признаки независимы: папка — из реестра');
            $this->assertTrue($flags['loaded'], 'подключение — из Kohana::modules()');
        }
        finally
        {
            $config->set('scanner', $saved);
            Prx_Db::execute('DELETE FROM prx_218_llm_analysis WHERE module_id IN (SELECT id FROM prx_218_modules WHERE system_name IN (:a, :b))',
                [':a' => $names[0], ':b' => $names[1]]);
            Prx_Db::execute('DELETE FROM prx_218_modules WHERE system_name IN (:a, :b)', [':a' => $names[0], ':b' => $names[1]]);
            foreach ($runs as $id)
            {
                Prx_Db::execute('DELETE FROM prx_218_scan_runs WHERE id = :id', [':id' => (int) $id]);
            }
            @rmdir($root.'/'.$names[0]);
            @rmdir($root.'/'.$names[1]);
            @rmdir($root);
        }
    }
}
