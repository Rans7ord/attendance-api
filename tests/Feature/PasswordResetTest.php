<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $company = Company::create([
            'name' => 'Acme',
            'join_code' => Company::generateUniqueJoinCode(),
            'status' => 'active',
        ]);

        return User::create([
            'name' => 'Ama Mensah',
            'email' => 'ama@example.com',
            'password' => 'old-password',
            'role' => 'member',
            'company_id' => $company->id,
        ]);
    }

    public function test_code_is_emailed_and_resets_the_password(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $user->createToken('mobile');

        $this->postJson('/api/forgot-password', ['email' => 'ama@example.com'])->assertOk();

        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;
            return $mail->hasTo('ama@example.com');
        });

        $this->postJson('/api/reset-password', [
            'email' => 'ama@example.com',
            'code' => $code,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        // The code is single-use.
        $this->postJson('/api/reset-password', [
            'email' => 'ama@example.com',
            'code' => $code,
            'password' => 'another-pass-1',
            'password_confirmation' => 'another-pass-1',
        ])->assertStatus(422);
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_wrong_code_is_rejected_and_locks_out_after_five_tries(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->postJson('/api/forgot-password', ['email' => 'ama@example.com']);

        $payload = [
            'email' => 'ama@example.com',
            'code' => '000000',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', $payload)->assertStatus(422);
        }

        $this->postJson('/api/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_expired_code_is_rejected(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->postJson('/api/forgot-password', ['email' => 'ama@example.com']);

        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;
            return true;
        });

        $this->travel(16)->minutes();

        $this->postJson('/api/reset-password', [
            'email' => 'ama@example.com',
            'code' => $code,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422);
    }
}