# VMO — Value Management Office para GLPI 11 e 12

Plugin que gere iniciativas **da proposta à comprovação de valor**: portfólio, tarefas e dependências, baseline imutável
com controle de mudanças, custos, benefícios (previsto × declarado × validado), riscos, indicadores, dashboard executivo e
relatórios reproduzíveis. Especificação: [`docs/Requisitos_Plugin_GLPI_VMO_v1.docx`](docs/Requisitos_Plugin_GLPI_VMO_v1.docx) (VMO SRS 1.0).

Versão **0.1.0** — entrega P0 da especificação (veja "Cobertura" abaixo). Licença GPLv3+.

## Instalação

A pasta do plugin no GLPI precisa se chamar `vmo`.

```bash
tools/deploy.sh                       # publica no GLPI do laboratório (192.168.15.45) e instala/ativa
tools/deploy.sh /var/www/glpi11test   # idem, no GLPI 11 de teste
```

Manual: copie para `<glpi>/plugins/vmo`, `chown -R www-data`, e rode `php bin/console plugin:install vmo` e `plugin:activate vmo`.
Para atualizar, copie os arquivos e rode `plugin:install vmo` de novo (migrações idempotentes).
**Desinstalar não apaga dados**; para expurgar as tabelas: `VMO_PURGE=1 php bin/console plugin:uninstall vmo`.

## Direitos (Administração → Perfis → aba VMO)

| Bit | Para quê |
| --- | --- |
| Ler / Criar / Atualizar / Excluir | uso normal; só vê iniciativas das entidades ativas e (se confidenciais) em que participa |
| **Aprovar** | aprovar iniciativa, baseline, mudança e benefício |
| **Validar** | validar medições de benefício |
| **Financeiro** | ver e lançar custos e valores financeiros |
| **Configurar** | catálogos, limites de saúde, câmbio, delegações, diagnóstico |

A instalação dá todos os direitos a perfis que podem alterar a configuração do GLPI e só leitura/criação/edição aos demais.
Ninguém ganha aprovação, validação, finanças ou configuração implicitamente.

## Regras que o plugin garante (testadas)

* **Segregação (RN07):** quem submeteu não aprova; quem solicitou a mudança não a aprova; quem declarou a medição não a valida; quem cadastrou o benefício não o aprova. Delegação só vale dentro da vigência.
* **Baseline imutável (RN04):** aprovação congela a revisão (hash SHA-256); prazo/escopo/custo só mudam por solicitação aprovada, que gera nova revisão com comparação. Ajustar a *previsão* não mexe na baseline.
* **Valor ≠ entrega (RN05):** previsto, declarado e validado são valores distintos; só medição validada por outra pessoa entra no realizado. Retificação cria revisão e exige nova validação; revisões nunca se somam. Benefício compartilhado soma uma vez (alocações ≤ 100%).
* **Acesso antes de somar (RN09):** listas, dashboard e relatórios partem das iniciativas visíveis; sem o direito financeiro os valores somem; acesso direto a registro restrito devolve 404.
* **Dado ausente nunca vira zero:** "sem dado", "não aplicável", "desatualizado". Moedas diferentes só se somam com taxa registrada (data e fonte); sem taxa, totais separados.
* **Concorrência:** controle otimista de versão (conflito explícito, sem sobrescrita silenciosa); dependências e transições são revalidadas na gravação; dependência com ciclo é rejeitada de forma atômica.
* **Auditoria:** trilha só de inserção, com ator, ação, antes/depois, versão e correlação por requisição; retenção configurável.

## Testes

```bash
php tests/calc.php                                                             # cálculos da seção 5 (sem GLPI)
sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/smoke.php               # regras de negócio (cenários CA01–CA13)
sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/pages.php               # renderiza todas as telas
sudo -u www-data env GLPI_ROOT=/var/www/glpi php tools/seed_demo.php [--reset] # dados "[Demo]"
```

Rodam no GLPI 11 e no 12 com o mesmo código (as classes de domínio não herdam do núcleo; só a base de direitos tem uma declaração por versão).

## Cobertura da especificação

**P0 implementado:** RF01, RF02, RF03 (sem visões salvas), RF05–RF15, RF17, RF19 (avisos dentro do GLPI), RF20, RF22, RF24; RN01–RN10; RNF de segurança, auditoria, concorrência e privacidade.

**Ainda não implementado (evolução):**

* **P1:** RF04 priorização com pesos, RF16 dashboard operacional (carga/capacidade), RF18 agendamento de relatórios, RF21 importação CSV, RF23 API REST e eventos.
* RF03: visões pessoais/compartilhadas salvas. RF19: envio por e-mail (hoje há fila de avisos + tela "Pendências e avisos"; o lab não tem SMTP).
* Medições de desempenho (RNF03) e recuperação (RNF05) precisam ser executadas num ambiente de referência; acessibilidade WCAG 2.2 AA e testes de segurança dedicados ainda não foram feitos.
* Transferência de entidade e expurgo administrativo de dados de negócio (hoje só a trilha e os relatórios expiram por retenção).
* Aprovação formal de requisitos pendentes da seção 14 da especificação (alçadas, retenção, câmbio) continua com a organização.
