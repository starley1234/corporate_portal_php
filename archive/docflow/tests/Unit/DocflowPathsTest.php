<?php
use PHPUnit\Framework\TestCase;

class DocflowPathsTest extends TestCase
{
    private function temp_dir(): string
    {
        $path = sys_get_temp_dir().'/prx-storage-test-'.bin2hex(random_bytes(8));
        if ( ! mkdir($path, 0700)) { throw new RuntimeException('Не создать тестовый каталог'); }
        return $path;
    }

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

    public function test_docflow_defaults_and_legacy_environment_paths(): void
    {
        $keys = ['PRX390_INBOX_DIR', 'PRX390_PROCESSED_DIR', 'PRX390_DUPLICATES_DIR', 'PRX390_ERROR_DIR'];
        $original = [];
        foreach ($keys as $key) { $original[$key] = getenv($key); putenv($key); }
        $root = rtrim(dirname(APPPATH), '/');
        $config_file = APPPATH.'modules/prx390_intake/config/prx390_intake.php';
        try {
            $config = include $config_file;
            $this->assertSame('storage/docflow/inbox', $config['inbox_dir']);
            $this->assertSame('storage/docflow/processed', $config['processed_dir']);
            $this->assertSame('storage/docflow/duplicates', $config['duplicates_dir']);
            $this->assertSame('storage/docflow/error', $config['error_dir']);

            putenv('PRX390_INBOX_DIR=public_html/storage/docflow/inbox');
            putenv('PRX390_PROCESSED_DIR='.$root.'/public_html/storage/docflow/processed/');
            putenv('PRX390_DUPLICATES_DIR=./public_html/storage/custom/duplicates');
            putenv('PRX390_ERROR_DIR=/srv/other-private-storage/error');
            $config = include $config_file;
            $this->assertSame($root.'/storage/docflow/inbox', $config['inbox_dir']);
            $this->assertSame($root.'/storage/docflow/processed/', $config['processed_dir']);
            $this->assertSame($root.'/storage/custom/duplicates', $config['duplicates_dir']);
            $this->assertSame('/srv/other-private-storage/error', $config['error_dir']);
            $this->assertSame($root.'/storage/docflow/inbox', Storage_Path::absolute('storage/docflow/inbox'));
            $this->assertSame('/srv/public_html/storage/file', Storage_Path::relocate_legacy('/srv/public_html/storage/file'));
        } finally {
            foreach ($original as $key => $value) {
                $value === FALSE ? putenv($key) : putenv($key.'='.$value);
            }
        }
    }

    public function test_docflow_creates_all_directories_privately_and_idempotently(): void
    {
        $tmp = $this->temp_dir();
        $prop = new ReflectionProperty(Service_Prx390_Intake::class, '_config');
        $prop->setAccessible(TRUE);
        $old = $prop->getValue();
        $mask = umask(0);
        try {
            $config = [];
            foreach (['inbox', 'processed', 'duplicates', 'error'] as $name) {
                $config[$name.'_dir'] = $tmp.'/docflow/'.$name;
            }
            $prop->setValue(NULL, $config);
            Service_Prx390_Intake::ensure_dirs();
            file_put_contents($tmp.'/docflow/inbox/keep.txt', 'keep');
            Service_Prx390_Intake::ensure_dirs();
            foreach ($config as $path) {
                clearstatcache(TRUE, $path);
                $this->assertTrue(is_dir($path));
                $this->assertSame(0700, fileperms($path) & 0777);
            }
            $this->assertSame('keep', file_get_contents($tmp.'/docflow/inbox/keep.txt'));
        } finally {
            umask($mask);
            $prop->setValue(NULL, $old);
            $this->remove_tree($tmp);
        }
    }
}
