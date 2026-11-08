<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Iniciativa: ciclo de vida, validações e indicadores derivados (RF02, RF05, RN01–RN07).
 * Toda gravação passa por aqui; as telas só chamam estes métodos.
 */
class Initiative
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_initiatives';
    }

    /** estado => [rótulo, cor, ícone] */
    public const STATUSES = [
        'draft'     => ['Rascunho', '#94a3b8', 'ti-pencil'],
        'review'    => ['Em análise', '#3b82f6', 'ti-eye'],
        'approved'  => ['Aprovada', '#14b8a6', 'ti-circle-check'],
        'running'   => ['Em execução', '#6366f1', 'ti-player-play'],
        'suspended' => ['Suspensa', '#f59e0b', 'ti-player-pause'],
        'delivered' => ['Entregue', '#22c55e', 'ti-package'],
        'measuring' => ['Em mensuração', '#a855f7', 'ti-ruler-measure'],
        'closed'    => ['Encerrada', '#64748b', 'ti-lock'],
        'rejected'  => ['Rejeitada', '#ef4444', 'ti-circle-x'],
        'cancelled' => ['Cancelada', '#6b7280', 'ti-ban'],
    ];

    /** Transições permitidas. "review→draft" é a devolução; "closed→running" é a reabertura. */
    public const TRANSITIONS = [
        'draft'     => ['review', 'cancelled'],
        'review'    => ['approved', 'draft', 'rejected', 'cancelled'],
        'approved'  => ['running', 'cancelled'],
        'running'   => ['suspended', 'delivered', 'cancelled'],
        'suspended' => ['running', 'cancelled'],
        'delivered' => ['measuring'],
        'measuring' => ['closed'],
        'closed'    => ['running'],
        'rejected'  => ['draft'],
        'cancelled' => [],
    ];

    /** Transições que exigem justificativa registrada. */
    public const NEED_REASON = ['review>draft', 'review>rejected', 'running>suspended', 'closed>running', 'rejected>draft'];

    public const PRIORITIES = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Crítica'];

    private const EDITABLE = ['name', 'description', 'problem', 'objective', 'justification', 'business_case', 'catalogs_id_objective', 'catalogs_id_category',
        'priority', 'confidentiality', 'users_id_manager', 'users_id_sponsor', 'planned_start', 'planned_end', 'forecast_end', 'actual_start', 'actual_end',
        'progress_method', 'progress_manual', 'progress_justification', 'currency'];

    public static function statusBadge(string $status): string
    {
        [$label, $color, $icon] = self::STATUSES[$status] ?? [$status, '#94a3b8', 'ti-circle'];
        return Menu::badge($label, $color, $icon);
    }

    // ---------- leitura ----------

    public static function load(int $id, bool $forEdit = false): array
    {
        return Access::initiativeOrFail($id, $forEdit);
    }

    public static function nextCode(int $entityId): string
    {
        global $DB;
        $max = 0;
        foreach ($DB->request(['SELECT' => ['code'], 'FROM' => self::getTable(), 'WHERE' => ['entities_id' => $entityId]]) as $r) {
            if (preg_match('/^INI-(\d+)$/', $r['code'], $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return sprintf('INI-%04d', $max + 1);
    }

    // ---------- validação (RN03) ----------

    /**
     * Valida as datas; devolve alertas não bloqueantes (datas planejadas no passado).
     *
     * @return string[]
     */
    public static function validateDates(array $d): array
    {
        $today = Db::today();
        $notices = [];
        $ps = $d['planned_start'] ?? null;
        $pe = $d['planned_end'] ?? null;
        if ($ps && $pe && $ps > $pe) {
            throw new RuleException('A data de início planejada não pode ser posterior à data de término planejada.');
        }
        $fe = $d['forecast_end'] ?? null;
        if ($fe && $ps && $fe < $ps) {
            throw new RuleException('A previsão de término não pode ser anterior ao início planejado.');
        }
        $as = $d['actual_start'] ?? null;
        $ae = $d['actual_end'] ?? null;
        if ($as && $ae && $as > $ae) {
            throw new RuleException('O início real não pode ser posterior ao término real.');
        }
        foreach (['actual_start' => 'Início real', 'actual_end' => 'Término real'] as $f => $label) {
            if (!empty($d[$f]) && $d[$f] > $today) {
                throw new RuleException("$label não pode estar no futuro: datas reais só registram o que já aconteceu.");
            }
        }
        if ($ps && $ps < $today) {
            $notices[] = 'O início planejado está no passado.';
        }
        if ($pe && $pe < $today) {
            $notices[] = 'O término planejado está no passado.';
        }
        return $notices;
    }

    private static function clean(array $in, ?array $current = null): array
    {
        $out = [];
        foreach (self::EDITABLE as $f) {
            if (!array_key_exists($f, $in) && ($current !== null || !in_array($f, ['priority', 'progress_method', 'currency'], true))) {
                continue;
            }
            $v = $in[$f] ?? null;
            switch ($f) {
                case 'name':
                    $out[$f] = Db::text($v, 255);
                    break;
                case 'description': case 'problem': case 'objective': case 'justification': case 'business_case':
                    $out[$f] = Db::text($v, 20000);
                    break;
                case 'catalogs_id_objective': case 'catalogs_id_category': case 'users_id_manager': case 'users_id_sponsor':
                    $out[$f] = max(0, (int) $v);
                    break;
                case 'priority':
                    $out[$f] = Db::int($v, 1, 5, 3);
                    break;
                case 'confidentiality':
                    $out[$f] = empty($v) ? 0 : 1;
                    break;
                case 'planned_start': case 'planned_end': case 'forecast_end': case 'actual_start': case 'actual_end':
                    $out[$f] = Db::date($v);
                    break;
                case 'progress_method':
                    $out[$f] = $v === 'manual' ? 'manual' : 'auto';
                    break;
                case 'progress_manual':
                    $n = Calc::parseNumber((string) $v);
                    if ($n !== null && ($n < 0 || $n > 100)) {
                        throw new RuleException('O percentual de progresso deve estar entre 0 e 100.');
                    }
                    $out[$f] = $n;
                    break;
                case 'progress_justification':
                    $out[$f] = Db::text($v, 500) ?: null;
                    break;
                case 'currency':
                    $c = strtoupper(Db::text($v, 3));
                    $out[$f] = preg_match('/^[A-Z]{3}$/', $c) ? $c : Settings::currency();
                    break;
            }
        }
        return $out;
    }

    private static function activeUser(int $id, string $label): void
    {
        global $DB;
        if ($id > 0 && !$DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $id, 'is_active' => 1, 'is_deleted' => 0]])->count()) {
            throw new RuleException("$label precisa ser um usuário ativo.");
        }
    }

    // ---------- criação e edição (RF02) ----------

    public static function create(array $in): int
    {
        if (!Access::canCreate()) {
            throw RuleException::denied();
        }
        $entity = (int) ($in['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
        if (!Session::haveAccessToEntity($entity)) {
            throw RuleException::denied('Sem acesso à entidade escolhida.');
        }
        $data = self::clean($in);
        if (($data['name'] ?? '') === '') {
            throw new RuleException('Informe o título da iniciativa.');
        }
        self::activeUser($data['users_id_manager'] ?? 0, 'O gestor');
        self::activeUser($data['users_id_sponsor'] ?? 0, 'O patrocinador');
        $notices = self::validateDates($data);

        $code = strtoupper(Db::text($in['code'] ?? '', 30));
        $id = Db::transaction(function () use ($entity, $data, $code, $in) {
            global $DB;
            $code = $code !== '' ? $code : self::nextCode($entity);
            if (countElementsInTable(self::getTable(), ['entities_id' => $entity, 'code' => $code])) {
                throw new RuleException("O código $code já existe nesta entidade.");
            }
            $row = $data + [
                'entities_id'   => $entity,
                'is_recursive'  => empty($in['is_recursive']) ? 0 : 1,
                'code'          => $code,
                'status'        => 'draft',
                'users_id_author' => (int) Session::getLoginUserID(),
                'currency'      => $data['currency'] ?? Settings::currency(),
                'last_update_at' => Db::now(),
                'date_creation' => Db::now(),
                'date_mod'      => Db::now(),
            ];
            $DB->insert(self::getTable(), $row);
            return (int) $DB->insertId();
        });
        Audit::log('Initiative', $id, 'create', ['code' => $code, 'name' => $data['name']], 1, $entity);
        $_SESSION['vmo_notices'] = $notices;
        return $id;
    }

    /** Atualiza com controle otimista de versão. Retorna a nova versão. */
    public static function update(int $id, array $in, int $expectedVersion): int
    {
        $cur = self::load($id, true);
        if (in_array($cur['status'], ['closed', 'cancelled'], true)) {
            throw new RuleException('Iniciativa ' . mb_strtolower(self::STATUSES[$cur['status']][0]) . ' não aceita edição.');
        }
        $data = self::clean($in, $cur);
        if (array_key_exists('name', $data) && $data['name'] === '') {
            throw new RuleException('Informe o título da iniciativa.');
        }
        // com baseline aprovada, prazo e escopo só mudam por solicitação de mudança (RN04)
        if ((int) $cur['baseline_revision'] > 0) {
            foreach (['planned_start', 'planned_end'] as $f) {
                if (array_key_exists($f, $data) && (string) $data[$f] !== (string) $cur[$f]) {
                    throw new RuleException('O prazo planejado faz parte da baseline aprovada. Registre uma solicitação de mudança; para refletir o cenário atual ajuste a "previsão de término".');
                }
            }
        }
        if (array_key_exists('users_id_manager', $data) && $data['users_id_manager'] !== (int) $cur['users_id_manager']) {
            self::activeUser($data['users_id_manager'], 'O gestor');
        }
        if (array_key_exists('users_id_sponsor', $data) && $data['users_id_sponsor'] !== (int) $cur['users_id_sponsor']) {
            self::activeUser($data['users_id_sponsor'], 'O patrocinador');
        }
        $merged = array_merge($cur, $data);
        $_SESSION['vmo_notices'] = self::validateDates($merged);
        if (($merged['progress_method'] ?? 'auto') === 'manual' && (Db::text($merged['progress_justification'] ?? '') === '')) {
            throw new RuleException('Progresso manual exige justificativa.');
        }
        $data['date_mod'] = Db::now();
        $data['last_update_at'] = Db::now();
        $version = Db::updateVersioned('initiatives', $id, $data, $expectedVersion, 'cadastro');
        $diff = Audit::diff($cur, $merged, array_keys($data));
        unset($diff['date_mod'], $diff['last_update_at']);
        if ($diff) {
            Audit::log('Initiative', $id, 'update', $diff, $version, (int) $cur['entities_id']);
        }
        return $version;
    }

    /** Cópia: novo código, volta a rascunho, sem aprovações, custos reais, medições ou progresso. */
    public static function copy(int $id): int
    {
        $src = self::load($id);
        if (!Access::canCreate()) {
            throw RuleException::denied();
        }
        $newId = self::create([
            'entities_id' => $src['entities_id'], 'name' => $src['name'] . ' (cópia)', 'description' => $src['description'], 'problem' => $src['problem'],
            'objective' => $src['objective'], 'justification' => $src['justification'], 'business_case' => $src['business_case'],
            'catalogs_id_objective' => $src['catalogs_id_objective'], 'catalogs_id_category' => $src['catalogs_id_category'], 'priority' => $src['priority'],
            'confidentiality' => $src['confidentiality'], 'users_id_manager' => $src['users_id_manager'], 'users_id_sponsor' => $src['users_id_sponsor'],
            'planned_start' => $src['planned_start'], 'planned_end' => $src['planned_end'], 'currency' => $src['currency'],
        ]);
        $map = [0 => 0];
        foreach (Db::rows('tasks', ['initiatives_id' => $id, 'is_deleted' => 0], ['parent_id ASC', 'id ASC']) as $t) {
            $map[(int) $t['id']] = Db::insert('tasks', [
                'initiatives_id' => $newId, 'parent_id' => $map[(int) $t['parent_id']] ?? 0, 'name' => $t['name'], 'description' => $t['description'],
                'users_id_responsible' => $t['users_id_responsible'], 'groups_id' => $t['groups_id'], 'planned_start' => $t['planned_start'], 'planned_end' => $t['planned_end'],
                'effort_plan' => $t['effort_plan'], 'weight' => $t['weight'], 'priority' => $t['priority'], 'is_milestone' => $t['is_milestone'], 'is_required' => $t['is_required'],
                'checklist' => null, 'date_creation' => Db::now(), 'date_mod' => Db::now(),
            ]);
        }
        Audit::log('Initiative', $newId, 'copy', ['from' => $id], 1, (int) $src['entities_id']);
        return $newId;
    }

    /** Exclusão lógica; só rascunho/rejeitada/cancelada. O expurgo é uma operação administrativa separada (RN06). */
    public static function archive(int $id): void
    {
        global $DB;
        $cur = self::load($id, true);
        if (!in_array($cur['status'], ['draft', 'rejected', 'cancelled'], true)) {
            throw new RuleException('Só rascunhos, rejeitadas e canceladas podem ser arquivadas. Cancele a iniciativa antes.');
        }
        $DB->update(self::getTable(), ['is_deleted' => 1, 'date_mod' => Db::now()], ['id' => $id]);
        Audit::log('Initiative', $id, 'archive', [], (int) $cur['version'], (int) $cur['entities_id']);
    }

    // ---------- transições (RF05) ----------

    /** Quem pode fazer a transição $from>$to. */
    public static function canTransition(array $row, string $to): bool
    {
        $from = $row['status'];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return false;
        }
        $me = (int) Session::getLoginUserID();
        $isSponsor = $me === (int) $row['users_id_sponsor'] || Delegation::actsFor($me, (int) $row['users_id_sponsor']);
        switch ("$from>$to") {
            case 'review>approved': case 'review>rejected': case 'review>draft':
                return self::canDecide($row);
            case 'running>delivered':
                return $isSponsor || Access::canApprove();
            case 'closed>running':
                return Access::canApprove() || Access::canConfigure();
        }
        if ($to === 'cancelled') {
            return $isSponsor || Access::canApprove();
        }
        return Access::canEditInitiative($row);
    }

    /** Pode decidir a aprovação: tem o direito de aprovar (ou é patrocinador/delegado) e não é quem submeteu. */
    public static function canDecide(array $row): bool
    {
        $me = (int) Session::getLoginUserID();
        if ($row['status'] !== 'review' || !Access::canSeeInitiative($row)) {
            return false;
        }
        $authority = Access::canApprove() || $me === (int) $row['users_id_sponsor'] || Delegation::actsFor($me, (int) $row['users_id_sponsor']);
        $pending = Db::rows('approvals', ['initiatives_id' => (int) $row['id'], 'status' => 'pending'], ['revision DESC'], 1)[0] ?? null;
        return $authority && $pending && (int) $pending['users_id_requester'] !== $me;
    }

    /**
     * Executa a transição com as regras de cada etapa.
     *
     * @param array $opts comment, review_date (suspensão), forecast_end (retomada), cancel_tasks, exception (entrega com tarefas obrigatórias abertas)
     */
    public static function transition(int $id, string $to, string $comment = '', array $opts = []): void
    {
        global $DB;
        $row = self::load($id);
        $from = $row['status'];
        $comment = Db::text($comment, 4000);
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new RuleException('Transição não permitida: ' . self::STATUSES[$from][0] . ' → ' . self::STATUSES[$to][0] . '.');
        }
        if (!self::canTransition($row, $to)) {
            $own = $from === 'review' && in_array($to, ['approved', 'rejected', 'draft'], true);
            throw RuleException::denied($own ? 'Quem submeteu a iniciativa não pode decidir sobre ela, e a decisão exige o direito de aprovar (ou ser o patrocinador/delegado).' : 'Sem permissão para esta transição.');
        }
        if (in_array("$from>$to", self::NEED_REASON, true) && $comment === '') {
            throw new RuleException('Informe a justificativa da transição.');
        }
        if ($to === 'cancelled' && $comment === '') {
            throw new RuleException('O cancelamento exige motivo.');
        }

        Db::transaction(function () use ($row, $id, $from, $to, $comment, $opts, $DB) {
            $upd = ['status' => $to, 'status_reason' => $comment ?: null, 'date_mod' => Db::now(), 'last_update_at' => Db::now(), 'version' => (int) $row['version'] + 1];
            $today = Db::today();
            $audit = ['de' => $from, 'para' => $to, 'comentario' => $comment];

            switch ("$from>$to") {
                case 'draft>review': case 'rejected>draft':
                    if ($to === 'review') {
                        self::assertSubmittable($row);
                        $rev = (int) ($DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(`revision`) AS `m`')], 'FROM' => Db::t('approvals'), 'WHERE' => ['initiatives_id' => $id]])->current()['m'] ?? 0) + 1;
                        Db::insert('approvals', [
                            'initiatives_id' => $id, 'revision' => $rev, 'status' => 'pending', 'users_id_requester' => (int) Session::getLoginUserID(),
                            'snapshot' => json_encode(array_intersect_key($row, array_flip(array_merge(self::EDITABLE, ['code']))), JSON_UNESCAPED_UNICODE),
                            'submitted_version' => (int) $row['version'], 'date_creation' => Db::now(),
                        ]);
                        $audit['revisao_solicitacao'] = $rev;
                    }
                    break;
                case 'review>approved': case 'review>rejected': case 'review>draft':
                    $approval = Db::rows('approvals', ['initiatives_id' => $id, 'status' => 'pending'], ['revision DESC'], 1)[0];
                    $result = ['approved' => 'approved', 'rejected' => 'rejected', 'draft' => 'returned'][$to];
                    $DB->update(Db::t('approvals'), ['status' => $result, 'users_id_decider' => (int) Session::getLoginUserID(), 'comment' => $comment ?: null, 'decided_at' => Db::now()], ['id' => $approval['id']]);
                    if ($to === 'approved') {
                        $bid = Baseline::freeze($row, (int) $approval['id'], 0, [], 'Aprovação inicial');
                        $upd['baseline_revision'] = 1;
                        $audit['baseline'] = $bid;
                    }
                    break;
                case 'approved>running':
                    Dependency::assertInitiativeCanStart($id);
                    $upd['actual_start'] = $row['actual_start'] ?: $today;
                    break;
                case 'running>suspended':
                    $review = Db::date($opts['review_date'] ?? '');
                    if (!$review) {
                        throw new RuleException('A suspensão exige a data de revisão.');
                    }
                    $upd['review_date'] = $review;
                    $upd['prev_status'] = $from;
                    break;
                case 'suspended>running':
                    $fe = Db::date($opts['forecast_end'] ?? '') ?: $row['forecast_end'];
                    if ($fe && $fe < $today) {
                        throw new RuleException('Para retomar, atualize a previsão de término: a data atual de previsão já passou.');
                    }
                    $upd['forecast_end'] = $fe;
                    $upd['review_date'] = null;
                    break;
                case 'running>delivered':
                    $open = countElementsInTable(Db::t('tasks'), ['initiatives_id' => $id, 'is_deleted' => 0, 'is_required' => 1, 'status' => ['todo', 'doing']]);
                    if ($open > 0) {
                        if (empty($opts['exception']) || $comment === '' || !Access::canApprove()) {
                            throw new RuleException("Há $open tarefa(s) obrigatória(s) em aberto. A entrega só é possível com exceção aprovada (direito de aprovar e justificativa).");
                        }
                        $audit['excecao_tarefas_abertas'] = $open;
                    }
                    $upd['actual_end'] = $today;
                    break;
                case 'measuring>closed':
                    $pending = countElementsInTable(Db::t('benefits'), ['is_deleted' => 0, 'horizon_end' => ['>', $today], 'id' => new \Glpi\DBAL\QuerySubQuery([
                        'SELECT' => 'benefits_id', 'FROM' => Db::t('allocations'), 'WHERE' => ['initiatives_id' => $id]])]);
                    if ($pending > 0 && $comment === '') {
                        throw new RuleException("Há $pending benefício(s) com horizonte de medição em andamento. Encerrar exige o fim do horizonte ou a dispensa formal do restante, com motivo.");
                    }
                    $audit['horizonte_pendente'] = $pending;
                    break;
                case 'closed>running':
                    $upd['actual_end'] = null;
                    // reabertura exige nova versão do plano: abre uma solicitação de mudança pendente
                    ChangeRequest::open($row, 'scope', ['reopen' => true], 'Reabertura: ' . $comment, true);
                    break;
            }
            if ($to === 'cancelled') {
                $openTasks = countElementsInTable(Db::t('tasks'), ['initiatives_id' => $id, 'is_deleted' => 0, 'status' => ['todo', 'doing']]);
                if ($openTasks > 0) {
                    if (empty($opts['cancel_tasks'])) {
                        throw new RuleException("Há $openTasks tarefa(s) pendente(s). Marque \"cancelar as tarefas pendentes\" para confirmar (custos permanecem).");
                    }
                    $DB->update(Db::t('tasks'), ['status' => 'cancelled', 'date_mod' => Db::now()], ['initiatives_id' => $id, 'is_deleted' => 0, 'status' => ['todo', 'doing']]);
                    $audit['tarefas_canceladas'] = $openTasks;
                }
            }
            if ($from === 'suspended' && $to === 'running') {
                $upd['prev_status'] = null; // retomada volta à execução (único estado de onde se suspende)
            }
            $DB->update(self::getTable(), $upd, ['id' => $id, 'version' => (int) $row['version']]);
            if ($DB->affectedRows() < 1) {
                throw RuleException::conflict('cadastro');
            }
            Audit::log('Initiative', $id, 'transition', $audit, (int) $row['version'] + 1, (int) $row['entities_id']);
        });

        $fresh = Db::row('initiatives', $id);
        $label = self::STATUSES[$to][0];
        Outbox::toOwners($fresh, 'status', "{$fresh['code']}: {$label}", "status:$id:$to:" . $fresh['version']);
    }

    /** Submissão exige gestor, patrocinador, objetivo e datas (RF02). */
    public static function assertSubmittable(array $row): void
    {
        $missing = [];
        if (!(int) $row['users_id_manager']) {
            $missing[] = 'gestor';
        }
        if (!(int) $row['users_id_sponsor']) {
            $missing[] = 'patrocinador';
        }
        if (trim((string) $row['objective']) === '' && !(int) $row['catalogs_id_objective']) {
            $missing[] = 'objetivo';
        }
        if (!$row['planned_start'] || !$row['planned_end']) {
            $missing[] = 'datas planejadas';
        }
        if ($missing) {
            throw new RuleException('Para submeter, complete: ' . implode(', ', $missing) . '.');
        }
        self::validateDates($row);
    }

    // ---------- indicadores derivados (seção 5) ----------

    /**
     * Calcula, em lote, os indicadores das iniciativas informadas (linhas já filtradas por acesso).
     *
     * @param array<int,array> $rows id => linha
     * @return array<int,array> id => métricas
     */
    public static function metricsFor(array $rows, ?string $cutoff = null, bool $withFinance = true): array
    {
        global $DB;
        if (!$rows) {
            return [];
        }
        $ids = array_keys($rows);
        $holidays = Settings::holidays();
        $th = Settings::thresholds();
        $today = $cutoff ?: Db::today();

        $tasks = [];
        foreach ($DB->request(['FROM' => Db::t('tasks'), 'WHERE' => ['initiatives_id' => $ids, 'is_deleted' => 0]]) as $t) {
            $tasks[(int) $t['initiatives_id']][(int) $t['id']] = $t;
        }
        $risk = [];
        foreach ($DB->request(['FROM' => Db::t('risks'), 'WHERE' => ['initiatives_id' => $ids, 'is_deleted' => 0, 'kind' => 'risk', 'status' => ['open', 'mitigating']]]) as $r) {
            $risk[(int) $r['initiatives_id']] = max($risk[(int) $r['initiatives_id']] ?? 0, Calc::riskExposure((int) $r['probability'], (int) $r['impact']));
        }
        $baseline = [];
        foreach ($DB->request(['FROM' => Db::t('baselines'), 'WHERE' => ['initiatives_id' => $ids], 'ORDER' => ['revision DESC']]) as $b) {
            $baseline[(int) $b['initiatives_id']] ??= $b;
        }
        $cost = [];
        foreach ($DB->request(['SELECT' => ['initiatives_id', 'kind', 'currency', new \Glpi\DBAL\QueryExpression('SUM(`amount`) AS `total`')], 'FROM' => Db::t('costs'),
            'WHERE' => ['initiatives_id' => $ids, 'competence_date' => ['<=', $today]], 'GROUPBY' => ['initiatives_id', 'kind', 'currency']]) as $c) {
            $cost[(int) $c['initiatives_id']][$c['currency']][$c['kind']] = (float) $c['total'];
        }
        $forecastAll = []; // a previsão não depende do corte
        foreach ($DB->request(['SELECT' => ['initiatives_id', 'currency', new \Glpi\DBAL\QueryExpression('SUM(`amount`) AS `total`')], 'FROM' => Db::t('costs'),
            'WHERE' => ['initiatives_id' => $ids, 'kind' => 'forecast'], 'GROUPBY' => ['initiatives_id', 'currency']]) as $c) {
            $forecastAll[(int) $c['initiatives_id']][$c['currency']] = (float) $c['total'];
        }
        $value = $withFinance ? Benefit::valueByInitiative($ids, $today) : [];

        $out = [];
        foreach ($rows as $id => $r) {
            $id = (int) $id;
            $leaves = Calc::leaves($tasks[$id] ?? []);
            $progress = $r['progress_method'] === 'manual' ? ($r['progress_manual'] !== null ? (float) $r['progress_manual'] : null) : Calc::progress($leaves);
            $b = $baseline[$id] ?? null;
            $baseEnd = $b ? $b['planned_end'] : $r['planned_end'];
            $forecastEnd = $r['forecast_end'] ?: $r['planned_end'];
            $delay = Calc::delay($baseEnd, $forecastEnd, $holidays);
            // tarefas abertas vencidas contam para o atraso das tarefas, não da iniciativa
            $lateTasks = 0;
            foreach ($tasks[$id] ?? [] as $t) {
                if (in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end'] && $t['planned_end'] < $today) {
                    $lateTasks++;
                }
            }
            $cur = $r['currency'];
            $forecast = $forecastAll[$id][$cur] ?? null;
            $budget = $b ? (json_decode((string) $b['budget_json'], true)[$cur] ?? null) : null;
            $variance = ($forecast !== null && $budget !== null) ? Calc::costVariance($forecast, (float) $budget) : null;
            $costPct = $variance[1] ?? null;
            $maxRisk = $risk[$id] ?? null;
            $hasBaseline = $b !== null;
            $health = in_array($r['status'], ['running', 'suspended', 'delivered', 'measuring', 'approved'], true)
                ? Calc::health($hasBaseline ? $delay : null, $costPct, $maxRisk, $th)
                : 'gray';
            $stale = $r['status'] === 'running' && $r['last_update_at']
                && strtotime($r['last_update_at'] . ' UTC') < strtotime("-" . Settings::cadenceDays() . ' days');
            $valid = 0;
            $applicable = 7;
            foreach (['name', 'users_id_manager', 'users_id_sponsor', 'planned_start', 'planned_end', 'objective', 'description'] as $f) {
                $valid += !empty($r[$f]) ? 1 : 0;
            }
            $v = $value[$id] ?? ['planned' => [], 'validated' => []];
            $realization = null;
            $roi = null;
            if ($withFinance && isset($v['planned'][$cur])) {
                $realization = Calc::valueRealization($v['validated'][$cur] ?? 0.0, $v['planned'][$cur]);
                $roi = Calc::roi($v['validated'][$cur] ?? 0.0, $cost[$id][$cur]['actual'] ?? null);
            }
            $out[$id] = [
                'progress'      => $progress,
                'delay'         => $delay,
                'late_tasks'    => $lateTasks,
                'tasks'         => count($tasks[$id] ?? []),
                'forecast'      => $forecast,
                'budget'        => $budget !== null ? (float) $budget : null,
                'actual'        => $cost[$id][$cur]['actual'] ?? 0.0,
                'cost_variance' => $variance,
                'costs'         => $cost[$id] ?? [],
                'max_risk'      => $maxRisk,
                'health'        => $health,
                'stale'         => $stale,
                'completeness'  => Calc::completeness($valid, $applicable),
                'value'         => $v,
                'realization'   => $realization,
                'roi'           => $roi,
                'baseline_rev'  => $b ? (int) $b['revision'] : 0,
            ];
        }
        return $out;
    }
}
