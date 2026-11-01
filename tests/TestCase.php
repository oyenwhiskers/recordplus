<?php

namespace Tests;

use App\Models\Category;
use App\Models\Profile;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    protected function categoryFor(Profile $profile, string $name = 'Expenses'): Category
    {
        return Category::query()->firstOrCreate([
            'profile_id' => $profile->id,
            'name' => $name,
        ]);
    }
}
