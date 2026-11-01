<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaCacheHeadersTest extends TestCase
{
    public function test_spa_shell_sends_no_cache_headers(): void
    {
        $spa = public_path('index.html');
        $created = false;

        if (! File::exists($spa)) {
            File::put($spa, '<!DOCTYPE html><html><body>spa</body></html>');
            $created = true;
        }

        try {
            $response = $this->get('/');

            $response->assertOk();
            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-cache', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('must-revalidate', $cacheControl);
            $response->assertHeader('Pragma', 'no-cache');
        } finally {
            if ($created) {
                File::delete($spa);
            }
        }
    }
}
