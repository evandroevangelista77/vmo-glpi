<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Taxas de câmbio com data e fonte (RN08). Sem taxa registrada não há conversão:
 * os totais permanecem separados por moeda.
 */
class Fx
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_fx_rates';
    }

    public static function add(string $from, string $to, string $date, string $rate, string $source, int $entity): int
    {
        if (!Access::canConfigure()) {
            throw RuleException::denied();
        }
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        $d = Db::date($date);
        $r = Calc::parseNumber($rate);
        if (!preg_match('/^[A-Z]{3}$/', $from) || !preg_match('/^[A-Z]{3}$/', $to) || $from === $to) {
            throw new RuleException('Informe duas moedas ISO distintas (ex.: USD e BRL).');
        }
        if (!$d || $r === null || $r <= 0) {
            throw new RuleException('Informe a data e uma taxa positiva.');
        }
        if (Db::text($source, 120) === '') {
            throw new RuleException('Toda taxa precisa registrar a fonte (ex.: PTAX Banco Central).');
        }
        if (!Session::haveAccessToEntity($entity)) {
            throw RuleException::denied('Sem acesso à entidade.');
        }
        if (countElementsInTable(self::getTable(), ['entities_id' => $entity, 'from_currency' => $from, 'to_currency' => $to, 'rate_date' => $d])) {
            throw new RuleException('Já existe uma taxa para este par e data; registre outra data.');
        }
        $id = Db::insert('fx_rates', ['entities_id' => $entity, 'from_currency' => $from, 'to_currency' => $to, 'rate_date' => $d, 'rate' => $r,
            'source' => Db::text($source, 120), 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
        Audit::log('FxRate', $id, 'create', ['par' => "$from/$to", 'data' => $d, 'taxa' => $r, 'fonte' => $source], 0, $entity);
        return $id;
    }

    /** Taxa vigente na data (a mais recente até ela), direta ou invertida; null se não houver. */
    public static function rate(string $from, string $to, ?string $date = null): ?float
    {
        if ($from === $to) {
            return 1.0;
        }
        $date ??= Db::today();
        $crit = getEntitiesRestrictCriteria(self::getTable(), 'entities_id', '', true);
        foreach ([[$from, $to, false], [$to, $from, true]] as [$a, $b, $invert]) {
            $r = Db::rows('fx_rates', ['from_currency' => $a, 'to_currency' => $b, 'rate_date' => ['<=', $date]] + $crit, ['rate_date DESC'], 1)[0] ?? null;
            if ($r) {
                return $invert ? 1 / (float) $r['rate'] : (float) $r['rate'];
            }
        }
        return null;
    }

    public static function convert(float $amount, string $from, string $to, ?string $date = null): ?float
    {
        $rate = self::rate($from, $to, $date);
        return $rate === null ? null : round($amount * $rate, 4);
    }

    /**
     * Consolida totais por moeda ([moeda => valor]) numa moeda-alvo usando as taxas registradas.
     * Devolve [total convertido, [moedas sem taxa => valor]]: o que não converte permanece separado.
     */
    public static function consolidate(array $byCurrency, string $target, ?string $date = null): array
    {
        $total = 0.0;
        $missing = [];
        foreach ($byCurrency as $cur => $amount) {
            $v = self::convert((float) $amount, $cur, $target, $date);
            if ($v === null) {
                $missing[$cur] = (float) $amount;
            } else {
                $total += $v;
            }
        }
        return [round($total, 4), $missing];
    }
}
