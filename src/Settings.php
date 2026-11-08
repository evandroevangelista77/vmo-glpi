<?php

namespace GlpiPlugin\Vmo;

use Config;

/**
 * Configuração global do VMO (Configurar → Geral, contexto plugin:vmo).
 * Os limites de saúde são versionados: cada alteração incrementa thresholds_version,
 * e snapshots/baselines guardam a versão que usaram.
 */
class Settings
{
    public const CONTEXT = 'plugin:vmo';

    public static function get(string $key, string $default = ''): string
    {
        $value = Config::getConfigurationValues(self::CONTEXT)[$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    public static function set(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function thresholds(): array
    {
        $saved = json_decode(self::get('thresholds', '{}'), true) ?: [];
        return array_merge(Calc::DEFAULT_THRESHOLDS, array_intersect_key($saved, Calc::DEFAULT_THRESHOLDS));
    }

    public static function thresholdsVersion(): int
    {
        return max(1, (int) self::get('thresholds_version', '1'));
    }

    public static function saveThresholds(array $new): void
    {
        $clean = [];
        foreach (Calc::DEFAULT_THRESHOLDS as $k => $default) {
            $clean[$k] = isset($new[$k]) && is_numeric($new[$k]) ? (float) $new[$k] + 0 : $default;
        }
        if ($clean == self::thresholds()) {
            return;
        }
        self::set(['thresholds' => json_encode($clean), 'thresholds_version' => (string) (self::thresholdsVersion() + 1)]);
        Audit::log('Settings', 0, 'thresholds', ['to' => $clean, 'version' => self::thresholdsVersion()]);
    }

    /** Moeda funcional padrão (ISO 4217). */
    public static function currency(): string
    {
        $c = strtoupper(self::get('currency', 'BRL'));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'BRL';
    }

    /** Dias sem atualização que tornam uma iniciativa "desatualizada". */
    public static function cadenceDays(): int
    {
        return max(1, (int) self::get('cadence_days', '14'));
    }

    /** Feriados (Y-m-d) usados nos cálculos em dias úteis. */
    public static function holidays(): array
    {
        $raw = preg_split('/[\s,;]+/', self::get('holidays', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($raw, fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
    }

    /** Meses que um relatório gerado fica disponível. */
    public static function reportRetentionDays(): int
    {
        return max(1, (int) self::get('report_retention_days', '90'));
    }

    public static function auditRetentionMonths(): int
    {
        return max(1, (int) self::get('audit_retention_months', '24'));
    }
}
