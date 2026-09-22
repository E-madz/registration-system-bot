<?php

namespace Tests\Unit;

use App\Services\Messengers\BaleAdapter;
use App\Services\Messengers\TelegramAdapter;
use Tests\TestCase;

class TelegramAdapterProxyTest extends TestCase
{
    /**
     * A shell-exported http_proxy/https_proxy only covers whichever process
     * happened to inherit it (e.g. a dev tunnel script's own shell) - a
     * queue worker started in a separate terminal never sees it, so Telegram
     * calls from there silently try to connect directly and hang/fail. The
     * proxy must instead be read from config (backend/.env's
     * TELEGRAM_HTTP_PROXY) so every process behaves the same way regardless
     * of which shell started it.
     */
    public function test_the_telegram_adapter_reads_its_proxy_from_config(): void
    {
        config(['services.telegram.proxy' => 'http://127.0.0.1:12334']);

        $adapter = new TelegramAdapter('test-token');
        $method = new \ReflectionMethod($adapter, 'proxyUrl');
        $method->setAccessible(true);

        $this->assertSame('http://127.0.0.1:12334', $method->invoke($adapter));
    }

    public function test_bale_never_uses_a_proxy_since_it_is_directly_reachable(): void
    {
        config(['services.telegram.proxy' => 'http://127.0.0.1:12334']);

        $adapter = new BaleAdapter('test-token');
        $method = new \ReflectionMethod($adapter, 'proxyUrl');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($adapter));
    }
}
