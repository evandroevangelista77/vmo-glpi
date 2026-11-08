<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Medição e validação de benefícios (RF12, RN05, RN07).
 * Declarado ≠ validado: o realizado validado só soma medições aprovadas por quem não as declarou.
 * Retificar cria nova revisão (a anterior deixa de ser vigente) e exige nova validação.
 */
class Measurement
{
    public const STATUSES = [
        'declared'  => ['Aguardando validação', '#f59e0b', 'ti-hourglass'],
        'validated' => ['Validada', '#22c55e', 'ti-circle-check'],
        'rejected'  => ['Rejeitada', '#ef4444', 'ti-circle-x'],
        'returned'  => ['Devolvida', '#3b82f6', 'ti-arrow-back-up'],
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_measurements';
    }

    public static function forBenefit(int $benefitId): array
    {
        return Db::rows('measurements', ['benefits_id' => $benefitId], ['period DESC', 'revision DESC']);
    }

    /** Declara (ou retifica) a medição do mês. */
    public static function declare(int $benefitId, array $in): int
    {
        global $DB;
        $b = Benefit::load($benefitId);
        $me = (int) Session::getLoginUserID();
        if (!Access::canUpdate() || ($me !== (int) $b['users_id_owner'] && !Benefit::canEdit($b))) {
            throw RuleException::denied('Só o dono do benefício (ou quem o administra) declara medições.');
        }
        if ($b['status'] !== 'approved') {
            throw new RuleException('Só benefícios aprovados recebem medições.');
        }
        $period = Benefit::periodStart((string) ($in['period'] ?? ''));
        if (!$period) {
            throw new RuleException('Informe o período da medição (mês).');
        }
        if ($period > Db::today()) {
            throw new RuleException('Não é possível declarar medição de um período futuro.');
        }
        if (($b['horizon_start'] && $period < substr($b['horizon_start'], 0, 7) . '-01') || ($b['horizon_end'] && $period > $b['horizon_end'])) {
            throw new RuleException('O período está fora do horizonte do benefício.');
        }
        $evidence = Db::text($in['evidence'] ?? '', 500);
        if ($evidence === '') {
            throw new RuleException('Anexe a evidência da medição (descrição, link ou referência do documento).');
        }
        $amount = Calc::parseNumber((string) ($in['declared_amount'] ?? ''));
        $observed = Calc::parseNumber((string) ($in['observed_value'] ?? ''));
        if ($b['type'] === 'financial' && ($amount === null || $amount < 0)) {
            throw new RuleException('Informe o valor realizado declarado (não negativo).');
        }
        if ($b['type'] === 'nonfinancial' && $observed === null) {
            throw new RuleException('Informe o valor observado na unidade/rubrica do benefício.');
        }
        return Db::transaction(function () use ($b, $benefitId, $period, $amount, $observed, $evidence, $in, $me, $DB) {
            $cur = Db::rows('measurements', ['benefits_id' => $benefitId, 'period' => $period, 'is_current' => 1], ['revision DESC'], 1)[0] ?? null;
            if ($cur && $cur['status'] === 'declared') {
                throw new RuleException('Já existe uma medição deste período aguardando validação.');
            }
            if ($cur) {
                $DB->update(self::getTable(), ['is_current' => 0], ['id' => $cur['id']]);
            }
            $id = Db::insert('measurements', [
                'benefits_id' => $benefitId, 'period' => $period, 'observed_value' => $observed, 'declared_amount' => $amount ?? 0, 'status' => 'declared',
                'evidence' => $evidence, 'comment' => Db::text($in['comment'] ?? '', 4000) ?: null, 'revision' => $cur ? (int) $cur['revision'] + 1 : 1,
                'supersedes_id' => $cur ? (int) $cur['id'] : 0, 'is_current' => 1, 'users_id_author' => $me, 'date_creation' => Db::now(),
            ]);
            Audit::log('Measurement', $id, $cur ? 'rectify' : 'declare', ['periodo' => $period, 'declarado' => $amount, 'observado' => $observed], $cur ? (int) $cur['revision'] + 1 : 1, (int) $b['entities_id']);
            if ((int) $b['users_id_validator']) {
                Outbox::push((int) $b['users_id_validator'], 'validation', 'Medição para validar: ' . $b['name'] . ' (' . substr($period, 0, 7) . ')', "measure:$id", 'Benefit', $benefitId, '', (int) $b['entities_id']);
            }
            return $id;
        });
    }

    public static function canValidate(array $benefit, array $measurement): bool
    {
        $me = (int) Session::getLoginUserID();
        if ($measurement['status'] !== 'declared' || !Access::canValidate()) {
            return false;
        }
        if ($me === (int) $measurement['users_id_author']) {
            return false; // o autor não valida a própria medição
        }
        return !(int) $benefit['users_id_validator'] || $me === (int) $benefit['users_id_validator'] || Access::canConfigure() || Delegation::actsFor($me, (int) $benefit['users_id_validator']);
    }

    /** Valida, rejeita ou devolve a medição. Rejeitar/devolver exigem comentário. */
    public static function decide(int $id, string $decision, string $comment, string $adjustedAmount = ''): void
    {
        global $DB;
        $m = Db::row('measurements', $id) ?? throw new RuleException('Medição não encontrada.', 404);
        $b = Benefit::load((int) $m['benefits_id']);
        if (!in_array($decision, ['validated', 'rejected', 'returned'], true)) {
            throw new RuleException('Decisão inválida.');
        }
        if ($m['status'] !== 'declared') {
            throw new RuleException('Esta medição já foi decidida.');
        }
        if (!self::canValidate($b, $m)) {
            throw RuleException::denied('Quem declarou a medição não pode validá-la, e a validação exige o direito de validar (e ser o validador designado).');
        }
        if ($decision !== 'validated' && Db::text($comment) === '') {
            throw new RuleException('Informe o motivo da rejeição/devolução.');
        }
        $validated = null;
        if ($decision === 'validated') {
            $validated = (float) $m['declared_amount'];
            if ($adjustedAmount !== '') {
                $adj = Calc::parseNumber($adjustedAmount);
                if ($adj === null || $adj < 0) {
                    throw new RuleException('Valor validado inválido.');
                }
                if (round($adj, 4) !== round($validated, 4) && Db::text($comment) === '') {
                    throw new RuleException('Validar um valor diferente do declarado exige comentário.');
                }
                $validated = $adj;
            }
        }
        $DB->update(self::getTable(), [
            'status' => $decision, 'validated_amount' => $validated, 'users_id_validator' => (int) Session::getLoginUserID(),
            'validation_comment' => Db::text($comment, 4000) ?: null, 'validated_at' => Db::now(),
        ], ['id' => $id, 'status' => 'declared']);
        if ($DB->affectedRows() < 1) {
            throw new RuleException('Esta medição já foi decidida por outra pessoa.', 409);
        }
        Audit::log('Measurement', $id, $decision, ['validado' => $validated, 'comentario' => $comment], (int) $m['revision'], (int) $b['entities_id']);
        Outbox::push((int) $m['users_id_author'], 'validation', 'Medição ' . mb_strtolower(self::STATUSES[$decision][0]) . ': ' . $b['name'], "measure-decision:$id", 'Benefit', (int) $b['id'], '', (int) $b['entities_id']);
    }

    /** Fila de medições aguardando validação que o usuário pode decidir. */
    public static function pendingFor(): array
    {
        global $DB;
        $out = [];
        foreach (Benefit::visible() as $b) {
            foreach (Db::rows('measurements', ['benefits_id' => (int) $b['id'], 'status' => 'declared', 'is_current' => 1]) as $m) {
                if (self::canValidate($b, $m)) {
                    $out[] = ['benefit' => $b, 'measurement' => $m];
                }
            }
        }
        return $out;
    }
}
