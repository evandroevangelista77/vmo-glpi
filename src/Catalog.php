<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Catálogos por entidade (RF01): objetivos estratégicos e categorias de iniciativa.
 */
class Catalog
{
    public const KINDS = ['objective' => 'Objetivos estratégicos', 'category' => 'Categorias de iniciativa'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_catalogs';
    }

    public static function all(string $kind, bool $onlyActive = true): array
    {
        $where = ['kind' => $kind] + Access::entityCriteria(self::getTable());
        if ($onlyActive) {
            $where['is_active'] = 1;
        }
        return Db::rows('catalogs', $where, ['code ASC']);
    }

    public static function options(string $kind): array
    {
        $o = [];
        foreach (self::all($kind) as $c) {
            $o[(int) $c['id']] = $c['code'] . ' · ' . $c['name'];
        }
        return $o;
    }

    public static function name(int $id): string
    {
        $c = $id ? Db::row('catalogs', $id) : null;
        return $c ? $c['code'] . ' · ' . $c['name'] : '—';
    }

    public static function add(string $kind, string $code, string $name, int $entity): int
    {
        if (!Access::canConfigure()) {
            throw RuleException::denied();
        }
        if (!isset(self::KINDS[$kind])) {
            throw new RuleException('Tipo de catálogo inválido.');
        }
        if (!Session::haveAccessToEntity($entity)) {
            throw RuleException::denied('Sem acesso à entidade.');
        }
        $code = strtoupper(Db::text($code, 30));
        $name = Db::text($name, 255);
        if ($code === '' || $name === '') {
            throw new RuleException('Informe código e nome.');
        }
        if (countElementsInTable(self::getTable(), ['entities_id' => $entity, 'kind' => $kind, 'code' => $code])) {
            throw new RuleException("O código $code já existe neste catálogo.");
        }
        $id = Db::insert('catalogs', ['entities_id' => $entity, 'kind' => $kind, 'code' => $code, 'name' => $name, 'date_creation' => Db::now(), 'date_mod' => Db::now()]);
        Audit::log('Catalog', $id, 'create', ['tipo' => $kind, 'codigo' => $code], 0, $entity);
        return $id;
    }

    public static function toggle(int $id): void
    {
        global $DB;
        $c = Db::row('catalogs', $id) ?? throw new RuleException('Registro não encontrado.', 404);
        if (!Access::canConfigure() || !Session::haveAccessToEntity((int) $c['entities_id'])) {
            throw RuleException::denied();
        }
        $DB->update(self::getTable(), ['is_active' => $c['is_active'] ? 0 : 1, 'date_mod' => Db::now()], ['id' => $id]);
        Audit::log('Catalog', $id, $c['is_active'] ? 'deactivate' : 'activate', [], 0, (int) $c['entities_id']);
    }
}
