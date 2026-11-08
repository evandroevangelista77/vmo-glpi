<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Orçamento e custos (RF10). Previsão atual (kind=forecast) e lançamentos reais por competência (kind=actual).
 * O orçamento da baseline é a previsão congelada na aprovação. Ajuste de lançamento real só por estorno vinculado.
 */
class Cost
{
    public const KINDS = ['forecast' => 'Previsão atual', 'actual' => 'Real (competência)'];
    public const TYPES = ['capex' => 'CAPEX', 'opex' => 'OPEX'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_costs';
    }

    public static function forInitiative(int $initiativeId): array
    {
        return Db::rows('costs', ['initiatives_id' => $initiativeId], ['competence_date DESC', 'id DESC']);
    }

    public static function add(int $initiativeId, array $in): int
    {
        $init = Initiative::load($initiativeId, true);
        if (!Access::canFinance()) {
            throw RuleException::denied('Lançar custos exige o direito financeiro.');
        }
        if (in_array($init['status'], ['cancelled', 'rejected'], true)) {
            throw new RuleException('Iniciativa ' . mb_strtolower(Initiative::STATUSES[$init['status']][0]) . ': lançamentos bloqueados.');
        }
        $kind = $in['kind'] ?? '';
        if (!isset(self::KINDS[$kind])) {
            throw new RuleException('Escolha se o lançamento é previsão ou valor real.');
        }
        $amount = Calc::parseNumber((string) ($in['amount'] ?? ''));
        if ($amount === null || $amount <= 0) {
            throw new RuleException('Informe um valor positivo (correções de valor real são feitas por estorno).');
        }
        $date = Db::date($in['competence_date'] ?? '');
        if (!$date) {
            throw new RuleException('Informe a data de competência.');
        }
        if ($kind === 'actual' && $date > Db::today()) {
            throw new RuleException('Custo real não pode ter competência no futuro.');
        }
        $currency = strtoupper(Db::text($in['currency'] ?? $init['currency'], 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuleException('Moeda inválida (use o código ISO, ex.: BRL).');
        }
        $type = isset(self::TYPES[$in['cost_type'] ?? '']) ? $in['cost_type'] : 'opex';
        $key = Db::text($in['external_key'] ?? '', 80) ?: null;
        $source = Db::text($in['source'] ?? 'manual', 30) ?: 'manual';
        if ($key !== null && countElementsInTable(self::getTable(), ['source' => $source, 'external_key' => $key])) {
            throw new RuleException('Lançamento duplicado: já existe um custo com esta origem e chave externa.');
        }
        $id = Db::insert('costs', [
            'initiatives_id' => $initiativeId, 'kind' => $kind, 'category' => Db::text($in['category'] ?? '', 60) ?: null, 'cost_type' => $type,
            'competence_date' => $date, 'amount' => $amount, 'currency' => $currency, 'description' => Db::text($in['description'] ?? '', 255) ?: null,
            'source' => $source, 'external_key' => $key, 'evidence' => Db::text($in['evidence'] ?? '', 500) ?: null,
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now(),
        ]);
        Audit::log('Cost', $id, 'create', ['tipo' => $kind, 'valor' => $amount, 'moeda' => $currency, 'competencia' => $date], 0, (int) $init['entities_id']);
        return $id;
    }

    /** Estorno: nova linha negativa vinculada; o lançamento original permanece. */
    public static function reverse(int $id, string $reason): int
    {
        $orig = Db::row('costs', $id) ?? throw new RuleException('Lançamento não encontrado.', 404);
        $init = Initiative::load((int) $orig['initiatives_id'], true);
        if (!Access::canFinance()) {
            throw RuleException::denied();
        }
        if ($orig['kind'] !== 'actual') {
            throw new RuleException('Só lançamentos reais são estornados; a previsão é ajustada com novos lançamentos de previsão.');
        }
        if ((float) $orig['amount'] <= 0 || (int) $orig['reversal_of_id'] > 0) {
            throw new RuleException('Um estorno não pode ser estornado.');
        }
        if (countElementsInTable(self::getTable(), ['reversal_of_id' => $id])) {
            throw new RuleException('Este lançamento já foi estornado.');
        }
        if (Db::text($reason) === '') {
            throw new RuleException('Informe o motivo do estorno.');
        }
        $rid = Db::insert('costs', [
            'initiatives_id' => (int) $orig['initiatives_id'], 'kind' => 'actual', 'category' => $orig['category'], 'cost_type' => $orig['cost_type'],
            'competence_date' => $orig['competence_date'], 'amount' => -1 * (float) $orig['amount'], 'currency' => $orig['currency'],
            'description' => 'Estorno: ' . Db::text($reason, 230), 'source' => 'estorno', 'external_key' => 'rev-' . $id, 'reversal_of_id' => $id,
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now(),
        ]);
        Audit::log('Cost', $rid, 'reverse', ['original' => $id, 'motivo' => $reason], 0, (int) $init['entities_id']);
        return $rid;
    }

    /** Totais por moeda: [moeda => [forecast, actual, capex, opex]]. */
    public static function totals(int $initiativeId): array
    {
        $t = [];
        foreach (self::forInitiative($initiativeId) as $c) {
            $cur = $c['currency'];
            $t[$cur] ??= ['forecast' => 0.0, 'actual' => 0.0, 'capex' => 0.0, 'opex' => 0.0];
            $t[$cur][$c['kind']] += (float) $c['amount'];
            if ($c['kind'] === 'actual') {
                $t[$cur][$c['cost_type']] += (float) $c['amount'];
            }
        }
        return $t;
    }
}
