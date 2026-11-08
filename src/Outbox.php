<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Fila de avisos (RF19). O dedup_key é único: reexecutar a rotina para o mesmo evento não duplica o aviso.
 * Os avisos aparecem em "Pendências e avisos"; o conteúdo é montado só com dados que o destinatário pode ver
 * quando ele abre a tela (a fila guarda apenas título curto e referência do objeto).
 */
class Outbox
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_outbox';
    }

    /** Enfileira um aviso; devolve false se o mesmo evento já foi avisado. */
    public static function push(int $userId, string $type, string $title, string $dedupKey, string $itemtype = '', int $itemId = 0, string $body = '', int $entity = 0): bool
    {
        global $DB;
        if ($userId <= 0) {
            return false;
        }
        $dedup = mb_substr($type . ':' . $userId . ':' . $dedupKey, 0, 120);
        if (countElementsInTable(self::getTable(), ['dedup_key' => $dedup])) {
            return false;
        }
        $DB->insert(self::getTable(), [
            'entities_id'   => $entity,
            'users_id'      => $userId,
            'type'          => $type,
            'itemtype'      => $itemtype ?: null,
            'items_id'      => $itemId,
            'title'         => mb_substr($title, 0, 255),
            'body'          => mb_substr($body, 0, 500) ?: null,
            'dedup_key'     => $dedup,
            'date_creation' => Db::now(),
        ]);
        return true;
    }

    /** Avisa o gestor e o patrocinador (menos quem fez a ação). */
    public static function toOwners(array $initiative, string $type, string $title, string $dedupKey): void
    {
        $me = (int) Session::getLoginUserID();
        foreach (array_unique([(int) $initiative['users_id_manager'], (int) $initiative['users_id_sponsor']]) as $u) {
            if ($u !== $me) {
                self::push($u, $type, $title, $dedupKey, 'Initiative', (int) $initiative['id'], $initiative['code'] . ' · ' . $initiative['name'], (int) $initiative['entities_id']);
            }
        }
    }

    public static function forUser(int $userId, int $limit = 100): array
    {
        return Db::rows('outbox', ['users_id' => $userId], ['id DESC'], $limit);
    }

    public static function unread(int $userId): int
    {
        return countElementsInTable(self::getTable(), ['users_id' => $userId, 'read_at' => null]);
    }

    public static function markRead(int $userId): void
    {
        global $DB;
        $DB->update(self::getTable(), ['read_at' => Db::now(), 'status' => 'read'], ['users_id' => $userId, 'read_at' => null]);
    }
}
