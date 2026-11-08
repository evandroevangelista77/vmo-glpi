<?php

/**
 * Download autenticado de relatório. As permissões são reavaliadas a cada download (Report::load):
 * usuário sem acesso, sem direito financeiro ou com relatório expirado não recebe o conteúdo.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\Report;
use GlpiPlugin\Vmo\RuleException;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$id = (int) ($_GET['id'] ?? 0);
$format = (string) ($_GET['format'] ?? 'pdf');
try {
    ['data' => $d] = Report::load($id);
} catch (RuleException $ex) {
    http_response_code($ex->getCode() >= 400 && $ex->getCode() < 600 ? $ex->getCode() : 422);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $ex->getMessage();
    return;
}
Audit::log('Snapshot', $id, 'download', ['formato' => $format]); // registra o acesso
$name = 'vmo-relatorio-' . $id . '-' . $d['cutoff'];
switch ($format) {
    case 'csv':
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        echo Report::csv($d);
        break;
    case 'executive':
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '-executivo-completo.pdf"');
        echo \GlpiPlugin\Vmo\ExecutiveReport::pdf($d);
        break;
    case 'docx':
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $name . '.docx"');
        echo Report::docx($d);
        break;
    default:
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
        echo Report::pdf($d);
}
