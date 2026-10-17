<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        $this->withoutVite();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/requests/SR-102')->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('Masuk ke ServOps')->assertSee('sari@servops.test');
    }

    public function test_demo_accounts_fill_the_form_client_side_and_can_be_hidden(): void
    {
        $this->get('/login')
            ->assertSee('data-demo-email="dewi@servops.test"', false)
            ->assertDontSee("\$set('email'", false);

        config(['servops.demo_accounts' => false]);

        $this->get('/login')->assertOk()->assertDontSee('Akun demo');
    }

    public function test_user_can_login_and_is_sent_home(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'rudi@servops.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertNotNull(auth()->user()->last_login_at);
    }

    public function test_wrong_password_is_rejected_and_rate_limited(): void
    {
        RateLimiter::clear('rudi@servops.test|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', 'rudi@servops.test')->set('password', 'salah')->call('login')
                ->assertHasErrors(['email']);
        }

        Livewire::test(Login::class)->set('email', 'rudi@servops.test')->set('password', 'password')->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_home_redirects_by_role(): void
    {
        $this->loginAs('sari');
        $this->get('/')->assertRedirect(route('dashboard'));

        $this->loginAs('ops');
        $this->get('/')->assertRedirect(route('requests.index'));
    }

    public function test_logout(): void
    {
        $this->loginAs('rudi');

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sidebar_shows_user_role_and_only_permitted_menus(): void
    {
        $this->loginAs('ops');
        $this->get('/requests')->assertOk()
            ->assertSee('Bayu Pratama')
            ->assertSee('Pemohon')
            ->assertDontSee('Tim &amp; Kapasitas', false)
            ->assertDontSee('Risiko SLA');

        $this->loginAs('sari');
        $this->get('/dashboard')->assertOk()
            ->assertSee('Tim &amp; Kapasitas', false)
            ->assertSee('Risiko SLA')
            ->assertSee('Override prioritas');
    }
}
