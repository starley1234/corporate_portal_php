<?php
use PHPUnit\Framework\TestCase;

class ManifestRegistryTest extends TestCase {
    public function test_all_manifests_belong_to_registered_modules(): void
    {
        $registered = Kohana::modules();
        foreach (Manifest::all() as $name => $manifest)
        {
            $this->assertArrayHasKey($name, $registered);
            $this->assertSame($name, $manifest['module_name']);
        }
    }

    public function test_platform_channels_and_owners(): void
    {
        $channels = Manifest::channels();
        $keys = array_keys($channels);
        sort($keys);
        $this->assertSame(['echo', 'embeddings.index', 'llm.summarize', 'semantic.index'], $keys);
        $this->assertSame('prl115_semantic', $channels['semantic.index']['module']);
        $this->assertSame('prl120_jobs', $channels['echo']['module']);
    }

    public function test_technical_rag_is_not_a_global_search_source(): void
    {
        foreach (Manifest::search() as $source)
        {
            $this->assertNotSame('prl560_knowledge', $source['module']);
        }
    }

    public function test_fifty_services_are_planned_not_registered(): void
    {
        $services = [];
        foreach ((array) Kohana::$config->load('portal')->get('domains') as $domain)
        {
            foreach ($domain['services'] as $service)
            {
                $services[] = $service['module'];
                $this->assertSame('planned', $service['status']);
                $this->assertArrayNotHasKey($service['module'], Kohana::modules());
            }
        }
        $this->assertCount(50, array_unique($services));
    }

    public function test_acl_is_deny_by_default(): void
    {
        $this->assertFalse(ACL::check([], 'portal.access'));
        $this->assertTrue(ACL::check(['login'], 'portal.access'));
        $this->assertFalse(ACL::check(['login'], 'prl595.users.admin'));
        $this->assertFalse(ACL::check(['login'], 'prl300.view'));
        $this->assertTrue(ACL::check(['admin'], 'prl300.view'));
    }

    public function test_worker_gate_requires_nonempty_matching_secret(): void
    {
        $before = getenv('WORKER_API_TOKEN');
        $header = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? NULL;
        try
        {
            putenv('WORKER_API_TOKEN=');
            $_SERVER['HTTP_X_WORKER_TOKEN'] = '';
            $this->assertFalse(Gate::worker_request());
            putenv('WORKER_API_TOKEN=portal-test-token');
            $_SERVER['HTTP_X_WORKER_TOKEN'] = 'wrong';
            $this->assertFalse(Gate::worker_request());
            $_SERVER['HTTP_X_WORKER_TOKEN'] = 'portal-test-token';
            $this->assertTrue(Gate::worker_request());
        }
        finally
        {
            $before === FALSE ? putenv('WORKER_API_TOKEN') : putenv('WORKER_API_TOKEN='.$before);
            if ($header === NULL) { unset($_SERVER['HTTP_X_WORKER_TOKEN']); }
            else { $_SERVER['HTTP_X_WORKER_TOKEN'] = $header; }
        }
    }

    public function test_auth_return_redirect_is_local(): void
    {
        $this->assertSame('', Controller_Auth::return_redirect('https://example.test'));
        $this->assertSame('', Controller_Auth::return_redirect('//example.test'));
        $this->assertSame('/employee', Controller_Auth::return_redirect('/employee'));
    }
}
