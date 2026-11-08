<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Riscos, problemas e decisões de comitê (RF14). Exposição = probabilidade × impacto (escala 1 a 5).
 */
class Risk
{
    public const STATUSES = ['open' => 'Aberto', 'mitigating' => 'Em mitigação', 'closed' => 'Encerrado'];
    public const KINDS = ['risk' => 'Risco', 'issue' => 'Problema'];
    public const SCALE = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_risks';
    }

    public static function exposureColor(int $e): string
    {
        return $e >= 20 ? '#ef4444' : ($e >= 12 ? '#f59e0b' : '#22c55e');
    }

    public static function save(int $initiativeId, int $id, array $in): int
    {
        global $DB;
        $init = Initiative::load($initiativeId);
        if (!Access::canUpdate() || !Access::canSeeInitiative($init)) {
            throw RuleException::denied();
        }
        $me = (int) Session::getLoginUserID();
        $cur = $id ? (Db::row('risks', $id) ?: throw new RuleException('Registro não encontrado.', 404)) : null;
        if ($cur && ((int) $cur['initiatives_id'] !== $initiativeId || $cur['is_deleted'])) {
            throw new RuleException('Registro não encontrado nesta iniciativa.', 404);
        }
        $title = Db::text($in['title'] ?? '', 255);
        if ($title === '') {
            throw new RuleException('Informe o título.');
        }
        $status = isset(self::STATUSES[$in['status'] ?? '']) ? $in['status'] : 'open';
        $outcome = Db::text($in['outcome'] ?? '', 4000);
        if ($status === 'closed' && $outcome === '') {
            throw new RuleException('Encerrar um risco exige registrar o resultado da resposta.');
        }
        $d = [
            'kind' => isset(self::KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'risk', 'title' => $title, 'cause' => Db::text($in['cause'] ?? '', 4000) ?: null,
            'impact_text' => Db::text($in['impact_text'] ?? '', 4000) ?: null, 'probability' => Db::int($in['probability'] ?? 3, 1, 5, 3), 'impact' => Db::int($in['impact'] ?? 3, 1, 5, 3),
            'response' => Db::text($in['response'] ?? '', 4000) ?: null, 'users_id_owner' => max(0, (int) ($in['users_id_owner'] ?? 0)),
            'due_date' => Db::date($in['due_date'] ?? ''), 'status' => $status, 'outcome' => $outcome ?: null, 'date_mod' => Db::now(),
        ];
        if ($cur) {
            $version = Db::updateVersioned('risks', $id, $d, (int) ($in['version'] ?? $cur['version']), 'registro de risco');
            Audit::log('Risk', $id, 'update', Audit::diff($cur, $d, array_keys($d)), $version, (int) $init['entities_id']);
        } else {
            $id = Db::insert('risks', $d + ['initiatives_id' => $initiativeId, 'date_creation' => Db::now()]);
            Audit::log('Risk', $id, 'create', ['titulo' => $title, 'exposicao' => Calc::riskExposure($d['probability'], $d['impact'])], 1, (int) $init['entities_id']);
            $e = Calc::riskExposure($d['probability'], $d['impact']);
            if ($e >= (int) Settings::thresholds()['risk_red']) {
                Outbox::toOwners($init, 'risk', $init['code'] . ': risco crítico (' . $e . ') — ' . $title, "risk:$id");
            }
        }
        $DB->update(Initiative::getTable(), ['last_update_at' => Db::now()], ['id' => $initiativeId]);
        return $id;
    }

    public static function forInitiative(int $initiativeId): array
    {
        return Db::rows('risks', ['initiatives_id' => $initiativeId, 'is_deleted' => 0], ['status ASC', 'id DESC']);
    }

    /** Decisões do comitê: imutáveis, com autor e data. */
    public static function decide(int $initiativeId, string $title, string $text): int
    {
        $init = Initiative::load($initiativeId);
        if (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        if (Db::text($title) === '') {
            throw new RuleException('Informe o título da decisão.');
        }
        $id = Db::insert('decisions', ['initiatives_id' => $initiativeId, 'title' => Db::text($title, 255), 'decision_text' => Db::text($text, 8000) ?: null,
            'users_id' => (int) Session::getLoginUserID(), 'decided_at' => Db::now()]);
        Audit::log('Decision', $id, 'create', ['titulo' => $title], 0, (int) $init['entities_id']);
        return $id;
    }

    public static function decisions(int $initiativeId): array
    {
        return Db::rows('decisions', ['initiatives_id' => $initiativeId], ['id DESC']);
    }
}
