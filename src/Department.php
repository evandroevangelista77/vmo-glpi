<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Departamentos próprios do VMO (RF09), por entidade, e sua participação nas iniciativas.
 * Associar um departamento ou grupo GLPI não concede acesso automaticamente.
 */
class Department
{
    public const ROLES = ['beneficiary' => 'Beneficiário', 'executor' => 'Executor', 'sponsor' => 'Patrocinador', 'support' => 'Apoio'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_departments';
    }

    public static function all(bool $onlyActive = false): array
    {
        $where = Access::entityCriteria(self::getTable());
        if ($onlyActive) {
            $where['is_active'] = 1;
        }
        return Db::rows('departments', $where, ['code ASC']);
    }

    public static function add(string $code, string $name, int $parent, int $responsible, int $entity): int
    {
        if (!Access::canConfigure()) {
            throw RuleException::denied();
        }
        if (!Session::haveAccessToEntity($entity)) {
            throw RuleException::denied('Sem acesso à entidade.');
        }
        $code = strtoupper(Db::text($code, 30));
        $name = Db::text($name, 255);
        if ($code === '' || $name === '') {
            throw new RuleException('Informe código e nome do departamento.');
        }
        if (countElementsInTable(self::getTable(), ['entities_id' => $entity, 'code' => $code])) {
            throw new RuleException("O código $code já existe nesta entidade.");
        }
        $id = Db::insert('departments', ['entities_id' => $entity, 'code' => $code, 'name' => $name, 'parent_id' => $parent, 'users_id_responsible' => $responsible,
            'date_creation' => Db::now(), 'date_mod' => Db::now()]);
        Audit::log('Department', $id, 'create', ['codigo' => $code, 'nome' => $name], 0, $entity);
        return $id;
    }

    public static function toggle(int $id): void
    {
        global $DB;
        $d = Db::row('departments', $id) ?? throw new RuleException('Departamento não encontrado.', 404);
        if (!Access::canConfigure() || !Session::haveAccessToEntity((int) $d['entities_id'])) {
            throw RuleException::denied();
        }
        $DB->update(self::getTable(), ['is_active' => $d['is_active'] ? 0 : 1, 'date_mod' => Db::now()], ['id' => $id]);
        Audit::log('Department', $id, $d['is_active'] ? 'deactivate' : 'activate', [], 0, (int) $d['entities_id']);
    }

    // ---------- participação na iniciativa ----------

    public static function forInitiative(int $initiativeId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['i.*', 'd.code AS dept_code', 'd.name AS dept_name', 'd.is_active AS dept_active'],
            'FROM' => ['glpi_plugin_vmo_initiative_departments AS i'],
            'LEFT JOIN' => [Db::t('departments') . ' AS d' => ['ON' => ['i' => 'departments_id', 'd' => 'id']]],
            'WHERE' => ['i.initiatives_id' => $initiativeId], 'ORDER' => ['d.code ASC'],
        ]) as $r) {
            $out[] = $r;
        }
        return $out;
    }

    public static function associate(int $initiativeId, int $departmentId, string $role, string $percent, int $responsible): int
    {
        $init = Initiative::load($initiativeId, true);
        $d = Db::row('departments', $departmentId) ?? throw new RuleException('Departamento não encontrado.', 404);
        if ((int) $d['entities_id'] !== (int) $init['entities_id'] && !$d['is_recursive']) {
            throw new RuleException('O departamento precisa ser da mesma entidade da iniciativa.');
        }
        if (!$d['is_active']) {
            throw new RuleException('Departamento inativo não recebe novas associações (o histórico é preservado).');
        }
        if (!isset(self::ROLES[$role])) {
            throw new RuleException('Papel inválido.');
        }
        $p = Calc::parseNumber($percent) ?? 0.0;
        if ($p < 0 || $p > 100) {
            throw new RuleException('A participação deve estar entre 0 e 100%.');
        }
        if (countElementsInTable(Db::t('initiative_departments'), ['initiatives_id' => $initiativeId, 'departments_id' => $departmentId, 'role' => $role])) {
            throw new RuleException('Este departamento já participa com este papel.');
        }
        $id = Db::insert('initiative_departments', ['initiatives_id' => $initiativeId, 'departments_id' => $departmentId, 'role' => $role, 'participation_percent' => $p,
            'users_id_responsible' => $responsible, 'date_creation' => Db::now()]);
        Audit::log('Initiative', $initiativeId, 'department', ['departamento' => $d['code'], 'papel' => $role], 0, (int) $init['entities_id']);
        return $id;
    }

    public static function dissociate(int $id): void
    {
        global $DB;
        $a = Db::row('initiative_departments', $id) ?? throw new RuleException('Associação não encontrada.', 404);
        $init = Initiative::load((int) $a['initiatives_id'], true);
        $DB->delete(Db::t('initiative_departments'), ['id' => $id]);
        Audit::log('Initiative', (int) $init['id'], 'department_remove', ['departamento' => $a['departments_id']], 0, (int) $init['entities_id']);
    }

    // ---------- equipes (grupos GLPI) ----------

    public static function teams(int $initiativeId): array
    {
        return Db::rows('participants', ['initiatives_id' => $initiativeId], ['id ASC']);
    }

    public static function addTeam(int $initiativeId, int $groupId, int $userId, string $role): int
    {
        $init = Initiative::load($initiativeId, true);
        if ($groupId <= 0 && $userId <= 0) {
            throw new RuleException('Escolha um grupo GLPI ou um usuário.');
        }
        $id = Db::insert('participants', ['initiatives_id' => $initiativeId, 'groups_id' => max(0, $groupId), 'users_id' => max(0, $userId),
            'role' => Db::text($role, 20) ?: 'member', 'date_creation' => Db::now()]);
        Audit::log('Initiative', $initiativeId, 'team', ['grupo' => $groupId, 'usuario' => $userId], 0, (int) $init['entities_id']);
        return $id;
    }

    public static function removeTeam(int $id): void
    {
        global $DB;
        $p = Db::row('participants', $id) ?? throw new RuleException('Participante não encontrado.', 404);
        Initiative::load((int) $p['initiatives_id'], true);
        $DB->delete(Db::t('participants'), ['id' => $id]);
    }
}
