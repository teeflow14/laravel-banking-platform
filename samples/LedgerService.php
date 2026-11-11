<?php
// File: core/app/Services/LedgerService.php
// REVISED: added adminAdjustment() for the new admin credit/debit action.
// Everything else unchanged from Phase 6.

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Carbon\Carbon;

class LedgerService
{
    public function transferInternal(
        Account $senderAccount,
        Account $receiverAccount,
        float $amount,
        ?int $beneficiaryId,
        string $description,
    ): array {
        return DB::transaction(function () use ($senderAccount, $receiverAccount, $amount, $beneficiaryId, $description) {
            [$first, $second] = $senderAccount->id < $receiverAccount->id
                ? [$senderAccount, $receiverAccount]
                : [$receiverAccount, $senderAccount];

            $first = Account::whereKey($first->id)->lockForUpdate()->first();
            $second = Account::whereKey($second->id)->lockForUpdate()->first();
            $sender = $senderAccount->id === $first->id ? $first : $second;
            $receiver = $senderAccount->id === $first->id ? $second : $first;

            if (bccomp((string) $sender->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance.');
            }

            $senderBalanceBefore = $sender->balance;
            $sender->decrement('balance', $amount);
            $sender->decrement('available_balance', $amount);

            $receiverBalanceBefore = $receiver->balance;
            $receiver->increment('balance', $amount);
            $receiver->increment('available_balance', $amount);

            $ref = Transaction::generateReference(Transaction::class, 'transaction_ref', 'TXN');

            $debitTxn = Transaction::create([
                'transaction_ref' => $ref . '-D',
                'user_id' => $sender->user_id,
                'account_id' => $sender->id,
                'type' => 'transfer_internal',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $senderBalanceBefore,
                'balance_after' => $sender->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
                'beneficiary_id' => $beneficiaryId,
            ]);

            $creditTxn = Transaction::create([
                'transaction_ref' => $ref . '-C',
                'user_id' => $receiver->user_id,
                'account_id' => $receiver->id,
                'type' => 'transfer_internal',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $receiverBalanceBefore,
                'balance_after' => $receiver->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
                'related_transaction_id' => $debitTxn->id,
            ]);

            $debitTxn->update(['related_transaction_id' => $creditTxn->id]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $debitTxn->id,
                'debit_account_id' => $sender->id,
                'credit_account_id' => $receiver->id,
                'amount' => $amount,
                'currency' => $sender->currency,
                'narration' => $description,
            ]);

            return ['debit' => $debitTxn, 'credit' => $creditTxn];
        });
    }

    public function debitForExternalTransfer(
        Account $senderAccount,
        float $amount,
        string $type,
        ?int $beneficiaryId,
        string $description,
        string $status = 'completed',
    ): Transaction {
        return DB::transaction(function () use ($senderAccount, $amount, $type, $beneficiaryId, $description, $status) {
            $account = Account::whereKey($senderAccount->id)->lockForUpdate()->first();

            if (bccomp((string) $account->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance.');
            }

            $balanceBefore = $account->balance;
            $account->decrement('balance', $amount);
            $account->decrement('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'TXN'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => $type,
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => $status,
                'description' => $description,
                'beneficiary_id' => $beneficiaryId,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => $account->id,
                'credit_account_id' => null,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    public function reverseDebit(Transaction $transaction, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $reason) {
            $account = Account::whereKey($transaction->account_id)->lockForUpdate()->first();

            $balanceBefore = $account->balance;
            $account->increment('balance', $transaction->amount);
            $account->increment('available_balance', $transaction->amount);

            $transaction->update(['status' => 'failed']);

            $reversal = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'TXN'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'reversal',
                'direction' => 'credit',
                'amount' => $transaction->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => 'Reversal: ' . $reason,
                'related_transaction_id' => $transaction->id,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $reversal->id,
                'debit_account_id' => null,
                'credit_account_id' => $account->id,
                'amount' => $transaction->amount,
                'currency' => $account->currency,
                'narration' => 'Reversal: ' . $reason,
            ]);

            return $reversal;
        });
    }

    public function creditDeposit(Account $account, float $amount, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            $balanceBefore = $account->balance;
            $account->increment('balance', $amount);
            $account->increment('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'TXN'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'deposit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => null,
                'credit_account_id' => $account->id,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    /**
     * Admin manually credits or debits an account — account funding
     * corrections, goodwill credits, fee reversals, etc. $direction is
     * 'credit' or 'debit'. Debits are balance-checked the same as any
     * other outflow; credits are not.
     */
    /**
     * Debits a fee as its own transaction/journal entry, linked to the
     * transaction it's charged against via related_transaction_id — kept
     * separate from the main transfer/deposit transaction so each
     * ledger row stays internally consistent (its own debit exactly
     * matches its own credit), rather than folding the fee into the
     * main transaction's amount and creating a mismatch.
     */
    public function chargeFee(Account $account, float $fee, ?int $relatedTransactionId, string $description): ?Transaction
    {
        if ($fee <= 0) {
            return null;
        }

        return DB::transaction(function () use ($account, $fee, $relatedTransactionId, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            if (bccomp((string) $account->balance, (string) $fee, 2) < 0) {
                throw new RuntimeException('Insufficient balance to cover the fee for this transaction.');
            }

            $balanceBefore = $account->balance;
            $account->decrement('balance', $fee);
            $account->decrement('available_balance', $fee);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'FEE'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'fee',
                'direction' => 'debit',
                'amount' => $fee,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
                'related_transaction_id' => $relatedTransactionId,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => $account->id,
                'credit_account_id' => null, // fee revenue — no dedicated revenue account in this MVP
                'amount' => $fee,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    public function adminAdjustment(Account $account, float $amount, string $direction, string $type, string $date, string $reason, int $adminId): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $direction, $type, $date, $reason, $adminId) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            if ($direction === 'debit' && bccomp((string) $account->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance for this debit.');
            }

            $balanceBefore = $account->balance;

            if ($direction === 'credit') {
                $account->increment('balance', $amount);
                $account->increment('available_balance', $amount);
            } else {
                $account->decrement('balance', $amount);
                $account->decrement('available_balance', $amount);
            }
            
            $transactionDate = Carbon::parse($date);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'TXN'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $reason,
                'meta' => ['admin_id' => $adminId],
                 
            ]);
            
            // Set timestamps directly so mass-assignment rules cannot ignore them.
                $transaction->created_at = $transactionDate;
                $transaction->updated_at = $transactionDate;
                $transaction->save();

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => $direction === 'debit' ? $account->id : null,
                'credit_account_id' => $direction === 'credit' ? $account->id : null,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' =>  $reason,
            ]);

            return $transaction;
        });
    }

    /**
     * Credits a disbursed loan's principal to the borrower's account.
     * Mirrors creditDeposit() — external "source" (the bank issuing the
     * loan), credit_account_id null on the journal entry.
     */
    public function creditLoanDisbursement(Account $account, float $amount, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            $balanceBefore = $account->balance;
            $account->increment('balance', $amount);
            $account->increment('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'LN'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'loan_disbursement',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => null,
                'credit_account_id' => $account->id,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    /**
     * Debits a loan installment repayment from the borrower's account.
     * Mirrors debitForExternalTransfer() — money leaving to repay the
     * bank, debit_account_id set, credit_account_id null.
     */
    public function debitLoanRepayment(Account $account, float $amount, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            if (bccomp((string) $account->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance to make this repayment.');
            }

            $balanceBefore = $account->balance;
            $account->decrement('balance', $amount);
            $account->decrement('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'LNR'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'loan_repayment',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => $account->id,
                'credit_account_id' => null,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    /**
     * Moves money FROM the main account INTO a savings plan — the money
     * genuinely leaves balance/available_balance (see the savings
     * migration's header comment for why that's the deliberate design).
     */
    public function debitToSavings(Account $account, float $amount, int $savingsPlanId, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $savingsPlanId, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            if (bccomp((string) $account->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance to fund this plan.');
            }

            $balanceBefore = $account->balance;
            $account->decrement('balance', $amount);
            $account->decrement('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'SAV'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'savings_plan_id' => $savingsPlanId,
                'type' => 'savings_deposit',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => $account->id,
                'credit_account_id' => null,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    /**
     * Moves money FROM a savings plan back INTO the main account —
     * principal and (if any) interest are recorded as two separate
     * transactions for a clean, auditable trail, same pattern as fees
     * being separate from the transaction they're charged against.
     */
    public function creditFromSavings(Account $account, float $principal, float $interest, int $savingsPlanId, string $description): array
    {
        return DB::transaction(function () use ($account, $principal, $interest, $savingsPlanId, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            $balanceBefore = $account->balance;
            $account->increment('balance', $principal);
            $account->increment('available_balance', $principal);

            $principalTransaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'SAVW'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'savings_plan_id' => $savingsPlanId,
                'type' => 'savings_withdrawal',
                'direction' => 'credit',
                'amount' => $principal,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $principalTransaction->id,
                'debit_account_id' => null,
                'credit_account_id' => $account->id,
                'amount' => $principal,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            $interestTransaction = null;

            if ($interest > 0) {
                $balanceBeforeInterest = $account->fresh()->balance;
                $account->increment('balance', $interest);
                $account->increment('available_balance', $interest);

                $interestTransaction = Transaction::create([
                    'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'SAVI'),
                    'user_id' => $account->user_id,
                    'account_id' => $account->id,
                    'savings_plan_id' => $savingsPlanId,
                    'type' => 'savings_interest',
                    'direction' => 'credit',
                    'amount' => $interest,
                    'balance_before' => $balanceBeforeInterest,
                    'balance_after' => $account->fresh()->balance,
                    'status' => 'completed',
                    'description' => 'Interest earned — ' . $description,
                ]);

                JournalEntry::create([
                    'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                    'transaction_id' => $interestTransaction->id,
                    'debit_account_id' => null,
                    'credit_account_id' => $account->id,
                    'amount' => $interest,
                    'currency' => $account->currency,
                    'narration' => 'Interest earned — ' . $description,
                ]);
            }

            return ['principal' => $principalTransaction, 'interest' => $interestTransaction];
        });
    }

    public function debitFixedDepositLock(Account $account, float $amount, int $depositId, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $depositId, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();
            if (bccomp((string) $account->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance to open this fixed deposit.');
            }
            $before = $account->balance;
            $account->decrement('balance', $amount);
            $account->decrement('available_balance', $amount);
            $transaction = Transaction::create(['transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'FDP'), 'user_id' => $account->user_id, 'account_id' => $account->id, 'fixed_deposit_id' => $depositId, 'type' => 'fixed_deposit', 'direction' => 'debit', 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $account->fresh()->balance, 'status' => 'completed', 'description' => $description]);
            JournalEntry::create(['entry_ref' => 'JNL-'.Str::upper(Str::random(10)), 'transaction_id' => $transaction->id, 'debit_account_id' => $account->id, 'credit_account_id' => null, 'amount' => $amount, 'currency' => $account->currency, 'narration' => $description]);
            return $transaction;
        });
    }

    public function creditFixedDepositPayout(Account $account, float $amount, int $depositId, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $depositId, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();
            $before = $account->balance;
            $account->increment('balance', $amount);
            $account->increment('available_balance', $amount);
            $transaction = Transaction::create(['transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'FDP'), 'user_id' => $account->user_id, 'account_id' => $account->id, 'fixed_deposit_id' => $depositId, 'type' => 'fixed_deposit_payout', 'direction' => 'credit', 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $account->fresh()->balance, 'status' => 'completed', 'description' => $description]);
            JournalEntry::create(['entry_ref' => 'JNL-'.Str::upper(Str::random(10)), 'transaction_id' => $transaction->id, 'debit_account_id' => null, 'credit_account_id' => $account->id, 'amount' => $amount, 'currency' => $account->currency, 'narration' => $description]);
            return $transaction;
        });
    }

    /**
     * Reverses a transaction's effect on its account balance without
     * creating a new ledger row — used when admin deletes a transaction
     * outright, so the deletion doesn't leave the account balance out
     * of sync with what remains in its transaction history. A no-op
     * for anything that wasn't 'completed' (pending/failed transactions
     * never actually moved money, so there's nothing to reverse), and
     * a safe no-op if the account itself is already gone (e.g. this is
     * running as part of cascade-deleting the account's own owner).
     */
    public function voidTransaction(Transaction $transaction): void
    {
        if ($transaction->status !== 'completed') {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $account = Account::whereKey($transaction->account_id)->lockForUpdate()->first();

            if (! $account) {
                return;
            }

            $reversal = $transaction->direction === 'credit' ? -$transaction->amount : $transaction->amount;
            $account->increment('balance', $reversal);
            $account->increment('available_balance', $reversal);
        });
    }

    public function debitCardlessWithdrawal(Account $account, float $amount, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            if (bccomp((string) $account->balance, (string) $amount, 2) < 0) {
                throw new RuntimeException('Insufficient balance for this withdrawal.');
            }

            $balanceBefore = $account->balance;
            $account->decrement('balance', $amount);
            $account->decrement('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'CWD'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'cardless_withdrawal',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => $account->id,
                'credit_account_id' => null,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }

    public function creditCardlessWithdrawalRefund(Account $account, float $amount, string $description): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description) {
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            $balanceBefore = $account->balance;
            $account->increment('balance', $amount);
            $account->increment('available_balance', $amount);

            $transaction = Transaction::create([
                'transaction_ref' => Transaction::generateReference(Transaction::class, 'transaction_ref', 'CWR'),
                'user_id' => $account->user_id,
                'account_id' => $account->id,
                'type' => 'cardless_withdrawal_refund',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->fresh()->balance,
                'status' => 'completed',
                'description' => $description,
            ]);

            JournalEntry::create([
                'entry_ref' => 'JNL-' . Str::upper(Str::random(10)),
                'transaction_id' => $transaction->id,
                'debit_account_id' => null,
                'credit_account_id' => $account->id,
                'amount' => $amount,
                'currency' => $account->currency,
                'narration' => $description,
            ]);

            return $transaction;
        });
    }
}

// End of file: core/app/Services/LedgerService.php
