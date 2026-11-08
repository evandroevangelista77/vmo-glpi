<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Direitos do VMO: perfil GLPI (direito "plugin_vmo" com bits próprios) + entidade ativa
 * + confidencialidade do registro. Regra geral: negar quando faltar qualquer condição.
 *
 * Bits: READ, CREATE, UPDATE, PURGE (nativos) e
 *   APPROVE   aprovar iniciativa, baseline e mudança;
 *   VALIDATE  validar medições de benefício;
 *   FINANCE   ver e lançar custos e valores financeiros;
 *   CONFIGURE catálogos, limites de saúde, câmbio e expurgo.
 */
class Access extends Base
{
    public const APPROVE = 1024;
    public const VALIDATE = 2048;
    public const FINANCE = 4096;
    public const CONFIGURE = 8192;

    public static function getTypeName($nb = 0)
    {
        return 'VMO';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_initiatives';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE);
    }

    public static function canApprove(): bool
    {
        return Session::haveRight(self::$rightname, self::APPROVE);
    }

    public static function canValidate(): bool
    {
        return Session::haveRight(self::$rightname, self::VALIDATE);
    }

    public static function canFinance(): bool
    {
        return Session::haveRight(self::$rightname, self::FINANCE);
    }

    public static function canConfigure(): bool
    {
        return Session::haveRight(self::$rightname, self::CONFIGURE);
    }

    /** Critério SQL para limitar uma tabela com entities_id às entidades ativas do usuário. */
    public static function entityCriteria(string $table): array
    {
        return getEntitiesRestrictCriteria($table, 'entities_id', '', true);
    }

    /** O usuário atual enxerga esta iniciativa (linha do banco)? */
    public static function canSeeInitiative(array $row): bool
    {
        if (!self::canView()) {
            return false;
        }
        $entities = $_SESSION['glpiactiveentities'] ?? [];
        if (!in_array((int) $row['entities_id'], array_map('intval', $entities), true)) {
            return false;
        }
        if (empty($row['confidentiality'])) {
            return true;
        }
        $me = (int) Session::getLoginUserID();
        return in_array($me, [(int) $row['users_id_manager'], (int) $row['users_id_sponsor'], (int) $row['users_id_author']], true)
            || self::canApprove() || self::canConfigure()
            || (bool) countElementsInTable('glpi_plugin_vmo_participants', ['initiatives_id' => (int) $row['id'], 'users_id' => $me]);
    }

    /** Pode editar a iniciativa: direito de atualizar + gestor, patrocinador ou administração do portfólio. */
    public static function canEditInitiative(array $row): bool
    {
        if (!self::canUpdate() || !self::canSeeInitiative($row)) {
            return false;
        }
        $me = (int) Session::getLoginUserID();
        return in_array($me, [(int) $row['users_id_manager'], (int) $row['users_id_author']], true)
            || self::canApprove() || self::canConfigure();
    }

    /**
     * IDs das iniciativas visíveis ao usuário (filtra entidade, confidencialidade e exclusão lógica).
     * Todo cálculo agregado parte desta lista, para que somas e contagens não revelem registros sem acesso.
     *
     * @return array<int,array> id => linha
     */
    public static function visibleInitiatives(array $extraWhere = []): array
    {
        global $DB;
        if (!self::canView()) {
            return [];
        }
        $rows = [];
        $it = $DB->request([
            'FROM'  => 'glpi_plugin_vmo_initiatives',
            'WHERE' => ['is_deleted' => 0] + self::entityCriteria('glpi_plugin_vmo_initiatives') + $extraWhere,
            'ORDER' => ['code ASC'],
        ]);
        foreach ($it as $r) {
            if (self::canSeeInitiative($r)) {
                $rows[(int) $r['id']] = $r;
            }
        }
        return $rows;
    }

    /** Carrega uma iniciativa e exige acesso; lança 404 (não revela se existe) quando não há. */
    public static function initiativeOrFail(int $id, bool $forEdit = false): array
    {
        global $DB;
        $row = $DB->request(['FROM' => 'glpi_plugin_vmo_initiatives', 'WHERE' => ['id' => $id, 'is_deleted' => 0]])->current();
        if (!$row || !self::canSeeInitiative($row)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        if ($forEdit && !self::canEditInitiative($row)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        return $row;
    }
}
