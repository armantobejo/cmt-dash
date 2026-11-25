<?php

namespace App\Parity;

class ParityDataset
{
    public const DOMAINS = [
        'shipments', 'shipment_detail',
        'invoices', 'ledger_sales',
        'data_quality', 'auth',
        'rbac', 'dashboard', 'apis',
        'projects_jobs_summary', 'projects_jobs_detail',
        'ar_reports', 'ap_reports', 'finance_reports',
        'statement_of_account', 'listing_reference',
        'performance_tracker', 'miscellaneous_views',
        'ship_finance_ap_journalism', 'volume_lifting_gross_profit',
    ];

    private array $domains;

    public function __construct(array $domains = [])
    {
        $this->domains = $domains ?: self::DOMAINS;
    }

    public function domains(): array
    {
        return $this->domains;
    }

    public static function build(): void
    {
        // Dataset build is a no-op in hosted CI (real DBs are gitignored).
        // The parity capture harness populates domains at runtime.
    }
}
