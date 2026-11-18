<?php
use PHPUnit\Framework\TestCase;

class StoragePathsProbe extends Service_Prx212_Storage
{
    public function root_for_test(array $config) { return $this->_get_local_root($config); }
    public function path_for_test($root, $path) { return $this->_local_file_path($root, $path); }
    public function save_for_test($backend, $source, $path) { $this->_physical_save($backend, $source, $path, []); }
    public function delete_for_test($backend, $path) { $this->_physical_delete($backend, $path); }
}

class StorageDoctorProbe extends Task_Dev_Doctor
{
    public function __construct() { parent::__construct(); }
    public function check_for_test() { $this->_report = []; $this->_check_dirs(); return $this->_report; }
}

class StoragePathsBackend
{
    public $driver = 'local';
    private $path;
    public function __construct($path) { $this->path = $path; }
    public function get_config_array() { return ['root_path' => $this->path]; }
}

/** Переход из docroot, создание приватных каталогов и безопасные пути файлов. */
class StoragePathsTest extends TestCase
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

    public function test_legacy_alias_is_resolved_and_parent_traversal_is_rejected(): void
    {
        $tmp = $this->temp_dir();
        $root = rtrim(dirname(APPPATH), '/');
        try {
            if (@symlink($root, $tmp.'/project')) {
                $this->assertSame($root.'/storage/prx212/files',
                    Storage_Path::relocate_legacy($tmp.'/project/public_html/storage/prx212/files'));
            }
            $rejected = FALSE;
            try { Storage_Path::relocate_legacy('public_html/storage/../../public_html/exposed'); }
            catch (InvalidArgumentException $e) { $rejected = TRUE; }
            $this->assertTrue($rejected);
        } finally { $this->remove_tree($tmp); }
    }

    public function test_custom_docroot_paths_and_symlink_aliases_are_rejected(): void
    {
        $tmp = $this->temp_dir();
        $root = rtrim(dirname(APPPATH), '/');
        try {
            $paths = ['public_html/uploads/custom', $root.'/public_html/img/custom'];
            if (@symlink($root.'/public_html', $tmp.'/web')) {
                $paths[] = $tmp.'/web/not-yet-created/files';
            }
            foreach ($paths as $path) {
                $rejected = FALSE;
                try { Storage_Path::absolute($path); }
                catch (InvalidArgumentException $e) {
                    $rejected = TRUE;
                    $this->assertStringContainsString('public_html', $e->getMessage());
                }
                $this->assertTrue($rejected, $path);
            }
            // Произвольный приватный backend не переключается автоматически.
            $this->assertSame($tmp.'/custom/files', Storage_Path::absolute($tmp.'/custom/files'));
        } finally { $this->remove_tree($tmp); }
    }

    public function test_lazy_paths_reject_symlinks_inside_project_storage(): void
    {
        $storage = rtrim(dirname(APPPATH), '/').'/storage';
        if (is_link($storage)) { $this->markTestSkipped('Корень storage — symlink'); }
        $created_storage = FALSE;
        if ( ! is_dir($storage)) {
            if ( ! @mkdir($storage, 0700)) { $this->markTestSkipped('Нет прав на создание storage'); }
            $created_storage = TRUE;
        }
        $test = $storage.'/.path-test-'.bin2hex(random_bytes(8));
        $outside = $this->temp_dir();
        mkdir($test, 0700);
        try {
            if ( ! @symlink($outside, $test.'/link')) {
                $this->markTestSkipped('В этой среде нельзя создавать symlink');
            }
            foreach ([$test.'/link/new/files', 'storage/'.basename($test).'/link/new/files'] as $path) {
                $rejected = FALSE;
                try { Storage_Path::absolute($path); }
                catch (InvalidArgumentException $e) {
                    $rejected = TRUE;
                    $this->assertStringContainsString('Symlink', $e->getMessage());
                }
                $this->assertTrue($rejected, $path);
            }
            $rejected = FALSE;
            try { (new StoragePathsProbe())->root_for_test(['root_path' => $test.'/link/new/files']); }
            catch (InvalidArgumentException $e) { $rejected = TRUE; }
            $this->assertTrue($rejected);
            $this->assertFalse(is_dir($outside.'/new'));
        } finally {
            $this->remove_tree($test);
            $this->remove_tree($outside);
            if ($created_storage) { @rmdir($storage); }
        }
    }

    public function test_doctor_checks_storage_without_creating_it(): void
    {
        $root = rtrim(dirname(APPPATH), '/');
        $paths = [$root.'/storage', $root.'/storage/prx212_file_storage/files'];
        $before = array_map('is_dir', $paths);
        $report = (new StorageDoctorProbe())->check_for_test();
        $after = array_map('is_dir', $paths);
        $this->assertSame($before, $after);
        $messages = array_column($report, 1);
        $this->assertStringNotContainsString('storage/docflow/', implode("\n", $messages));
        $this->assertStringContainsString('storage/prx212_file_storage/files', implode("\n", $messages));
    }

    public function test_prx212_creates_missing_root_and_reports_failure(): void
    {
        $tmp = $this->temp_dir();
        $mask = umask(0);
        $probe = new StoragePathsProbe();
        try {
            $path = $tmp.'/prx212/files';
            $this->assertSame($path, $probe->root_for_test(['root_path' => $path]));
            $this->assertSame($path, $probe->root_for_test(['root_path' => $path]));
            clearstatcache(TRUE, $path);
            $this->assertSame(0700, fileperms($path) & 0777);
            $default = Kohana::$config->load('prx212_storage')->get('local_storage_paths');
            $this->assertSame(rtrim(dirname(APPPATH), '/').'/storage/prx212_file_storage/files', $default['default']);

            file_put_contents($tmp.'/block', 'not a directory');
            $failure = '';
            try { $probe->root_for_test(['root_path' => $tmp.'/block/files']); }
            catch (RuntimeException $e) { $failure = $e->getMessage(); }
            $this->assertStringContainsString('Не удалось создать папку хранилища prx212', $failure);
            $this->assertStringContainsString($tmp.'/block/files', $failure);
        } finally {
            umask($mask);
            $this->remove_tree($tmp);
        }
    }

    public function test_prx212_custom_relative_backend_keeps_its_working_directory_semantics(): void
    {
        $tmp = $this->temp_dir();
        $previous = getcwd();
        try {
            chdir($tmp);
            $resolved = (new StoragePathsProbe())->root_for_test(['root_path' => 'custom/files']);
            $this->assertSame($tmp.'/custom/files', $resolved);
            $this->assertTrue(is_dir($tmp.'/custom/files'));
        } finally {
            if ($previous !== FALSE) { chdir($previous); }
            $this->remove_tree($tmp);
        }
    }

    public function test_prx212_relative_storage_from_fpm_docroot_maps_to_project_storage(): void
    {
        $root = rtrim(dirname(APPPATH), '/');
        $storage = $root.'/storage';
        if (is_link($storage) OR ( ! is_dir($storage) AND ! is_writable($root))) {
            $this->markTestSkipped('Штатный storage недоступен для теста');
        }
        $existed = is_dir($storage);
        $name = '.cwd-test-'.bin2hex(random_bytes(8));
        $previous = getcwd();
        try {
            chdir($root.'/public_html');
            $resolved = (new StoragePathsProbe())->root_for_test(['root_path' => 'storage/'.$name.'/files']);
            $this->assertSame($storage.'/'.$name.'/files', $resolved);
            $this->assertFalse(is_dir($root.'/public_html/storage/'.$name));
        } finally {
            if ($previous !== FALSE) { chdir($previous); }
            $this->remove_tree($storage.'/'.$name);
            if ( ! $existed) { @rmdir($storage); }
        }
    }

    public function test_prx212_separates_modules_and_rejects_paths_outside_backend(): void
    {
        $tmp = $this->temp_dir();
        $mask = umask(0);
        $probe = new StoragePathsProbe();
        try {
            $source = $tmp.'/source.txt';
            file_put_contents($source, 'private file');
            $backend = new StoragePathsBackend($tmp.'/backend/files');
            $probe->save_for_test($backend, $source, 'prx390/000/1.txt');
            $probe->save_for_test($backend, $source, 'prx300/000/2.txt');
            foreach (['prx390/000/1.txt', 'prx300/000/2.txt'] as $relative) {
                $path = $tmp.'/backend/files/'.$relative;
                clearstatcache(TRUE, $path);
                $this->assertSame('private file', file_get_contents($path));
                $this->assertSame(0600, fileperms($path) & 0777);
            }
            $rejected = FALSE;
            try { $probe->save_for_test($backend, $source, '../public_html/exposed.txt'); }
            catch (RuntimeException $e) { $rejected = TRUE; }
            $this->assertTrue($rejected);
            $rejected = FALSE;
            try { $probe->save($source, ['module' => '../../public_html']); }
            catch (InvalidArgumentException $e) { $rejected = TRUE; }
            $this->assertTrue($rejected);
        } finally {
            umask($mask);
            $this->remove_tree($tmp);
        }
    }

    public function test_prx212_never_overwrites_an_existing_file(): void
    {
        $tmp = $this->temp_dir();
        $probe = new StoragePathsProbe();
        try {
            $source = $tmp.'/source.txt';
            file_put_contents($source, 'new data');
            $root = $tmp.'/backend/files';
            mkdir($root.'/prx390/000', 0700, TRUE);
            $existing = $root.'/prx390/000/keep.txt';
            file_put_contents($existing, 'original data');
            $backend = new StoragePathsBackend($root);
            $rejected = FALSE;
            try { $probe->save_for_test($backend, $source, 'prx390/000/keep.txt'); }
            catch (RuntimeException $e) { $rejected = TRUE; }
            $this->assertTrue($rejected);
            $this->assertSame('original data', file_get_contents($existing));
            $this->assertSame('new data', file_get_contents($source));
        } finally { $this->remove_tree($tmp); }
    }

    public function test_prx212_rejects_symlinks_for_save_read_and_delete(): void
    {
        $tmp = $this->temp_dir();
        $probe = new StoragePathsProbe();
        try {
            $root = $tmp.'/backend/files';
            $outside = $tmp.'/outside';
            mkdir($root, 0700, TRUE);
            mkdir($outside, 0700);
            $source = $tmp.'/source.txt';
            file_put_contents($source, 'new data');
            file_put_contents($outside.'/keep.txt', 'outside data');
            if ( ! @symlink($outside, $root.'/prx390')) {
                $this->markTestSkipped('В этой среде нельзя создавать symlink');
            }
            $backend = new StoragePathsBackend($root);
            foreach (['save', 'read', 'delete'] as $operation) {
                $rejected = FALSE;
                try {
                    if ($operation === 'save') $probe->save_for_test($backend, $source, 'prx390/keep.txt');
                    if ($operation === 'read') $probe->path_for_test($root, 'prx390/keep.txt');
                    if ($operation === 'delete') $probe->delete_for_test($backend, 'prx390/keep.txt');
                } catch (RuntimeException $e) {
                    $rejected = TRUE;
                    $this->assertStringContainsString('Symlink', $e->getMessage());
                }
                $this->assertTrue($rejected, $operation);
            }
            $this->assertSame('outside data', file_get_contents($outside.'/keep.txt'));

            mkdir($root.'/prx300', 0700);
            symlink($outside.'/keep.txt', $root.'/prx300/file.txt');
            $rejected = FALSE;
            try { $probe->path_for_test($root, 'prx300/file.txt'); }
            catch (RuntimeException $e) { $rejected = TRUE; }
            $this->assertTrue($rejected);
        } finally { $this->remove_tree($tmp); }
    }
}
