<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Core\Database;
use InvalidArgumentException;
use RuntimeException;

final class AccountingChartService
{
    private const DEFAULT_RULES = [
        ['2', 'Passifs'],
        ['3', 'Produits'],
    ];

    private const DEFAULT_CATEGORIES = [
        ['1', 'Actifs'], ['10', 'Liquidités'], ['11', 'Débiteurs'], ['12', 'Stocks'],
        ['13', 'Comptes de régularisation actif'], ['14', 'Placements financiers'],
        ['15', 'Mobilier et installations'], ['16', 'Immobilisations corporelles'],
        ['17', 'Immobilisations incorporelles'], ['18', 'Capital non versé'],
        ['2', 'Passifs'], ['20', 'Fournisseurs'], ['21', 'Dettes rémunérées'],
        ['22', 'Autres dettes à court terme'], ['23', 'Comptes de régularisation passif'],
        ['3', 'Produits'], ['4', 'Charges de marchandises'], ['5', 'Charges de personnel'],
        ['6', 'Autres charges d’exploitation'], ['7', 'Résultats hors exploitation'],
        ['8', 'Résultats exceptionnels'], ['9', 'Clôture'],
    ];

    private const DEFAULT_ACCOUNTS = [
        ['1000', 'Caisse'], ['1010', 'Poste'], ['1020', 'Banque'], ['1100', 'Créances clients'],
        ['1200', 'Stock'], ['1600', 'Actifs immobilisés'], ['2000', 'Fournisseurs'],
        ['2400', 'Dettes à long terme'], ['2800', 'Capital'], ['2900', 'Résultat reporté'],
        ['3000', 'Ventes'], ['3900', 'Variation de stocks'], ['4000', 'Achats de marchandises'],
        ['5000', 'Salaires'], ['6000', 'Diverses prestations de tiers'], ['9400', 'Résultat de l’exercice'],
    ];

    public function __construct(private readonly AccountingDatabaseConnection $connection) {}

    public function structure(int $siteId, ?int $fiscalPeriodId = null): array
    {
        $chart = $this->ensureDefaultStructure($siteId);
        $chartId = (int) $chart['id'];
        $db = $this->db();
        $rules = $db->all('SELECT * FROM accounting_balance_rules WHERE chart_id=? ORDER BY length(account_prefix),account_prefix', [$chartId]);
        $categories = $db->all(
            'SELECT c.*,(SELECT COUNT(*) FROM accounting_accounts a WHERE a.chart_id=c.chart_id AND a.is_active=1 AND a.account_number LIKE c.account_prefix || "%") AS account_count
             FROM accounting_categories c WHERE c.chart_id=? ORDER BY c.sort_order,length(c.account_prefix),c.account_prefix',
            [$chartId]
        );
        $accounts = $db->all('SELECT * FROM accounting_accounts WHERE chart_id=? ORDER BY length(account_number),account_number', [$chartId]);
        foreach ($accounts as &$account) {
            $account['normal_side'] = $this->normalSideForChart($chart, (string) $account['account_number']);
            $account['category'] = $this->categoryForChart($chartId, (string) $account['account_number']);
        }
        unset($account);
        $periods = $db->all('SELECT * FROM accounting_fiscal_periods WHERE chart_id=? ORDER BY starts_on DESC', [$chartId]);
        $availablePeriodIds = array_map(static fn(array $row): int => (int)$row['id'], $periods);
        $selectedPeriodId = $fiscalPeriodId !== null && in_array($fiscalPeriodId, $availablePeriodIds, true)
            ? $fiscalPeriodId
            : (int) ($periods[0]['id'] ?? 0);
        $opening = $selectedPeriodId > 0
            ? $db->all('SELECT account_id,amount_minor FROM accounting_opening_balances WHERE fiscal_period_id=? ORDER BY account_id', [$selectedPeriodId])
            : [];
        return [
            'chart' => $chart,
            'rules' => $rules,
            'categories' => $categories,
            'accounts' => $accounts,
            'fiscal_periods' => $periods,
            'opening_balances' => $opening,
            'selected_fiscal_period_id' => $selectedPeriodId ?: null,
        ];
    }

    public function ensureDefaultStructure(int $siteId, ?int $actorId = null): array
    {
        if ($siteId < 1) throw new InvalidArgumentException('Site invalide.');
        $db = $this->db();
        $chart = $db->one('SELECT * FROM accounting_charts WHERE site_id=?', [$siteId]);
        if ($chart !== null) return $chart;

        return $db->transaction(function () use ($db, $siteId, $actorId): array {
            $db->run('INSERT INTO accounting_charts(site_id,name,currency,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?)', [$siteId, 'Plan comptable suisse', 'CHF', $actorId, $actorId]);
            $chartId = $db->lastInsertId();
            foreach (self::DEFAULT_RULES as [$prefix, $label]) {
                $db->run('INSERT INTO accounting_balance_rules(chart_id,account_prefix,increase_side,label,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,"credit",?,?,?)', [$chartId, $prefix, $label, $actorId, $actorId]);
            }
            foreach (self::DEFAULT_CATEGORIES as $sort => [$prefix, $label]) {
                $db->run('INSERT INTO accounting_categories(chart_id,account_prefix,label,sort_order,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,?)', [$chartId, $prefix, $label, $sort * 10, $actorId, $actorId]);
            }
            foreach (self::DEFAULT_ACCOUNTS as [$number, $label]) {
                $db->run('INSERT INTO accounting_accounts(chart_id,account_number,label,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?)', [$chartId, $number, $label, $actorId, $actorId]);
            }
            $year = (int) gmdate('Y');
            $db->run('INSERT INTO accounting_fiscal_periods(chart_id,code,label,starts_on,ends_on,status,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,"draft",?,?)', [$chartId, (string) $year, 'Exercice ' . $year, $year . '-01-01', $year . '-12-31', $actorId, $actorId]);
            return $db->one('SELECT * FROM accounting_charts WHERE id=?', [$chartId]) ?? throw new RuntimeException('Plan comptable introuvable après création.');
        });
    }

    public function saveRule(int $siteId, ?int $id, array $payload, ?int $actorId): array
    {
        $chart = $this->ensureDefaultStructure($siteId, $actorId);
        $prefix = $this->digits($payload['account_prefix'] ?? '', 'account_prefix', 12);
        $side = (string) ($payload['increase_side'] ?? 'credit');
        if (!in_array($side, ['debit', 'credit'], true)) throw new InvalidArgumentException('Le sens d’augmentation doit être debit ou credit.');
        $label = trim((string) ($payload['label'] ?? ''));
        if ($id === null) {
            $this->db()->run('INSERT INTO accounting_balance_rules(chart_id,account_prefix,increase_side,label,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,?)', [(int)$chart['id'],$prefix,$side,$label ?: null,$actorId,$actorId]);
            $id = $this->db()->lastInsertId();
        } else {
            $this->assertOwned('accounting_balance_rules', $id, (int)$chart['id']);
            $this->db()->run('UPDATE accounting_balance_rules SET account_prefix=?,increase_side=?,label=?,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$prefix,$side,$label ?: null,$actorId,$id]);
        }
        return $this->db()->one('SELECT * FROM accounting_balance_rules WHERE id=?', [$id]) ?? [];
    }

    public function deleteRule(int $siteId, int $id): void
    {
        $chart = $this->ensureDefaultStructure($siteId);
        $this->assertOwned('accounting_balance_rules', $id, (int)$chart['id']);
        $this->db()->run('DELETE FROM accounting_balance_rules WHERE id=?', [$id]);
    }

    public function saveCategory(int $siteId, ?int $id, array $payload, ?int $actorId): array
    {
        $chart = $this->ensureDefaultStructure($siteId, $actorId);
        $prefix = $this->digits($payload['account_prefix'] ?? '', 'account_prefix', 12);
        $label = $this->label($payload['label'] ?? '', 'label');
        $sort = (int) ($payload['sort_order'] ?? 0);
        if ($id === null) {
            $this->db()->run('INSERT INTO accounting_categories(chart_id,account_prefix,label,sort_order,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,?)', [(int)$chart['id'],$prefix,$label,$sort,$actorId,$actorId]);
            $id = $this->db()->lastInsertId();
        } else {
            $this->assertOwned('accounting_categories', $id, (int)$chart['id']);
            $this->db()->run('UPDATE accounting_categories SET account_prefix=?,label=?,sort_order=?,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$prefix,$label,$sort,$actorId,$id]);
        }
        return $this->db()->one('SELECT * FROM accounting_categories WHERE id=?', [$id]) ?? [];
    }

    public function deleteCategory(int $siteId, int $id): void
    {
        $chart = $this->ensureDefaultStructure($siteId);
        $this->assertOwned('accounting_categories', $id, (int)$chart['id']);
        $this->db()->run('DELETE FROM accounting_categories WHERE id=?', [$id]);
    }

    public function saveAccount(int $siteId, ?int $id, array $payload, ?int $actorId): array
    {
        $chart = $this->ensureDefaultStructure($siteId, $actorId);
        $number = $this->digits($payload['account_number'] ?? '', 'account_number', 20);
        $label = $this->label($payload['label'] ?? '', 'label');
        $active = array_key_exists('is_active', $payload) ? (int)(bool)$payload['is_active'] : 1;
        if ($id === null) {
            $this->db()->run('INSERT INTO accounting_accounts(chart_id,account_number,label,is_active,archived_at,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,?,?)', [(int)$chart['id'],$number,$label,$active,$active ? null : gmdate('Y-m-d H:i:s'),$actorId,$actorId]);
            $id = $this->db()->lastInsertId();
        } else {
            $this->assertOwned('accounting_accounts', $id, (int)$chart['id']);
            $this->db()->run('UPDATE accounting_accounts SET account_number=?,label=?,is_active=?,archived_at=?,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$number,$label,$active,$active ? null : gmdate('Y-m-d H:i:s'),$actorId,$id]);
        }
        return $this->accountPayload((int)$chart['id'], $id);
    }

    public function deleteAccount(int $siteId, int $id, ?int $actorId): array
    {
        $chart = $this->ensureDefaultStructure($siteId);
        $this->assertOwned('accounting_accounts', $id, (int)$chart['id']);
        $used = (int)($this->db()->one(
            'SELECT (SELECT COUNT(*) FROM accounting_opening_balances WHERE account_id=?)+(SELECT COUNT(*) FROM accounting_journal_lines WHERE account_id=?) AS total',
            [$id,$id]
        )['total'] ?? 0);
        if ($used === 0) {
            $this->db()->run('DELETE FROM accounting_accounts WHERE id=?', [$id]);
            return ['id'=>$id,'deleted'=>true,'archived'=>false];
        }
        $this->db()->run('UPDATE accounting_accounts SET is_active=0,archived_at=CURRENT_TIMESTAMP,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$actorId,$id]);
        return ['id'=>$id,'deleted'=>false,'archived'=>true];
    }

    public function createFiscalPeriod(int $siteId, array $payload, ?int $actorId): array
    {
        $chart = $this->ensureDefaultStructure($siteId, $actorId);
        $code = $this->label($payload['code'] ?? '', 'code', 40);
        $label = $this->label($payload['label'] ?? '', 'label');
        $starts = $this->date($payload['starts_on'] ?? '', 'starts_on');
        $ends = $this->date($payload['ends_on'] ?? '', 'ends_on');
        if ($starts > $ends) throw new InvalidArgumentException('La date de fin doit suivre la date de début.');
        $this->db()->run('INSERT INTO accounting_fiscal_periods(chart_id,code,label,starts_on,ends_on,status,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,"draft",?,?)', [(int)$chart['id'],$code,$label,$starts,$ends,$actorId,$actorId]);
        return $this->db()->one('SELECT * FROM accounting_fiscal_periods WHERE id=?', [$this->db()->lastInsertId()]) ?? [];
    }

    public function saveOpeningBalances(int $siteId, int $periodId, array $balances, ?int $actorId): array
    {
        $chart = $this->ensureDefaultStructure($siteId, $actorId);
        $period = $this->db()->one('SELECT * FROM accounting_fiscal_periods WHERE id=? AND chart_id=?', [$periodId,(int)$chart['id']]);
        if ($period === null) throw new InvalidArgumentException('Exercice comptable introuvable.');
        if ((string)$period['status'] === 'closed') throw new InvalidArgumentException('Un exercice clôturé ne peut plus être modifié.');
        $known = [];
        foreach ($this->db()->all('SELECT id FROM accounting_accounts WHERE chart_id=?', [(int)$chart['id']]) as $row) $known[(int)$row['id']] = true;
        $normalized = [];
        foreach ($balances as $balance) {
            if (!is_array($balance)) throw new InvalidArgumentException('Chaque solde doit être un objet.');
            $accountId = (int)($balance['account_id'] ?? 0);
            if (!isset($known[$accountId])) throw new InvalidArgumentException('Un compte du solde d’ouverture est invalide.');
            $amount = $balance['amount_minor'] ?? null;
            if (!is_int($amount) && !(is_string($amount) && preg_match('/^-?\d+$/', $amount))) throw new InvalidArgumentException('Le montant d’ouverture doit être exprimé en unités mineures entières.');
            $normalized[$accountId] = (int)$amount;
        }
        $this->db()->transaction(function () use ($periodId,$normalized,$actorId): void {
            foreach ($normalized as $accountId => $amount) {
                $this->db()->run(
                    'INSERT INTO accounting_opening_balances(fiscal_period_id,account_id,amount_minor,created_by_iam_user_id,updated_by_iam_user_id)
                     VALUES(?,?,?,?,?) ON CONFLICT(fiscal_period_id,account_id) DO UPDATE SET amount_minor=excluded.amount_minor,updated_by_iam_user_id=excluded.updated_by_iam_user_id,updated_at=CURRENT_TIMESTAMP',
                    [$periodId,$accountId,$amount,$actorId,$actorId]
                );
            }
        });
        return $this->db()->all('SELECT account_id,amount_minor FROM accounting_opening_balances WHERE fiscal_period_id=? ORDER BY account_id', [$periodId]);
    }

    public function normalSide(int $siteId, string $accountNumber): string
    {
        $chart = $this->ensureDefaultStructure($siteId);
        return $this->normalSideForChart($chart, $this->digits($accountNumber, 'account_number', 20));
    }

    public function balanceMinor(int $siteId, string $accountNumber, int $openingMinor, int $debitMinor, int $creditMinor): int
    {
        return $this->normalSide($siteId, $accountNumber) === 'credit'
            ? $openingMinor - $debitMinor + $creditMinor
            : $openingMinor + $debitMinor - $creditMinor;
    }

    private function normalSideForChart(array $chart, string $accountNumber): string
    {
        $rule = $this->db()->one(
            'SELECT increase_side FROM accounting_balance_rules WHERE chart_id=? AND ? LIKE account_prefix || "%" ORDER BY length(account_prefix) DESC,id DESC LIMIT 1',
            [(int)$chart['id'],$accountNumber]
        );
        return (string)($rule['increase_side'] ?? $chart['default_increase_side']);
    }

    private function categoryForChart(int $chartId, string $number): ?array
    {
        return $this->db()->one(
            'SELECT id,account_prefix,label FROM accounting_categories WHERE chart_id=? AND ? LIKE account_prefix || "%" ORDER BY length(account_prefix) DESC,id DESC LIMIT 1',
            [$chartId,$number]
        );
    }

    private function accountPayload(int $chartId, int $id): array
    {
        $account = $this->db()->one('SELECT * FROM accounting_accounts WHERE id=? AND chart_id=?', [$id,$chartId]) ?? [];
        if ($account !== []) {
            $chart = $this->db()->one('SELECT * FROM accounting_charts WHERE id=?', [$chartId]) ?? [];
            $account['normal_side'] = $this->normalSideForChart($chart, (string)$account['account_number']);
            $account['category'] = $this->categoryForChart($chartId, (string)$account['account_number']);
        }
        return $account;
    }

    private function assertOwned(string $table, int $id, int $chartId): void
    {
        $allowed = ['accounting_balance_rules','accounting_categories','accounting_accounts'];
        if (!in_array($table, $allowed, true) || $this->db()->one("SELECT id FROM {$table} WHERE id=? AND chart_id=?", [$id,$chartId]) === null) {
            throw new InvalidArgumentException('Élément comptable introuvable.');
        }
    }

    private function digits(mixed $value, string $field, int $max): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $max || preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} doit contenir uniquement des chiffres.");
        }
        return $value;
    }

    private function label(mixed $value, string $field, int $max = 160): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value) > $max) throw new InvalidArgumentException("{$field} est obligatoire.");
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) throw new InvalidArgumentException("{$field} doit être une date YYYY-MM-DD.");
        return $value;
    }

    private function db(): Database
    {
        return $this->connection->database() ?? throw new RuntimeException('La base Comptabilité n’est pas disponible.');
    }
}
