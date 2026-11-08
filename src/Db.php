<?php

namespace GlpiPlugin\Vmo;

/**
 * Acesso a dados com controle otimista de versão e transações simples.
 */
class Db
{
    public const T = 'glpi_plugin_vmo_';

    public static function t(string $name): string
    {
        return self::T . $name;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s'); // UTC
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function row(string $table, int $id): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => self::t($table), 'WHERE' => ['id' => $id]])->current();
        return $r ?: null;
    }

    public static function rows(string $table, array $where = [], array $order = ['id ASC'], int $limit = 0): array
    {
        global $DB;
        $q = ['FROM' => self::t($table), 'WHERE' => $where, 'ORDER' => $order];
        if ($limit > 0) {
            $q['LIMIT'] = $limit;
        }
        return iterator_to_array($DB->request($q), false);
    }

    public static function insert(string $table, array $data): int
    {
        global $DB;
        $DB->insert(self::t($table), $data);
        return (int) $DB->insertId();
    }

    /**
     * Atualiza somente se a versão ainda for a esperada (controle otimista). Incrementa a versão.
     * Lança conflito (409) quando outra pessoa gravou antes.
     */
    public static function updateVersioned(string $table, int $id, array $data, int $expectedVersion, string $what = 'registro'): int
    {
        global $DB;
        $data['version'] = $expectedVersion + 1;
        $DB->update(self::t($table), $data, ['id' => $id, 'version' => $expectedVersion]);
        if ($DB->affectedRows() < 1) {
            throw RuleException::conflict($what);
        }
        return $expectedVersion + 1;
    }

    /** Executa $fn numa transação; reverte e repropaga em caso de exceção. */
    public static function transaction(callable $fn)
    {
        global $DB;
        $DB->beginTransaction();
        try {
            $result = $fn();
            $DB->commit();
            return $result;
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    /** Texto limpo: sem controles, aparado, com limite de tamanho. */
    public static function text($v, int $max = 0): string
    {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v));
        return $max > 0 ? mb_substr($v, 0, $max) : $v;
    }

    /** Data Y-m-d válida ou null. */
    public static function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', substr($v, 0, 10));
        return ($d && $d->format('Y-m-d') === substr($v, 0, 10)) ? substr($v, 0, 10) : null;
    }

    /** Inteiro dentro de uma faixa, ou o padrão. */
    public static function int($v, int $min, int $max, int $default): int
    {
        return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
    }

    public static function userName(int $id): string
    {
        return $id > 0 ? (string) (getUserName($id) ?: '#' . $id) : '—';
    }

    /** Neutraliza células de CSV que começam com caracteres de fórmula (injeção de fórmula). */
    public static function csvCell($v): string
    {
        $s = (string) $v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }
}
