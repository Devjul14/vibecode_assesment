<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(public_path('hot'));

        parent::tearDown();
    }

    public function test_vite_hot_file_is_reported_as_failure(): void
    {
        file_put_contents(public_path('hot'), 'http://localhost:5173');

        $this->artisan('servops:doctor')
            ->expectsOutputToContain('Hapus file public/hot')
            ->assertFailed();
    }

    public function test_missing_demo_users_are_reported(): void
    {
        config(['servops.demo_accounts' => true]);

        $this->artisan('servops:doctor')
            ->expectsOutputToContain('Tabel users kosong')
            ->assertFailed();
    }
}
