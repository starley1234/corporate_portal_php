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
        $modules = Kohana::modules();
        if (isset($modules['prx392_analysis'])) $expected[] = 'doc.analyze';
        if (isset($modules['prx390_intake'])) $expected[] = 'doc.normalize';
        sort($expected);
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
        $loaded = array_intersect($optional, array_keys(Kohana::modules()));
        $enabled = $loaded !== [];
        $this->assertSame($enabled ? $optional : [], $loaded, 'Подключайте старый документооборот целым блоком');
        $this->assertSame(array_merge(['prx300_employee'], $enabled ? $optional : []), $active);
        $this->assertSame(array_merge($enabled ? [] : $optional, ['prx394_drafts']), $archived);
        $this->assertArrayNotHasKey('prx398_vlm_demo', Kohana::modules());
    }

    public function test_acl_is_deny_by_default(): void
    {
        $this->assertFalse(Prx_Acl::check([], 'portal.access'));
        $this->assertTrue(Prx_Acl::check(['login'], 'portal.access'));
        $this->assertFalse(Prx_Acl::check(['login'], 'prx595.users.admin'));
        $this->assertTrue(Prx_Acl::check(['login'], 'prx300.view'));
        $this->assertTrue(Prx_Acl::check(['login'], 'prx300.directory'));
        $this->assertFalse(Prx_Acl::check(['login'], 'prx300.admin.view'));
        $this->assertFalse(Prx_Acl::check(['login'], 'prx300.manage'));
        $this->assertTrue(Prx_Acl::check(['hr_viewer'], 'prx300.admin.view'));
        $this->assertFalse(Prx_Acl::check(['hr_viewer'], 'prx300.import'));
        $this->assertTrue(Prx_Acl::check(['hr_admin'], 'prx300.identity'));
        $this->assertTrue(Prx_Acl::check(['admin'], 'prx300.view'));
    }

    public function test_worker_gate_requires_nonempty_matching_secret(): void
    {
        $before = getenv('WORKER_API_TOKEN');
        $header = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? NULL;
        $request = Request::factory('test');
        try
        {
            putenv('WORKER_API_TOKEN=');
            $request->headers('X-Worker-Token', '');
            $this->assertFalse(Prx_Gate::worker_request($request));
            putenv('WORKER_API_TOKEN=portal-test-token');
            $request->headers('X-Worker-Token', 'wrong');
            $this->assertFalse(Prx_Gate::worker_request($request));
            $request->headers('X-Worker-Token', 'portal-test-token');
            $this->assertTrue(Prx_Gate::worker_request($request));
            $request->headers('X-Worker-Token', '');
            $request->query('token', 'portal-test-token');
            $this->assertFalse(Prx_Gate::worker_request($request));
            $this->assertTrue(Prx_Gate::worker_request($request, TRUE));
            $request->headers('X-Worker-Token', 'wrong');
            $this->assertFalse(Prx_Gate::worker_request($request, TRUE));
            $request->headers('X-Worker-Token', ' portal-test-token ');
            $this->assertTrue(Prx_Gate::worker_request($request, TRUE));
            $this->assertFalse(Prx_Gate::worker_request($request, FALSE, NULL, FALSE));
            $request->headers('X-Worker-Token', 'site-secret');
            $this->assertTrue(Prx_Gate::worker_request($request, FALSE, 'site-secret', FALSE));
            $this->assertFalse(Prx_Gate::worker_request($request, FALSE, 'other', FALSE));
            $request->headers('X-Worker-Token', ' ');
            $this->assertTrue(Prx_Gate::worker_request($request, TRUE));
            $this->assertFalse(Prx_Gate::worker_request($request, TRUE, NULL, TRUE, TRUE));
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
