<?php

namespace GlpiPlugin\Vmo;

/**
 * Relatório executivo COMPLETO em PDF (paisagem A4): capa, visão executiva com indicadores e destaques,
 * portfólio, investimento e valor, ficha de cada iniciativa, benefícios, indicadores, riscos, marcos,
 * decisões e notas metodológicas. Usa só o snapshot (Report::load): nada é recalculado, então o arquivo
 * reproduz exatamente os números do corte.
 */
class ExecutiveReport
{
    private const NAVY = [15, 23, 42];
    private const BLUE = [37, 99, 235];
    private const MUTED = [100, 116, 139];
    private const LIGHT = [241, 245, 249];
    private const LINE = [203, 213, 225];
    private const W = 273;     // largura útil (A4 paisagem, margens de 12)
    private const BOTTOM = 192; // limite de conteúdo antes do rodapé

    private \TCPDF $pdf;
    private array $d;
    private bool $fin;
    /** @var array<int,string> id da iniciativa => código */
    private array $codes = [];
    /** @var array<int,string> id da iniciativa => nome */
    private array $names = [];

    public static function pdf(array $d): string
    {
        return (new self($d))->build();
    }

    private function __construct(array $d)
    {
        $this->d = $d;
        $this->fin = !empty($d['finance']);
        foreach ($d['rows'] as $r) {
            $this->codes[(int) $r['id']] = (string) $r['code'];
            $this->names[(int) $r['id']] = (string) $r['name'];
        }
        $this->pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
    }

    // ------------------------------------------------------------------ montagem

    private function build(): string
    {
        $p = $this->pdf;
        $p->SetCreator('VMO');
        $p->SetTitle($this->d['title']);
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        $p->SetMargins(12, 12, 12);
        $p->SetAutoPageBreak(false);

        $this->cover();
        $this->overview();
        $this->portfolio();
        if ($this->fin) {
            $this->financial();
        }
        $this->sheets();
        $this->benefitsAndIndicators();
        $this->risksMilestonesDecisions();
        $this->notes();
        $this->footers();

        return $p->Output('', 'S');
    }

    // ------------------------------------------------------------------ blocos

    private function cover(): void
    {
        $p = $this->pdf;
        $p->AddPage();
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 297, 210, 'F');
        $p->SetFillColor(...self::BLUE);
        $p->Rect(12, 70, 28, 2.4, 'F');
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('dejavusans', 'B', 30);
        $p->SetXY(12, 78);
        $p->MultiCell(240, 14, $this->d['title'], 0, 'L');
        $p->SetFont('dejavusans', '', 13);
        $p->SetTextColor(186, 200, 220);
        $p->SetX(12);
        $p->Cell(0, 9, 'Relatório executivo do portfólio de iniciativas', 0, 1);
        $p->SetFont('dejavusans', '', 10);
        $p->SetXY(12, 150);
        $meta = [
            'Data de corte: ' . $this->date($this->d['cutoff']),
            'Gerado por ' . $this->d['generated_by'] . ' em ' . $this->d['generated_at'],
            $this->d['totals']['count'] . ' iniciativa(s) no recorte · ' . $this->d['generator'],
        ];
        foreach ($meta as $m) {
            $p->SetX(12);
            $p->Cell(0, 6, $m, 0, 1);
        }
        $p->SetTextColor(0);
    }

    private function overview(): void
    {
        $p = $this->pdf;
        $this->page('Visão executiva', 'Situação do portfólio na data de corte');
        $t = $this->d['totals'];
        $rows = $this->d['rows'];
        $count = max(1, (int) $t['count']);

        $prog = array_filter(array_column($rows, 'progress'), fn ($v) => $v !== null);
        $avg = $prog ? array_sum($prog) / count($prog) : null;
        $red = (int) ($t['by_health']['red'] ?? 0);
        $yellow = (int) ($t['by_health']['yellow'] ?? 0);
        $green = (int) ($t['by_health']['green'] ?? 0);

        $cards = [
            ['Iniciativas', (string) $t['count'], 'no recorte', self::BLUE],
            ['No prazo', (string) $green, round($green * 100 / $count) . '% do portfólio', [34, 197, 94]],
            ['Atenção / Crítica', ($yellow + $red) . ' (' . $yellow . ' / ' . $red . ')', 'exigem acompanhamento', [239, 68, 68]],
            ['Progresso médio', $avg === null ? 'sem dado' : $this->num($avg, 1) . '%', 'tarefas ponderadas', [99, 102, 241]],
        ];
        if ($this->fin) {
            $cards[] = ['Valor validado', $this->moneyMap($t['value_validated'] ?? []), 'previsto: ' . $this->moneyMap($t['value_planned'] ?? []), [168, 85, 247]];
        }
        $w = (self::W - 4 * (count($cards) - 1)) / count($cards);
        $y = $p->GetY();
        foreach ($cards as $i => [$label, $value, $sub, $color]) {
            $this->card(12 + $i * ($w + 4), $y, $w, 26, $label, $value, $sub, $color);
        }
        $y += 32;

        // saúde e situação (barras horizontais)
        $this->sectionTitle('Saúde do portfólio', 12, $y);
        $yy = $y + 8;
        foreach (Menu::HEALTH as $k => [$label, $hex]) {
            $n = (int) ($t['by_health'][$k] ?? 0);
            $this->hbar(12, $yy, 120, $label, $n, $count, $this->rgb($hex));
            $yy += 8;
        }
        $this->sectionTitle('Situação das iniciativas', 150, $y);
        $yy2 = $y + 8;
        $shown = 0;
        foreach (Initiative::STATUSES as $k => [$label, $hex]) {
            $n = (int) ($t['by_status'][$k] ?? 0);
            if ($n === 0 || $shown >= 6) {
                continue;
            }
            $this->hbar(150, $yy2, 120, $label, $n, $count, $this->rgb($hex));
            $yy2 += 8;
            $shown++;
        }

        // destaques
        $y = max($yy, $yy2) + 6;
        $this->sectionTitle('Destaques para decisão', 12, $y);
        $p->SetXY(12, $y + 8);
        $p->SetFont('dejavusans', '', 9);
        foreach ($this->highlights() as $h) {
            $p->SetTextColor(...self::BLUE);
            $p->SetX(12);
            $p->Cell(4, 5.4, '•');
            $p->SetTextColor(30);
            $p->MultiCell(self::W - 4, 5.4, $h, 0, 'L', false, 1, 16);
        }
        $p->SetTextColor(0);
    }

    /** Frases curtas e objetivas geradas dos próprios números do snapshot. */
    private function highlights(): array
    {
        $rows = $this->d['rows'];
        $out = [];
        $crit = array_values(array_filter($rows, fn ($r) => $r['health'] === 'red'));
        if ($crit) {
            $out[] = count($crit) . ' iniciativa(s) em situação crítica: ' . implode('; ', array_map(fn ($r) => $r['code'] . ' ' . $r['name'], array_slice($crit, 0, 4))) . '.';
        } else {
            $out[] = 'Nenhuma iniciativa em situação crítica no corte.';
        }
        $late = array_values(array_filter($rows, fn ($r) => ($r['delay'] ?? 0) > 0));
        if ($late) {
            usort($late, fn ($a, $b) => $b['delay'] <=> $a['delay']);
            $out[] = 'Maior atraso previsto: ' . $late[0]['code'] . ' ' . $late[0]['name'] . ' (' . $late[0]['delay'] . ' dias úteis em relação à baseline).';
        }
        if ($this->fin) {
            $over = [];
            foreach ($rows as $r) {
                if (($r['budget'] ?? null) && ($r['forecast'] ?? null) && $r['forecast'] > $r['budget']) {
                    $over[] = $r['code'] . ' (+' . $this->num(($r['forecast'] - $r['budget']) * 100 / $r['budget'], 1) . '%)';
                }
            }
            $out[] = $over ? 'Previsão de custo acima do orçamento da baseline em: ' . implode(', ', array_slice($over, 0, 5)) . '.'
                : 'Nenhuma iniciativa com previsão de custo acima do orçamento da baseline.';
        }
        if (!empty($this->d['risks'])) {
            $r = $this->d['risks'][0];
            $out[] = 'Maior exposição a risco: "' . $r['title'] . '" (' . ($this->codes[$r['initiative']] ?? '—') . ', exposição ' . $r['exposure'] . ').';
        }
        if (!empty($this->d['milestones'])) {
            $m = $this->d['milestones'][0];
            $out[] = 'Próximo marco: "' . $m['title'] . '" (' . ($this->codes[$m['initiative']] ?? '—') . ') em ' . $this->date($m['date']) . '.';
        }
        $nodata = count(array_filter($rows, fn ($r) => $r['health'] === 'gray'));
        if ($nodata) {
            $out[] = $nodata . ' iniciativa(s) ainda sem dados suficientes para calcular a saúde (não iniciadas ou sem baseline).';
        }
        return $out;
    }

    private function portfolio(): void
    {
        $this->page('Portfólio de iniciativas', 'Progresso, prazo e saúde de cada iniciativa');
        $cols = [['Código', 24], ['Iniciativa', 73], ['Situação', 30], ['Gestor', 34], ['Progresso', 38], ['Término prev.', 26], ['Atraso', 20], ['Saúde', 28]];
        $this->tableHeader($cols);
        foreach ($this->d['rows'] as $r) {
            if ($this->pdf->GetY() > self::BOTTOM - 8) {
                $this->page('Portfólio de iniciativas', 'continuação');
                $this->tableHeader($cols);
            }
            $y = $this->pdf->GetY();
            $x = 12;
            $vals = [$r['code'], $r['name'], Initiative::STATUSES[$r['status']][0] ?? $r['status'], $r['manager'], null,
                $this->date($r['forecast_end'] ?? $r['planned_end']), $r['delay'] === null ? 'sem dado' : $r['delay'] . ' d.ú.', null];
            foreach ($cols as $i => [, $w]) {
                $this->pdf->SetFont('dejavusans', '', 8);
                $this->pdf->SetXY($x, $y);
                $this->pdf->Cell($w, 7, '', 1);
                if ($i === 4) {
                    $this->progressCell($x, $y, $w, $r['progress']);
                } elseif ($i === 7) {
                    $hex = Menu::HEALTH[$r['health']][1] ?? '#94a3b8';
                    $this->pdf->SetFillColor(...$this->rgb($hex));
                    $this->pdf->Circle($x + 4, $y + 3.5, 1.6, 0, 360, 'F');
                    $this->pdf->SetXY($x + 7, $y);
                    $this->pdf->Cell($w - 7, 7, Menu::HEALTH[$r['health']][0] ?? '', 0, 0);
                } else {
                    $this->pdf->SetXY($x + 1, $y);
                    $this->pdf->Cell($w - 2, 7, $this->cut((string) $vals[$i], $w - 2), 0, 0);
                }
                $x += $w;
            }
            $this->pdf->SetY($y + 7);
        }
    }

    private function financial(): void
    {
        $this->page('Investimento e valor', 'Orçamento da baseline, previsão e custo realizado; benefício previsto e validado');
        $cols = [['Código', 22], ['Iniciativa', 60], ['Orçamento', 28], ['Previsão', 28], ['Real', 28], ['Desvio', 18], ['Benef. previsto', 30], ['Validado', 28], ['Realiz.', 15], ['ROI', 16]];
        $this->tableHeader($cols);
        foreach ($this->d['rows'] as $r) {
            if ($this->pdf->GetY() > self::BOTTOM - 8) {
                $this->page('Investimento e valor', 'continuação');
                $this->tableHeader($cols);
            }
            $cur = $r['currency'];
            $dev = (($r['budget'] ?? null) && ($r['forecast'] ?? null)) ? $this->num(($r['forecast'] - $r['budget']) * 100 / $r['budget'], 1) . '%' : '—';
            $vals = [$r['code'], $r['name'], $this->money($r['budget'] ?? null, $cur), $this->money($r['forecast'] ?? null, $cur), $this->money($r['actual'] ?? null, $cur), $dev,
                $this->money($r['value_planned'] ?? null, $cur), $this->money($r['value_validated'] ?? null, $cur),
                isset($r['realization']) ? $this->num($r['realization'], 0) . '%' : '—', isset($r['roi']) ? $this->num($r['roi'], 0) . '%' : '—'];
            $this->tableRow($cols, $vals);
        }

        // custo por categoria
        if (!empty($this->d['costs_cat'])) {
            $this->pdf->Ln(6);
            if ($this->pdf->GetY() > self::BOTTOM - 40) {
                $this->page('Investimento e valor', 'custo por categoria');
            }
            $this->sectionTitle('Custo por categoria', 12, $this->pdf->GetY());
            $this->pdf->Ln(8);
            $by = [];
            foreach ($this->d['costs_cat'] as $c) {
                $key = ($c['category'] ?: 'Sem categoria') . '|' . $c['cost_type'] . '|' . $c['currency'];
                $by[$key]['cat'] = $c['category'] ?: 'Sem categoria';
                $by[$key]['type'] = mb_strtoupper((string) $c['cost_type']);
                $by[$key]['cur'] = $c['currency'];
                $by[$key][$c['kind']] = ($by[$key][$c['kind']] ?? 0) + (float) $c['total'];
            }
            $cols2 = [['Categoria', 110], ['Tipo', 24], ['Previsão', 45], ['Real', 45], ['Executado', 49]];
            $this->tableHeader($cols2);
            foreach ($by as $b) {
                if ($this->pdf->GetY() > self::BOTTOM - 8) {
                    $this->page('Investimento e valor', 'custo por categoria (continuação)');
                    $this->tableHeader($cols2);
                }
                $f = $b['forecast'] ?? 0;
                $a = $b['actual'] ?? 0;
                $this->tableRow($cols2, [$b['cat'], $b['type'], $this->money($f, $b['cur']), $this->money($a, $b['cur']), $f > 0 ? $this->num($a * 100 / $f, 0) . '%' : '—']);
            }
        }
    }

    private function sheets(): void
    {
        $max = 14;
        foreach (array_slice($this->d['rows'], 0, $max) as $r) {
            $id = (int) $r['id'];
            $det = $this->d['details'][$id] ?? [];
            $hex = Menu::HEALTH[$r['health']][1] ?? '#94a3b8';
            $this->page($r['code'] . ' · ' . $r['name'], 'Ficha da iniciativa');
            $p = $this->pdf;
            $y = $p->GetY();

            // faixa de situação e saúde
            $p->SetFillColor(...self::LIGHT);
            $p->Rect(12, $y, self::W, 11, 'F');
            $p->SetFillColor(...$this->rgb($hex));
            $p->Circle(18, $y + 5.5, 2.2, 0, 360, 'F');
            $p->SetFont('dejavusans', 'B', 10);
            $p->SetTextColor(30);
            $p->SetXY(23, $y + 2);
            $p->Cell(60, 7, (Menu::HEALTH[$r['health']][0] ?? '') . ' · ' . (Initiative::STATUSES[$r['status']][0] ?? $r['status']));
            $p->SetFont('dejavusans', '', 9);
            $p->SetTextColor(...self::MUTED);
            $p->Cell(0, 7, 'Gestor: ' . $r['manager'] . '   Patrocinador: ' . $r['sponsor'] . '   Prioridade: ' . (Initiative::PRIORITIES[$det['priority'] ?? 0] ?? '—'), 0, 0);
            $p->SetTextColor(0);

            // coluna esquerda: texto
            $y += 16;
            $p->SetXY(12, $y);
            $this->label('Objetivo');
            $p->SetFont('dejavusans', '', 9);
            $p->MultiCell(150, 5, ($det['objective'] ?? '') !== '' ? $det['objective'] : '—', 0, 'L', false, 1, 12);
            $p->Ln(2);
            $this->label('Descrição');
            $p->SetFont('dejavusans', '', 9);
            $p->MultiCell(150, 5, ($det['description'] ?? '') !== '' ? $det['description'] : '—', 0, 'L', false, 1, 12);

            // coluna direita: indicadores
            $x = 175;
            $yy = $y;
            $this->label('Progresso', $x, $yy);
            $this->bar($x, $yy + 6, 110, 4, $r['progress'] ?? 0, 100, self::BLUE);
            $p->SetFont('dejavusans', 'B', 9);
            $p->SetXY($x, $yy + 11);
            $p->Cell(110, 5, $r['progress'] === null ? 'sem dado' : $this->num($r['progress'], 1) . '%');
            $yy += 20;
            $this->label('Prazo', $x, $yy);
            $p->SetFont('dejavusans', '', 9);
            $p->SetXY($x, $yy + 5);
            $p->MultiCell(110, 5, 'Planejado: ' . $this->date($r['planned_start']) . ' → ' . $this->date($r['planned_end'])
                . "\nPrevisão de término: " . $this->date($r['forecast_end'] ?? $r['planned_end'])
                . "\nAtraso vs baseline: " . ($r['delay'] === null ? 'sem dado' : $r['delay'] . ' dias úteis')
                . "\nTarefas: " . ($r['tasks'] ?? 0) . ' (' . ($r['late_tasks'] ?? 0) . ' vencida(s))', 0, 'L');
            $yy = $p->GetY() + 3;
            if ($this->fin) {
                $this->label('Custo e valor (' . $r['currency'] . ')', $x, $yy);
                $yy += 6;
                $top = max(1.0, (float) max($r['budget'] ?? 0, $r['forecast'] ?? 0, $r['actual'] ?? 0));
                foreach ([['Orçamento', $r['budget'] ?? null, [148, 163, 184]], ['Previsão', $r['forecast'] ?? null, self::BLUE], ['Real', $r['actual'] ?? null, [34, 197, 94]]] as [$lab, $v, $col]) {
                    $p->SetFont('dejavusans', '', 8);
                    $p->SetXY($x, $yy);
                    $p->Cell(22, 5, $lab);
                    $this->bar($x + 22, $yy + 1, 50, 3, (float) ($v ?? 0), $top, $col);
                    $p->SetXY($x + 74, $yy);
                    $p->Cell(36, 5, $this->money($v, $r['currency']), 0, 0, 'R');
                    $yy += 6;
                }
                $p->SetFont('dejavusans', '', 8);
                $p->SetXY($x, $yy);
                $p->MultiCell(110, 4.6, 'Benefício previsto: ' . $this->money($r['value_planned'] ?? null, $r['currency'])
                    . "\nBenefício validado: " . $this->money($r['value_validated'] ?? null, $r['currency'])
                    . '   Realização: ' . (isset($r['realization']) ? $this->num($r['realization'], 0) . '%' : '—')
                    . '   ROI: ' . (isset($r['roi']) ? $this->num($r['roi'], 0) . '%' : '—'), 0, 'L');
            }

            // rodapé da ficha: riscos, marcos e decisões da iniciativa
            $yb = max($p->GetY(), $y + 52) + 4;
            $this->miniList('Riscos abertos', array_values(array_filter($this->d['risks'] ?? [], fn ($x2) => (int) $x2['initiative'] === $id)), fn ($i) => $i['title'] . ' (exposição ' . $i['exposure'] . ')', 12, $yb, 88);
            $this->miniList('Próximos marcos', array_values(array_filter($this->d['milestones'] ?? [], fn ($x2) => (int) $x2['initiative'] === $id)), fn ($i) => $i['title'] . ' — ' . $this->date($i['date']), 106, $yb, 88);
            $this->miniList('Decisões recentes', array_values(array_filter($this->d['decisions'] ?? [], fn ($x2) => (int) $x2['initiative'] === $id)), fn ($i) => $i['title'] . ' — ' . $this->date(substr((string) $i['date'], 0, 10)), 200, $yb, 85);
        }
        if (count($this->d['rows']) > $max) {
            $this->page('Fichas das iniciativas', 'limite do relatório');
            $this->pdf->SetFont('dejavusans', '', 9);
            $this->pdf->MultiCell(self::W, 5, 'Foram detalhadas as ' . $max . ' primeiras iniciativas do recorte. As demais constam no portfólio e no arquivo CSV; use os filtros para gerar um relatório focado.', 0, 'L');
        }
    }

    private function benefitsAndIndicators(): void
    {
        $ben = $this->d['benefits'] ?? [];
        $ind = $this->d['indicators'] ?? [];
        if (!$ben && !$ind) {
            return;
        }
        $this->page('Benefícios e indicadores', 'Resultado esperado e acompanhamento de metas');
        if ($ben) {
            $this->sectionTitle('Benefícios', 12, $this->pdf->GetY());
            $this->pdf->Ln(8);
            $cols = [['Benefício', 70], ['Tipo', 32], ['Situação', 24], ['Dono', 36], ['Base', 22], ['Meta', 20], ['Atual', 20], ['Atingido', 22], ['Iniciativas', 27]];
            $this->tableHeader($cols);
            foreach ($ben as $b) {
                if ($this->pdf->GetY() > self::BOTTOM - 8) {
                    $this->page('Benefícios e indicadores', 'continuação');
                    $this->tableHeader($cols);
                }
                $this->tableRow($cols, [$b['name'], $b['type'], $b['status'], $b['owner'], $this->nv($b['baseline']), $this->nv($b['target']), $this->nv($b['current']),
                    $b['progress'] === null ? '—' : $this->num($b['progress'], 0) . '%', $b['initiatives']]);
            }
            $this->pdf->Ln(6);
        }
        if ($ind) {
            if ($this->pdf->GetY() > self::BOTTOM - 40) {
                $this->page('Benefícios e indicadores', 'indicadores');
            }
            $this->sectionTitle('Indicadores', 12, $this->pdf->GetY());
            $this->pdf->Ln(8);
            $cols = [['Indicador', 90], ['Unidade', 24], ['Base', 28], ['Meta', 24], ['Atual', 24], ['Meta atingida', 32], ['Leitura', 26], ['Situação', 25]];
            $this->tableHeader($cols);
            foreach ($ind as $i) {
                if ($this->pdf->GetY() > self::BOTTOM - 8) {
                    $this->page('Benefícios e indicadores', 'indicadores (continuação)');
                    $this->tableHeader($cols);
                }
                $this->tableRow($cols, [$i['name'], $i['unit'], $this->nv($i['baseline']), $this->nv($i['target']), $this->nv($i['value']),
                    $i['progress'] === null ? '—' : $this->num($i['progress'], 0) . '%', $this->date($i['last']), $i['label']]);
            }
        }
    }

    private function risksMilestonesDecisions(): void
    {
        $this->page('Riscos, marcos e decisões', 'O que exige atenção da liderança');
        $blocks = [
            ['Principais riscos', [['Iniciativa', 24], ['Risco / problema', 190], ['Exposição', 25], ['Tipo', 34]], array_map(
                fn ($r) => [$this->codes[$r['initiative']] ?? '—', $r['title'], (string) $r['exposure'], $r['kind'] === 'issue' ? 'Problema' : 'Risco'], array_slice($this->d['risks'] ?? [], 0, 12))],
            ['Próximos marcos', [['Iniciativa', 24], ['Marco', 190], ['Data', 59]], array_map(
                fn ($r) => [$this->codes[$r['initiative']] ?? '—', $r['title'], $this->date($r['date'])], array_slice($this->d['milestones'] ?? [], 0, 12))],
            ['Decisões recentes', [['Iniciativa', 24], ['Decisão', 190], ['Data', 59]], array_map(
                fn ($r) => [$this->codes[$r['initiative']] ?? '—', $r['title'], $this->date(substr((string) $r['date'], 0, 10))], array_slice($this->d['decisions'] ?? [], 0, 12))],
        ];
        foreach ($blocks as [$title, $cols, $rows]) {
            if ($this->pdf->GetY() > self::BOTTOM - 30) {
                $this->page('Riscos, marcos e decisões', 'continuação');
            }
            $this->sectionTitle($title, 12, $this->pdf->GetY());
            $this->pdf->Ln(8);
            if (!$rows) {
                $this->pdf->SetFont('dejavusans', '', 9);
                $this->pdf->SetTextColor(...self::MUTED);
                $this->pdf->Cell(0, 6, 'Nada registrado no recorte.', 0, 1);
                $this->pdf->SetTextColor(0);
                $this->pdf->Ln(3);
                continue;
            }
            $this->tableHeader($cols);
            foreach ($rows as $row) {
                if ($this->pdf->GetY() > self::BOTTOM - 8) {
                    $this->page('Riscos, marcos e decisões', 'continuação');
                    $this->tableHeader($cols);
                }
                $this->tableRow($cols, $row);
            }
            $this->pdf->Ln(5);
        }
    }

    private function notes(): void
    {
        $d = $this->d;
        $this->page('Notas metodológicas', 'Como ler os números deste relatório');
        $this->pdf->SetFont('dejavusans', '', 9);
        $items = [
            'Snapshot: os números foram gravados na data de corte (' . $this->date($d['cutoff']) . '). Editar uma iniciativa depois não altera este relatório; reprocessar o snapshot devolve os mesmos números (hash do conteúdo).',
            'Fórmulas v' . $d['formula_version'] . '; limites de saúde v' . $d['thresholds_version'] . ': atraso crítico acima de ' . $d['thresholds']['delay_red'] . ' dias úteis, desvio de custo crítico acima de '
                . $d['thresholds']['cost_red'] . '% e risco crítico a partir de exposição ' . $d['thresholds']['risk_red'] . '.',
            'Progresso é a média ponderada das tarefas-folha elegíveis. Atraso compara a previsão de término com a baseline, em dias úteis.',
            'Benefício validado soma apenas medições aprovadas por pessoa diferente de quem as declarou. Valores em moedas diferentes não são somados sem taxa registrada.',
            'Dado ausente aparece como "sem dado" e nunca como zero.',
            'Os dados respeitam o acesso de quem gera e de quem baixa o arquivo; sem o direito financeiro, valores em dinheiro são omitidos.',
            'Filtros aplicados: ' . ($d['filters'] ? json_encode($d['filters'], JSON_UNESCAPED_UNICODE) : 'nenhum (todas as iniciativas visíveis)') . '.',
        ];
        foreach ($items as $it) {
            $this->pdf->SetTextColor(...self::BLUE);
            $this->pdf->Cell(4, 5.4, '•');
            $this->pdf->SetTextColor(30);
            $this->pdf->MultiCell(self::W - 4, 5.4, $it, 0, 'L', false, 1, 16);
            $this->pdf->Ln(1);
        }
        $this->pdf->SetTextColor(0);
    }

    private function footers(): void
    {
        $p = $this->pdf;
        $n = $p->getNumPages();
        for ($i = 2; $i <= $n; $i++) {
            $p->setPage($i);
            $p->SetDrawColor(...self::LINE);
            $p->Line(12, 198, 285, 198);
            $p->SetFont('dejavusans', '', 7.5);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY(12, 199.5);
            $p->Cell(200, 5, $this->d['title'] . ' · corte ' . $this->date($this->d['cutoff']) . ' · confidencial', 0, 0, 'L');
            $p->Cell(73, 5, 'Página ' . $i . ' de ' . $n, 0, 0, 'R');
        }
        $p->SetTextColor(0);
        $p->SetDrawColor(0);
    }

    // ------------------------------------------------------------------ desenho

    /** Nova página com faixa de título. */
    private function page(string $title, string $sub = ''): void
    {
        $p = $this->pdf;
        $p->AddPage();
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 297, 20, 'F');
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('dejavusans', 'B', 14);
        $p->SetXY(12, 4);
        $p->Cell(self::W, 7, $this->cut($title, 250), 0, 1);
        if ($sub !== '') {
            $p->SetFont('dejavusans', '', 8.5);
            $p->SetTextColor(186, 200, 220);
            $p->SetX(12);
            $p->Cell(self::W, 5, $sub, 0, 1);
        }
        $p->SetTextColor(0);
        $p->SetXY(12, 27);
    }

    private function sectionTitle(string $t, float $x, float $y): void
    {
        $this->pdf->SetFont('dejavusans', 'B', 11);
        $this->pdf->SetTextColor(...self::NAVY);
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(0, 6, $t);
        $this->pdf->SetFillColor(...self::BLUE);
        $this->pdf->Rect($x, $y + 6.4, 14, 0.8, 'F');
        $this->pdf->SetTextColor(0);
        $this->pdf->SetY($y + 6);
    }

    private function label(string $t, ?float $x = null, ?float $y = null): void
    {
        if ($x !== null) {
            $this->pdf->SetXY($x, $y);
        }
        $this->pdf->SetFont('dejavusans', 'B', 8.5);
        $this->pdf->SetTextColor(...self::MUTED);
        $this->pdf->Cell(0, 5, mb_strtoupper($t), 0, 1);
        $this->pdf->SetTextColor(0);
        if ($x !== null) {
            $this->pdf->SetXY($x, $y + 5);
        }
    }

    private function card(float $x, float $y, float $w, float $h, string $label, string $value, string $sub, array $color): void
    {
        $p = $this->pdf;
        $p->SetFillColor(...self::LIGHT);
        $p->RoundedRect($x, $y, $w, $h, 2, '1111', 'F');
        $p->SetFillColor(...$color);
        $p->Rect($x, $y + 2, 1.6, $h - 4, 'F');
        $p->SetFont('dejavusans', '', 8);
        $p->SetTextColor(...self::MUTED);
        $p->SetXY($x + 5, $y + 3);
        $p->Cell($w - 6, 4, $this->cut($label, $w - 6));
        $p->SetFont('dejavusans', 'B', 15);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY($x + 5, $y + 8.5);
        $p->Cell($w - 6, 8, $this->cut($value, $w - 6, 1.35));
        $p->SetFont('dejavusans', '', 7.5);
        $p->SetTextColor(...self::MUTED);
        $p->SetXY($x + 5, $y + 19);
        $p->Cell($w - 6, 4, $this->cut($sub, $w - 6));
        $p->SetTextColor(0);
    }

    private function hbar(float $x, float $y, float $w, string $label, int $n, int $total, array $color): void
    {
        $p = $this->pdf;
        $p->SetFont('dejavusans', '', 8.5);
        $p->SetXY($x, $y);
        $p->Cell(30, 5, $label);
        $this->bar($x + 32, $y + 1, $w - 48, 3.6, $n, max(1, $total), $color);
        $p->SetXY($x + $w - 14, $y);
        $p->SetFont('dejavusans', 'B', 8.5);
        $p->Cell(14, 5, (string) $n, 0, 0, 'R');
    }

    /** Barra de proporção: valor/máximo. */
    private function bar(float $x, float $y, float $w, float $h, float $value, float $max, array $color): void
    {
        $p = $this->pdf;
        $p->SetFillColor(226, 232, 240);
        $p->Rect($x, $y, $w, $h, 'F');
        $f = $max > 0 ? max(0.0, min(1.0, $value / $max)) : 0.0;
        if ($f > 0) {
            $p->SetFillColor(...$color);
            $p->Rect($x, $y, $w * $f, $h, 'F');
        }
    }

    private function progressCell(float $x, float $y, float $w, ?float $progress): void
    {
        if ($progress === null) {
            $this->pdf->SetXY($x + 1, $y);
            $this->pdf->Cell($w - 2, 7, 'sem dado', 0, 0);
            return;
        }
        $this->bar($x + 2, $y + 2.6, $w - 18, 1.8, $progress, 100, self::BLUE);
        $this->pdf->SetXY($x + $w - 15, $y);
        $this->pdf->Cell(14, 7, $this->num($progress, 0) . '%', 0, 0, 'R');
    }

    private function tableHeader(array $cols): void
    {
        $p = $this->pdf;
        $p->SetFont('dejavusans', 'B', 8);
        $p->SetFillColor(...self::NAVY);
        $p->SetTextColor(255, 255, 255);
        $p->SetDrawColor(...self::LINE);
        $x = 12;
        $y = $p->GetY();
        foreach ($cols as [$label, $w]) {
            $p->SetXY($x, $y);
            $p->Cell($w, 7, $this->cut($label, $w - 2), 1, 0, 'L', true);
            $x += $w;
        }
        $p->SetY($y + 7);
        $p->SetTextColor(0);
    }

    private function tableRow(array $cols, array $vals): void
    {
        $p = $this->pdf;
        $p->SetFont('dejavusans', '', 8);
        $p->SetDrawColor(...self::LINE);
        $x = 12;
        $y = $p->GetY();
        foreach ($cols as $i => [, $w]) {
            $p->SetXY($x, $y);
            $p->Cell($w, 7, $this->cut((string) ($vals[$i] ?? ''), $w - 2), 1, 0, 'L');
            $x += $w;
        }
        $p->SetY($y + 7);
    }

    private function miniList(string $title, array $items, callable $fmt, float $x, float $y, float $w): void
    {
        $p = $this->pdf;
        $p->SetFont('dejavusans', 'B', 8.5);
        $p->SetTextColor(...self::MUTED);
        $p->SetXY($x, $y);
        $p->Cell($w, 5, mb_strtoupper($title));
        $p->SetTextColor(30);
        $p->SetFont('dejavusans', '', 8);
        $yy = $y + 6;
        if (!$items) {
            $p->SetXY($x, $yy);
            $p->SetTextColor(...self::MUTED);
            $p->Cell($w, 5, 'Nada registrado.');
        }
        foreach (array_slice($items, 0, 4) as $it) {
            if ($yy > self::BOTTOM - 6) {
                break;
            }
            $p->SetXY($x, $yy);
            $p->MultiCell($w, 4.4, '• ' . $this->cut((string) $fmt($it), $w * 2.1), 0, 'L', false, 1, $x);
            $yy = $p->GetY() + 0.5;
        }
        $p->SetTextColor(0);
    }

    // ------------------------------------------------------------------ formatação

    private function cut(string $s, float $mmWidth, float $factor = 1.0): string
    {
        return mb_strimwidth($s, 0, max(4, (int) ($mmWidth / (1.75 * $factor))), '…');
    }

    private function num(?float $v, int $dec = 2): string
    {
        return $v === null ? '—' : number_format($v, $dec, ',', '.');
    }

    private function nv($v): string
    {
        return $v === null || $v === '' ? '—' : $this->num((float) $v, 1);
    }

    private function money(?float $v, string $cur): string
    {
        return $v === null ? '—' : Calc::formatMoney((float) $v, $cur);
    }

    private function moneyMap(?array $by): string
    {
        if (!$by) {
            return '—';
        }
        $parts = [];
        foreach ($by as $cur => $v) {
            $parts[] = Calc::formatMoney((float) $v, (string) $cur);
        }
        return implode(' | ', $parts);
    }

    private function date(?string $s): string
    {
        if (!$s) {
            return '—';
        }
        $t = strtotime(substr($s, 0, 10));
        return $t ? date('d/m/Y', $t) : $s;
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
