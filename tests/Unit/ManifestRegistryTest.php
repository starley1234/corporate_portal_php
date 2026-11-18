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
        $expected = ['document.extract', 'echo', 'embeddings.index', 'llm.summarize', 'semantic.index'];
        if (PRX_DOCFLOW_ENABLED) array_unshift($expected, 'doc.analyze', 'doc.normalize');
        $this->assertSame($expected, $keys);
        $this->assertSame('prx395_document_extract', $channels['document.extract']['module']);
        $this->assertSame('prx115_semantic', $channels['semantic.index']['module']);
        $this->assertSame('prx120_jobs', $channels['echo']['module']);
    }

    public function test_technical_rag_is_not_a_global_search_source(): void
    {
        foreach (Manifest::search() as $source)
        {
            $this->assertNotSame('prx560_knowledge', $source['module']);
        }
    }

    public function test_service_plan_matches_registration_status(): void
    {
        $services = [];
        $active = [];
        $archived = [];
        foreach ((array) Kohana::$config->load('portal')->get('domains') as $domain)
        {
            foreach ($domain['services'] as $service)
            {
                $services[] = $service['module'];
                $this->assertContains($service['status'], ['planned', 'active', 'archived']);
                if ($service['status'] === 'active')
                {
                    $active[] = $service['module'];
                    $this->assertArrayHasKey($service['module'], Kohana::modules());
                }
                else
                {
                    $this->assertArrayNotHasKey($service['module'], Kohana::modules());
                    if ($service['status'] === 'archived') { $archived[] = $service['module']; }
                }
            }
        }
        $this->assertCount(55, array_unique($services));
        $optional = ['prx390_intake', 'prx391_documents', 'prx392_analysis', 'prx393_workflow'];
        $enabled = PRX_DOCFLOW_ENABLED;
        $this->assertSame(array_merge(['prx300_employee'], $enabled ? $optional : []), $active);
        $this->assertSame(array_merge($enabled ? [] : $optional, ['prx394_drafts']), $archived);
        $this->assertArrayNotHasKey('prx398_vlm_demo', Kohana::modules());
    }

    public function test_acl_is_deny_by_default(): void
    {
        $this->assertFalse(ACL::check([], 'portal.access'));
        $this->assertTrue(ACL::check(['login'], 'portal.access'));
        $this->assertFalse(ACL::check(['login'], 'prx595.users.admin'));
        $this->assertTrue(ACL::check(['login'], 'prx300.view'));
        $this->assertTrue(ACL::check(['login'], 'prx300.directory'));
        $this->assertFalse(ACL::check(['login'], 'prx300.admin.view'));
        $this->assertFalse(ACL::check(['login'], 'prx300.manage'));
        $this->assertTrue(ACL::check(['hr_viewer'], 'prx300.admin.view'));
        $this->assertFalse(ACL::check(['hr_viewer'], 'prx300.import'));
        $this->assertTrue(ACL::check(['hr_admin'], 'prx300.identity'));
        $this->assertTrue(ACL::check(['admin'], 'prx300.view'));
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
