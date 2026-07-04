<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spdotdev\Inventory\Mail\PasswordResetMail;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * forgot-password must never leak whether an address is registered — it returns
 * 200 either way. Only a real account triggers a stored token + email.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private string $url = 'http://inventory.test/api/v1/auth/forgot-password';

    public function test_a_known_email_stores_a_token_and_sends_the_link(): void
    {
        Mail::fake();
        User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        $this->postJson($this->url, ['email' => 'stan@example.test'])->assertOk();

        $this->assertDatabaseHas('inventory_password_resets', ['email' => 'stan@example.test']);
        Mail::assertSent(PasswordResetMail::class);
    }

    public function test_the_reset_link_is_built_on_the_inventory_domain(): void
    {
        // X3: the link must point at config('inventory.domain') (where the
        // /reset-password page lives), not the host app's APP_URL — otherwise a
        // split-domain deploy emails a 404.
        Mail::fake();
        config(['inventory.domain' => 'inventory.example.test', 'app.url' => 'https://host-app.test']);
        User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        $this->postJson($this->url, ['email' => 'stan@example.test'])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            return str_starts_with($mail->resetUrl, 'https://inventory.example.test/reset-password')
                && ! str_contains($mail->resetUrl, 'host-app.test');
        });
    }

    public function test_an_unknown_email_still_returns_200_and_leaks_nothing(): void
    {
        Mail::fake();

        // Same 200 shape as the known-email case — no enumeration signal.
        $this->postJson($this->url, ['email' => 'ghost@example.test'])->assertOk();

        $this->assertDatabaseMissing('inventory_password_resets', ['email' => 'ghost@example.test']);
        Mail::assertNothingSent();
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->postJson($this->url, ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
