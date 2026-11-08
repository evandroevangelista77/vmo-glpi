<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Tarefas hierárquicas, marcos e progresso (RF06, RF07).
 */
class Task
{
    public const MAX_DEPTH = 5;

    public const STATUSES = [
        'todo'      => ['A fazer', '#94a3b8', 'ti-circle'],
        'doing'     => ['Em andamento', '#6366f1', 'ti-player-play'],
        'done'      => ['Concluída', '#22c55e', 'ti-circle-check'],
        'cancelled' => ['Cancelada', '#6b7280', 'ti-ban'],
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_tasks';
    }

    public static function forInitiative(int $initiativeId): array
    {
        $rows = [];
        foreach (Db::rows('tasks', ['initiatives_id' => $initiativeId, 'is_deleted' => 0], ['planned_end ASC', 'id ASC']) as $t) {
            $rows[(int) $t['id']] = $t;
        }
        return $rows;
    }

    /** Ordem de exibição em árvore: [id, nível]. */
    public static function tree(array $tasks): array
    {
        $by = [];
        foreach ($tasks as $t) {
            $by[(int) $t['parent_id']][] = (int) $t['id'];
        }
        $out = [];
        $walk = function (int $parent, int $level) use (&$walk, &$out, $by): void {
            foreach ($by[$parent] ?? [] as $id) {
                $out[] = [$id, $level];
                $walk($id, $level + 1);
            }
        };
        // tarefas cujo pai não existe mais aparecem na raiz
        $walk(0, 0);
        return $out;
    }

    /** O usuário pode atualizar o progresso: é o responsável da tarefa ou edita a iniciativa. */
    private static function canProgress(array $task, array $initiative): bool
    {
        return Access::canSeeInitiative($initiative) && Access::canUpdate()
            && ((int) $task['users_id_responsible'] === (int) Session::getLoginUserID() || Access::canEditInitiative($initiative));
    }

    private static function clean(array $in): array
    {
        $d = [
            'name'                 => Db::text($in['name'] ?? '', 255),
            'description'          => Db::text($in['description'] ?? '', 10000) ?: null,
            'users_id_responsible' => max(0, (int) ($in['users_id_responsible'] ?? 0)),
            'groups_id'            => max(0, (int) ($in['groups_id'] ?? 0)),
            'priority'             => Db::int($in['priority'] ?? 3, 1, 5, 3),
            'planned_start'        => Db::date($in['planned_start'] ?? ''),
            'planned_end'          => Db::date($in['planned_end'] ?? ''),
            'is_milestone'         => empty($in['is_milestone']) ? 0 : 1,
            'is_required'          => isset($in['is_required']) && $in['is_required'] === '0' ? 0 : 1,
            'checklist'            => null,
        ];
        if ($d['name'] === '') {
            throw new RuleException('Informe o título da tarefa.');
        }
        if ($d['planned_start'] && $d['planned_end'] && $d['planned_start'] > $d['planned_end']) {
            throw new RuleException('O início da tarefa não pode ser posterior ao término.');
        }
        $effort = Calc::parseNumber((string) ($in['effort_plan'] ?? '0')) ?? 0.0;
        if ($effort < 0) {
            throw new RuleException('O esforço planejado não pode ser negativo.');
        }
        $d['effort_plan'] = $effort;
        // marcos têm peso zero por padrão (não entram na média ponderada)
        $weight = Calc::parseNumber((string) ($in['weight'] ?? '')) ?? ($d['is_milestone'] ? 0.0 : 1.0);
        if ($weight < 0 || ($weight == 0.0 && !$d['is_milestone'])) {
            throw new RuleException('O peso da tarefa deve ser positivo (marcos podem ter peso zero).');
        }
        $d['weight'] = $weight;
        if (!empty($in['checklist'])) {
            $items = array_values(array_filter(array_map(fn ($l) => Db::text($l, 200), preg_split('/\R/', (string) $in['checklist']) ?: [])));
            $d['checklist'] = $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;
        }
        return $d;
    }

    /** Cria ou edita a tarefa. $id = 0 cria. */
    public static function save(int $initiativeId, int $id, array $in, int $expectedVersion = 0): int
    {
        $init = Initiative::load($initiativeId, true);
        if (in_array($init['status'], ['closed', 'cancelled', 'rejected'], true)) {
            throw new RuleException('Iniciativa ' . mb_strtolower(Initiative::STATUSES[$init['status']][0]) . ': não é possível alterar tarefas.');
        }
        $d = self::clean($in);
        $all = self::forInitiative($initiativeId);
        $parent = (int) ($in['parent_id'] ?? 0);
        if ($parent > 0 && !isset($all[$parent])) {
            throw new RuleException('A tarefa-pai precisa pertencer à mesma iniciativa.');
        }
        $parentOf = array_map(fn ($t) => (int) $t['parent_id'], $all);
        if ($id > 0) {
            if (!isset($all[$id])) {
                throw new RuleException('Tarefa não encontrada nesta iniciativa.', 404);
            }
            if ($parent === $id) {
                throw new RuleException('Uma tarefa não pode ser filha de si mesma.');
            }
            $parentOf[$id] = $parent;
            if (Calc::depth($parentOf, $id) === 0 || ($parent > 0 && Calc::depth($parentOf, $parent) === 0)) {
                throw new RuleException('Essa mudança de pai criaria um ciclo na hierarquia.');
            }
            $depth = Calc::depth($parentOf, $id) + Calc::height($parentOf, $id) - 1;
        } else {
            $parentOf[-1] = $parent;
            $depth = Calc::depth($parentOf, -1);
        }
        if ($depth > self::MAX_DEPTH) {
            throw new RuleException('A hierarquia de tarefas é limitada a ' . self::MAX_DEPTH . ' níveis.');
        }
        $d['parent_id'] = $parent;
        $d['date_mod'] = Db::now();

        if ($id > 0) {
            $version = Db::updateVersioned('tasks', $id, $d, $expectedVersion ?: (int) $all[$id]['version'], 'tarefa');
            Audit::log('Task', $id, 'update', Audit::diff($all[$id], $d, array_keys($d)), $version, (int) $init['entities_id']);
        } else {
            $d += ['initiatives_id' => $initiativeId, 'date_creation' => Db::now(), 'status' => 'todo', 'progress' => 0];
            $id = Db::insert('tasks', $d);
            Audit::log('Task', $id, 'create', ['name' => $d['name'], 'initiative' => $initiativeId], 1, (int) $init['entities_id']);
        }
        self::touch($initiativeId);
        self::refreshSummaries($initiativeId);
        return $id;
    }

    public static function remove(int $initiativeId, int $id): void
    {
        global $DB;
        $init = Initiative::load($initiativeId, true);
        $all = self::forInitiative($initiativeId);
        if (!isset($all[$id])) {
            throw new RuleException('Tarefa não encontrada nesta iniciativa.', 404);
        }
        foreach ($all as $t) {
            if ((int) $t['parent_id'] === $id) {
                throw new RuleException('A tarefa tem subtarefas: remova ou mova as subtarefas primeiro.');
            }
        }
        if (countElementsInTable(Db::t('dependencies'), ['kind' => 'task', 'OR' => ['pred_id' => $id, 'succ_id' => $id]])) {
            throw new RuleException('A tarefa participa de dependências: remova os vínculos antes.');
        }
        $DB->update(self::getTable(), ['is_deleted' => 1, 'date_mod' => Db::now()], ['id' => $id]);
        Audit::log('Task', $id, 'delete', ['name' => $all[$id]['name']], (int) $all[$id]['version'], (int) $init['entities_id']);
        self::refreshSummaries($initiativeId);
    }

    /**
     * Registra progresso (RF07): percentual 0–100, esforço, evidência e data de referência.
     * Tarefa resumo não aceita progresso manual; atualização concorrente gera conflito explícito.
     */
    public static function addProgress(int $taskId, array $in, int $expectedVersion): int
    {
        global $DB;
        $task = Db::row('tasks', $taskId);
        if (!$task || $task['is_deleted']) {
            throw new RuleException('Tarefa não encontrada.', 404);
        }
        $init = Initiative::load((int) $task['initiatives_id']);
        if (!self::canProgress($task, $init)) {
            throw RuleException::denied();
        }
        if (!in_array($init['status'], ['running', 'approved', 'suspended'], true)) {
            throw new RuleException('Só é possível registrar progresso em iniciativas aprovadas, em execução ou suspensas.');
        }
        if (countElementsInTable(self::getTable(), ['parent_id' => $taskId, 'is_deleted' => 0])) {
            throw new RuleException('Tarefa resumo: o progresso é calculado a partir das subtarefas.');
        }
        if ($task['status'] === 'cancelled') {
            throw new RuleException('Tarefa cancelada não recebe progresso.');
        }
        $p = Calc::parseNumber((string) ($in['progress'] ?? ''));
        if ($p === null || $p < 0 || $p > 100) {
            throw new RuleException('O percentual deve estar entre 0 e 100.');
        }
        $ref = Db::date($in['reference_date'] ?? '') ?: Db::today();
        if ($ref > Db::today()) {
            throw new RuleException('A data de referência não pode estar no futuro.');
        }
        $effort = Calc::parseNumber((string) ($in['effort_delta'] ?? '0')) ?? 0.0;
        if ($effort < 0) {
            throw new RuleException('O esforço realizado não pode ser negativo.');
        }
        $status = $task['status'];
        $upd = ['progress' => $p, 'effort_actual' => round((float) $task['effort_actual'] + $effort, 2), 'date_mod' => Db::now()];
        if ($p >= 100) {
            $status = 'done';
            $upd['actual_end'] = $ref;
            $upd['actual_start'] = $task['actual_start'] ?: $ref;
        } elseif ($p > 0 || $effort > 0) {
            if ($status === 'todo') {
                Dependency::assertTaskCanStart($taskId);
                $upd['actual_start'] = $task['actual_start'] ?: $ref;
            }
            $status = 'doing';
            $upd['actual_end'] = null;
        } elseif ($status === 'done') {
            $status = 'todo';
            $upd['actual_end'] = null;
        }
        $upd['status'] = $status;
        $version = Db::updateVersioned('tasks', $taskId, $upd, $expectedVersion, 'progresso da tarefa');
        Db::insert('task_updates', [
            'tasks_id' => $taskId, 'initiatives_id' => (int) $task['initiatives_id'], 'progress' => $p, 'effort_delta' => $effort, 'reference_date' => $ref,
            'comment' => Db::text($in['comment'] ?? '', 4000) ?: null, 'evidence' => Db::text($in['evidence'] ?? '', 500) ?: null,
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now(),
        ]);
        Audit::log('Task', $taskId, 'progress', ['de' => $task['progress'], 'para' => $p, 'status' => $status], $version, (int) $init['entities_id']);
        self::touch((int) $task['initiatives_id']);
        self::refreshSummaries((int) $task['initiatives_id']);
        return $version;
    }

    /** Cancela ou reativa uma tarefa (cancelada sai do cálculo mantendo o histórico). */
    public static function setCancelled(int $initiativeId, int $id, bool $cancel): void
    {
        global $DB;
        $init = Initiative::load($initiativeId, true);
        $all = self::forInitiative($initiativeId);
        if (!isset($all[$id])) {
            throw new RuleException('Tarefa não encontrada nesta iniciativa.', 404);
        }
        $new = $cancel ? 'cancelled' : ((float) $all[$id]['progress'] >= 100 ? 'done' : ((float) $all[$id]['progress'] > 0 ? 'doing' : 'todo'));
        $DB->update(self::getTable(), ['status' => $new, 'date_mod' => Db::now(), 'version' => (int) $all[$id]['version'] + 1], ['id' => $id]);
        Audit::log('Task', $id, $cancel ? 'cancel' : 'reactivate', ['status' => $new, 'efeito' => 'base de cálculo do progresso alterada'], (int) $all[$id]['version'] + 1, (int) $init['entities_id']);
        self::touch($initiativeId);
        self::refreshSummaries($initiativeId);
    }

    /** Inicia uma tarefa (checa vínculos rígidos). */
    public static function start(int $taskId): void
    {
        global $DB;
        $task = Db::row('tasks', $taskId) ?? throw new RuleException('Tarefa não encontrada.', 404);
        $init = Initiative::load((int) $task['initiatives_id']);
        if (!self::canProgress($task, $init)) {
            throw RuleException::denied();
        }
        if ($task['status'] !== 'todo') {
            throw new RuleException('A tarefa já foi iniciada.');
        }
        Dependency::assertTaskCanStart($taskId);
        $DB->update(self::getTable(), ['status' => 'doing', 'actual_start' => $task['actual_start'] ?: Db::today(), 'date_mod' => Db::now(), 'version' => (int) $task['version'] + 1], ['id' => $taskId]);
        Audit::log('Task', $taskId, 'start', [], (int) $task['version'] + 1, (int) $init['entities_id']);
        self::touch((int) $task['initiatives_id']);
    }

    private static function touch(int $initiativeId): void
    {
        global $DB;
        $DB->update(Initiative::getTable(), ['last_update_at' => Db::now()], ['id' => $initiativeId]);
    }

    /** Recalcula o progresso gravado das tarefas resumo a partir das filhas. */
    public static function refreshSummaries(int $initiativeId): void
    {
        global $DB;
        $tasks = self::forInitiative($initiativeId);
        $roll = Calc::rollup($tasks);
        $parents = [];
        foreach ($tasks as $t) {
            if ((int) $t['parent_id'] > 0) {
                $parents[(int) $t['parent_id']] = true;
            }
        }
        foreach ($parents as $pid => $_) {
            if (isset($tasks[$pid]) && $roll[$pid] !== null && (float) $tasks[$pid]['progress'] !== (float) $roll[$pid]) {
                $DB->update(self::getTable(), ['progress' => $roll[$pid]], ['id' => $pid]);
            }
        }
    }

    public static function updates(int $taskId, int $limit = 50): array
    {
        return Db::rows('task_updates', ['tasks_id' => $taskId], ['id DESC'], $limit);
    }
}
