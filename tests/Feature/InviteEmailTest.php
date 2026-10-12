<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteEmailTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $company = Company::create([
            'name' => 'Acme',
            'join_code' => Company::generateUniqueJoinCode(),
            'status' => 'active',
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'role' => 'admin',
            'company_id' => $company->id,
        ]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_creating_an_invite_emails_the_token(): void
    {
        Mail::fake();
        $this->actingAdmin();

        $response = $this->postJson('/api/invites', ['email' => 'new@example.com', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('emailed', true);

        Mail::assertSent(InviteMail::class, function ($mail) use ($response) {
            return $mail->hasTo('new@example.com')
                && $mail->invite->token === $response->json('token')
                && $mail->companyName === 'Acme';
        });
    }

    public function test_a_mail_failure_does_not_block_the_invite(): void
    {
        $this->actingAdmin();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->postJson('/api/invites', ['email' => 'new@example.com', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('emailed', false)
            ->assertJsonStructure(['token']);
    }
}