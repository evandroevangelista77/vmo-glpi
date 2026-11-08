<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Solicitação de mudança de baseline (RN04, RN07): prazo, escopo, custo ou benefício.
 * Só uma mudança aprovada, por alguém diferente de quem solicitou, gera nova revisão da baseline.
 */
class ChangeRequest
{
    public const TYPES = ['schedule' => 'Prazo', 'scope' => 'Escopo', 'cost' => 'Custo', 'benefit' => 'Benefício'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_changes';
    }

    /**
     * Registra a solicitação. $proposed: planned_start, planned_end, scope, budget (valor na moeda da iniciativa).
     * Também chamada pela reabertura de iniciativa encerrada ($system = true dispensa o direito de edição).
     */
    public static function open(array $initiative, string $type, array $proposed, string $justification, bool $system = false): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new RuleException('Tipo de mudança inválido.');
        }
        if (!$system && !Access::canEditInitiative($initiative)) {
            throw RuleException::denied();
        }
        if (trim($justification) === '') {
            throw new RuleException('Toda mudança de baseline exige justificativa.');
        }
        if ((int) $initiative['baseline_revision'] < 1) {
            throw new RuleException('Ainda não há baseline aprovada: edite a iniciativa diretamente.');
        }
        $clean = [];
        foreach (['planned_start', 'planned_end'] as $f) {
            if (isset($proposed[$f]) && $proposed[$f] !== '') {
                $clean[$f] = Db::date($proposed[$f]) ?? throw new RuleException('Data proposta inválida.');
            }
        }
        if (isset($proposed['scope']) && trim((string) $proposed['scope']) !== '') {
            $clean['scope'] = Db::text($proposed['scope'], 20000);
        }
        if (isset($proposed['budget']) && $proposed['budget'] !== '') {
            $n = Calc::parseNumber((string) $proposed['budget']);
            if ($n === null || $n < 0) {
                throw new RuleException('O orçamento proposto deve ser um valor válido e não negativo.');
            }
            $clean['budget'] = [$initiative['currency'] => $n];
        }
        if (!empty($proposed['reopen'])) {
            $clean['reopen'] = true;
        }
        $ps = $clean['planned_start'] ?? $initiative['planned_start'];
        $pe = $clean['planned_end'] ?? $initiative['planned_end'];
        if ($ps && $pe && $ps > $pe) {
            throw new RuleException('O início proposto não pode ser posterior ao término proposto.');
        }
        if (!$clean) {
            throw new RuleException('Informe ao menos uma alteração proposta.');
        }
        $id = Db::insert('changes', [
            'initiatives_id' => (int) $initiative['id'], 'type' => $type, 'proposed' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'justification' => Db::text($justification, 4000), 'base_revision' => (int) $initiative['baseline_revision'],
            'users_id_requester' => (int) Session::getLoginUserID(), 'date_creation' => Db::now(),
        ]);
        Audit::log('ChangeRequest', $id, 'open', ['tipo' => $type, 'proposta' => $clean], 0, (int) $initiative['entities_id']);
        foreach (array_unique([(int) $initiative['users_id_sponsor']]) as $u) {
            Outbox::push($u, 'change', $initiative['code'] . ': solicitação de mudança ' . self::TYPES[$type], "change:$id", 'Initiative', (int) $initiative['id'], $initiative['name'], (int) $initiative['entities_id']);
        }
        return $id;
    }

    public static function canDecide(array $initiative, array $change): bool
    {
        $me = (int) Session::getLoginUserID();
        if ($change['status'] !== 'pending' || !Access::canSeeInitiative($initiative)) {
            return false;
        }
        $authority = Access::canApprove() || $me === (int) $initiative['users_id_sponsor'] || Delegation::actsFor($me, (int) $initiative['users_id_sponsor']);
        return $authority && (int) $change['users_id_requester'] !== $me;
    }

    /** Aprova (gera nova revisão da baseline) ou rejeita a mudança. */
    public static function decide(int $changeId, bool $approve, string $comment): void
    {
        global $DB;
        $change = Db::row('changes', $changeId) ?? throw new RuleException('Solicitação não encontrada.', 404);
        $init = Initiative::load((int) $change['initiatives_id']);
        if ($change['status'] !== 'pending') {
            throw new RuleException('Esta solicitação já foi decidida.');
        }
        if (!self::canDecide($init, $change)) {
            throw RuleException::denied('Quem solicitou a mudança não pode aprová-la, e a decisão exige o direito de aprovar (ou ser o patrocinador/delegado).');
        }
        if (!$approve && trim($comment) === '') {
            throw new RuleException('Informe o motivo da rejeição.');
        }
        Db::transaction(function () use ($change, $init, $approve, $comment, $DB, $changeId) {
            $DB->update(self::getTable(), [
                'status' => $approve ? 'approved' : 'rejected', 'users_id_decider' => (int) Session::getLoginUserID(),
                'decision_comment' => Db::text($comment, 4000) ?: null, 'decided_at' => Db::now(),
            ], ['id' => $changeId, 'status' => 'pending']);
            if ($DB->affectedRows() < 1) {
                throw new RuleException('Esta solicitação já foi decidida por outra pessoa.', 409);
            }
            if ($approve) {
                $proposed = json_decode((string) $change['proposed'], true) ?: [];
                $override = array_intersect_key($proposed, array_flip(['planned_start', 'planned_end', 'scope', 'budget']));
                $bid = Baseline::freeze(Db::row('initiatives', (int) $init['id']), 0, $changeId, $override, $change['justification']);
                $rev = (int) Db::row('baselines', $bid)['revision'];
                $upd = ['baseline_revision' => $rev, 'date_mod' => Db::now(), 'version' => (int) $init['version'] + 1];
                // a baseline mudou: o cenário vigente acompanha as datas aprovadas
                foreach (['planned_start', 'planned_end'] as $f) {
                    if (isset($override[$f])) {
                        $upd[$f] = $override[$f];
                    }
                }
                if (isset($override['planned_end'])) {
                    $upd['forecast_end'] = $override['planned_end'];
                }
                if (isset($override['scope'])) {
                    $upd['description'] = $override['scope'];
                }
                $DB->update(Initiative::getTable(), $upd, ['id' => (int) $init['id']]);
            }
            Audit::log('ChangeRequest', $changeId, $approve ? 'approve' : 'reject', ['comentario' => $comment], 0, (int) $init['entities_id']);
        });
        Outbox::push((int) $change['users_id_requester'], 'change', $init['code'] . ': mudança ' . ($approve ? 'aprovada' : 'rejeitada'), "change-decision:$changeId", 'Initiative', (int) $init['id'], $init['name'], (int) $init['entities_id']);
    }

    public static function forInitiative(int $id): array
    {
        return Db::rows('changes', ['initiatives_id' => $id], ['id DESC']);
    }
}
