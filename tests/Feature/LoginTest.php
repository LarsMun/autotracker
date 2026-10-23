<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->artisan('auto:user', ['name' => 'lars', '--password' => 'geheim-wachtwoord'])->assertSuccessful();
    }

    public function test_guests_go_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/runs')->assertRedirect('/login');
        $this->postJson('/sources/preview', ['url' => 'https://www.gaspedaal.nl/bmw'])->assertUnauthorized();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_logging_in_and_out(): void
    {
        $this->post('/login', ['name' => 'lars', 'password' => 'geheim-wachtwoord', 'remember' => true])->assertRedirect('/voor-jou');
        $this->assertAuthenticatedAs(User::first());
        $this->get('/voor-jou')->assertOk();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_wrong_password_is_refused_and_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['name' => 'lars', 'password' => 'fout'])->assertSessionHasErrors('name');
        }

        $this->post('/login', ['name' => 'lars', 'password' => 'geheim-wachtwoord'])->assertSessionHasErrors('name');
        $this->assertGuest();
    }

    public function test_running_the_command_again_changes_the_password(): void
    {
        $this->artisan('auto:user', ['name' => 'lars', '--password' => 'nieuw-wachtwoord'])->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->post('/login', ['name' => 'lars', 'password' => 'nieuw-wachtwoord'])->assertRedirect('/voor-jou');
    }
}
