<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Dependências término→início entre tarefas ou entre iniciativas (RF08). Defasagem em dias úteis,
 * vínculo rígido ou informativo. Autorrelação e ciclo transitivo são rejeitados de forma atômica.
 */
class Dependency
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_dependencies';
    }

    private static function edges(string $kind): array
    {
        return array_map(fn ($d) => [(int) $d['pred_id'], (int) $d['succ_id']], Db::rows('dependencies', ['kind' => $kind]));
    }

    private static function entityOf(string $kind, int $id): ?array
    {
        if ($kind === 'task') {
            $t = Db::row('tasks', $id);
            if (!$t || $t['is_deleted']) {
                return null;
            }
            $init = Db::row('initiatives', (int) $t['initiatives_id']);
        } else {
            $init = Db::row('initiatives', $id);
        }
        return ($init && !$init['is_deleted'] && Access::canSeeInitiative($init)) ? $init : null;
    }

    public static function add(string $kind, int $pred, int $succ, int $lag, bool $hard): int
    {
        global $DB;
        if (!in_array($kind, ['task', 'initiative'], true)) {
            throw new RuleException('Tipo de dependência inválido.');
        }
        $ip = self::entityOf($kind, $pred);
        $is = self::entityOf($kind, $succ);
        if (!$ip || !$is) {
            throw new RuleException('Os dois lados do vínculo precisam existir e estar acessíveis a você.', 404);
        }
        if (!Access::canEditInitiative($is)) {
            throw RuleException::denied();
        }
        if ((int) $ip['entities_id'] !== (int) $is['entities_id']) {
            throw new RuleException('Dependências só ligam itens da mesma entidade.');
        }
        $lag = max(-30, min(365, $lag));

        return Db::transaction(function () use ($kind, $pred, $succ, $lag, $hard, $is, $DB) {
            // lê o grafo dentro da transação e trava as linhas para que dois vínculos simultâneos não fechem um ciclo
            $DB->doQuery('SELECT `id` FROM `' . Db::t('dependencies') . "` WHERE `kind` = '" . $kind . "' FOR UPDATE");
            if (Calc::wouldCreateCycle(self::edges($kind), $pred, $succ)) {
                throw new RuleException($pred === $succ ? 'Um item não pode depender de si mesmo.' : 'Este vínculo criaria um ciclo de dependências.');
            }
            if (countElementsInTable(self::getTable(), ['kind' => $kind, 'pred_id' => $pred, 'succ_id' => $succ])) {
                throw new RuleException('Este vínculo já existe.');
            }
            $id = Db::insert('dependencies', ['entities_id' => (int) $is['entities_id'], 'kind' => $kind, 'pred_id' => $pred, 'succ_id' => $succ, 'lag_days' => $lag,
                'is_hard' => $hard ? 1 : 0, 'date_creation' => Db::now()]);
            Audit::log('Dependency', $id, 'create', ['tipo' => $kind, 'de' => $pred, 'para' => $succ, 'defasagem' => $lag, 'rigido' => $hard], 0, (int) $is['entities_id']);
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        global $DB;
        $d = Db::row('dependencies', $id) ?? throw new RuleException('Vínculo não encontrado.', 404);
        $is = self::entityOf($d['kind'], (int) $d['succ_id']);
        if (!$is || !Access::canEditInitiative($is)) {
            throw RuleException::denied();
        }
        $DB->delete(self::getTable(), ['id' => $id]);
        Audit::log('Dependency', $id, 'delete', $d, 0, (int) $is['entities_id']);
    }

    /** Libera uma exceção ao vínculo rígido: exige direito de aprovar e motivo; fica na trilha. */
    public static function grantException(int $id, string $reason): void
    {
        global $DB;
        $d = Db::row('dependencies', $id) ?? throw new RuleException('Vínculo não encontrado.', 404);
        if (!Access::canApprove()) {
            throw RuleException::denied('Exceção a vínculo rígido exige o direito de aprovar.');
        }
        if (trim($reason) === '') {
            throw new RuleException('Informe o motivo da exceção.');
        }
        $DB->update(self::getTable(), ['exception_reason' => Db::text($reason, 500), 'exception_users_id' => (int) Session::getLoginUserID(), 'exception_at' => Db::now()], ['id' => $id]);
        Audit::log('Dependency', $id, 'exception', ['motivo' => $reason], 0, (int) $d['entities_id']);
    }

    /** Impede iniciar a tarefa antes da conclusão (e da defasagem) das predecessoras rígidas. */
    public static function assertTaskCanStart(int $taskId): void
    {
        $today = Db::today();
        foreach (Db::rows('dependencies', ['kind' => 'task', 'succ_id' => $taskId, 'is_hard' => 1]) as $d) {
            if (!empty($d['exception_reason'])) {
                continue;
            }
            $p = Db::row('tasks', (int) $d['pred_id']);
            if (!$p || $p['is_deleted'] || $p['status'] === 'cancelled') {
                continue; // a predecessora cancelada não desbloqueia sozinha, mas também não bloqueia: a decisão fica com quem cancelou (documentado)
            }
            if ($p['status'] !== 'done' || !$p['actual_end']) {
                throw new RuleException('Bloqueada: a predecessora "' . $p['name'] . '" ainda não foi concluída.');
            }
            $earliest = Calc::addBusinessDays($p['actual_end'], (int) $d['lag_days'], Settings::holidays());
            if ($earliest > $today) {
                throw new RuleException('Bloqueada: a defasagem de ' . (int) $d['lag_days'] . ' dia(s) útil(eis) após "' . $p['name'] . '" só termina em ' . \Html::convDate($earliest) . '.');
            }
        }
    }

    /** Iniciativa sucessora só começa depois que a predecessora rígida foi entregue. */
    public static function assertInitiativeCanStart(int $initiativeId): void
    {
        foreach (Db::rows('dependencies', ['kind' => 'initiative', 'succ_id' => $initiativeId, 'is_hard' => 1]) as $d) {
            if (!empty($d['exception_reason'])) {
                continue;
            }
            $p = Db::row('initiatives', (int) $d['pred_id']);
            if ($p && !in_array($p['status'], ['delivered', 'measuring', 'closed'], true) && $p['status'] !== 'cancelled') {
                throw new RuleException('Bloqueada: a iniciativa predecessora ' . $p['code'] . ' ainda não foi entregue.');
            }
        }
    }

    /** Vínculos de uma iniciativa (de tarefas dela e da própria iniciativa), já com nomes autorizados. */
    public static function forInitiative(int $initiativeId): array
    {
        global $DB;
        $taskIds = array_keys(Task::forInitiative($initiativeId));
        $where = ['OR' => [['kind' => 'initiative', 'OR' => ['pred_id' => $initiativeId, 'succ_id' => $initiativeId]]]];
        if ($taskIds) {
            $where['OR'][] = ['kind' => 'task', 'OR' => ['pred_id' => $taskIds, 'succ_id' => $taskIds]];
        }
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => ['id ASC']]) as $d) {
            // nomes de itens que o usuário não pode ver não aparecem (RN09)
            $d['pred_name'] = self::label($d['kind'], (int) $d['pred_id']);
            $d['succ_name'] = self::label($d['kind'], (int) $d['succ_id']);
            $out[] = $d;
        }
        return $out;
    }

    public static function label(string $kind, int $id): string
    {
        $init = self::entityOf($kind, $id);
        if (!$init) {
            return 'item restrito';
        }
        if ($kind === 'task') {
            return $init['code'] . ' › ' . (Db::row('tasks', $id)['name'] ?? '?');
        }
        return $init['code'] . ' · ' . $init['name'];
    }
}
