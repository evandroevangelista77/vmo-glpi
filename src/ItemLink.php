<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Vínculos consultivos com objetos do GLPI (RF22): tickets e projetos. Sem duplicar conteúdo.
 * Criar o vínculo exige acesso à iniciativa e ao objeto; o título do objeto só aparece a quem o pode ver.
 */
class ItemLink
{
    /** Lista branca de tipos suportados. */
    public const TYPES = ['Ticket' => 'Chamado', 'Project' => 'Projeto'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_item_links';
    }

    public static function add(int $initiativeId, string $itemtype, int $itemId, string $relation = 'related'): int
    {
        $init = Initiative::load($initiativeId, true);
        if (!isset(self::TYPES[$itemtype])) {
            throw new RuleException('Tipo de objeto não suportado.');
        }
        $item = new $itemtype();
        if ($itemId <= 0 || !$item->getFromDB($itemId) || !$item->can($itemId, READ)) {
            throw new RuleException('Objeto não encontrado ou sem acesso a ele.', 404);
        }
        if (countElementsInTable(self::getTable(), ['initiatives_id' => $initiativeId, 'itemtype' => $itemtype, 'items_id' => $itemId])) {
            throw new RuleException('Este objeto já está vinculado.');
        }
        $id = Db::insert('item_links', ['initiatives_id' => $initiativeId, 'itemtype' => $itemtype, 'items_id' => $itemId, 'relation' => Db::text($relation, 20) ?: 'related',
            'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
        Audit::log('Initiative', $initiativeId, 'link', ['objeto' => "$itemtype #$itemId"], 0, (int) $init['entities_id']);
        return $id;
    }

    public static function remove(int $id): void
    {
        global $DB;
        $l = Db::row('item_links', $id) ?? throw new RuleException('Vínculo não encontrado.', 404);
        Initiative::load((int) $l['initiatives_id'], true);
        $DB->delete(self::getTable(), ['id' => $id]);
    }

    /** Vínculos com o rótulo resolvido: objeto removido → "indisponível"; sem acesso → "restrito" (sem título nem URL). */
    public static function forInitiative(int $initiativeId): array
    {
        $out = [];
        foreach (Db::rows('item_links', ['initiatives_id' => $initiativeId]) as $l) {
            $type = $l['itemtype'];
            $label = 'referência indisponível';
            $url = null;
            if (isset(self::TYPES[$type])) {
                $item = new $type();
                if (!$item->getFromDB((int) $l['items_id']) || !empty($item->fields['is_deleted'])) {
                    $label = 'referência indisponível';
                } elseif ($item->can((int) $l['items_id'], READ)) {
                    $label = self::TYPES[$type] . ' #' . $l['items_id'] . ' · ' . ($item->fields['name'] ?? '');
                    $url = $item->getFormURLWithID((int) $l['items_id']);
                } else {
                    $label = self::TYPES[$type] . ' restrito';
                }
            }
            $out[] = $l + ['label' => $label, 'url' => $url];
        }
        return $out;
    }
}
