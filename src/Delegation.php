<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Delegação de alçada com vigência (RN07). Um substituto só vale dentro do período cadastrado;
 * a ausência de aprovador mantém a solicitação pendente.
 */
class Delegation
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_delegations';
    }

    /** $user age em nome de $principal hoje? */
    public static function actsFor(int $user, int $principal): bool
    {
        if ($user <= 0 || $principal <= 0 || $user === $principal) {
            return false;
        }
        $today = Db::today();
        return (bool) countElementsInTable(self::getTable(), [
            'users_id_from' => $principal, 'users_id_to' => $user,
            'valid_from' => ['<=', $today], 'valid_to' => ['>=', $today],
        ]);
    }

    public static function add(int $from, int $to, string $validFrom, string $validTo, string $reason, int $entity): int
    {
        if (!Access::canConfigure() && $from !== (int) Session::getLoginUserID()) {
            throw RuleException::denied('Só o próprio titular da alçada ou a administração do VMO cadastram delegação.');
        }
        $a = Db::date($validFrom);
        $b = Db::date($validTo);
        if ($from <= 0 || $to <= 0 || $from === $to) {
            throw new RuleException('Escolha titular e substituto diferentes.');
        }
        if (!$a || !$b || $a > $b) {
            throw new RuleException('A vigência da delegação precisa de início e fim válidos (início não posterior ao fim).');
        }
        $id = Db::insert('delegations', ['entities_id' => $entity, 'users_id_from' => $from, 'users_id_to' => $to, 'valid_from' => $a, 'valid_to' => $b,
            'reason' => Db::text($reason, 255) ?: null, 'date_creation' => Db::now()]);
        Audit::log('Delegation', $id, 'create', ['de' => $from, 'para' => $to, 'vigencia' => "$a/$b"], 0, $entity);
        return $id;
    }

    public static function remove(int $id): void
    {
        global $DB;
        $row = Db::row('delegations', $id);
        if (!$row) {
            return;
        }
        if (!Access::canConfigure() && (int) $row['users_id_from'] !== (int) Session::getLoginUserID()) {
            throw RuleException::denied();
        }
        $DB->delete(self::getTable(), ['id' => $id]);
        Audit::log('Delegation', $id, 'delete', $row, 0, (int) $row['entities_id']);
    }
}
