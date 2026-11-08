<?php

namespace GlpiPlugin\Vmo;

/**
 * Regras de cálculo do VMO (seção 5 dos requisitos). Sem dependência do GLPI:
 * funções puras, testáveis na linha de comando (tests/calc.php).
 *
 * Princípio: dado ausente nunca vira zero. Onde a especificação diz "sem dado"
 * ou "não aplicável", o retorno é null e quem exibe mostra o texto adequado.
 */
class Calc
{
    public const FORMULA_VERSION = '1.0';

    /** Limites padrão de saúde (configuráveis e versionados em Settings). */
    public const DEFAULT_THRESHOLDS = [
        'delay_red'   => 10,  // dias úteis de atraso
        'delay_yellow' => 0,
        'cost_red'    => 20,  // % de desvio de custo
        'cost_yellow' => 10,
        'risk_red'    => 20,  // exposição de risco aberto
        'risk_yellow' => 12,
    ];

    /** Converte "1.234,56" (pt-BR) ou "1234.56" em float; null se vazio ou inválido. */
    public static function parseNumber(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[^\d,.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            // o último separador é o decimal
            $value = $comma > $dot ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        } elseif ($comma !== false) {
            $value = str_replace(',', '.', $value);
        } elseif ($dot !== false && substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        } elseif ($dot !== false && preg_match('/^-?\d{1,3}\.\d{3}$/', $value)) {
            // "1.234" sem vírgula: milhar
            $value = str_replace('.', '', $value);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    public static function formatMoney(?float $value, string $currency = 'BRL'): string
    {
        if ($value === null) {
            return '—';
        }
        $symbols = ['BRL' => 'R$', 'USD' => 'US$', 'EUR' => '€'];
        return ($symbols[$currency] ?? $currency) . ' ' . number_format($value, 2, ',', '.');
    }

    // ---------- progresso ----------

    /**
     * Progresso da iniciativa: Σ(peso × progresso das folhas elegíveis) ÷ Σ(pesos).
     * Folhas canceladas ou de peso zero não entram; sem folhas elegíveis → null (sem dado).
     *
     * @param array<int,array{weight:float|int|string,progress:float|int|string,status?:string}> $leaves
     */
    public static function progress(array $leaves): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($leaves as $l) {
            if (($l['status'] ?? '') === 'cancelled') {
                continue;
            }
            $w = (float) $l['weight'];
            if ($w <= 0) {
                continue;
            }
            $sum += $w * max(0.0, min(100.0, (float) $l['progress']));
            $weights += $w;
        }
        return $weights > 0 ? round($sum / $weights, 3) : null;
    }

    /**
     * Folhas de uma árvore de tarefas ([id => [parent_id, ...]]): tarefa resumo não conta.
     *
     * @return array<int,array> folhas, indexadas por id
     */
    public static function leaves(array $tasks): array
    {
        $parents = [];
        foreach ($tasks as $t) {
            if ((int) $t['parent_id'] > 0) {
                $parents[(int) $t['parent_id']] = true;
            }
        }
        return array_filter($tasks, fn ($t) => !isset($parents[(int) $t['id']]));
    }

    /**
     * Progresso derivado de cada tarefa resumo (média ponderada dos filhos não cancelados).
     * As folhas mantêm o próprio progresso.
     *
     * @return array<int,?float> id => progresso (null = sem dado)
     */
    public static function rollup(array $tasks): array
    {
        $byParent = [];
        foreach ($tasks as $t) {
            $byParent[(int) $t['parent_id']][] = $t;
        }
        $result = [];
        $walk = function (int $id) use (&$walk, &$result, $byParent, $tasks): ?float {
            if (empty($byParent[$id])) {
                return $result[$id] = (float) $tasks[$id]['progress'];
            }
            $sum = 0.0;
            $weights = 0.0;
            foreach ($byParent[$id] as $child) {
                $p = $walk((int) $child['id']);
                if (($child['status'] ?? '') === 'cancelled' || $p === null) {
                    continue;
                }
                $w = (float) $child['weight'];
                if ($w <= 0) {
                    continue;
                }
                $sum += $w * $p;
                $weights += $w;
            }
            return $result[$id] = ($weights > 0 ? round($sum / $weights, 3) : null);
        };
        foreach ($tasks as $id => $t) {
            if ((int) $t['parent_id'] === 0) {
                $walk((int) $id);
            }
        }
        return $result;
    }

    /** Profundidade de uma tarefa (raiz = 1). Retorna 0 se houver ciclo. */
    public static function depth(array $parentOf, int $id): int
    {
        $depth = 1;
        $seen = [$id => true];
        while (($p = (int) ($parentOf[$id] ?? 0)) > 0) {
            if (isset($seen[$p])) {
                return 0;
            }
            $seen[$p] = true;
            $id = $p;
            $depth++;
        }
        return $depth;
    }

    /** Altura da subárvore de $id (a própria tarefa conta 1). */
    public static function height(array $parentOf, int $id): int
    {
        $max = 1;
        foreach ($parentOf as $child => $parent) {
            if ((int) $parent === $id) {
                $max = max($max, 1 + self::height($parentOf, (int) $child));
            }
        }
        return $max;
    }

    // ---------- grafo de dependências ----------

    /**
     * Inserir a aresta pred→succ cria ciclo? ($edges = lista de [pred, succ]).
     * Autorrelação também conta como ciclo.
     */
    public static function wouldCreateCycle(array $edges, int $pred, int $succ): bool
    {
        if ($pred === $succ) {
            return true;
        }
        $next = [];
        foreach ($edges as [$a, $b]) {
            $next[(int) $a][] = (int) $b;
        }
        $stack = [$succ];
        $seen = [];
        while ($stack) {
            $n = array_pop($stack);
            if ($n === $pred) {
                return true;
            }
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            foreach ($next[$n] ?? [] as $m) {
                $stack[] = $m;
            }
        }
        return false;
    }

    // ---------- datas ----------

    /**
     * Dias úteis entre duas datas (Y-m-d): conta os dias de (from, to], de segunda a sexta,
     * fora os feriados. Negativo quando to < from.
     *
     * @param string[] $holidays datas Y-m-d
     */
    public static function businessDays(string $from, string $to, array $holidays = []): int
    {
        if ($from === $to) {
            return 0;
        }
        $sign = 1;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
            $sign = -1;
        }
        $skip = array_flip($holidays);
        $count = 0;
        $d = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($d < $end) {
            $d = $d->modify('+1 day');
            if ((int) $d->format('N') < 6 && !isset($skip[$d->format('Y-m-d')])) {
                $count++;
            }
        }
        return $sign * $count;
    }

    /** Soma $n dias úteis a uma data (Y-m-d). */
    public static function addBusinessDays(string $date, int $n, array $holidays = []): string
    {
        $skip = array_flip($holidays);
        $d = new \DateTimeImmutable($date);
        $step = $n < 0 ? '-1 day' : '+1 day';
        $left = abs($n);
        while ($left > 0) {
            $d = $d->modify($step);
            if ((int) $d->format('N') < 6 && !isset($skip[$d->format('Y-m-d')])) {
                $left--;
            }
        }
        return $d->format('Y-m-d');
    }

    /** Atraso em dias úteis: max(0, previsão atual − término da baseline); null se faltar alguma data. */
    public static function delay(?string $baselineEnd, ?string $forecastEnd, array $holidays = []): ?int
    {
        if (!$baselineEnd || !$forecastEnd) {
            return null;
        }
        return max(0, self::businessDays($baselineEnd, $forecastEnd, $holidays));
    }

    // ---------- finanças ----------

    /** Variação de custo: [valor, percentual]; percentual null quando o orçamento é zero. */
    public static function costVariance(?float $forecast, ?float $baselineBudget): ?array
    {
        if ($forecast === null || $baselineBudget === null) {
            return null;
        }
        $delta = round($forecast - $baselineBudget, 4);
        return [$delta, $baselineBudget != 0.0 ? round($delta / $baselineBudget * 100, 3) : null];
    }

    /** Realização de valor: 100 × validado acumulado ÷ previsto aprovado até o corte; null se previsto = 0. */
    public static function valueRealization(?float $validated, ?float $planned): ?float
    {
        if ($validated === null || $planned === null || $planned == 0.0) {
            return null;
        }
        return round(100 * $validated / $planned, 3);
    }

    /** ROI: 100 × (benefício − custo) ÷ custo; null se o custo for zero. */
    public static function roi(?float $benefit, ?float $cost): ?float
    {
        if ($benefit === null || $cost === null || $cost == 0.0) {
            return null;
        }
        return round(100 * ($benefit - $cost) / $cost, 3);
    }

    /** Benefício líquido: bruto validado menos custos operacionais incrementais. */
    public static function netBenefit(float $gross, float $incrementalCosts): float
    {
        return round($gross - $incrementalCosts, 4);
    }

    /**
     * Benefício incremental por período.
     * reduzir: (baseline − observado) × volume × fator × atribuição; aumentar: (observado − baseline) × ...
     * $attribution em 0..1.
     */
    public static function incremental(string $direction, float $baseline, float $observed, float $volume, float $factor, float $attribution): float
    {
        $delta = $direction === 'reduce' ? $baseline - $observed : $observed - $baseline;
        return round($delta * $volume * $factor * $attribution, 4);
    }

    /** Cumprimento de meta; $direction 'increase' | 'reduce'. Meta = baseline usa comparação booleana. Sem teto. */
    public static function goalProgress(?float $observed, ?float $baseline, ?float $target, string $direction = 'increase'): ?float
    {
        if ($observed === null || $baseline === null || $target === null) {
            return null;
        }
        if ($target == $baseline) {
            $hit = $direction === 'reduce' ? $observed <= $target : $observed >= $target;
            return $hit ? 100.0 : 0.0;
        }
        return round(($observed - $baseline) / ($target - $baseline) * 100, 3);
    }

    // ---------- risco e saúde ----------

    public static function riskExposure(int $probability, int $impact): int
    {
        return max(1, min(5, $probability)) * max(1, min(5, $impact));
    }

    /**
     * Saúde da iniciativa: 'red' | 'yellow' | 'green' | 'gray' (sem dados críticos).
     * Alertas vermelhos conhecidos continuam visíveis mesmo com dados incompletos.
     * $delay em dias úteis, $costPct em %, $maxRisk = maior exposição de risco aberto.
     */
    public static function health(?int $delay, ?float $costPct, ?int $maxRisk, array $th = []): string
    {
        $th += self::DEFAULT_THRESHOLDS;
        if (($delay !== null && $delay > $th['delay_red']) || ($costPct !== null && $costPct > $th['cost_red']) || ($maxRisk !== null && $maxRisk >= $th['risk_red'])) {
            return 'red';
        }
        if ($delay === null && $costPct === null && $maxRisk === null) {
            return 'gray';
        }
        if ($delay === null) {
            return 'gray'; // sem prazo de baseline não há como afirmar que está verde
        }
        if ($delay > $th['delay_yellow'] || ($costPct !== null && $costPct > $th['cost_yellow']) || ($maxRisk !== null && $maxRisk >= $th['risk_yellow'])) {
            return 'yellow';
        }
        return 'green';
    }

    // ---------- priorização ----------

    /**
     * Pontuação ponderada de critérios já normalizados (0 a 100). Os pesos devem somar 100.
     *
     * @param array<string,float> $scores
     * @param array<string,float> $weights
     */
    public static function priorityScore(array $scores, array $weights): float
    {
        if (abs(array_sum($weights) - 100.0) > 0.0001) {
            throw new \InvalidArgumentException('Os pesos dos critérios devem somar 100.');
        }
        $total = 0.0;
        foreach ($weights as $k => $w) {
            $total += ((float) ($scores[$k] ?? 0)) * $w / 100;
        }
        return round($total, 3);
    }

    /** Completude: campos obrigatórios válidos ÷ aplicáveis (null se nenhum é aplicável). */
    public static function completeness(int $valid, int $applicable): ?float
    {
        return $applicable > 0 ? round(100 * $valid / $applicable, 1) : null;
    }
}
