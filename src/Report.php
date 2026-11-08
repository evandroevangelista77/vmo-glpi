<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Relatórios executivos (RF17). Cada relatório grava um snapshot consistente (números no corte, filtros,
 * versões de fórmula e limites) e gera PDF, DOCX ou CSV a partir dele: editar a iniciativa depois não muda
 * um relatório já gerado, e reprocessar o snapshot devolve os mesmos números (hash do conteúdo).
 */
class Report
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_snapshots';
    }

    // ---------- dados do portfólio no corte ----------

    /**
     * Linhas do portfólio com indicadores, respeitando acesso ANTES de qualquer soma (RN09).
     *
     * @return array{rows:array,totals:array,rows_raw:array}
     */
    public static function portfolio(array $filters, ?string $cutoff = null): array
    {
        $cutoff = $cutoff ?: Db::today();
        $where = [];
        if (!empty($filters['status']) && isset(Initiative::STATUSES[$filters['status']])) {
            $where['status'] = $filters['status'];
        }
        if (!empty($filters['manager'])) {
            $where['users_id_manager'] = (int) $filters['manager'];
        }
        if (!empty($filters['entity'])) {
            $where['entities_id'] = (int) $filters['entity'];
        }
        $visible = Access::visibleInitiatives($where);
        // período: iniciativas cujo planejado intersecta [from, to]
        $from = Db::date($filters['from'] ?? '');
        $to = Db::date($filters['to'] ?? '');
        $visible = array_filter($visible, function ($r) use ($from, $to) {
            if ($from && $r['planned_end'] && $r['planned_end'] < $from) {
                return false;
            }
            if ($to && $r['planned_start'] && $r['planned_start'] > $to) {
                return false;
            }
            return true;
        });
        $finance = Access::canFinance();
        $metrics = Initiative::metricsFor($visible, $cutoff, $finance);
        if (!empty($filters['health'])) {
            $visible = array_filter($visible, fn ($r) => ($metrics[(int) $r['id']]['health'] ?? '') === $filters['health']);
        }
        $rows = [];
        $planned = [];
        $validated = [];
        $actual = [];
        foreach ($visible as $id => $r) {
            $m = $metrics[$id];
            $cur = $r['currency'];
            $row = [
                'id' => (int) $id, 'code' => $r['code'], 'name' => $r['name'], 'entity' => (int) $r['entities_id'], 'status' => $r['status'],
                'manager' => Db::userName((int) $r['users_id_manager']), 'sponsor' => Db::userName((int) $r['users_id_sponsor']),
                'planned_start' => $r['planned_start'], 'planned_end' => $r['planned_end'], 'forecast_end' => $r['forecast_end'],
                'progress' => $m['progress'], 'delay' => $m['delay'], 'health' => $m['health'], 'max_risk' => $m['max_risk'], 'currency' => $cur,
            ];
            if ($finance) {
                $row += ['budget' => $m['budget'], 'forecast' => $m['forecast'], 'actual' => $m['actual'],
                    'value_planned' => $m['value']['planned'][$cur] ?? null, 'value_validated' => $m['value']['validated'][$cur] ?? null,
                    'realization' => $m['realization'], 'roi' => $m['roi']];
                foreach (($m['value']['planned'] ?? []) as $c => $v) {
                    $planned[$c] = ($planned[$c] ?? 0) + $v;
                }
                foreach (($m['value']['validated'] ?? []) as $c => $v) {
                    $validated[$c] = ($validated[$c] ?? 0) + $v;
                }
                foreach ($m['costs'] as $c => $v) {
                    $actual[$c] = ($actual[$c] ?? 0) + ($v['actual'] ?? 0);
                }
            }
            $rows[] = $row;
        }
        $byHealth = array_count_values(array_column($rows, 'health'));
        $byStatus = array_count_values(array_column($rows, 'status'));
        return [
            'rows' => $rows,
            'totals' => ['count' => count($rows), 'by_health' => $byHealth, 'by_status' => $byStatus,
                'value_planned' => $finance ? array_map(fn ($v) => round($v, 4), $planned) : null,
                'value_validated' => $finance ? array_map(fn ($v) => round($v, 4), $validated) : null,
                'investment' => $finance ? array_map(fn ($v) => round($v, 4), $actual) : null],
            'finance' => $finance,
            'cutoff' => $cutoff,
        ];
    }

    /** Conteúdo narrativo para o executivo: riscos, decisões e marcos das iniciativas do recorte. */
    public static function extras(array $ids): array
    {
        global $DB;
        $risks = [];
        $decisions = [];
        $milestones = [];
        if (!$ids) {
            return compact('risks', 'decisions', 'milestones');
        }
        foreach ($DB->request(['FROM' => Db::t('risks'), 'WHERE' => ['initiatives_id' => $ids, 'is_deleted' => 0, 'status' => ['open', 'mitigating']]]) as $r) {
            $risks[] = ['initiative' => (int) $r['initiatives_id'], 'title' => $r['title'], 'exposure' => Calc::riskExposure((int) $r['probability'], (int) $r['impact']), 'kind' => $r['kind']];
        }
        usort($risks, fn ($a, $b) => $b['exposure'] <=> $a['exposure']);
        foreach ($DB->request(['FROM' => Db::t('decisions'), 'WHERE' => ['initiatives_id' => $ids], 'ORDER' => ['id DESC'], 'LIMIT' => 30]) as $d) {
            $decisions[] = ['initiative' => (int) $d['initiatives_id'], 'title' => $d['title'], 'date' => $d['decided_at']];
        }
        foreach ($DB->request(['FROM' => Db::t('tasks'), 'WHERE' => ['initiatives_id' => $ids, 'is_deleted' => 0, 'is_milestone' => 1, 'status' => ['todo', 'doing']], 'ORDER' => ['planned_end ASC'], 'LIMIT' => 30]) as $t) {
            $milestones[] = ['initiative' => (int) $t['initiatives_id'], 'title' => $t['name'], 'date' => $t['planned_end']];
        }
        return compact('risks', 'decisions', 'milestones');
    }

    // ---------- snapshot ----------

    public static function generate(string $title, array $filters, ?string $cutoff = null): int
    {
        if (!Access::canView()) {
            throw RuleException::denied();
        }
        $cutoff = Db::date($cutoff ?? '') ?: Db::today();
        $data = self::portfolio($filters, $cutoff);
        $ids = array_column($data['rows'], 'id');
        $payload = [
            'title' => Db::text($title, 255) ?: 'Relatório executivo VMO',
            'cutoff' => $cutoff,
            'filters' => $filters,
            'formula_version' => Calc::FORMULA_VERSION,
            'thresholds' => Settings::thresholds(),
            'thresholds_version' => Settings::thresholdsVersion(),
            'generator' => 'VMO ' . PLUGIN_VMO_VERSION,
            'generated_by' => Db::userName((int) Session::getLoginUserID()),
            'generated_at' => Db::now() . ' UTC',
            'finance' => $data['finance'],
            'totals' => $data['totals'],
            'rows' => $data['rows'],
        ] + self::extras($ids);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $id = Db::insert('snapshots', [
            'entities_id' => (int) ($_SESSION['glpiactive_entity'] ?? 0), 'users_id' => (int) Session::getLoginUserID(), 'title' => $payload['title'],
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'cutoff' => $cutoff . ' 23:59:59', 'formula_version' => Calc::FORMULA_VERSION,
            'thresholds_version' => Settings::thresholdsVersion(), 'payload' => $json, 'hash' => hash('sha256', $json), 'status' => 'ready',
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+' . Settings::reportRetentionDays() . ' days')), 'date_creation' => Db::now(),
        ]);
        Audit::log('Snapshot', $id, 'generate', ['titulo' => $payload['title'], 'corte' => $cutoff, 'hash' => hash('sha256', $json)], 0);
        return $id;
    }

    /** Carrega o snapshot se o usuário o gerou (ou administra) e o acesso à entidade ainda vale (reavaliado no download). */
    public static function load(int $id): array
    {
        $s = Db::row('snapshots', $id);
        if (!$s || !Access::canView() || ((int) $s['users_id'] !== (int) Session::getLoginUserID() && !Access::canConfigure())) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        if ($s['expires_at'] && $s['expires_at'] < Db::now()) {
            throw new RuleException('Este relatório expirou pela política de retenção. Gere-o novamente.', 410);
        }
        if (!hash_equals($s['hash'], hash('sha256', (string) $s['payload']))) {
            throw new RuleException('Integridade do snapshot comprometida.', 500);
        }
        $p = json_decode((string) $s['payload'], true);
        // sem direito financeiro agora, os valores do snapshot não saem (reavaliado a cada download)
        if (!Access::canFinance() && !empty($p['finance'])) {
            $p['finance'] = false;
            $p['totals']['value_planned'] = $p['totals']['value_validated'] = $p['totals']['investment'] = null;
            foreach ($p['rows'] as &$row) {
                foreach (['budget', 'forecast', 'actual', 'value_planned', 'value_validated', 'realization', 'roi'] as $k) {
                    unset($row[$k]);
                }
            }
            unset($row);
        }
        // linhas de iniciativas que o usuário não enxerga mais saem do arquivo
        $p['rows'] = array_values(array_filter($p['rows'], function ($r) {
            $i = Db::row('initiatives', (int) $r['id']);
            return $i && Access::canSeeInitiative($i);
        }));
        return ['snapshot' => $s, 'data' => $p];
    }

    public static function mine(int $limit = 30): array
    {
        $where = ['expires_at' => ['>', Db::now()]];
        if (!Access::canConfigure()) {
            $where['users_id'] = (int) Session::getLoginUserID();
        }
        return Db::rows('snapshots', $where, ['id DESC'], $limit);
    }

    public static function expire(): int
    {
        global $DB;
        $n = countElementsInTable(self::getTable(), ['expires_at' => ['<', Db::now()]]);
        if ($n) {
            $DB->delete(self::getTable(), ['expires_at' => ['<', Db::now()]]);
        }
        return $n;
    }

    // ---------- formatos ----------

    private static function fmt($v, string $cur = ''): string
    {
        return $v === null ? '' : number_format((float) $v, 2, ',', '.');
    }

    public static function csv(array $d): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $meta = [
            ['Relatório', $d['title']], ['Corte', $d['cutoff']], ['Filtros', json_encode($d['filters'], JSON_UNESCAPED_UNICODE)],
            ['Versão das fórmulas', $d['formula_version']], ['Versão dos limites', $d['thresholds_version']], ['Gerador', $d['generator']], ['Gerado em', $d['generated_at']],
        ];
        foreach ($meta as $m) {
            fputcsv($out, array_map([Db::class, 'csvCell'], $m), ';');
        }
        fputcsv($out, [], ';');
        $head = ['Código', 'Iniciativa', 'Situação', 'Gestor', 'Patrocinador', 'Início planejado', 'Término planejado', 'Previsão de término', 'Progresso %', 'Atraso (dias úteis)', 'Saúde', 'Maior risco', 'Moeda'];
        if ($d['finance']) {
            $head = array_merge($head, ['Orçamento baseline', 'Previsão de custo', 'Custo real', 'Benefício previsto', 'Benefício validado', 'Realização %', 'ROI %']);
        }
        fputcsv($out, $head, ';');
        foreach ($d['rows'] as $r) {
            $line = [$r['code'], $r['name'], Initiative::STATUSES[$r['status']][0] ?? $r['status'], $r['manager'], $r['sponsor'], $r['planned_start'], $r['planned_end'], $r['forecast_end'],
                $r['progress'] === null ? 'sem dado' : self::fmt($r['progress']), $r['delay'] === null ? 'sem dado' : $r['delay'], Menu::HEALTH[$r['health']][0] ?? '', $r['max_risk'] ?? '', $r['currency']];
            if ($d['finance']) {
                $line = array_merge($line, [self::fmt($r['budget'] ?? null), self::fmt($r['forecast'] ?? null), self::fmt($r['actual'] ?? null), self::fmt($r['value_planned'] ?? null),
                    self::fmt($r['value_validated'] ?? null), isset($r['realization']) ? self::fmt($r['realization']) : 'não aplicável', isset($r['roi']) ? self::fmt($r['roi']) : 'não aplicável']);
            }
            fputcsv($out, array_map([Db::class, 'csvCell'], $line), ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Texto executivo (usado no PDF e no DOCX): lista de [tipo, conteúdo]. */
    public static function outline(array $d): array
    {
        $t = $d['totals'];
        $blocks = [['h1', $d['title']], ['meta', 'Corte: ' . $d['cutoff'] . ' · Gerado por ' . $d['generated_by'] . ' em ' . $d['generated_at'] . ' · ' . $d['generator']]];
        $blocks[] = ['h2', 'Resumo'];
        $health = [];
        foreach (Menu::HEALTH as $k => $h) {
            $health[] = $h[0] . ': ' . ($t['by_health'][$k] ?? 0);
        }
        $blocks[] = ['p', $t['count'] . ' iniciativa(s) no recorte. Saúde — ' . implode(' · ', $health) . '.'];
        if ($d['finance']) {
            $blocks[] = ['h2', 'Valor e investimento'];
            foreach (['value_planned' => 'Benefício financeiro previsto (aprovado)', 'value_validated' => 'Benefício financeiro validado', 'investment' => 'Investimento realizado'] as $k => $label) {
                $parts = [];
                foreach ($t[$k] ?? [] as $cur => $v) {
                    $parts[] = Calc::formatMoney((float) $v, $cur);
                }
                $blocks[] = ['p', $label . ': ' . ($parts ? implode(' | ', $parts) : '—') . ' (moedas diferentes não são somadas sem taxa registrada).'];
            }
        } else {
            $blocks[] = ['p', 'Valores financeiros omitidos: o seu perfil não tem o direito financeiro.'];
        }
        $blocks[] = ['h2', 'Iniciativas'];
        $rows = [['Código', 'Iniciativa', 'Situação', 'Progresso', 'Atraso', 'Saúde']];
        foreach ($d['rows'] as $r) {
            $rows[] = [$r['code'], $r['name'], Initiative::STATUSES[$r['status']][0] ?? '', $r['progress'] === null ? 'sem dado' : number_format($r['progress'], 1, ',', '.') . '%',
                $r['delay'] === null ? 'sem dado' : $r['delay'] . ' d.ú.', Menu::HEALTH[$r['health']][0] ?? ''];
        }
        $blocks[] = ['table', $rows];
        $codes = array_column($d['rows'], 'code', 'id');
        if (!empty($d['risks'])) {
            $blocks[] = ['h2', 'Principais riscos'];
            $r2 = [['Iniciativa', 'Risco/problema', 'Exposição']];
            foreach (array_slice($d['risks'], 0, 10) as $r) {
                $r2[] = [$codes[$r['initiative']] ?? '—', $r['title'], $r['exposure']];
            }
            $blocks[] = ['table', $r2];
        }
        if (!empty($d['milestones'])) {
            $blocks[] = ['h2', 'Próximos marcos'];
            $r3 = [['Iniciativa', 'Marco', 'Data']];
            foreach (array_slice($d['milestones'], 0, 10) as $r) {
                $r3[] = [$codes[$r['initiative']] ?? '—', $r['title'], $r['date'] ?? '—'];
            }
            $blocks[] = ['table', $r3];
        }
        if (!empty($d['decisions'])) {
            $blocks[] = ['h2', 'Decisões recentes'];
            $r4 = [['Iniciativa', 'Decisão', 'Data']];
            foreach (array_slice($d['decisions'], 0, 10) as $r) {
                $r4[] = [$codes[$r['initiative']] ?? '—', $r['title'], substr((string) $r['date'], 0, 10)];
            }
            $blocks[] = ['table', $r4];
        }
        $blocks[] = ['h2', 'Notas metodológicas'];
        $blocks[] = ['p', 'Versão das fórmulas ' . $d['formula_version'] . ', limites de saúde v' . $d['thresholds_version'] . ' (atraso vermelho > ' . $d['thresholds']['delay_red'] . ' dias úteis; desvio de custo vermelho > '
            . $d['thresholds']['cost_red'] . '%; risco vermelho ≥ ' . $d['thresholds']['risk_red'] . '). Progresso = média ponderada das tarefas-folha elegíveis. Benefício validado soma apenas medições '
            . 'aprovadas por pessoa diferente de quem declarou. Dado ausente aparece como "sem dado" e nunca como zero. Filtros: ' . json_encode($d['filters'], JSON_UNESCAPED_UNICODE) . '.'];
        return $blocks;
    }

    /** PDF com TCPDF (incluso no GLPI). */
    public static function pdf(array $d): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('VMO'); $pdf->SetTitle($d['title']);
        $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12); $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        foreach (self::outline($d) as [$type, $content]) {
            switch ($type) {
                case 'h1': $pdf->SetFont('dejavusans', 'B', 16); $pdf->Cell(0, 9, $content, 0, 1); break;
                case 'h2': $pdf->Ln(2); $pdf->SetFont('dejavusans', 'B', 12); $pdf->Cell(0, 8, $content, 0, 1); break;
                case 'meta': $pdf->SetFont('dejavusans', '', 8); $pdf->SetTextColor(100); $pdf->MultiCell(0, 5, $content, 0, 'L'); $pdf->SetTextColor(0); break;
                case 'p': $pdf->SetFont('dejavusans', '', 9); $pdf->MultiCell(0, 5, $content, 0, 'L'); break;
                case 'table':
                    $widths = count($content[0]) === 6 ? [28, 110, 35, 30, 30, 34] : [40, 150, 40];
                    $pdf->SetFont('dejavusans', 'B', 8); $pdf->SetFillColor(230, 233, 240);
                    foreach ($content[0] as $i => $h) { $pdf->Cell($widths[$i], 6, $h, 1, 0, 'L', true); }
                    $pdf->Ln();
                    $pdf->SetFont('dejavusans', '', 8);
                    foreach (array_slice($content, 1) as $row) {
                        foreach ($row as $i => $cell) { $pdf->Cell($widths[$i], 6, mb_strimwidth((string) $cell, 0, (int) ($widths[$i] / 1.9), '…'), 1); }
                        $pdf->Ln();
                    }
                    break;
            }
        }
        return $pdf->Output('', 'S');
    }

    /** DOCX mínimo (OOXML) com o mesmo conteúdo do PDF. */
    public static function docx(array $d): string
    {
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = '';
        foreach (self::outline($d) as [$type, $content]) {
            switch ($type) {
                case 'h1': $body .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . $esc($content) . '</w:t></w:r></w:p>'; break;
                case 'h2': $body .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>' . $esc($content) . '</w:t></w:r></w:p>'; break;
                case 'meta': case 'p': $body .= '<w:p><w:r><w:t xml:space="preserve">' . $esc($content) . '</w:t></w:r></w:p>'; break;
                case 'table':
                    $body .= '<w:tbl><w:tblPr><w:tblBorders>';
                    foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) { $body .= "<w:$b w:val=\"single\" w:sz=\"4\" w:color=\"999999\"/>"; }
                    $body .= '</w:tblBorders></w:tblPr>';
                    foreach ($content as $ri => $row) {
                        $body .= '<w:tr>';
                        foreach ($row as $cell) { $body .= '<w:tc><w:p><w:r>' . ($ri === 0 ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . $esc($cell) . '</w:t></w:r></w:p></w:tc>'; }
                        $body .= '</w:tr>';
                    }
                    $body .= '</w:tbl><w:p/>';
                    break;
            }
        }
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr></w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $ns . '><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style></w:styles>';
        $tmp = tempnam(sys_get_temp_dir(), 'vmo');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }
}
