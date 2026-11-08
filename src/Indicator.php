<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Indicadores e metas (RF13). Dado ausente aparece como "sem dado", dado vencido como "desatualizado";
 * ausência nunca é convertida em zero.
 */
class Indicator
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_indicators';
    }

    public static function visible(): array
    {
        $rows = [];
        foreach (Db::rows('indicators', ['is_deleted' => 0] + Access::entityCriteria(self::getTable()), ['name ASC']) as $i) {
            if ((int) $i['initiatives_id'] > 0) {
                $init = Db::row('initiatives', (int) $i['initiatives_id']);
                if (!$init || !Access::canSeeInitiative($init)) {
                    continue; // indicador de iniciativa restrita não aparece
                }
            }
            $rows[(int) $i['id']] = $i;
        }
        return $rows;
    }

    public static function save(int $id, array $in): int
    {
        if (!Access::canUpdate() || (!$id && !Access::canCreate())) {
            throw RuleException::denied();
        }
        $entity = (int) ($in['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
        if (!Session::haveAccessToEntity($entity)) {
            throw RuleException::denied('Sem acesso à entidade escolhida.');
        }
        $name = Db::text($in['name'] ?? '', 255);
        $code = strtoupper(Db::text($in['code'] ?? '', 30));
        if ($name === '' || $code === '') {
            throw new RuleException('Informe código e nome do indicador.');
        }
        $initiative = (int) ($in['initiatives_id'] ?? 0);
        if ($initiative) {
            Initiative::load($initiative);
        }
        $d = [
            'name' => $name, 'unit' => Db::text($in['unit'] ?? '', 30) ?: null, 'direction' => ($in['direction'] ?? '') === 'reduce' ? 'reduce' : 'increase',
            'baseline_value' => Calc::parseNumber((string) ($in['baseline_value'] ?? '')), 'target' => Calc::parseNumber((string) ($in['target'] ?? '')),
            'cadence_days' => Db::int($in['cadence_days'] ?? 30, 1, 366, 30), 'source' => Db::text($in['source'] ?? '', 255) ?: null,
            'users_id_owner' => max(0, (int) ($in['users_id_owner'] ?? 0)), 'initiatives_id' => $initiative, 'date_mod' => Db::now(),
        ];
        if ($id) {
            $cur = Db::row('indicators', $id) ?? throw new RuleException('Indicador não encontrado.', 404);
            if (!Session::haveAccessToEntity((int) $cur['entities_id'])) {
                throw RuleException::denied();
            }
            global $DB;
            $DB->update(self::getTable(), $d, ['id' => $id]);
            Audit::log('Indicator', $id, 'update', Audit::diff($cur, $d, array_keys($d)), 0, (int) $cur['entities_id']);
            return $id;
        }
        if (countElementsInTable(self::getTable(), ['entities_id' => $entity, 'code' => $code])) {
            throw new RuleException("O código $code já existe nesta entidade.");
        }
        $id = Db::insert('indicators', $d + ['entities_id' => $entity, 'code' => $code, 'date_creation' => Db::now()]);
        Audit::log('Indicator', $id, 'create', ['codigo' => $code], 0, $entity);
        return $id;
    }

    /** Registra uma medição do período. Importações repetidas (mesma origem e chave) não duplicam. */
    public static function addValue(int $indicatorId, string $period, string $observed, string $source = 'manual', ?string $externalKey = null): int
    {
        $ind = Db::row('indicators', $indicatorId);
        if (!$ind || !Session::haveAccessToEntity((int) $ind['entities_id']) || !Access::canUpdate()) {
            throw RuleException::denied();
        }
        $p = Db::date($period);
        $v = Calc::parseNumber($observed);
        if (!$p || $v === null) {
            throw new RuleException('Informe a data do período e um valor numérico.');
        }
        if ($p > Db::today()) {
            throw new RuleException('O período da medição não pode estar no futuro.');
        }
        if ($externalKey !== null && countElementsInTable(Db::t('indicator_values'), ['source' => $source, 'external_key' => $externalKey])) {
            return (int) Db::rows('indicator_values', ['source' => $source, 'external_key' => $externalKey])[0]['id'];
        }
        if (countElementsInTable(Db::t('indicator_values'), ['indicators_id' => $indicatorId, 'period' => $p])) {
            throw new RuleException('Já existe medição para esta data; use outra data (períodos fechados não são sobrescritos).');
        }
        $id = Db::insert('indicator_values', ['indicators_id' => $indicatorId, 'period' => $p, 'observed' => $v, 'source' => $source, 'external_key' => $externalKey,
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
        Audit::log('Indicator', $indicatorId, 'value', ['periodo' => $p, 'valor' => $v], 0, (int) $ind['entities_id']);
        return $id;
    }

    /** Estado do indicador: valor atual, cumprimento da meta e situação (sem dado / desatualizado / ok). */
    public static function state(array $ind, ?string $today = null): array
    {
        $today ??= Db::today();
        $last = Db::rows('indicator_values', ['indicators_id' => (int) $ind['id']], ['period DESC'], 1)[0] ?? null;
        if (!$last) {
            return ['status' => 'nodata', 'label' => 'sem dado', 'value' => null, 'progress' => null, 'last' => null];
        }
        $stale = Calc::businessDays($last['period'], $today) > 0 && (strtotime($today) - strtotime($last['period'])) / 86400 > (int) $ind['cadence_days'];
        $progress = Calc::goalProgress((float) $last['observed'], $ind['baseline_value'] !== null ? (float) $ind['baseline_value'] : null,
            $ind['target'] !== null ? (float) $ind['target'] : null, $ind['direction']);
        return ['status' => $stale ? 'stale' : 'ok', 'label' => $stale ? 'desatualizado' : 'atualizado', 'value' => (float) $last['observed'], 'progress' => $progress, 'last' => $last['period']];
    }
}
