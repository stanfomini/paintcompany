<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Create a journal entry with lines, validating that debits equal credits.
     *
     * @param  array{description: string, transaction_date: ?string, recognition_date: ?string, reference_type: ?string, reference_id: ?int, status: ?string, lines: array<array{account_id: int, debit_amount: float, credit_amount: float, description: ?string}>}  $data
     *
     * @throws \InvalidArgumentException
     */
    public function createJournalEntry(array $data): JournalEntry
    {
        $lines = $data['lines'] ?? [];

        if (empty($lines)) {
            throw new \InvalidArgumentException('Journal entry must have at least one line.');
        }

        $totalDebits = round(array_sum(array_column($lines, 'debit_amount')), 2);
        $totalCredits = round(array_sum(array_column($lines, 'credit_amount')), 2);

        if ($totalDebits !== $totalCredits) {
            throw new \InvalidArgumentException(
                "Journal entry is unbalanced: debits ({$totalDebits}) != credits ({$totalCredits})"
            );
        }

        $settings = CompanySetting::getInstance();

        return DB::transaction(function () use ($data, $lines, $settings) {
            $entry = JournalEntry::create([
                'company_setting_id' => $settings->id,
                'entry_number' => $data['entry_number'] ?? $this->generateEntryNumber(),
                'transaction_date' => $data['transaction_date'] ?? null,
                'recognition_date' => $data['recognition_date'] ?? null,
                'description' => $data['description'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => $data['status'] ?? JournalEntry::STATUS_DRAFT,
                'created_by' => $data['created_by'] ?? Auth::id(),
            ]);

            foreach ($lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit_amount' => $line['debit_amount'] ?? 0,
                    'credit_amount' => $line['credit_amount'] ?? 0,
                    'description' => $line['description'] ?? null,
                ]);
            }

            $entry->load('lines');

            return $entry;
        });
    }

    /**
     * Post a draft journal entry.
     */
    public function postEntry(JournalEntry $entry): void
    {
        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only draft entries can be posted.');
        }

        $entry->update([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
        ]);
    }

    /**
     * Void a journal entry.
     */
    public function voidEntry(JournalEntry $entry): void
    {
        if ($entry->status === JournalEntry::STATUS_VOID) {
            throw new \InvalidArgumentException('Entry is already void.');
        }

        $entry->update([
            'status' => JournalEntry::STATUS_VOID,
            'voided_at' => now(),
        ]);
    }

    /**
     * Generate a unique journal entry number.
     */
    public function generateEntryNumber(): string
    {
        $year = now()->year;
        $prefix = "JE-{$year}-";

        $lastNumber = JournalEntry::where('entry_number', 'like', "{$prefix}%")
            ->orderByDesc('entry_number')
            ->value('entry_number');

        if ($lastNumber) {
            $lastSequence = (int) substr($lastNumber, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix.str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get the accounting mode from company settings.
     */
    public function getAccountingMode(): string
    {
        return CompanySetting::getInstance()->accounting_mode ?? 'cash';
    }

    /**
     * Calculate the net balance for a single account as of a given date.
     * For debit-normal accounts: sum(debits) - sum(credits)
     * For credit-normal accounts: sum(credits) - sum(debits)
     */
    public function getAccountBalance(Account $account, CarbonInterface $asOf, ?string $mode = null): float
    {
        $mode = $mode ?? $this->getAccountingMode();
        $column = $this->getDateColumn($mode, $account->type);

        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($column, $asOf) {
                $q->where('status', JournalEntry::STATUS_POSTED)
                    ->whereNotNull($column)
                    ->where($column, '<=', $asOf);
            });

        $totalDebits = (float) $query->sum('debit_amount');
        $totalCredits = (float) (clone $query)->sum('credit_amount');

        if ($account->normal_balance === Account::NORMAL_DEBIT) {
            return round($totalDebits - $totalCredits, 2);
        }

        return round($totalCredits - $totalDebits, 2);
    }

    /**
     * Get trial balance as of a given date.
     *
     * @return array{accounts: Collection, total_debits: float, total_credits: float, is_balanced: bool}
     */
    public function getTrialBalance(CarbonInterface $asOf, ?string $mode = null): array
    {
        $mode = $mode ?? $this->getAccountingMode();
        $settings = CompanySetting::getInstance();

        $accounts = Account::where('company_setting_id', $settings->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $totalDebits = 0.0;
        $totalCredits = 0.0;
        $results = [];

        foreach ($accounts as $account) {
            $balance = $this->getAccountBalance($account, $asOf, $mode);

            $debit = 0.0;
            $credit = 0.0;

            if ($account->normal_balance === Account::NORMAL_DEBIT) {
                if ($balance >= 0) {
                    $debit = $balance;
                } else {
                    $credit = abs($balance);
                }
            } else {
                if ($balance >= 0) {
                    $credit = $balance;
                } else {
                    $debit = abs($balance);
                }
            }

            $totalDebits += $debit;
            $totalCredits += $credit;

            $results[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];
        }

        return [
            'accounts' => collect($results),
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'is_balanced' => round($totalDebits, 2) === round($totalCredits, 2),
        ];
    }

    /**
     * Get Profit & Loss statement for a date range.
     *
     * @return array{revenue: Collection, expenses: Collection, total_revenue: float, total_expenses: float, net_income: float}
     */
    public function getProfitAndLoss(CarbonInterface $from, CarbonInterface $to, ?string $mode = null): array
    {
        $mode = $mode ?? $this->getAccountingMode();
        $settings = CompanySetting::getInstance();

        $revenueAccounts = Account::where('company_setting_id', $settings->id)
            ->where('type', Account::TYPE_REVENUE)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $expenseAccounts = Account::where('company_setting_id', $settings->id)
            ->where('type', Account::TYPE_EXPENSE)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $revenueItems = [];
        $totalRevenue = 0.0;

        foreach ($revenueAccounts as $account) {
            $amount = $this->getAccountBalanceForPeriod($account, $from, $to, $mode);
            $totalRevenue += $amount;
            $revenueItems[] = ['account' => $account, 'amount' => $amount];
        }

        $expenseItems = [];
        $totalExpenses = 0.0;

        foreach ($expenseAccounts as $account) {
            $amount = $this->getAccountBalanceForPeriod($account, $from, $to, $mode);
            $totalExpenses += $amount;
            $expenseItems[] = ['account' => $account, 'amount' => $amount];
        }

        return [
            'revenue' => collect($revenueItems),
            'expenses' => collect($expenseItems),
            'total_revenue' => round($totalRevenue, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_income' => round($totalRevenue - $totalExpenses, 2),
        ];
    }

    /**
     * Get Balance Sheet as of a given date.
     *
     * @return array{assets: Collection, liabilities: Collection, equity: Collection, total_assets: float, total_liabilities: float, total_equity: float, is_balanced: bool}
     */
    public function getBalanceSheet(CarbonInterface $asOf, ?string $mode = null): array
    {
        $mode = $mode ?? $this->getAccountingMode();
        $settings = CompanySetting::getInstance();

        $types = [
            Account::TYPE_ASSET => [],
            Account::TYPE_LIABILITY => [],
            Account::TYPE_EQUITY => [],
        ];

        $totals = [
            Account::TYPE_ASSET => 0.0,
            Account::TYPE_LIABILITY => 0.0,
            Account::TYPE_EQUITY => 0.0,
        ];

        foreach (array_keys($types) as $type) {
            $accounts = Account::where('company_setting_id', $settings->id)
                ->where('type', $type)
                ->where('is_active', true)
                ->orderBy('code')
                ->get();

            foreach ($accounts as $account) {
                $balance = $this->getAccountBalance($account, $asOf, $mode);
                $types[$type][] = ['account' => $account, 'balance' => $balance];
                $totals[$type] += $balance;
            }
        }

        $totalAssets = round($totals[Account::TYPE_ASSET], 2);
        $totalLiabilities = round($totals[Account::TYPE_LIABILITY], 2);
        $totalEquity = round($totals[Account::TYPE_EQUITY], 2);

        return [
            'assets' => collect($types[Account::TYPE_ASSET]),
            'liabilities' => collect($types[Account::TYPE_LIABILITY]),
            'equity' => collect($types[Account::TYPE_EQUITY]),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'is_balanced' => $totalAssets === round($totalLiabilities + $totalEquity, 2),
        ];
    }

    /**
     * Calculate account balance for a specific period (used in P&L).
     */
    protected function getAccountBalanceForPeriod(Account $account, CarbonInterface $from, CarbonInterface $to, string $mode): float
    {
        $column = $this->getDateColumn($mode, $account->type);

        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($column, $from, $to) {
                $q->where('status', JournalEntry::STATUS_POSTED)
                    ->whereNotNull($column)
                    ->whereBetween($column, [$from, $to]);
            });

        $totalDebits = (float) $query->sum('debit_amount');
        $totalCredits = (float) (clone $query)->sum('credit_amount');

        if ($account->normal_balance === Account::NORMAL_DEBIT) {
            return round($totalDebits - $totalCredits, 2);
        }

        return round($totalCredits - $totalDebits, 2);
    }

    /**
     * Get the appropriate date column based on accounting mode and account type.
     * Modified Cash: Cash for expenses, Accrual for revenue + assets.
     */
    protected function getDateColumn(string $mode, string $accountType): string
    {
        if ($mode === 'accrual') {
            return 'recognition_date';
        }

        if ($mode === 'modified_cash') {
            return in_array($accountType, [Account::TYPE_REVENUE, Account::TYPE_ASSET])
                ? 'recognition_date'
                : 'transaction_date';
        }

        return 'transaction_date';
    }
}
