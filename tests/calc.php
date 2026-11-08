<?php
/**
 * Testes das regras de cálculo (seção 5 dos requisitos). Não precisa do GLPI:
 *
 *   php tests/calc.php
 */
require __DIR__ . '/../src/Calc.php';

use GlpiPlugin\Vmo\Calc;

$fail = 0;
$t = function (string $label, $got, $want) use (&$fail): void {
    $ok = is_float($want) || is_float($got) ? (is_null($want) ? $got === null : (!is_null($got) && abs($got - $want) < 0.0005)) : $got === $want;
    echo ($ok ? 'OK   ' : 'FALHA') . " $label" . ($ok ? '' : ' → obtido ' . var_export($got, true) . ', esperado ' . var_export($want, true)) . "\n";
    $fail += $ok ? 0 : 1;
};

// ---- progresso (exemplo da especificação: pesos 2 e 3, progresso 50 e 100 → 80%)
$t('progresso 2×50 + 3×100 = 80', Calc::progress([['weight' => 2, 'progress' => 50], ['weight' => 3, 'progress' => 100]]), 80.0);
$t('segunda folha cancelada → 50', Calc::progress([['weight' => 2, 'progress' => 50], ['weight' => 3, 'progress' => 100, 'status' => 'cancelled']]), 50.0);
$t('sem tarefas → sem dado (null), nunca 0', Calc::progress([]), null);
$t('só marcos de peso zero → sem dado', Calc::progress([['weight' => 0, 'progress' => 100]]), null);
$tasks = [1 => ['id' => 1, 'parent_id' => 0, 'weight' => 1, 'progress' => 0, 'status' => 'doing'], 2 => ['id' => 2, 'parent_id' => 1, 'weight' => 2, 'progress' => 50, 'status' => 'doing'],
    3 => ['id' => 3, 'parent_id' => 1, 'weight' => 3, 'progress' => 100, 'status' => 'done']];
$t('tarefa resumo não conta como folha', array_keys(Calc::leaves($tasks)), [2, 3]);
$t('progresso derivado da tarefa resumo', Calc::rollup($tasks)[1], 80.0);

// ---- hierarquia e grafo
$t('profundidade 3', Calc::depth([1 => 0, 2 => 1, 3 => 2], 3), 3);
$t('ciclo na hierarquia → 0', Calc::depth([1 => 2, 2 => 1], 1), 0);
$t('altura da subárvore', Calc::height([1 => 0, 2 => 1, 3 => 2], 1), 3);
$edges = [[1, 2], [2, 3]];
$t('A→B, B→C: inserir C→A cria ciclo', Calc::wouldCreateCycle($edges, 3, 1), true);
$t('autorrelação é ciclo', Calc::wouldCreateCycle([], 5, 5), true);
$t('A→C é válido', Calc::wouldCreateCycle($edges, 1, 3), false);

// ---- dias úteis
$t('sexta→segunda = 1 dia útil', Calc::businessDays('2026-11-06', '2026-11-09'), 1);
$t('com feriado na segunda = 0', Calc::businessDays('2026-11-06', '2026-11-09', ['2026-11-09']), 0);
$t('negativo quando invertido', Calc::businessDays('2026-11-09', '2026-11-06'), -1);
$t('soma 2 dias úteis a uma sexta → terça', Calc::addBusinessDays('2026-11-06', 2), '2026-11-10');
$t('atraso nunca é negativo', Calc::delay('2026-12-10', '2026-12-01'), 0);
$t('atraso 5 dias úteis', Calc::delay('2026-12-01', '2026-12-08'), 5);
$t('atraso sem data → null', Calc::delay(null, '2026-12-08'), null);

// ---- financeiro
$t('variação de custo 120 vs 100', Calc::costVariance(120.0, 100.0), [20.0, 20.0]);
$t('orçamento zero → percentual null', Calc::costVariance(10.0, 0.0), [10.0, null]);
$t('realização 100/200 = 50%', Calc::valueRealization(100.0, 200.0), 50.0);
$t('realização pode exceder 100%', Calc::valueRealization(300.0, 200.0), 150.0);
$t('denominador zero → não aplicável', Calc::valueRealization(10.0, 0.0), null);
$t('ROI (150−100)/100 = 50%', Calc::roi(150.0, 100.0), 50.0);
$t('ROI com custo zero → null', Calc::roi(150.0, 0.0), null);
$t('incremental (reduzir): (100−80)×10×2×0,5 = 200', Calc::incremental('reduce', 100, 80, 10, 2, 0.5), 200.0);
$t('incremental (aumentar): (80−50)×1×1×1 = 30', Calc::incremental('increase', 50, 80, 1, 1, 1), 30.0);
$t('benefício líquido', Calc::netBenefit(1000.0, 250.0), 750.0);

// ---- metas
$t('meta: baseline 0, meta 10, observado 5 → 50%', Calc::goalProgress(5.0, 0.0, 10.0), 50.0);
$t('meta sem teto: 20 → 200%', Calc::goalProgress(20.0, 0.0, 10.0), 200.0);
$t('meta igual à baseline usa comparação booleana (atingiu)', Calc::goalProgress(10.0, 10.0, 10.0, 'increase'), 100.0);
$t('meta de redução: baseline 100, meta 50, observado 75 → 50%', Calc::goalProgress(75.0, 100.0, 50.0, 'reduce'), 50.0);
$t('sem observação → null', Calc::goalProgress(null, 0.0, 10.0), null);

// ---- risco e saúde
$t('exposição 4×5 = 20', Calc::riskExposure(4, 5), 20);
$t('saúde vermelha por atraso > 10', Calc::health(11, 0.0, 1), 'red');
$t('saúde vermelha por custo > 20%', Calc::health(0, 25.0, 1), 'red');
$t('saúde vermelha por risco ≥ 20', Calc::health(0, 0.0, 20), 'red');
$t('saúde amarela por atraso > 0', Calc::health(3, 0.0, 1), 'yellow');
$t('saúde amarela por risco ≥ 12', Calc::health(0, 0.0, 12), 'yellow');
$t('saúde verde', Calc::health(0, 5.0, 4), 'green');
$t('sem dados críticos → cinza', Calc::health(null, null, null), 'gray');
$t('alerta vermelho conhecido continua visível sem outros dados', Calc::health(null, null, 25), 'red');
$t('limites configuráveis (delay_red = 3)', Calc::health(5, 0.0, 1, ['delay_red' => 3]), 'red');

// ---- priorização (exemplo da especificação: pesos 40/60, notas 50/100 → 80)
$t('pontuação 40%×50 + 60%×100 = 80', Calc::priorityScore(['a' => 50, 'b' => 100], ['a' => 40, 'b' => 60]), 80.0);
try {
    Calc::priorityScore(['a' => 1], ['a' => 50, 'b' => 40]);
    $t('pesos que não somam 100 são rejeitados', false, true);
} catch (InvalidArgumentException $e) {
    $t('pesos que não somam 100 são rejeitados', true, true);
}

// ---- números pt-BR
$t('1.234,56 → 1234.56', Calc::parseNumber('1.234,56'), 1234.56);
$t('1234.56 → 1234.56', Calc::parseNumber('1234.56'), 1234.56);
$t('12,5 → 12.5', Calc::parseNumber('12,5'), 12.5);
$t('1.234 (milhar) → 1234', Calc::parseNumber('1.234'), 1234.0);
$t('vazio → null', Calc::parseNumber(''), null);
$t('texto → null', Calc::parseNumber('abc'), null);
$t('formata moeda', Calc::formatMoney(1234.5, 'BRL'), 'R$ 1.234,50');
$t('completude 6/7', Calc::completeness(6, 7), 85.7);

echo $fail ? "\n$fail falha(s)\n" : "\nTodos os testes passaram.\n";
exit($fail ? 1 : 0);
