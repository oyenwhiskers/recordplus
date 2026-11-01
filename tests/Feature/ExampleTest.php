<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_application_root_responds(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}
