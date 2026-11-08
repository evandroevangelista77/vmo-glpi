<?php

namespace GlpiPlugin\Vmo;

/**
 * Baseline aprovada (RN04): imutável. Mudanças de prazo, escopo, custo ou benefício geram nova revisão
 * com justificativa, aprovador e comparação com a anterior. Ajustar a previsão corrente não reescreve a baseline.
 * Esta classe só insere; não existe atualização nem exclusão.
 */
class Baseline
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_baselines';
    }

    public static function latest(int $initiativeId): ?array
    {
        $rows = Db::rows('baselines', ['initiatives_id' => $initiativeId], ['revision DESC'], 1);
        return $rows[0] ?? null;
    }

    public static function all(int $initiativeId): array
    {
        return Db::rows('baselines', ['initiatives_id' => $initiativeId], ['revision ASC']);
    }

    /**
     * Congela a baseline da iniciativa. $override substitui campos propostos por uma mudança aprovada
     * (planned_start, planned_end, scope, budget[moeda=>valor]).
     *
     * @return int id da baseline criada
     */
    public static function freeze(array $initiative, int $approvalId, int $changeId = 0, array $override = [], string $reason = '', int $approver = 0): int
    {
        $id = (int) $initiative['id'];
        $prev = self::latest($id);
        $revision = $prev ? (int) $prev['revision'] + 1 : 1;

        // orçamento = previsão atual por moeda (lançamentos kind=forecast), salvo valor aprovado na mudança
        $budget = [];
        foreach (Db::rows('costs', ['initiatives_id' => $id, 'kind' => 'forecast']) as $c) {
            $budget[$c['currency']] = round(($budget[$c['currency']] ?? 0) + (float) $c['amount'], 4);
        }
        if ($prev && !isset($override['budget'])) {
            $budget = json_decode((string) $prev['budget_json'], true) ?: $budget;
        }
        if (isset($override['budget'])) {
            $budget = $override['budget'];
        }

        $plan = [];
        foreach (Db::rows('allocations', ['initiatives_id' => $id]) as $a) {
            $plan[] = [
                'benefit' => (int) $a['benefits_id'], 'percent' => (float) $a['percent'],
                'plans' => array_map(fn ($p) => ['period' => $p['period'], 'amount' => (float) $p['planned_amount']], Db::rows('benefit_plans', ['benefits_id' => (int) $a['benefits_id']], ['period ASC'])),
            ];
        }
        $tasks = array_map(fn ($t) => ['id' => (int) $t['id'], 'parent_id' => (int) $t['parent_id'], 'name' => $t['name'], 'weight' => (float) $t['weight'], 'planned_start' => $t['planned_start'], 'planned_end' => $t['planned_end']],
            Db::rows('tasks', ['initiatives_id' => $id, 'is_deleted' => 0]));

        $data = [
            'initiatives_id'        => $id,
            'revision'              => $revision,
            'scope'                 => $override['scope'] ?? ($prev['scope'] ?? $initiative['description']),
            'planned_start'         => $override['planned_start'] ?? ($prev['planned_start'] ?? $initiative['planned_start']),
            'planned_end'           => $override['planned_end'] ?? ($prev['planned_end'] ?? $initiative['planned_end']),
            'budget_json'           => json_encode($budget, JSON_UNESCAPED_UNICODE),
            'currency'              => $initiative['currency'],
            'benefit_plan_snapshot' => json_encode($plan, JSON_UNESCAPED_UNICODE),
            'tasks_snapshot'        => json_encode($tasks, JSON_UNESCAPED_UNICODE),
            'formula_version'       => Calc::FORMULA_VERSION,
            'thresholds_version'    => Settings::thresholdsVersion(),
            'approvals_id'          => $approvalId,
            'changes_id'            => $changeId,
            'reason'                => $reason,
            'users_id_approver'     => $approver ?: (int) \Session::getLoginUserID(),
            'approved_at'           => Db::now(),
        ];
        $data['hash'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
        $bid = Db::insert('baselines', $data);
        Audit::log('Baseline', $bid, 'freeze', ['initiative' => $id, 'revision' => $revision, 'hash' => $data['hash']], $revision, (int) $initiative['entities_id']);
        return $bid;
    }

    /** Comparação campo a campo entre duas revisões (para a tela e para o relatório). */
    public static function compare(array $a, array $b): array
    {
        $out = [];
        foreach (['scope' => 'Escopo', 'planned_start' => 'Início planejado', 'planned_end' => 'Término planejado'] as $f => $label) {
            if ((string) $a[$f] !== (string) $b[$f]) {
                $out[$label] = [$a[$f], $b[$f]];
            }
        }
        $ba = json_decode((string) $a['budget_json'], true) ?: [];
        $bb = json_decode((string) $b['budget_json'], true) ?: [];
        foreach (array_unique(array_merge(array_keys($ba), array_keys($bb))) as $cur) {
            if ((float) ($ba[$cur] ?? 0) !== (float) ($bb[$cur] ?? 0)) {
                $out["Orçamento ($cur)"] = [number_format((float) ($ba[$cur] ?? 0), 2, ',', '.'), number_format((float) ($bb[$cur] ?? 0), 2, ',', '.')];
            }
        }
        return $out;
    }

    /** Verifica a integridade (hash) de uma revisão gravada. */
    public static function verify(array $row): bool
    {
        $copy = $row;
        unset($copy['id'], $copy['hash']);
        // a ordem das chaves segue a da inserção; recalcula sem 'id'
        $order = ['initiatives_id', 'revision', 'scope', 'planned_start', 'planned_end', 'budget_json', 'currency', 'benefit_plan_snapshot', 'tasks_snapshot', 'formula_version',
            'thresholds_version', 'approvals_id', 'changes_id', 'reason', 'users_id_approver', 'approved_at'];
        $data = [];
        $ints = ['initiatives_id', 'revision', 'thresholds_version', 'approvals_id', 'changes_id', 'users_id_approver'];
        foreach ($order as $k) {
            $data[$k] = in_array($k, $ints, true) ? (int) $copy[$k] : $copy[$k];
        }
        return hash_equals($row['hash'], hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE)));
    }
}
