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

    public function test_demo_link_prefills_form_server_side_without_javascript(): void
    {
        $this->get(route('login', ['as' => 'dewi@servops.test']))
            ->assertSee('value="dewi@servops.test"', false)
            ->assertSee('value="password"', false)
            ->assertSee('href="'.e(route('login', ['as' => 'sari@servops.test'])).'"', false);

        $this->get(route('login', ['as' => 'orang@luar.test']))
            ->assertDontSee('value="orang@luar.test"', false)
            ->assertDontSee('value="password"', false);

        config(['servops.demo_accounts' => false]);

        $this->get(route('login', ['as' => 'dewi@servops.test']))
            ->assertDontSee('value="dewi@servops.test"', false)
            ->assertDontSee('value="password"', false);
    }

    public function test_plain_post_login_works_without_javascript(): void
    {
        $this->get('/login')->assertSee('action="'.route('login.store').'"', false)->assertSee('name="password"', false);

        $this->post(route('login.store'), ['email' => 'sari@servops.test', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
    }

    public function test_plain_post_login_failure_returns_to_form_with_error_and_email(): void
    {
        $this->from(route('login'))
            ->post(route('login.store'), ['email' => 'sari@servops.test', 'password' => 'salah'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->get(route('login'))->assertSee('value="sari@servops.test"', false);
        $this->assertGuest();
    }

    public function test_rate_limit_per_email_cannot_be_bypassed_by_changing_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post(route('login.store'), ['email' => 'rudi@servops.test', 'password' => 'salah']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post(route('login.store'), ['email' => 'rudi@servops.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn('email|rudi@servops.test').' detik.']);

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
