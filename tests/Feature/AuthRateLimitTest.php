<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public auth endpoints are rate limited to slow credential brute-forcing.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        $payload = ['email' => 'ratelimit@example.com', 'password' => 'wrong-password'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }

    public function test_a_different_email_has_its_own_bucket(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'first@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // A different email is not affected by the first email's bucket.
        $this->postJson('/api/auth/login', [
            'email' => 'second@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
}
