<?php

namespace GlpiPlugin\Vmo;

use CronTask;

/**
 * Rotinas agendadas. Todas são idempotentes: o dedup_key do Outbox impede duplicar o aviso do mesmo evento.
 */
class Jobs
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_outbox';
    }

    public static function cronInfo($name)
    {
        return ['description' => $name === 'vmonotify' ? 'VMO: avisos de atrasos, medições vencidas e aprovações pendentes' : 'VMO: retenção de relatórios e auditoria'];
    }

    /** Avisos de tarefas atrasadas, medições vencidas, aprovações e validações pendentes. */
    public static function cronVmonotify(CronTask $task): int
    {
        global $DB;
        $today = Db::today();
        $n = 0;
        // tarefas atrasadas → responsável e gestor
        foreach ($DB->request(['FROM' => Db::t('tasks'), 'WHERE' => ['is_deleted' => 0, 'status' => ['todo', 'doing'], 'planned_end' => ['<', $today]]]) as $t) {
            $init = Db::row('initiatives', (int) $t['initiatives_id']);
            if (!$init || $init['is_deleted'] || !in_array($init['status'], ['running', 'approved'], true)) {
                continue;
            }
            $key = 'late:' . $t['id'] . ':' . $t['planned_end'];
            foreach (array_unique(array_filter([(int) $t['users_id_responsible'], (int) $init['users_id_manager']])) as $u) {
                $n += Outbox::push($u, 'late', $init['code'] . ': tarefa atrasada — ' . $t['name'], $key, 'Initiative', (int) $init['id'], 'Prazo: ' . $t['planned_end'], (int) $init['entities_id']) ? 1 : 0;
            }
        }
        // medições vencidas → dono do benefício
        foreach ($DB->request(['FROM' => Db::t('benefits'), 'WHERE' => ['is_deleted' => 0, 'status' => 'approved']]) as $b) {
            foreach (Benefit::overduePeriods($b, $today) as $period) {
                $n += Outbox::push((int) $b['users_id_owner'], 'measure-due', 'Medição vencida: ' . $b['name'] . ' (' . substr($period, 0, 7) . ')', 'due:' . $b['id'] . ':' . $period, 'Benefit', (int) $b['id'], '', (int) $b['entities_id']) ? 1 : 0;
            }
        }
        // propostas em análise → patrocinador
        foreach ($DB->request(['FROM' => Db::t('initiatives'), 'WHERE' => ['is_deleted' => 0, 'status' => 'review']]) as $i) {
            $n += Outbox::push((int) $i['users_id_sponsor'], 'approval', $i['code'] . ': proposta aguardando decisão', 'review:' . $i['id'] . ':' . $i['version'], 'Initiative', (int) $i['id'], $i['name'], (int) $i['entities_id']) ? 1 : 0;
        }
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }

    /** Expira relatórios gerados e a trilha de auditoria conforme a retenção. */
    public static function cronVmoretention(CronTask $task): int
    {
        global $DB;
        $n = Report::expire();
        $n += Audit::purge();
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }
}
