<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Plano de benefícios (RF11): financeiros e não financeiros, com dono, validador, meta, horizonte e alocação
 * entre iniciativas. Benefício previsto, declarado e validado são valores distintos (RN05).
 */
class Benefit
{
    public const TYPES = ['financial' => 'Financeiro', 'nonfinancial' => 'Não financeiro'];
    public const DIRECTIONS = ['increase' => 'Aumentar', 'reduce' => 'Reduzir'];
    public const CADENCES = ['monthly' => 'Mensal'];
    public const STATUSES = ['draft' => ['Rascunho', '#94a3b8'], 'approved' => ['Aprovado', '#22c55e'], 'closed' => ['Encerrado', '#64748b']];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_benefits';
    }

    /** Benefícios visíveis (entidade ativa); a alocação decide quais iniciativas os mostram. */
    public static function visible(): array
    {
        return Db::rows('benefits', ['is_deleted' => 0] + Access::entityCriteria(self::getTable()), ['name ASC']);
    }

    public static function load(int $id): array
    {
        $b = Db::row('benefits', $id);
        if (!$b || $b['is_deleted'] || !Access::canView() || !in_array((int) $b['entities_id'], array_map('intval', $_SESSION['glpiactiveentities'] ?? []), true)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        return $b;
    }

    public static function canEdit(array $b): bool
    {
        $me = (int) Session::getLoginUserID();
        return Access::canUpdate() && ($me === (int) $b['users_id_owner'] || $me === (int) $b['users_id_author'] || Access::canApprove() || Access::canConfigure());
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
        return substr($s ?: 'beneficio', 0, 50);
    }

    public static function save(int $id, array $in, int $expectedVersion = 0): int
    {
        if (!$id && !Access::canCreate()) {
            throw RuleException::denied();
        }
        $cur = $id ? self::load($id) : null;
        if ($cur && !self::canEdit($cur)) {
            throw RuleException::denied();
        }
        if ($cur && $cur['status'] === 'closed') {
            throw new RuleException('Benefício encerrado não aceita edição.');
        }
        $entity = (int) ($cur['entities_id'] ?? $in['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
        if (!Session::haveAccessToEntity($entity)) {
            throw RuleException::denied('Sem acesso à entidade escolhida.');
        }
        $type = isset(self::TYPES[$in['type'] ?? '']) ? $in['type'] : 'financial';
        $currency = $type === 'financial' ? strtoupper(Db::text($in['currency'] ?? '', 3)) : '';
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuleException('Moeda inválida (use o código ISO, ex.: BRL).');
        }
        $name = Db::text($in['name'] ?? '', 255);
        if ($name === '') {
            throw new RuleException('Informe o nome do benefício.');
        }
        $hs = Db::date($in['horizon_start'] ?? '');
        $he = Db::date($in['horizon_end'] ?? '');
        if ($hs && $he && $hs > $he) {
            throw new RuleException('O início do horizonte não pode ser posterior ao fim.');
        }
        $d = [
            'name' => $name, 'description' => Db::text($in['description'] ?? '', 10000) ?: null, 'type' => $type, 'unit' => Db::text($in['unit'] ?? '', 40) ?: null,
            'currency' => $currency ?: null, 'direction' => isset(self::DIRECTIONS[$in['direction'] ?? '']) ? $in['direction'] : 'increase',
            'baseline_value' => Calc::parseNumber((string) ($in['baseline_value'] ?? '')), 'target' => Calc::parseNumber((string) ($in['target'] ?? '')),
            'horizon_start' => $hs, 'horizon_end' => $he, 'method' => Db::text($in['method'] ?? '', 5000) ?: null, 'source' => Db::text($in['source'] ?? '', 255) ?: null,
            'rubric' => Db::text($in['rubric'] ?? '', 5000) ?: null, 'attribution_note' => Db::text($in['attribution_note'] ?? '', 255) ?: null,
            'users_id_owner' => max(0, (int) ($in['users_id_owner'] ?? 0)), 'users_id_validator' => max(0, (int) ($in['users_id_validator'] ?? 0)),
            'date_mod' => Db::now(),
        ];
        if ($d['users_id_owner'] && $d['users_id_owner'] === $d['users_id_validator']) {
            throw new RuleException('O dono e o validador do benefício precisam ser pessoas diferentes.');
        }
        if ($cur) {
            if ($cur['status'] === 'approved') {
                foreach (['type', 'currency', 'direction', 'baseline_value', 'target'] as $f) {
                    if ((string) $d[$f] !== (string) $cur[$f]) {
                        throw new RuleException('Tipo, moeda, direção, baseline e meta de benefício aprovado só mudam por solicitação de mudança da iniciativa.');
                    }
                }
            }
            $version = Db::updateVersioned('benefits', $id, $d, $expectedVersion ?: (int) $cur['version'], 'benefício');
            Audit::log('Benefit', $id, 'update', Audit::diff($cur, $d, array_keys($d)), $version, $entity);
            return $id;
        }
        $key = Db::text($in['economic_key'] ?? '', 60) ?: self::slug($name);
        if (countElementsInTable(self::getTable(), ['entities_id' => $entity, 'economic_key' => $key])) {
            throw new RuleException("Já existe um benefício com a chave econômica \"$key\" nesta entidade.");
        }
        $d += ['entities_id' => $entity, 'economic_key' => $key, 'users_id_author' => (int) Session::getLoginUserID(), 'status' => 'draft', 'date_creation' => Db::now()];
        $id = Db::insert('benefits', $d);
        Audit::log('Benefit', $id, 'create', ['nome' => $name, 'chave' => $key], 1, $entity);
        return $id;
    }

    /** Aprovação: financeiro exige moeda e horizonte; qualitativo exige rubrica. Autor não aprova o próprio. */
    public static function approve(int $id): void
    {
        global $DB;
        $b = self::load($id);
        if (!Access::canApprove()) {
            throw RuleException::denied('Aprovar benefício exige o direito de aprovar.');
        }
        if ((int) $b['users_id_author'] === (int) Session::getLoginUserID()) {
            throw RuleException::denied('Quem cadastrou o benefício não pode aprová-lo.');
        }
        if ($b['status'] !== 'draft') {
            throw new RuleException('Só benefícios em rascunho são aprovados.');
        }
        $miss = [];
        if ($b['type'] === 'financial') {
            if (!$b['currency']) {
                $miss[] = 'moeda';
            }
            if (!$b['horizon_start'] || !$b['horizon_end']) {
                $miss[] = 'horizonte';
            }
        } elseif (trim((string) $b['rubric']) === '') {
            $miss[] = 'rubrica';
        }
        if (!(int) $b['users_id_owner']) {
            $miss[] = 'dono';
        }
        if (!(int) $b['users_id_validator']) {
            $miss[] = 'validador';
        }
        if ($miss) {
            throw new RuleException('Benefício não pode ser aprovado sem: ' . implode(', ', $miss) . '.');
        }
        if (!countElementsInTable(Db::t('allocations'), ['benefits_id' => $id])) {
            throw new RuleException('Aloque o benefício a ao menos uma iniciativa antes de aprová-lo.');
        }
        $DB->update(self::getTable(), ['status' => 'approved', 'users_id_approver' => (int) Session::getLoginUserID(), 'approved_at' => Db::now(), 'date_mod' => Db::now(), 'version' => (int) $b['version'] + 1], ['id' => $id]);
        Audit::log('Benefit', $id, 'approve', [], (int) $b['version'] + 1, (int) $b['entities_id']);
        Outbox::push((int) $b['users_id_owner'], 'benefit', 'Benefício aprovado: ' . $b['name'], "benefit-approved:$id", 'Benefit', $id, '', (int) $b['entities_id']);
    }

    // ---------- alocação ----------

    public static function allocations(int $benefitId): array
    {
        return Db::rows('allocations', ['benefits_id' => $benefitId], ['id ASC']);
    }

    /** Aloca o benefício (percentual) a uma iniciativa. A soma das alocações não passa de 100%. */
    public static function allocate(int $benefitId, int $initiativeId, string $percent): int
    {
        $b = self::load($benefitId);
        if (!self::canEdit($b)) {
            throw RuleException::denied();
        }
        $init = Initiative::load($initiativeId);
        if ((int) $init['entities_id'] !== (int) $b['entities_id']) {
            throw new RuleException('Benefício e iniciativa precisam ser da mesma entidade.');
        }
        $p = Calc::parseNumber($percent);
        if ($p === null || $p <= 0 || $p > 100) {
            throw new RuleException('O percentual de atribuição deve ser maior que 0 e no máximo 100.');
        }
        $sum = 0.0;
        foreach (self::allocations($benefitId) as $a) {
            if ((int) $a['initiatives_id'] === $initiativeId) {
                throw new RuleException('Este benefício já está alocado a esta iniciativa; remova a alocação para refazê-la.');
            }
            $sum += (float) $a['percent'];
        }
        if ($sum + $p > 100.0001) {
            throw new RuleException(sprintf('A soma das alocações não pode passar de 100%% (já alocado: %s%%).', rtrim(rtrim(number_format($sum, 3, ',', ''), '0'), ',')));
        }
        $id = Db::insert('allocations', ['benefits_id' => $benefitId, 'initiatives_id' => $initiativeId, 'percent' => $p, 'date_creation' => Db::now()]);
        Audit::log('Benefit', $benefitId, 'allocate', ['iniciativa' => $initiativeId, 'percentual' => $p], 0, (int) $b['entities_id']);
        return $id;
    }

    public static function unallocate(int $allocationId): void
    {
        global $DB;
        $a = Db::row('allocations', $allocationId) ?? throw new RuleException('Alocação não encontrada.', 404);
        $b = self::load((int) $a['benefits_id']);
        if (!self::canEdit($b)) {
            throw RuleException::denied();
        }
        if ($b['status'] === 'approved' && countElementsInTable(Db::t('measurements'), ['benefits_id' => (int) $b['id']])) {
            throw new RuleException('Benefício com medições não pode perder alocação: ajuste por solicitação de mudança.');
        }
        $DB->delete(Db::t('allocations'), ['id' => $allocationId]);
        Audit::log('Benefit', (int) $b['id'], 'unallocate', ['iniciativa' => $a['initiatives_id']], 0, (int) $b['entities_id']);
    }

    // ---------- plano por período ----------

    public static function plans(int $benefitId): array
    {
        return Db::rows('benefit_plans', ['benefits_id' => $benefitId], ['period ASC']);
    }

    public static function setPlan(int $benefitId, string $period, string $amount): void
    {
        global $DB;
        $b = self::load($benefitId);
        if (!self::canEdit($b) || !Access::canFinance()) {
            throw RuleException::denied();
        }
        $p = self::periodStart($period);
        $v = Calc::parseNumber($amount);
        if (!$p || $v === null || $v < 0) {
            throw new RuleException('Informe o período (mês) e um valor previsto não negativo.');
        }
        if ($b['horizon_start'] && $p < substr($b['horizon_start'], 0, 7) . '-01' || $b['horizon_end'] && $p > $b['horizon_end']) {
            throw new RuleException('O período está fora do horizonte do benefício.');
        }
        $cur = Db::rows('benefit_plans', ['benefits_id' => $benefitId, 'period' => $p])[0] ?? null;
        if ($cur) {
            $DB->update(Db::t('benefit_plans'), ['planned_amount' => $v, 'revision' => (int) $cur['revision'] + 1], ['id' => $cur['id']]);
        } else {
            Db::insert('benefit_plans', ['benefits_id' => $benefitId, 'period' => $p, 'planned_amount' => $v, 'date_creation' => Db::now()]);
        }
        Audit::log('Benefit', $benefitId, 'plan', ['periodo' => $p, 'previsto' => $v], 0, (int) $b['entities_id']);
    }

    /** Normaliza "2026-11", "2026-11-15" ou "11/2026" para o primeiro dia do mês. */
    public static function periodStart(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $s, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return "{$m[1]}-{$m[2]}-01";
        }
        if (preg_match('/^(\d{2})\/(\d{4})$/', $s, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return "{$m[2]}-{$m[1]}-01";
        }
        return null;
    }

    // ---------- valores por iniciativa (previsto × validado) ----------

    /**
     * Previsto e validado de benefícios financeiros APROVADOS por iniciativa, já multiplicados
     * pelo percentual de alocação, por moeda. O portfólio soma esses quinhões: um benefício
     * compartilhado entra uma vez (60% + 40% = 100%), nunca em duplicidade.
     *
     * @return array<int,array{planned:array<string,float>,validated:array<string,float>}>
     */
    public static function valueByInitiative(array $initiativeIds, string $cutoff): array
    {
        global $DB;
        $out = [];
        if (!$initiativeIds) {
            return $out;
        }
        $allocs = [];
        foreach ($DB->request(['FROM' => Db::t('allocations'), 'WHERE' => ['initiatives_id' => $initiativeIds]]) as $a) {
            $allocs[(int) $a['benefits_id']][] = $a;
        }
        if (!$allocs) {
            return $out;
        }
        $benefits = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => array_keys($allocs), 'status' => ['approved', 'closed'], 'type' => 'financial', 'is_deleted' => 0]]) as $b) {
            $benefits[(int) $b['id']] = $b;
        }
        if (!$benefits) {
            return $out;
        }
        $plan = [];
        foreach ($DB->request(['FROM' => Db::t('benefit_plans'), 'WHERE' => ['benefits_id' => array_keys($benefits), 'period' => ['<=', $cutoff]]]) as $p) {
            $plan[(int) $p['benefits_id']][] = $p;
        }
        $meas = [];
        foreach ($DB->request(['FROM' => Db::t('measurements'), 'WHERE' => ['benefits_id' => array_keys($benefits), 'status' => 'validated', 'is_current' => 1, 'period' => ['<=', $cutoff]]]) as $m) {
            $meas[(int) $m['benefits_id']][] = $m;
        }
        foreach ($benefits as $bid => $b) {
            $cur = (string) $b['currency'];
            foreach ($allocs[$bid] as $a) {
                $share = (float) $a['percent'] / 100;
                $iid = (int) $a['initiatives_id'];
                $out[$iid] ??= ['planned' => [], 'validated' => []];
                foreach ($plan[$bid] ?? [] as $p) {
                    if (self::inWindow($p['period'], $a)) {
                        $out[$iid]['planned'][$cur] = round(($out[$iid]['planned'][$cur] ?? 0) + (float) $p['planned_amount'] * $share, 4);
                    }
                }
                foreach ($meas[$bid] ?? [] as $m) {
                    if (self::inWindow($m['period'], $a)) {
                        $out[$iid]['validated'][$cur] = round(($out[$iid]['validated'][$cur] ?? 0) + (float) $m['validated_amount'] * $share, 4);
                    }
                }
            }
        }
        return $out;
    }

    private static function inWindow(string $period, array $alloc): bool
    {
        return (empty($alloc['valid_from']) || $period >= $alloc['valid_from']) && (empty($alloc['valid_to']) || $period <= $alloc['valid_to']);
    }

    /** Períodos (mês) vencidos sem medição vigente (declarada ou validada) dentro do horizonte. */
    public static function overduePeriods(array $b, ?string $today = null): array
    {
        if ($b['status'] !== 'approved' || !$b['horizon_start']) {
            return [];
        }
        $today ??= Db::today();
        $end = min($b['horizon_end'] ?: $today, $today);
        $have = [];
        foreach (Db::rows('measurements', ['benefits_id' => (int) $b['id'], 'is_current' => 1, 'status' => ['declared', 'validated', 'returned']]) as $m) {
            $have[$m['period']] = true;
        }
        $out = [];
        $d = new \DateTimeImmutable(substr($b['horizon_start'], 0, 7) . '-01');
        $limit = new \DateTimeImmutable(substr($end, 0, 7) . '-01');
        // o mês corrente só vence depois que acaba
        while ($d < $limit) {
            if (!isset($have[$d->format('Y-m-d')])) {
                $out[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 month');
        }
        return $out;
    }
}
