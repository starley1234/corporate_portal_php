<?php
use PHPUnit\Framework\TestCase;

/** Real multipart/cURL against a fake gateway; never calls external services. */
class OcrGatewayIntegrationTest extends TestCase
{
    private static $server;
    private static string $base;
    public static function setUpBeforeClass(): void
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0');
        $address=stream_socket_get_name($socket,FALSE);
        fclose($socket);
        self::$base='http://'.$address;
        self::$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/../Support/ocr_gateway.php'],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        for ($i=0;$i<100;$i++) {
            $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
            if ($probe) {fclose($probe); return;}
            usleep(20000);
        }
        throw new RuntimeException('Fake OCR gateway did not start');
    }
    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {proc_terminate(self::$server); proc_close(self::$server);}
    }
    private function post(string $path, int $max=4096): array
    {
        $file=tempnam(sys_get_temp_dir(),'ocr-test-');
        file_put_contents($file,'%PDF-1.7 test');
        $config=Kohana::$config->load('prx450_document_ocr')->as_array();
        $upstream=array_replace($config['upstream'],['base_url'=>self::$base,'parse_path'=>$path,'api_key'=>'','timeout'=>3]);
        try {
            return (new ReflectionMethod(Service_Prx450_Ocr::class,'_post'))->invoke(NULL,$upstream,
                ['tmp_name'=>$file,'type'=>'application/pdf','name'=>'document.pdf'],'engine=auto',$max);
        } finally {unlink($file);}
    }
    public function test_real_multipart_and_public_normalization(): void
    {
        [$status,$body]=$this->post('/parse');
        $this->assertSame(200,$status);
        $config=Kohana::$config->load('prx450_document_ocr')->as_array();
        $result=(new ReflectionMethod(Service_Prx450_Ocr::class,'_result'))->invoke(NULL,$status,$body,$config,'full');
        $this->assertSame('document.pdf',$result['file']);
        $this->assertSame('auto',$result['engine_used']);
        $this->assertSame('application/pdf',$result['raw_result']['mime']);
        $this->assertSame('%PDF-1.7 test',$result['raw_result']['body']);
    }
    public function test_transport_aborts_oversized_response(): void
    {
        $this->expectException(Prx450_Error::class);
        $this->expectExceptionMessage('result_too_large');
        $this->post('/large',1024);
    }
    public function test_redirect_is_not_followed(): void
    {
        [$status]=$this->post('/redirect');
        $this->assertSame(302,$status);
    }
}
