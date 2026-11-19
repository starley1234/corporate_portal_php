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

            foreach (['integration_test', 'prx300'] as $module) {
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
}
