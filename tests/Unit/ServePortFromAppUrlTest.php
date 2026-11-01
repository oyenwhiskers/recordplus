<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServePortFromAppUrlTest extends TestCase
{
    #[Test]
    public function it_derives_server_port_from_app_url_when_unset(): void
    {
        $this->assertSame(
            '8017',
            AppServiceProvider::serverPortFromAppUrl(null, 'http://127.0.0.1:8017'),
        );
    }

    #[Test]
    public function it_keeps_an_explicit_server_port(): void
    {
        $this->assertNull(
            AppServiceProvider::serverPortFromAppUrl('9000', 'http://127.0.0.1:8017'),
        );
    }

    #[Test]
    public function it_skips_app_urls_without_a_port(): void
    {
        $this->assertNull(
            AppServiceProvider::serverPortFromAppUrl(null, 'http://localhost'),
        );
    }
}
