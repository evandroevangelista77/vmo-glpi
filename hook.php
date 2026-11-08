<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Settings;

/** Tabelas do plugin, na ordem de criação (nomes sem o prefixo glpi_plugin_vmo_). */
const PLUGIN_VMO_TABLES = [
    'initiatives', 'tasks', 'task_updates', 'dependencies', 'departments', 'initiative_departments', 'participants',
    'delegations', 'catalogs', 'baselines', 'changes', 'approvals', 'costs', 'benefits', 'allocations', 'benefit_plans',
    'measurements', 'indicators', 'indicator_values', 'risks', 'decisions', 'fx_rates', 'audit_events', 'snapshots',
    'item_links', 'outbox',
];

function plugin_vmo_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    $int       = "int $sign NOT NULL DEFAULT 0";
    $money     = 'decimal(19,4) NOT NULL DEFAULT 0';
    $ts        = '`date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL';
    $id        = "`id` int $sign NOT NULL AUTO_INCREMENT";

    $tables = [
        // iniciativa: unidade principal do portfólio. Datas Y-m-d (competência/calendário), timestamps em UTC.
        'initiatives' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL,
            `description` text, `problem` text, `objective` text, `justification` text, `business_case` text,
            `catalogs_id_objective` $int, `catalogs_id_category` $int, `priority` tinyint NOT NULL DEFAULT 3,
            `status` varchar(12) NOT NULL DEFAULT 'draft', `prev_status` varchar(12) DEFAULT NULL, `confidentiality` tinyint NOT NULL DEFAULT 0,
            `users_id_manager` $int, `users_id_sponsor` $int, `users_id_author` $int, `currency` char(3) NOT NULL DEFAULT 'BRL',
            `planned_start` date DEFAULT NULL, `planned_end` date DEFAULT NULL, `forecast_end` date DEFAULT NULL, `actual_start` date DEFAULT NULL, `actual_end` date DEFAULT NULL,
            `progress_method` varchar(6) NOT NULL DEFAULT 'auto', `progress_manual` decimal(7,3) DEFAULT NULL, `progress_justification` varchar(500) DEFAULT NULL,
            `review_date` date DEFAULT NULL, `status_reason` text, `baseline_revision` int NOT NULL DEFAULT 0, `last_update_at` timestamp NULL DEFAULT NULL,
            `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`), KEY `status_idx` (`entities_id`,`status`,`date_mod`), KEY `manager` (`users_id_manager`), KEY `sponsor` (`users_id_sponsor`)",
        'tasks' => "$id, `initiatives_id` $int, `parent_id` $int, `name` varchar(255) NOT NULL, `description` text, `users_id_responsible` $int, `groups_id` $int,
            `status` varchar(10) NOT NULL DEFAULT 'todo', `priority` tinyint NOT NULL DEFAULT 3, `planned_start` date DEFAULT NULL, `planned_end` date DEFAULT NULL,
            `actual_start` date DEFAULT NULL, `actual_end` date DEFAULT NULL, `effort_plan` decimal(10,2) NOT NULL DEFAULT 0, `effort_actual` decimal(10,2) NOT NULL DEFAULT 0,
            `weight` decimal(7,3) NOT NULL DEFAULT 1, `progress` decimal(7,3) NOT NULL DEFAULT 0, `is_milestone` tinyint NOT NULL DEFAULT 0, `is_required` tinyint NOT NULL DEFAULT 1,
            `checklist` text, `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), KEY `initiative_idx` (`initiatives_id`,`status`,`planned_end`), KEY `parent_id` (`parent_id`), KEY `responsible` (`users_id_responsible`)",
        'task_updates' => "$id, `tasks_id` $int, `initiatives_id` $int, `progress` decimal(7,3) NOT NULL DEFAULT 0, `effort_delta` decimal(10,2) NOT NULL DEFAULT 0,
            `reference_date` date DEFAULT NULL, `comment` text, `evidence` varchar(500) DEFAULT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `tasks_id` (`tasks_id`), KEY `initiatives_id` (`initiatives_id`)",
        // dependência término→início entre tarefas OU entre iniciativas (um par de tipos por registro); defasagem em dias úteis
        'dependencies' => "$id, `entities_id` $int, `kind` varchar(10) NOT NULL DEFAULT 'task', `pred_id` $int, `succ_id` $int, `lag_days` int NOT NULL DEFAULT 0,
            `is_hard` tinyint NOT NULL DEFAULT 1, `exception_reason` varchar(500) DEFAULT NULL, `exception_users_id` $int, `exception_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `pair` (`kind`,`pred_id`,`succ_id`), KEY `succ` (`kind`,`succ_id`)",
        'departments' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL, `parent_id` $int,
            `users_id_responsible` $int, `is_active` tinyint NOT NULL DEFAULT 1, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`)",
        'initiative_departments' => "$id, `initiatives_id` $int, `departments_id` $int, `role` varchar(20) NOT NULL DEFAULT 'beneficiary', `users_id_responsible` $int,
            `participation_percent` decimal(7,3) NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`initiatives_id`,`departments_id`,`role`)",
        // equipes (grupos GLPI) e participantes; associar um grupo não concede acesso por si só
        'participants' => "$id, `initiatives_id` $int, `groups_id` $int, `users_id` $int, `role` varchar(20) NOT NULL DEFAULT 'member', `valid_from` date DEFAULT NULL, `valid_to` date DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`), KEY `users_id` (`users_id`)",
        // delegação de aprovação com vigência (RN07)
        'delegations' => "$id, `entities_id` $int, `users_id_from` $int, `users_id_to` $int, `valid_from` date NOT NULL, `valid_to` date NOT NULL, `reason` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `users_id_to` (`users_id_to`)",
        // objetivos estratégicos e categorias (kind = objective | category | benefit_category | cost_category)
        'catalogs' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 1, `kind` varchar(20) NOT NULL, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL,
            `parent_id` $int, `is_active` tinyint NOT NULL DEFAULT 1, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`entities_id`,`kind`,`code`)",
        // baseline aprovada: imutável (nunca atualizada); mudanças geram nova revisão
        'baselines' => "$id, `initiatives_id` $int, `revision` int NOT NULL, `scope` text, `planned_start` date DEFAULT NULL, `planned_end` date DEFAULT NULL,
            `budget_json` text, `currency` char(3) NOT NULL DEFAULT 'BRL', `benefit_plan_snapshot` mediumtext, `tasks_snapshot` mediumtext, `formula_version` varchar(10) NOT NULL,
            `thresholds_version` int NOT NULL DEFAULT 1, `approvals_id` $int, `changes_id` $int, `reason` text, `users_id_approver` $int, `approved_at` timestamp NULL DEFAULT NULL, `hash` char(64) NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `rev` (`initiatives_id`,`revision`)",
        // solicitação de mudança de baseline (prazo, escopo, custo, benefício)
        'changes' => "$id, `initiatives_id` $int, `type` varchar(10) NOT NULL, `proposed` text, `justification` text NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'pending',
            `base_revision` int NOT NULL DEFAULT 0, `users_id_requester` $int, `users_id_decider` $int, `decision_comment` text, `decided_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`,`status`)",
        // aprovação da proposta (submissão → decisão), uma linha por revisão da solicitação
        'approvals' => "$id, `initiatives_id` $int, `revision` int NOT NULL DEFAULT 1, `status` varchar(10) NOT NULL DEFAULT 'pending', `users_id_requester` $int, `users_id_decider` $int,
            `comment` text, `snapshot` mediumtext, `submitted_version` int NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `decided_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `rev` (`initiatives_id`,`revision`)",
        // kind: forecast (previsão atual) | actual (lançamento real, por competência). Estorno = linha negativa ligada à original.
        'costs' => "$id, `initiatives_id` $int, `kind` varchar(8) NOT NULL, `category` varchar(60) DEFAULT NULL, `cost_type` varchar(5) NOT NULL DEFAULT 'opex',
            `competence_date` date NOT NULL, `amount` $money, `currency` char(3) NOT NULL DEFAULT 'BRL', `description` varchar(255) DEFAULT NULL, `source` varchar(30) NOT NULL DEFAULT 'manual',
            `external_key` varchar(80) DEFAULT NULL, `reversal_of_id` $int, `evidence` varchar(500) DEFAULT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `origin_key` (`source`,`external_key`), KEY `initiative_idx` (`initiatives_id`,`kind`,`competence_date`)",
        'benefits' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `economic_key` varchar(60) NOT NULL, `name` varchar(255) NOT NULL, `description` text,
            `type` varchar(12) NOT NULL DEFAULT 'financial', `unit` varchar(40) DEFAULT NULL, `currency` char(3) DEFAULT NULL, `direction` varchar(8) NOT NULL DEFAULT 'increase',
            `baseline_value` decimal(19,4) DEFAULT NULL, `target` decimal(19,4) DEFAULT NULL, `horizon_start` date DEFAULT NULL, `horizon_end` date DEFAULT NULL,
            `cadence` varchar(10) NOT NULL DEFAULT 'monthly', `method` text, `source` varchar(255) DEFAULT NULL, `rubric` text, `attribution_note` varchar(255) DEFAULT NULL,
            `users_id_owner` $int, `users_id_validator` $int, `users_id_author` $int, `users_id_approver` $int, `status` varchar(10) NOT NULL DEFAULT 'draft', `approved_at` timestamp NULL DEFAULT NULL,
            `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_key` (`entities_id`,`economic_key`), KEY `owner` (`users_id_owner`)",
        // alocação do benefício entre iniciativas: a soma vigente não excede 100% (portfólio soma o benefício uma vez)
        'allocations' => "$id, `benefits_id` $int, `initiatives_id` $int, `percent` decimal(7,3) NOT NULL, `valid_from` date DEFAULT NULL, `valid_to` date DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`benefits_id`,`initiatives_id`), KEY `initiatives_id` (`initiatives_id`)",
        'benefit_plans' => "$id, `benefits_id` $int, `period` date NOT NULL, `planned_amount` $money, `revision` int NOT NULL DEFAULT 1, `baselines_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`benefits_id`,`period`)",
        // medição: declarado × validado; cada retificação cria revisão (is_current marca a vigente)
        'measurements' => "$id, `benefits_id` $int, `period` date NOT NULL, `observed_value` decimal(19,4) DEFAULT NULL, `declared_amount` $money, `validated_amount` decimal(19,4) DEFAULT NULL,
            `status` varchar(10) NOT NULL DEFAULT 'declared', `evidence` varchar(500) DEFAULT NULL, `comment` text, `revision` int NOT NULL DEFAULT 1, `supersedes_id` $int, `is_current` tinyint NOT NULL DEFAULT 1,
            `users_id_author` $int, `users_id_validator` $int, `validation_comment` text, `validated_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `benefit_idx` (`benefits_id`,`period`,`status`)",
        'indicators' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `initiatives_id` $int, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL, `unit` varchar(30) DEFAULT NULL,
            `direction` varchar(8) NOT NULL DEFAULT 'increase', `baseline_value` decimal(19,4) DEFAULT NULL, `target` decimal(19,4) DEFAULT NULL, `cadence_days` int NOT NULL DEFAULT 30, `source` varchar(255) DEFAULT NULL,
            `users_id_owner` $int, `thresholds` varchar(255) DEFAULT NULL, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`)",
        'indicator_values' => "$id, `indicators_id` $int, `period` date NOT NULL, `observed` decimal(19,4) NOT NULL, `quality` varchar(10) NOT NULL DEFAULT 'manual', `source` varchar(30) NOT NULL DEFAULT 'manual',
            `external_key` varchar(80) DEFAULT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`indicators_id`,`period`), UNIQUE KEY `origin_key` (`source`,`external_key`)",
        'risks' => "$id, `initiatives_id` $int, `kind` varchar(8) NOT NULL DEFAULT 'risk', `title` varchar(255) NOT NULL, `cause` text, `impact_text` text, `probability` tinyint NOT NULL DEFAULT 3, `impact` tinyint NOT NULL DEFAULT 3,
            `response` text, `users_id_owner` $int, `due_date` date DEFAULT NULL, `status` varchar(10) NOT NULL DEFAULT 'open', `outcome` text, `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), KEY `initiative_idx` (`initiatives_id`,`status`)",
        'decisions' => "$id, `initiatives_id` $int, `title` varchar(255) NOT NULL, `decision_text` text, `status` varchar(10) NOT NULL DEFAULT 'decided', `users_id` $int, `decided_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`)",
        // taxa de câmbio com data e fonte (RN08); sem taxa, os totais ficam separados por moeda
        'fx_rates' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 1, `from_currency` char(3) NOT NULL, `to_currency` char(3) NOT NULL, `rate_date` date NOT NULL,
            `rate` decimal(19,8) NOT NULL, `source` varchar(120) NOT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`entities_id`,`from_currency`,`to_currency`,`rate_date`)",
        // só inserção; nenhuma tela edita a trilha
        'audit_events' => "$id, `entities_id` $int, `itemtype` varchar(30) NOT NULL, `items_id` $int, `action` varchar(30) NOT NULL, `users_id` $int, `version` int NOT NULL DEFAULT 0,
            `diff` mediumtext, `correlation_id` varchar(32) DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `target` (`entities_id`,`itemtype`,`items_id`,`date_creation`), KEY `date_creation` (`date_creation`)",
        // relatório: cópia consistente dos números no corte; reprocessar o snapshot gera os mesmos valores (hash)
        'snapshots' => "$id, `entities_id` $int, `users_id` $int, `title` varchar(255) NOT NULL, `filters` text, `cutoff` datetime NOT NULL, `formula_version` varchar(10) NOT NULL,
            `thresholds_version` int NOT NULL DEFAULT 1, `payload` longtext, `hash` char(64) NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'ready', `error` varchar(255) DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `users_id` (`users_id`), KEY `expires_at` (`expires_at`)",
        // vínculos consultivos com objetos do GLPI (tickets, projetos); exige acesso aos dois lados
        'item_links' => "$id, `initiatives_id` $int, `itemtype` varchar(30) NOT NULL, `items_id` $int, `relation` varchar(20) NOT NULL DEFAULT 'related', `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`initiatives_id`,`itemtype`,`items_id`)",
        // fila de avisos: dedup_key único evita duplicar o aviso do mesmo evento ao reexecutar a rotina
        'outbox' => "$id, `entities_id` $int, `users_id` $int, `type` varchar(30) NOT NULL, `itemtype` varchar(30) DEFAULT NULL, `items_id` $int, `title` varchar(255) NOT NULL, `body` varchar(500) DEFAULT NULL,
            `dedup_key` varchar(120) NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'pending', `attempts` int NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `read_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `dedup_key` (`dedup_key`), KEY `users_id` (`users_id`,`read_at`)",
    ];
    foreach ($tables as $name => $def) {
        $table = Db::t($name);
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // configuração padrão (mantida nas atualizações)
    $defaults = ['currency' => 'BRL', 'cadence_days' => '14', 'report_retention_days' => '90', 'audit_retention_months' => '24', 'holidays' => '', 'thresholds_version' => '1'];
    $missing = array_diff_key($defaults, Config::getConfigurationValues(Settings::CONTEXT));
    if ($missing) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // direitos: ninguém recebe aprovação, validação, finanças ou configuração implicitamente;
    // super-admin (quem pode alterar a configuração do GLPI) recebe tudo, os demais perfis centrais só leem/criam/atualizam.
    $all   = READ | CREATE | UPDATE | DELETE | PURGE | Access::APPROVE | Access::VALIDATE | Access::FINANCE | Access::CONFIGURE;
    $basic = READ | CREATE | UPDATE;
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Access::$rightname])) {
        ProfileRight::addProfileRights([Access::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? $all : $basic], ['profiles_id' => $row['id'], 'name' => Access::$rightname]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $current = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname]);
        $_SESSION['glpiactiveprofile'][Access::$rightname] = (int) ($current[Access::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    // rotinas agendadas (avisos de atraso e expurgo por retenção)
    CronTask::register('GlpiPlugin\\Vmo\\Jobs', 'vmonotify', DAY_TIMESTAMP / 4, [
        'comment' => 'VMO: avisos de tarefas atrasadas, medições vencidas e aprovações pendentes',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);
    CronTask::register('GlpiPlugin\\Vmo\\Jobs', 'vmoretention', DAY_TIMESTAMP, [
        'comment' => 'VMO: expira relatórios gerados e trilha de auditoria conforme a retenção configurada',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);

    Audit::log('Plugin', 0, 'install', ['version' => PLUGIN_VMO_VERSION], 0, 0);
    return true;
}

/**
 * Desinstalar NÃO apaga dados por padrão (RF24): só retira direitos e rotinas. Para expurgar as tabelas
 * é preciso confirmar de forma explícita com a variável VMO_PURGE=1 (ou pela tela de Configuração).
 */
function plugin_vmo_uninstall(): bool
{
    global $DB;
    CronTask::unregister('vmo');
    ProfileRight::deleteProfileRights([Access::$rightname]);
    unset($_SESSION['glpimenu']);
    if (getenv('VMO_PURGE') === '1') {
        foreach (array_reverse(PLUGIN_VMO_TABLES) as $name) {
            $DB->dropTable(Db::t($name), true);
        }
        Config::deleteConfigurationValues(Settings::CONTEXT);
    }
    return true;
}
