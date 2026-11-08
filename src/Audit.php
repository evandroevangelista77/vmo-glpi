<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Trilha de auditoria (RF20): quem, quando, o quê, antes e depois, com correlação por requisição.
 * Só há inserção: nenhuma tela edita ou apaga a trilha; o expurgo é uma rotina administrativa.
 */
class Audit
{
    private static ?string $correlation = null;

    public static function correlation(): string
    {
        return self::$correlation ??= bin2hex(random_bytes(8));
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_audit_events';
    }

    public static function log(string $itemtype, int $id, string $action, array $diff = [], int $version = 0, ?int $entity = null): void
    {
        global $DB;
        $short = str_contains($itemtype, '\\') ? substr(strrchr($itemtype, '\\'), 1) : $itemtype;
        $DB->insert(self::getTable(), [
            'entities_id'    => $entity ?? (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'itemtype'       => $short,
            'items_id'       => $id,
            'action'         => $action,
            'users_id'       => (int) Session::getLoginUserID(),
            'version'        => $version,
            'diff'           => json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'correlation_id' => self::correlation(),
            'date_creation'  => gmdate('Y-m-d H:i:s'), // UTC
        ]);
    }

    /** Diferença campo a campo entre dois estados (só campos que mudaram). */
    public static function diff(array $before, array $after, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $a = $before[$f] ?? null;
            $b = $after[$f] ?? null;
            if ((string) $a !== (string) $b) {
                $out[$f] = ['de' => $a, 'para' => $b];
            }
        }
        return $out;
    }

    /** Eventos de um objeto, do mais recente ao mais antigo. */
    public static function forItem(string $itemtype, int $id, int $limit = 200): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $id],
            'ORDER' => ['id DESC'],
            'LIMIT' => $limit,
        ]), false);
    }

    /** Expurga eventos além da retenção configurada; devolve quantos removeu (e registra o expurgo). */
    public static function purge(): int
    {
        global $DB;
        $limit = gmdate('Y-m-d H:i:s', strtotime('-' . Settings::auditRetentionMonths() . ' months'));
        $n = countElementsInTable(self::getTable(), ['date_creation' => ['<', $limit]]);
        if ($n > 0) {
            $DB->delete(self::getTable(), ['date_creation' => ['<', $limit]]);
            self::log('Settings', 0, 'audit_purge', ['removidos' => $n, 'antes_de' => $limit]);
        }
        return $n;
    }
}
