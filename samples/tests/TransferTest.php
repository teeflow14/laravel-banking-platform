<?php
// File: core/tests/Feature/Customer/TransferTest.php

namespace Tests\Feature\Customer;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsReadyUser(float $balance = 50000): User
    {
        $user = User::factory()->onboarded()->create();
        Account::factory()->for($user)->withBalance($balance)->create(['account_number' => $user->account_number]);

        $this->actingAs($user)->withSession(['pin_verified' => true]);

        return $user->fresh();
    }

    public function test_internal_transfer_moves_funds_between_two_accounts(): void
    {
        $sender = $this->actingAsReadyUser(50000);

        $receiver = User::factory()->onboarded()->create();
        Account::factory()->for($receiver)->create(['account_number' => $receiver->account_number]);

        $response = $this->post(route('transfers.internal.store'), [
            'recipient_account_number' => $receiver->account_number,
            'amount' => 10000,
            'pin' => '123456',
        ]);

        $response->assertRedirect();
        $this->assertEquals(40000, $sender->account->fresh()->balance);
        $this->assertEquals(10000, $receiver->account->fresh()->balance);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $sender->account->id,
            'direction' => 'debit',
            'amount' => 10000,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $receiver->account->id,
            'direction' => 'credit',
            'amount' => 10000,
            'status' => 'completed',
        ]);

        // Every completed transfer should leave exactly one journal_entries row.
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_internal_transfer_rejects_insufficient_balance(): void
    {
        $sender = $this->actingAsReadyUser(5000);

        $receiver = User::factory()->onboarded()->create();
        Account::factory()->for($receiver)->create(['account_number' => $receiver->account_number]);

        $response = $this->post(route('transfers.internal.store'), [
            'recipient_account_number' => $receiver->account_number,
            'amount' => 10000, // more than the sender has
            'pin' => '123456',
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertEquals(5000, $sender->account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_internal_transfer_rejects_wrong_pin(): void
    {
        $sender = $this->actingAsReadyUser(50000);
        $receiver = User::factory()->onboarded()->create();
        Account::factory()->for($receiver)->create(['account_number' => $receiver->account_number]);

        $response = $this->post(route('transfers.internal.store'), [
            'recipient_account_number' => $receiver->account_number,
            'amount' => 1000,
            'pin' => '000000',
        ]);

        $response->assertSessionHasErrors('pin');
        $this->assertEquals(50000, $sender->account->fresh()->balance);
    }

    public function test_internal_transfer_rejects_unknown_recipient(): void
    {
        $sender = $this->actingAsReadyUser(50000);

        $response = $this->post(route('transfers.internal.store'), [
            'recipient_account_number' => '0000000000',
            'amount' => 1000,
            'pin' => '123456',
        ]);

        $response->assertSessionHasErrors('recipient_account_number');
    }

    public function test_international_transfer_above_threshold_is_held_for_review(): void
    {
        \App\Models\Setting::set('international_transfer_review_threshold', 100000, 'text', 'transfers');

        $sender = $this->actingAsReadyUser(500000);

        $plainCode = $this->getKnownOtpCodeForTest($sender->email, 'transfer_verification');

        $response = $this->post(route('transfers.international.store'), [
            'from_account_id' => $sender->account->id,
            'bank_name' => 'Test International Bank',
            'country' => 'US',
            'account_number' => 'US12345678901234',
            'account_name' => 'Test Recipient',
            'amount' => 200000, // above the 100,000 threshold set above
            'pin' => '123456',
            'otp_code' => $plainCode,
        ]);

        $response->assertRedirect();
        $this->assertEquals(300000, $sender->account->fresh()->balance);
        $this->assertDatabaseHas('transactions', ['status' => 'under_review', 'type' => 'transfer_international']);
        $this->assertDatabaseHas('transfer_reviews', ['status' => 'pending']);
    }

    public function test_transfer_page_uses_the_searchable_iso_country_picker(): void
    {
        $this->actingAsReadyUser();

        $this->get(route('transfers.create', ['type' => 'international']))
            ->assertOk()
            ->assertSee('Search and choose a country')
            ->assertSee('Search by country name or code')
            ->assertSee('United States');
    }

    public function test_international_transfer_rejects_an_unknown_country_code(): void
    {
        $sender = $this->actingAsReadyUser();

        $response = $this->postJson(route('transfers.international.prepare'), [
            'from_account_id' => $sender->account->id,
            'bank_name' => 'Test International Bank',
            'country' => 'XX',
            'account_number' => 'US12345678901234',
            'account_name' => 'Test Recipient',
            'amount' => 1000,
        ]);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('country', $response->json('errors'));
    }

    /**
     * Test helper: OTPs are stored hashed (App\Models\Otp::generateFor),
     * so tests can't read the plain code back from the DB — regenerate
     * one directly via the service and capture its plain value instead
     * of re-deriving it from the hash.
     */
    protected function getKnownOtpCodeForTest(string $email, string $purpose): string
    {
        $result = \App\Models\Otp::generateFor($email, $purpose, null, 10);

        return $result['plain_code'];
    }
}

// End of file: core/tests/Feature/Customer/TransferTest.php
