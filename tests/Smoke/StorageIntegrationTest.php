<?php
use PHPUnit\Framework\TestCase;

/** Реальная БД + локальный backend: файлы разных модулей и права на скачивание. */
class StorageIntegrationTest extends TestCase
{
    private function remove_tree(string $dir): void
    {
        if ( ! is_dir($dir)) { return; }
        foreach (new DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) { continue; }
            $path = $entry->getPathname();
            if ($entry->isDir() && ! $entry->isLink()) { $this->remove_tree($path); }
            else { unlink($path); }
        }
        rmdir($dir);
    }

    public function test_local_backend_creates_root_and_saves_files_for_multiple_modules(): void
    {
        $tmp = sys_get_temp_dir().'/prx212-integration-'.bin2hex(random_bytes(8));
        mkdir($tmp, 0700);
        // Инициализируем общую шину до тестовой транзакции.
        Event::has('prx212_storage.uploaded');
        $db = Database::instance();
        $db->begin();
        try {
            $source = $tmp.'/source.txt';
            file_put_contents($source, 'тестовый файл');
            $root = $tmp.'/private/files';
            $name = 'storage-test-'.bin2hex(random_bytes(6));
            $rows = DB::query(Database::SELECT,
                'INSERT INTO prx_212_storage_backends (name, driver, config_data, is_active) '
                .'VALUES (:name, :driver, :config, 1) RETURNING id')
                ->parameters([':name' => $name, ':driver' => 'local',
                    ':config' => json_encode(['root_path' => $root], JSON_UNESCAPED_SLASHES)])
                ->execute()->as_array();
            $backend_id = (int) $rows[0]['id'];
            $service = Service_Prx212_Storage::instance();

            foreach (['prx390', 'prx300'] as $module) {
                $file_id = $service->save([
                    'tmp_name' => $source,
                    'name' => 'test.txt',
                    'size' => filesize($source),
                    'type' => 'text/plain',
                ], ['backend_id' => $backend_id, 'module' => $module, 'is_public' => 0]);

                $path = $service->getFilePath($file_id);
                $this->assertStringStartsWith($root.'/'.$module.'/', $path);
                $this->assertSame('тестовый файл', file_get_contents($path));
                clearstatcache(TRUE, $path);
                $this->assertSame(0600, fileperms($path) & 0777);
                $this->assertFalse($service->getFileForDownload($file_id, NULL));
                $metadata = $service->get_extended_metadata($file_id);
                $this->assertSame($module, $metadata['module']);
            }
        } finally {
            $db->rollback();
            $this->remove_tree($tmp);
        }
    }

    public function test_docflow_scans_new_private_directories_and_stores_pdf_in_prx212(): void
    {
        $tmp = sys_get_temp_dir().'/prx390-integration-'.bin2hex(random_bytes(8));
        mkdir($tmp, 0700);
        $config_property = new ReflectionProperty(Service_Prx390_Intake::class, '_config');
        $config_property->setAccessible(TRUE);
        $original_config = $config_property->getValue();
        Event::has('prx212_storage.uploaded');
        $old_log_enabled = getenv('PRX_LOG_ENABLED');
        putenv('PRX_LOG_ENABLED=1');
        $writer = new Log_Plain($tmp.'/diagnostics.log', 0, 0, Log::INFO);
        Kohana::$log->attach($writer);
        $db = Database::instance();
        $db->begin();
        try {
            // Default backend находится только в тестовой транзакции.
            DB::update('prx_212_storage_backends')->set(['is_active' => 0])->execute();
            $root = $tmp.'/storage/prx212_file_storage/files';
            DB::query(Database::INSERT,
                'INSERT INTO prx_212_storage_backends (name, driver, config_data, is_active) '
                .'VALUES (:name, :driver, :config, 1)')
                ->parameters([':name' => 'docflow-test-'.bin2hex(random_bytes(6)), ':driver' => 'local',
                    ':config' => json_encode(['root_path' => $root], JSON_UNESCAPED_SLASHES)])
                ->execute();

            $config = include APPPATH.'modules/prx390_intake/config/prx390_intake.php';
            foreach (['inbox', 'processed', 'duplicates', 'error'] as $name) {
                $config[$name.'_dir'] = $tmp.'/storage/docflow/'.$name;
            }
            $config_property->setValue(NULL, $config);
            Service_Prx390_Intake::ensure_dirs();
            $inbox = $config['inbox_dir'].'/unique.pdf';
            $pdf = file_get_contents(APPPATH.'modules/prx390_intake/demo/01_claim_romashka.pdf')
                ."\n% storage-integration-".bin2hex(random_bytes(8))."\n";
            file_put_contents($inbox, $pdf);

            $report = Service_Prx390_Intake::scan_folder();
            $this->assertSame(1, $report['found']);
            $this->assertSame(1, $report['accepted']);
            $this->assertSame(0, $report['errors']);
            $item = $report['items'][0];
            $this->assertSame('accepted', $item['state']);
            $this->assertSame($pdf, file_get_contents($config['processed_dir'].'/unique.pdf'));
            $path = Service_Prx212_Storage::instance()->getFilePath($item['file_id']);
            $this->assertStringStartsWith($root.'/prx390/', $path);
            $this->assertSame($pdf, file_get_contents($path));
            $job = DB::query(Database::SELECT,
                "SELECT id FROM prx_120_jobs WHERE channel = 'doc.normalize' AND payload->>'message_id' = :message_id")
                ->param(':message_id', (string) $item['message_id'])->execute()->as_array();
            $this->assertCount(1, $job);

            file_put_contents($inbox, $pdf);
            $duplicates = Service_Prx390_Intake::scan_folder();
            $this->assertSame(1, $duplicates['duplicates']);
            $this->assertSame($pdf, file_get_contents($config['duplicates_dir'].'/unique.pdf'));

            file_put_contents($config['inbox_dir'].'/invalid.pdf', 'not a PDF');
            $errors = Service_Prx390_Intake::scan_folder();
            $this->assertSame(1, $errors['errors']);
            $this->assertSame('not a PDF', file_get_contents($config['error_dir'].'/invalid.pdf'));

            $private_text = str_repeat('private-document-content-42 ', 4);
            $saved = Service_Prx390_Intake::store_text($item['message_id'], [
                'file_id' => $item['file_id'], 'text' => $private_text, 'engine' => 'pypdf', 'pages' => 1,
            ]);
            $this->assertSame('text_ready', $saved['status']);
            $message = Service_Prx390_Intake::message($item['message_id']);
            $document_id = (int) $message['document_id'];
            $this->assertGreaterThan(0, $document_id);
            $deadline = Service_Prx393_Workflow::deadline_for($document_id);
            $this->assertNotNull($deadline);
            $this->assertSame('open', $deadline['status']);
            $document = Service_Prx391_Documents::document($document_id, ['is_system' => TRUE]);
            $this->assertSame($deadline['due_at'], $document['deadline_at']);
            $lines = preg_grep('/prx390_diag/', explode("\n", file_get_contents($tmp.'/diagnostics.log')));
            $diagnostics = implode("\n", $lines);
            $this->assertStringContainsString('stage=enqueue result=ok message_id='.$item['message_id'].' job_id='.(int) $job[0]['id'], $diagnostics);
            $this->assertStringContainsString('stage=intake result=accepted message_id='.$item['message_id'].' file_id='.$item['file_id'], $diagnostics);
            $this->assertStringContainsString('stage=intake result=duplicate message_id='.$item['message_id'], $diagnostics);
            $this->assertStringContainsString('stage=intake result=scan_error error_type=InvalidArgumentException reason=invalid_mime', $diagnostics);
            $this->assertStringContainsString('stage=text_saved result=ok message_id='.$item['message_id'], $diagnostics);
            $this->assertStringNotContainsString('private-document-content-42', $diagnostics);
            $this->assertStringNotContainsString('unique.pdf', $diagnostics);
            $this->assertStringNotContainsString('invalid.pdf', $diagnostics);
            $this->assertStringNotContainsString('not a PDF', $diagnostics);
        } finally {
            $db->rollback();
            $config_property->setValue(NULL, $original_config);
            Kohana::$log->detach($writer);
            $old_log_enabled === FALSE ? putenv('PRX_LOG_ENABLED') : putenv('PRX_LOG_ENABLED='.$old_log_enabled);
            $this->remove_tree($tmp);
        }
    }
}
