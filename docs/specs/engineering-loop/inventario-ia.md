# Inventário: BlueJus Intelligence / IA

Auditoria somente leitura em 07/10/2026, HEAD 607f2c29. Prefixo dos IDs: IA-.
Escopo: telas de IA que estão no código (botão e lista "Resumir com IA" no Push, botão "BLUEJUS Intelligence" e
drawer dos 7 agentes na pasta, `/admin/inteligencia`, rótulos de indisponibilidade), arquitetura, segurança,
permissões, persistência, estados desligados, rótulos de IA sobre regra fixa, e o inventário de requisitos do
README do handoff, classificado por grupo.

Regra aplicada: nada de IA simulada e nada de regra inventada. Regra de agente, comando, núcleo cognitivo ou
Master cuja origem não está segura → **D**. Provedor, chave e LGPD → **B/C** (D-IA, D-IA2).

## Fontes lidas (arquivo:faixa de linhas)

Pacote `docs/design/claude-design-2026-10-05 (1)/`:
- `design_handoff_bluejus_intelligence/README.md`: lido inteiro (L1-619, em 3 faixas); a contagem dos comandos foi
  feita por script sobre as L331-588.
- `02 - EXPEDIENTES 1.2.3.dc.html`: L455-470 e L525-535 (cadeado e link), L843-1040 (drawer BlueJus IA completo),
  L1136-1142 (botão Intelligence), L2085-2125 (checklist ✦), L2410-2512 (cartão da Análise IA no Push),
  L2601/2622 ("Previsão da I.A"), L2675-2695 (avisar cliente), L4068/4092/4125/4216, L4962-5170 (Push IA:
  gerarPushIA, edição, envio ao cliente, recolher), L5440-5760 (AGENTES, IA_REGRAS, iaContexto, iaFase,
  iaProvidencias, iaSituacao, iaProvedor, iaOrquestrar, iaLocal, iaVals).
- `bluejus-intelligence.js` (181 linhas): limiares conferidos por grep (1,05 · .85 · .65 · .45 · .8 · 1,25).
- `bluejus-central.js` (6.831 linhas, 1,4 MB): só grep dirigido (BLUEJUS_IA_URL L393/L3078/L3801,
  claude.complete, responderIA, proativoResposta, cogResposta, detectarManipulacao, privacyGate, guardaContas,
  MASTER, localStorage ×45, CAPACIDADES, semTraco).
- `bluejus-docs.js` (161 linhas): pelo mapa do inventário antigo §1.13.

Código e documentação:
- `app/src/Inteligencia/CLAUDE.md` (inteiro); `docs/specs/inteligencia-agentes-da-pasta.md` (inteiro);
  `docs/specs/trilha-b-inventario/inteligencia.md` §0-§1.17 (só como mapa); mestre
  `docs/specs/estado-geral-claude-designer-bluejus.md` §1.2-§1.4, §7, §10.
- Templates: `inteligencia/_agentes_drawer.html.twig` (1-56), `_agentes_painel.html.twig` (1-65),
  `_analises_agente.html.twig` (por grep), `_analises_push.html.twig` (1-148), `admin/configuracao.html.twig`
  (1-131), `pasta/_cabecalho.html.twig` (315-338), `pasta/_push_processual.html.twig` (25-100),
  `dashboard/_inteligencia.html.twig` (30-60), grep de `✦|Intelligence|IA|inteligente|por regras` em
  `app/templates` e `app/public/js`.
- PHP: `MessageHandler/ProcessarAnaliseDeInteligenciaHandler.php` (1-200), `Controller/AnalisePushController.php`
  (49-240), `Controller/ConfiguracaoController.php` (por grep), `Service/DisponibilidadeDeInteligencia.php`,
  `Enum/Disponibilidade.php`, `Enum/TipoDePonto.php`, `Service/MascaradorDeDadosPessoais.php` (20-61),
  `Contexto/FonteDeDadosDaPastaDoctrine.php` (95-125), `Service/PermissionChecker.php` (por grep),
  `Tenant/UseCase/PurgarEscritorioUseCase.php:115-116`, `DataFixtures/PermissionFixture.php:30,57`,
  `migrations/Version20261006120000.php` (11-71).
- Config: `config/services.yaml:66-69,119-121,197-199`, `config/packages/messenger.yaml` (inteiro),
  `framework.yaml:67-73`, `monolog.yaml:2-8`, `doctrine.yaml:20-28`; `docker-compose.yml` e
  `docker-compose.prod.yml:34-66`.
- CSS/JS: `public/css/pasta-ia.css` (grep), `public/css/pasta-ia-agentes.css` (63-65, 151-152, 515-534),
  `public/js/pasta-ia-push.js` e `pasta-ia-agentes.js` (grep).
- Testes: `app/tests/Inteligencia/**` tem 207 métodos `test*` (contagem por grep; a suíte não foi rodada).

## Matriz (contagem real)

Denominador: só os elementos das telas de IA que o desenho mostra (Push IA, botão e drawer da pasta) mais o
admin e os rótulos. O inventário do README (Chat, núcleos, 197 comandos) fica numa tabela própria, mais abaixo, e
não entra aqui, porque não é tela desta rodada (§1.2 do mestre).

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual | 21 | 34 |
| Interações | 13 | 37 |
| Funções (persistência/backend/segurança) | 27 | 33 |
| Responsivo | 4 | 4 |
| Dark mode | 5 | 5 |
| **Total** | **70** | **113** |

Divergências: **43** linhas (IA-001 a IA-043). Por classe: **A 14 · B 5 · C 13 · D 10 · E 1** (IA-043, B + C, contada como B).
Estão em A, B, C, D ou E: 43 de 113. Quatro dos itens já F têm ressalva registrada nas Observações, sem linha
própria.

## Divergências

Legenda: Dim V = visual, F = função ou interação, R = responsivo. Prioridades conforme o briefing.

| ID | Elemento | Fonte (arquivo:linha) | Dim | Divergência (desenho × código) | Classe | Prior. | Evidência no código | Esforço | Proposta (se A) |
|---|---|---|---|---|---|---|---|---|---|
| IA-001 | Texto de aceite em `/admin/inteligencia` | §7.2 do mestre; README L20-33 | F | O aceite diz que o escritório envia "conteúdo processual (publicações e movimentações…)". Desde a fatia 2, os agentes enviam também os nomes dos clientes, metas, anotações, observações, metadados de documentos, checklist e, quando permitido, o financeiro. O consentimento registrado descreve menos do que de fato sai. A IA está desligada em produção (0 escritórios), então ainda não houve dano | A | P1 | `templates/inteligencia/admin/configuracao.html.twig:74-78`; seções em `docs/specs/inteligencia-agentes-da-pasta.md` §2.1 | P | Listar no texto as categorias que saem de verdade, tiradas de `SecaoDoContexto` (gerado pelo enum, para não divergir de novo). A redação jurídica final continua com o D-IA (ver IA-030). Arquivo: o template (e o enum, se o texto vier dele). Sem migration. Teste: `ConfiguracaoControllerTest`, provando que cada `SecaoDoContexto::rotuloCurto` aparece no aviso |
| IA-002 | Máscara de CPF e CNPJ sem pontuação | README L256 ("CPF com dígito verificador"), L591 | F | O mascarador só reconhece `000.000.000-00` e `00.000.000/0000-00`. Um "CPF 12345678901" digitado numa anotação ou observação vai ao provedor sem máscara | A | P0 | `app/src/Inteligencia/Service/MascaradorDeDadosPessoais.php:26-28` (o comentário diz que é intencional: "dígito solto não é PII") | P | Mascarar também a sequência de 11 dígitos que passa no DV do CPF e a de 14 que passa no DV do CNPJ, sempre depois de proteger o CNJ. Arquivo: o Mascarador. Sem migration. Teste: novos casos no `MascaradorDeDadosPessoaisTest`: CPF válido sem pontuação mascarado, 11 dígitos com DV inválido intactos, e o caso "texto que é só CPF vira só a máscara" |
| IA-003 | Mensagem de cota mensal | — (convenção) | F | Quando o limite **mensal** estoura, a tela diz "Limite diário de análises atingido". A mensagem é falsa | A | P4 | `Service/DisponibilidadeDeInteligencia.php` (os dois `return LimiteAtingido`); `Enum/Disponibilidade.php:33` | P | Criar o caso `LimiteMensalAtingido` (ou texto neutro "Limite de análises atingido (dia ou mês)"). Arquivos: o enum e o Disponibilidade. Sem migration. Teste: `DisponibilidadeDeInteligenciaTest::testLimiteMensal` passando a conferir a mensagem |
| IA-004 | Travessão em texto da interface | README L600 ("Nenhum texto da interface usa travessão") | V | "BlueJus IA desligada neste escritório — peça ao administrador" | A | P4 | `Enum/Disponibilidade.php:31` | P | Trocar por "…neste escritório. Peça ao administrador". Sem migration. Teste: asserção da mensagem no `PastaCabecalhoIaTelaTest` |
| IA-005 | Chips de contexto do drawer | dc L852-854 e L5732 (`contexto`) | V | O desenho tem Pasta nº · nome do cliente · número do processo · responsável. O código tem Pasta nº · N processos · N metas · N documentos | A | P3 | `templates/inteligencia/_agentes_drawer.html.twig:37-42` | P | Usar os 4 chips do desenho: cliente principal (`pasta.clientePrincipal`), processo principal (número), responsável ("Sem processo" e "Sem responsável" quando faltar). Os dados já estão na página. Sem migration. Teste: `PastaCabecalhoIaTelaTest` com `.ia-drawer-chips > .ia-chip` em ordem |
| IA-006 | Grade "O que a IA sabe desta pasta" | dc L861-864 e L5736 (`sabe`) | V | O desenho tem 5 contadores (documentos, tarefas, prazos, anotações, fontes). O código não tem nenhum | A | P3 | não existe em `_agentes_drawer.html.twig` | P | Renderizar 4 contadores reais com consulta por tenant: documentos vivos, metas, metas com prazo aberto, anotações + observações. O 5º ("fontes" = soma + 2 no protótipo) fica de fora (ver IA-021). Sem migration. Teste: tela conferindo os números contra as fixtures |
| IA-007 | Copiar, Baixar .txt e Imprimir no resultado do agente | dc L1001-1003 e L5752-5755 | F | Os três botões do cabeçalho do resultado não existem | A | P2 | `templates/inteligencia/_analises_agente.html.twig:39-120` (só ⋮ e excluir/lida) | P | Três botões no cartão concluído. Copiar usa `navigator.clipboard` sobre `texto_da_analise`. Baixar: GET `/pasta/{id}/ia/analises/{analiseId}.txt` (mesmo `guardar()`, `Content-Disposition`). Imprimir: `window.print` de uma página escapada. Arquivos: `_analises_agente`, `pasta-ia-agentes.js`, `AnalisePushController` (rota txt). Sem migration. Teste: functional do .txt (cross-tenant 404, sem módulo 403, conteúdo escapado) |
| IA-008 | Rodapé "Registro de auditoria (N)" do drawer | dc L1030-1035 | F | O desenho lista as ações de IA da pasta (hora + o quê). O código não tem | A | P2 | não existe no drawer; `AnaliseDeInteligencia` é `Auditavel` (`Entity/AnaliseDeInteligencia.php:43`) | M | Rodapé recolhível que lista as últimas 50 análises da pasta (quem pediu, agente ou Push, quando, status). Vem de `inteligencia_analise` (já tem tenant), via o UseCase de listagem. Sem migration. Teste: functional com 2 tenants (não vaza) e escape |
| IA-009 | Editar análise do Push e selo "editado" | dc L2445 (`editado`), L2452-2486, L5149-5152 | F | O desenho tem "Editar" no ⋮ e na linha de ações (textarea do resumo, inputs dos pontos, "Sua edição fica registrada como editado"). O código não tem: o comentário do template diz "Edição… não existe no sistema", decisão de quem implementou, sem registro de decisão do Samuel | A | P2 | `templates/inteligencia/_analises_push.html.twig:9-12` | M | UseCase `EditarAnaliseUseCase` (CSRF por análise, `guardar()`), que grava `resumo` e `pontos` editados e mantém `texto_bruto` como o original do modelo. Colunas `editada_em` e `editada_por_id`. **Migration: sim.** Teste: unit do UseCase (ponto vazio sai, resumo vazio mantém o anterior) + functional (cross-tenant 404, escape, selo "editado") |
| IA-010 | Recolher e expandir a análise, com prévia | dc L2448 e L2463-2465; L5137-5139; README L137 ("preferência salva") | F | O botão chevron, a prévia de uma linha ("resumo · N ponto(s)") e a preferência salva não existem | A | P3 | `templates/inteligencia/_analises_push.html.twig` (sem chevron); `pasta-ia-push.js` (sem estado) | P | Chevron + prévia. Persistir por usuário em `preferencia_usuario` (já existe: chave `push.ia.recolhidas` com ids), nunca em localStorage. Sem migration. Teste: functional da preferência e tela com `.ps-ia-cab > button[aria-expanded]` |
| IA-011 | "Marcar como não lido" | dc L5159-5160 (`alternarLido`) | F | O desenho alterna lido e não lido. O código só marca como lida: depois disso a opção some | A | P4 | `_analises_push.html.twig:71,134`; `UseCase/MarcarAnaliseComoLidaUseCase.php` | P | Rota `…/nao-lida` (ou toggle no mesmo UseCase) que zera `lida_em` e `lida_por`. Sem migration. Teste: functional do toggle |
| IA-012 | Runbook "ligar a IA em produção" | §7.2 do mestre; CLAUDE.md do domínio, "Ligar um provedor" | F | Não existe runbook (env, rebuild, smoke, rollback, Anexo I) | A | P2 | `docs/runbooks/` sem arquivo de IA | P | `docs/runbooks/ligar-ia-em-producao.md`, com os passos do `app/src/Inteligencia/CLAUDE.md` + `app:inteligencia:status` + rollback (`IA_HABILITADA` vazio + rebuild). Sem migration. Teste: n/a (é documentação) |
| IA-013 | Worker no compose de dev | §7.2 e §8.4 do mestre | F | Em dev não há serviço `worker`: análise solicitada fica `pendente` até alguém rodar `messenger:consume` à mão | A | P3 | `docker-compose.yml` sem `messenger`; `docker-compose.prod.yml:38-56` tem | P | Serviço `worker` no `docker-compose.yml`, espelhando o de prod (`messenger:consume async --time-limit=3600`). Sem migration. Teste: n/a (infra; conferir com `docker compose config`) |
| IA-014 | Corpus de injeção de prompt (aceite "/redteam") | README L593-595 e L610 | F | O README pede uma bateria de ataques (injeção, letras espaçadas, "todos os escritórios"). Os testes do `NeutralizadorDeConteudoTest` têm 6 casos | A | P2 | `tests/Inteligencia/Unit/NeutralizadorDeConteudoTest.php` (6 testes) | M | Data provider com ~50 entradas hostis (tag delimitadora, zero-width, bidi, homoglifos, `</movimentacoes>` partido) contra Neutralizador + `PromptDoAgente`/`PromptResumoDoPush`: nenhuma pode fechar bloco nem sair do delimitador. Sem migration. Teste: o próprio corpus |
| IA-015 | Ícone e rótulo do cadeado "interna do escritório" | dc L5141-5142 (`privada` nasce falso) | V | O cadeado promete efeito no "link compartilhado com o cliente", que não existe (D-LINK). A ação persiste mas não tem efeito hoje. O padrão também é o inverso: o desenho nasce "visível", o código nasce interna (`DEFAULT true`) | C (D-LINK) | P4 | `_analises_push.html.twig:52-65`; `migrations/Version20261006120000.php:50` (`interna_do_escritorio DEFAULT true`) | P | — (decisão: esconder o cadeado até haver link, ou manter com outro texto; o padrão "interna" é o seguro) |
| IA-016 | Abas Documentos, Relatórios e Perguntar do drawer | dc L857 e L944-985; L5733 | F | O desenho tem 4 abas. O código só tem a lista de agentes ("aba que não funciona é mentira") | B | P2 | `_agentes_drawer.html.twig:4-8` | G | — Documentos: depende de extração/OCR no servidor (D13, frente D). Perguntar: depende de provedor (D-IA) e de conversa persistida. Relatórios: os 6 modelos de prompt são do Designer (ver IA-024) |
| IA-017 | "Perguntar" (chat da pasta) com sugestões | dc L966-985, L1019-1024, L5671-5686 | F | Não existe. O fallback do protótipo responde com regex local "montada localmente" | B (D-IA) | P2 | — | G | — (o fallback local fica proibido pela regra 5 do mestre) |
| IA-018 | Adaptador do provedor real | README L28 | F | Só existe `ProvedorNaoConfigurado`; falta o adaptador HTTP e a troca do alias | B (D-IA) | P2 | `config/services.yaml:119` | M | — (autônomo depois da escolha do provedor) |
| IA-019 | "Ler documentos" e "Índice inteligente" (aba Documentos) | dc L944-959 e L5709-5710 | F | Leitura de conteúdo e OCR não existem; o Agente Documental só vê metadados | B | P2 | spec agentes §2.2; `Enum/Agente` (documental) | G | — (D13, extração no servidor) |
| IA-020 | "Fase identificada pela IA" + Corrigir fase + "Inconsistência identificada" + "Sugerir atualização" | dc L866-904; L5506-5534 (`iaFase` por regex) | F | Não existe. No protótipo a "fase pela IA" é regex sobre prazos e registros, com contador de evidências e confiança Baixa/Média/Alta/Confirmada | D | P2 | spec agentes §2.2 ("fatias seguintes") | — | — Regra de fase e confiança do Designer; também cai no D-PRAZOS. Rotular IA o que é regex violaria o §1.4.3 |
| IA-021 | Contador "fontes" e KPIs do resultado (fontes, documentos, ausentes, revisão humana) | dc L1007-1009; L5603-5604 | V | Não existe. No protótipo, "fontes" = 7 − faltas; "ausentes" = contagem por regex de "não consta" | D | P4 | — | — | — Heurística sem lastro |
| IA-022 | "Próximas providências sugeridas" marcáveis + "Criar N tarefas em Metas" | dc L905-916; L5535-5545, L5741-5744, L5762 | F | Não existe. No protótipo é uma lista por regra (prazos, metas atrasadas, sem processo, sem responsável), e as tarefas são criadas com prazo de 3 dias úteis | D | P2 | — | — | — Regra de providências e de prazo automático sem especificação do Farlei |
| IA-023 | "Situação da pasta" NORMAL/ATENÇÃO/PRIORIDADE/CRÍTICO + "Como cheguei a esta conclusão?" | dc L917-933; L5546-5563 (`iaSituacao`) | F | Não existe. No protótipo é regra fixa (prazo ≤ 3 dias, meta > 30 dias atrasada, sem responsável), apresentada como IA | D | P2 | — | — | — Se for feita por regras, o rótulo tem de dizer "por regras" |
| IA-024 | "Comandos para esta pasta" (10 botões) × 7 cartões de agente | dc L934-941; L5637-5648 (`CMDS`); L5649-5655 (`MOD`, 6 relatórios) | V/F | O desenho tem 10 comandos em 2 colunas, cada um com o seu pedido (riscos, providências, briefing, linha do tempo, raio-X…). O código tem 7 cartões, um pedido canônico por agente. Faltam 6 comandos e os 6 modelos de relatório. Reinterpretação feita pela spec, sem decisão do Samuel | D | P3 | `_agentes_painel.html.twig:75-120`; spec agentes §2 | — | — Os textos dos pedidos são prompts do Designer; a origem não é segura (briefing) |
| IA-025 | "Memória de aprendizado do escritório" | dc L929-933; L5466, L5743 | F | Não existe | D | P4 | spec agentes §2.2 ("memória/correções") | — | — Núcleo de memória |
| IA-026 | "Criar prazo" (em revisão) a partir da análise | dc L1012 e L5749 | F | Não existe | C (D-PRAZOS) | P3 | — | — | — |
| IA-027 | "Enviar para revisão" | dc L1013 e L5750 | F | Não existe; o sistema não tem fluxo de revisão de análise | D | P3 | — | — | — Regra de revisão (quem revisa, estado) sem especificação |
| IA-028 | "Enviar ao cliente" / "Reenviar" da Análise IA + histórico de envios + CC obrigatória da Controladoria | dc L2472-2474; L4968-5031 | F | Não existe | C | P2 | `_analises_push.html.twig:11-12` | — | — Decisão nova: enviar análise de IA por e-mail ao cliente (LGPD, OAB, revisão obrigatória) + papel "Controladoria" (não existe em `src/`) + vários e-mails (D-CLI-CONTATOS) |
| IA-029 | Envio automático de IA ("Análise da BlueJus IA · Só manual") e IA a cada movimentação | dc L2685-2686 e L5447; README L178 | F | Não existe; hoje a análise só sai sob demanda | C (D-IA) | P3 | — | — | — Custo e cota |
| IA-030 | Referência ao "Anexo I da Política" no aceite | §7.2 do mestre | F | O texto remete ao Anexo I, mas o Anexo I não lista fornecedor de LLM | C (D-IA) | P1 | `admin/configuracao.html.twig:76`; `templates/legal/_politica_privacidade_texto.html.twig` (cap. 11 sem fornecedor) | — | — Fornecedor, zero-retention, comunicação aos contratantes |
| IA-031 | Papéis que recebem `modules.inteligencia.view` e `admin.inteligencia.manage` | — | F | Ninguém recebe as permissões (migration "sem conceder a papéis"); só o admin do escritório usa, por bypass | C (D-IA2) | P3 | `migrations/Version20261006120000.php:36-37`; `Service/PermissionChecker.php:30,53,76,104` (`isSystem`) | — | — |
| IA-032 | Botão "BlueJus Intelligence" do Dashboard (estado fechado) | dc do Dashboard L2706 | V | O painel diz "por regras fixas", mas o botão usa o mesmo nome e a mesma estrela do botão da pasta, que é IA real. A dica diz só "Leitura do período" | C | P4 | `templates/dashboard/_inteligencia.html.twig:37-41,51` | — | — Decisão nova: pôr "por regras" na dica ou no rótulo desvia do texto do desenho |
| IA-033 | "Previsão da I.A, não é ato oficial" no Monitoramento do Push | dc L2601, L2622, L4216; REGRAS_PROC L5063+ | V/F | O card de previsão não existe. No desenho, a previsão é regra (13 regras do CPC) rotulada "I.A" | C (D-PRAZOS) | P3 | `pasta/_push_processual.html.twig:116` ("monitoramento… fora") | — | — Se vier a ser feito: rótulo "previsão por regras" |
| IA-034 | BlueJus IA bloqueada para pasta trancada (cadeado) | dc L463; L5729 (`cadNivel() === 'oculto'`); README L119 | F | Não existe trancamento de pasta | C (D-MASTER) | P3 | — | — | — |
| IA-035 | Botões de IA em pasta excluída (lápide) | — | F | No servidor, o `PastaSomenteLeituraListener` recusa o POST de IA. A tela, porém, ainda mostra "Resumir com IA" e "Gerar análise" | C (D-DOC-RO tela) | P4 | `src/Pasta/EventListener/PastaSomenteLeituraListener.php`; `_push_processual.html.twig:39-74` | — | — |
| IA-036 | Chat I.A / Central de Comunicação (+ @IA, comandos /, proativo, Connect) | README L1-7, L188-192, L220-271, L292-330 | F | Produto inteiro fora | C (D-CONNECT) | P5 | §1.2 do mestre | — | — |
| IA-037 | Os 197 comandos `/` (29 grupos) | README L331-587 | F | Nenhum existe. Classificação por grupo na tabela de requisitos abaixo | D | P5 | — | — | — |
| IA-038 | Núcleos cognitivos (constituição, memória, red team do chat, autocorreção, Engine 48, runtime) | README L5, L30-31, L252, L259, L590-599 | F | Nada existe | D | P5 | — | — | — |
| IA-039 | Master único (`is_master`, CHECK no e-mail) e "usuário primário" | README L47-76 | F | Não existe. O repositório tem `ROLE_SUPER_ADMIN` e `TenantRole.isSystem` | C (D-MASTER) | P5 | `PermissionChecker.php:121` | — | — Escrito como está (e-mail fixo numa constraint), não implementar sem spec |
| IA-040 | Inteligência coletiva entre contas (Privacy Gate, /global, /benchmark, k ≥ 5/10 contas) | README L31, L476-480, L524 | F | Cruza dados entre escritórios | E | P5 | regra inegociável de isolamento (CLAUDE.md, `docs/AUTORIZACAO.md`) | — | — Fora: contradiz o isolamento por escritório; só com decisão explícita e spec ALTO |
| IA-041 | Relatório mensal de pastas paradas (PDF + envio automático) | README L199-202 | F | Não existe nem relatório, nem cron, nem envio | C | P3 | grep `parada` em `src/` sem nada | — | — O relatório sob demanda é deterministico, mas os destinatários ("Controladoria", "primário") dependem de D-MASTER e o envio por cron/e-mail é decisão nova. A "pré-análise com sugestão Movimentar/Arquivar" (`PARADAS_REF`) é D |
| IA-042 | Baixas aceleradas (níveis 1-3, suspensão de 10 min) | README L180-186 | F | Não existe; nem existe o conceito de "baixa" de notificação da Central | D | P5 | — | — | — Limiares e suspensão de ação do usuário: regra de negócio do Designer |
| IA-043 | Leitura inteligente do processo (PDF do PJe, IA por ato) e atualização por arquivos grandes | README L89-98 e L108-114; `bj-processo.js` | F | Não existe | B + C (D-PJE) | P5 | — | — | — Extração e OCR no servidor; o "refinarIA" por ato depende do provedor |

## Inventário de REQUISITOS do README (classificado por grupo)

### Comandos do Chat I.A: 197 entradas em 29 grupos (contagem por script, README L331-588)

Há 7 nomes duplicados entre grupos (`/decisao`, `/equipe`, `/evolucao`, `/hoje`, `/plano`, `/proximo`, `/riscos`):
197 entradas, **190 comandos distintos**. Fora da lista ainda aparecem outros (`/masterunico`, `/integracao`,
`/baixas`, `/paradas`, `/rumo`, `/roadmap`, `/carga`, `/financas`, `/clientes`, `/terceiros`, `/timeline`,
`/automacoes`, `/negociacao`, `/runtime`, `/contextocog`, `/ligacaoteste`, `/futuro`, `/decisao…`, `/dicas`…), nas
L170-271. Todos dependem da superfície do Chat (D-CONNECT); a classe abaixo é a do **núcleo** de cada grupo.

| Grupo | Entradas (restritas) | Classe | Motivo |
|---|---|---|---|
| Núcleo Cognitivo | 7 (1) | D | Crenças, memória e testes cognitivos: regra do Designer |
| Grafo | 11 (1) | D | Grafo de conhecimento com confiança: sem especificação |
| Oportunidades | 5 (5) | D | Score de oportunidade: heurística |
| Resultados | 9 (8) | D | Efetividade, A/B, experimentos: sem lastro de dados |
| Organização | 10 (10) | D + C (D-EQUIPE) | Gêmeo organizacional, carga cognitiva por pessoa |
| Execução | 8 (0) | D | `/executar` cria metas sozinho: autonomia sem regra |
| Decisão | 8 (6) | D | Matriz de decisão |
| Previsão | 6 (1) | D + C (D-PRAZOS) | Chance de atraso e calibração |
| Equipe | 11 (10) | C (D-EQUIPE) + D | Rotular pessoas |
| Governança cognitiva | 9 (7) | C (D-MASTER) + D | "Master" governa o cérebro |
| Pesquisa | 4 (0) | D (+ B) | Pesquisa a fundo; `/monitorar` |
| Memória | 5 (2) | D | Memória viva e reconciliação |
| Segurança | 4 (3) | D | Red team do chat e break glass |
| Simulação | 9 (3) | D | Cenários e prompt sandbox |
| Autocorreção | 6 (5) | D | Snapshot e rollback do "estado cognitivo" |
| Inteligência coletiva | 4 (2) | E | Entre contas: contradiz o isolamento (IA-040) |
| Competências | 4 (1) | D | Skills da IA |
| Tempo | 9 (0) | D | `/em`, `/vigente`: máquina do tempo |
| Situação | 5 (0) | D | Mapa vivo e agenda cognitiva |
| Orquestração | 7 (4) | D | Planos que criam e desfazem metas |
| Metacognição | 5 (1) | D | — |
| Conhecimento | 11 (2) | D + E (`/global`) + B (`/semelhantes`, busca semântica) | — |
| Governança | 1 (1) | C (D-MASTER) | — |
| Conformidade | 4 (3) | D | Ocorrências da "controller" |
| Controller | 13 (8) | C (D-CONNECT) + D | `/semandamento`, `/semprocesso`, `/parados` são deterministicos sobre dados que existem, mas os limiares e a superfície estão em aberto |
| Rendimento | 9 (6) | C (D-EQUIPE) | Rendimento individual |
| Jurídico | 3 (0) | C (D-PRAZOS) + D | `/prazos`, `/semprovidencia` |
| Pessoal | 4 (0) | D | `/meudia`, `/prioridades`: ordem por risco |
| Feedback | 6 (4) | C (D-EQUIPE) | Cobrança e feedback por pessoa |
| **Total** | **197 (106)** | — | — |

### Demais requisitos do README

| # | Requisito (README L) | Classe | Observação |
|---|---|---|---|
| R1 | Chat I.A e Central de Comunicação: conversas, grupos, toques, janela, PiP, anexos, rolagem, ajuda (L4-7, L188-192, L238, L244, L246, L260-261) | C (D-CONNECT) | §1.2: fora |
| R2 | Persistência no servidor das 17 chaves de `localStorage` (L21-22) | C (D-CONNECT) | Só faz sentido com o produto |
| R3 | `GET /api/ia/contexto` e `/api/ia/indice` do escritório inteiro, por permissão (L23-24, L298-329) | C (D-CONNECT) + B | Hoje o contexto é por pasta, montado no servidor (F para a pasta) |
| R4 | Identidade e papéis `BLUEJUS_*`; MASTER pela permissão do servidor (L25-27) | C (D-MASTER) | — |
| R5 | Motor de linguagem do sistema no lugar de `claude.complete` (L28-29) | F (porta) + B (adaptador) | `services.yaml:119`; IA-018 |
| R6 | Jobs no servidor: gêmeo digital, red team, health check, coletivo, follow-ups (L30-32) | D (+ E no coletivo) | — |
| R7 | `auditar()` em tabela do servidor (L33) | F | `AnaliseDeInteligencia`/`ConfiguracaoDeInteligencia` `Auditavel` (`Entity/…:43`, `:30`) |
| R8 | Link público do Push (L35-45) | C (D-LINK) | — |
| R9 | Master × primário × secundário; Master único com CHECK no e-mail (L47-76) | C (D-MASTER) | IA-039 |
| R10 | Auditoria de integração, travas do chat, red team 113/113 (L78-87) | D | — |
| R11 | Leitura inteligente do processo `bj-processo.js` (L89-98) | B + C (D-PJE) | IA-043 |
| R12 | Sugerir documentos `bj-docsug.js` (L100-106) | F (por regras, D-DOC-S4) | `templates/pasta/_documentos_sugeridos.html.twig:9-12,43`; `_documentos_checklist.html.twig:59` |
| R13 | Atualização por arquivos e processos grandes (L108-114) | B + C (D-PJE) | — |
| R14 | Cadeado da pasta e pedido de acesso (L116-123) | C (D-MASTER) | IA-034 |
| R15 | Push: botão direito, link externo (L125-126) | C (D-LINK) | — |
| R16 | Visualização rápida e impressão (L128-133) | E (área Documentos/visualizador) | Auditado em outra área |
| R17 | Linha do tempo do Push; Análise IA recolher/expandir e cadeado (L135-137) | linha do tempo: outra área · recolher A (IA-010) · cadeado C (IA-015) | — |
| R18 | Dashboard: Comentários/Relatório e zerar (L139-160) | C (D-DASH, D-MASTER) | Área Dashboard |
| R19 | X em toda janelinha, janelas arrastáveis, quinas 4px (L145-149, L162-163) | E (padrão global, Trilha A) | — |
| R20 | CAPACIDADES e `integracaoResposta` (L165-170) | C (D-CONNECT) | — |
| R21 | Previsão de andamento processual (L172-178) | C (D-PRAZOS) | IA-033 |
| R22 | Baixas aceleradas (L180-186) | D | IA-042 |
| R23 | Excluir comentário só pelo primário (L194-197) | C (D-MASTER) | — |
| R24 | Relatório mensal de pastas paradas (L199-202) | C (+ D na pré-análise) | IA-041 |
| R25 | Estratégia (L204-206) | D | — |
| R26 | Recursos e alocação (L208-211) | D + C (D-EQUIPE) | Horas não existem |
| R27 | Inteligência financeira (L213-218) | D + C (D-FIN) | Despesas e saldo não existem |
| R28 | Novas camadas: clientes, documentos, comunicação, jurídico, conformidade, pessoas, experiência, acessos, continuidade, "o que sabemos", dados, operações, catálogo (L220-234) | D | Cada uma "declara o limite"; todas no chat |
| R29 | Processos apensados, responder, editar e destacar comentário, carimbo ATIVO, numeração e nome de meta, CC da Controladoria, favoritos, sininho (L235-249) | E nesta área | Pertencem a Pasta, Documentos e Metas (D-15MIN, D-DESTAQUE, D-META) |
| R30 | Acompanhamento de alertas (L250) | D | — |
| R31 | Dicas adaptativas `BJDicas` (L251) | E nesta área | É UX por regras, não IA |
| R32 | Runtime cognitivo, antecipação, decisão causal (L252, L254-255) | D | — |
| R33 | BlueJus Connect, ligações (L253, L271) | C (D-CONNECT) + B (TURN/SFU) | — |
| R34 | Motor universal de documentos e mídias `bluejus-docs.js` (L256) | B (OCR/transcrição/LLM no servidor) | D13 |
| R35 | Cronologia de registros × threads (L257) | E nesta área | Área Pasta |
| R36 | Persistência protegida (backup no IndexedDB) (L258) | E | Só do protótipo; o servidor é a fonte da verdade |
| R37 | Engine 48 / CCE (L259) | D | — |
| R38 | Central de Atenção (L262) | C (D-CONNECT) | — |
| R39 | Timeline inteligente (L263) | F (por regras) | `templates/pasta/_timeline_inteligente.html.twig:7,69`; `public/js/pasta-timeline.js:237` |
| R40 | Fábrica de automações (L264) | D | — |
| R41 | Equipe cognitiva / 8 agentes do chat (L265) | D | São 8 no chat contra 7 na pasta; não confundir |
| R42 | Negociação, copiloto, contexto da conversa, causa raiz (L266-269) | D | — |
| R43 | Terceiros (L270) | D | Não há cadastro |
| R44 | Inteligência cadastral (L272-279) | B (OCR/extração) + D (pontuação `iiScore`) | — |
| R45 | Prompt: conteúdo é dado não confiável, citar fonte, apontar conflito (L280, L591) | F | `Service/NeutralizadorDeConteudo.php`; `Prompt/PromptDoAgente.php`; CLAUDE.md regra 5 |
| R46 | Inteligência proativa (L282-290) | D | — |
| R47 | Conversa humana / `humanizar` (L292-296) | D | — |
| R48 | Regras que não podem se perder (L589-600): zero trust F · isolamento F · detector de manipulação D · autonomia/idempotência F (por tipo e agente) · pessoas D · aprendizado D · "previsão ≠ fato" F (IA_REGRAS transcritas) · sem travessão: parcial (IA-004) | misto | — |
| R49 | Testes de aceite (L609-612): `/isolamento` 0 vazamentos F · `/redteam` A (IA-014) · demais D | misto | `tests/Inteligencia/Functional/InteligenciaTenantFilterTest.php` + cross-tenant em `AnalisePushControllerTest`/`AnaliseDaPastaControllerTest` |

## Itens F (agrupados, com evidência)

**Visual (21):**
- Push: botão "Resumir com IA" (posição entre "ver no módulo" e os filtros, roxo, maiúsculas, 11,5px/800) e
  os 3 rótulos e 3 titles do desenho (`_push_processual.html.twig:39-74`, `pasta-ia.css:90-102`); coluna da marca
  ✦ + dia (`_analises_push:40-43`); selo "✦ Análise IA" e aviso "Gerado por IA · não é movimentação oficial ·
  quando" (`:46-47`); "lido" (`:48-50`); ⋮ com Marcar lida / Criar tarefa da providência / Excluir (`:66-95`); resumo,
  chips dos 5 tipos com os rótulos do desenho (`Enum/TipoDePonto.php:31-35`), "Quem deve agir", "Base"
  (`:103-118`); andamento honesto "Analisando… (lendo N movimentações)" no lugar das etapas por temporizador
  (`:98-102`); falha "Não foi possível gerar a análise agora. Tente novamente." (`:119-123`).
- Pasta: botão "BLUEJUS Intelligence" com a estrela SVG do desenho, à direita da linha do cliente
  (`_cabecalho.html.twig:323-337`). O title "Ler documentos…" do desenho foi trocado de propósito: o sistema não lê
  documentos (§1.4.3).
- Drawer: 460px à direita, fundo, cabeçalho em degradê, "BLUEJUS IA / Assistente jurídica do escritório", X
  (`_agentes_drawer.html.twig:21-36`; `pasta-ia-agentes.css:151-152`); cantos ≤ 4px (`pasta-ia-agentes.css`, pílulas
  só nos chips e selos).
- Rótulos sobre regra: Dashboard "por regras fixas" (`dashboard/_inteligencia.html.twig:51`); Timeline
  inteligente "Montada por regras fixas" (`_timeline_inteligente.html.twig:69`); "Sugerir documentos" sem ✦ e com
  "· por regras" (`_documentos_checklist.html.twig:59`, `_documentos_sugeridos.html.twig:43,175`); textos "BlueJus IA"
  do checklist neutralizados (`_documentos_checklist.html.twig:23,94`); "Encaminhar via Chat I.A" não renderizado em
  nenhum menu (`_dados_anotacoes:17`, `_processos_vinculados:178`, `_push_teor:17`, `_detalhes_obs:121`,
  `pasta-explorador.js:22,1882`); item "BlueJus IA" do menu lateral só com `admin.inteligencia.manage`
  (`_sidebar.html.twig:234-239`); admin `/admin/inteligencia` no padrão do sistema (sem desenho)
  (`admin/configuracao.html.twig`).

**Interações (13):** gerar do Push (POST por XHR + CSRF `inteligencia_push_{id}`), polling e recarga da lista sem F5,
Marcar como lida, Excluir com a confirmação do desenho ("As movimentações oficiais não são alteradas"), alternar
interna, Criar tarefa da providência (abre `#modalCriarTarefa` com prazo de 7 dias, como o desenho;
`pasta-ia-push.js:357-383`), menu ⋮ abre/fecha (`:300-313`); drawer abre/fecha (botão, X, fundo, Esc;
`pasta-ia-agentes.js:53-111`), painel carregado na 1ª abertura, "Gerar análise" por agente com polling e pausa em
aba oculta (`:211`), ações por id nos cartões do agente, "Análise completa" recolhível (`_analises_agente:115-118`).

**Funções e backend (27):** porta `ProvedorDeLinguagem` + `ProvedorNaoConfigurado` (`services.yaml:119`); provedor com
resposta programada só em `when@test` (`services.yaml:197-199`); fila `async` (`messenger.yaml:36`) com retry 3×
espelhado em `TENTATIVAS_MAXIMAS = 4` (`Handler:59`); handler idempotente por status, exclusão e tipo (`Handler:99-105`);
tenant da mensagem revalidado contra a linha e a pasta (`Handler:88-97,117-122`); sem provedor → `indisponivel` +
Unrecoverable (`:146-151`); transitória × definitiva (`:152-177`); sigilo `nivelSigilo > 0` bloqueado antes de ler
(`MontadorDeContextoDoPush.php:177`, `MontadorDeContextoDaPasta.php:87,401`); máscara de CPF/CNPJ/e-mail/telefone
formatados, com o CNJ protegido (`MascaradorDeDadosPessoais.php:24-61`; a ressalva está em IA-002); neutralização e
blocos delimitados (CLAUDE.md regra 5); resposta escapada, sem `|raw` nos 4 templates; log no canal `inteligencia`
sem o prompt (`monolog.yaml:8`, `Handler:47`); 2 permissões no catálogo (`PermissionFixture.php:30,57`, migration
idempotente `Version20261006120000.php:65-71`); bypass do admin do escritório (`PermissionChecker.php:30…104`); guardas
do controller: tenant + `canAccessResource view` + módulo (`AnalisePushController::guardar`); CSRF por análise;
limiter de 10/min por usuário (`framework.yaml:69-72`); disponibilidade em camadas plataforma → escritório →
permissão → cota (`DisponibilidadeDeInteligencia::para`); `inteligencia_analise` e `inteligencia_configuracao`
(desligada, 50/dia, 500/mês, máscara ligada, consentimento) (`Version20261006120000.php:43-61`); purga do escritório
(`PurgarEscritorioUseCase.php:115-116`); lixeira de documentos respeitada no worker (filtro `lixeira` ligado por
padrão, `doctrine.yaml:26-28`); POST de IA em pasta-lápide recusado no servidor (`PastaSomenteLeituraListener`);
financeiro só com `VisibilidadeDoFinanceiroDaPasta`; prompt versionado (`agente-v1`); `contexto_hash` para "nada
novo"; sem fallback local; motivo técnico só para quem administra (`_analises_push:21,121`); comando
`app:inteligencia:status`; 207 testes em `tests/Inteligencia` (unit + functional + cross-tenant).

**Estados desligados (nenhuma tela finge IA):** o botão sai desabilitado com o motivo real
(`_push_processual:65-73`, `_agentes_painel:101-108`); aviso geral no drawer (`_agentes_drawer:43-48`); admin "Ligar
aqui não ativa nada…" (`configuracao.html.twig:52-55`); status `indisponivel` → "IA não configurada nesta instalação"
(`AnalisePushController::status`). Nenhuma resposta estática: o único texto de análise vem de `concluir()` com o
`texto_bruto` do provedor.

**Responsivo (4):** drawer em 100vw abaixo de 520px e rótulo do botão escondido (`pasta-ia-agentes.css:521-524`);
cabeçalho, quem e ações do cartão do Push com `flex-wrap` (`pasta-ia.css:169,284,294`); admin em grade do Bootstrap.

**Dark mode (5):** tokens do botão e do drawer (`pasta-ia-agentes.css:63-65`), cartões do agente (tokens `--ia-*`),
Push (`pasta-ia.css:49`), admin (tema do Bootstrap), `prefers-reduced-motion` nos dois CSS.

## Observações (riscos, conflitos Designer × sistema, dúvidas para o Farlei)

1. **Antes de ligar a IA, nesta ordem:** IA-002 (CPF sem pontuação vazando), IA-001 (aceite que descreve menos do
   que sai), IA-030 e IA-018 (D-IA), IA-031 (D-IA2). Com a IA desligada, nada disso causa dano hoje.
2. **Corrida no handler:** a checagem de status e o `iniciarProcessamento()` + `flush()` não usam trava
   (`Handler:99-108`). Dois consumidores com a mesma mensagem poderiam chamar o provedor duas vezes. Hoje o risco
   é baixo: há 1 worker em produção e a idempotência do UseCase impede despachos duplos. Se houver mais workers,
   vale um `UPDATE … WHERE status IN ('pendente','falhou')` condicional.
3. **O drawer do desenho é, em grande parte, regra rotulada como IA:** fase, situação, providências, KPIs e
   "fontes" saem de regex e contagens no navegador (`iaFase`, `iaSituacao`, `iaProvidencias`, L5506-5563), e o
   `local()` (L5606-5625) inventa análise quando o provedor cai. A implementação fez certo ao não portar isso como IA.
   Dúvida para o Farlei: essas regras são dele (então devem virar regras rotuladas "por regras") ou são do
   Designer? Por isso o D em IA-020 a IA-025.
4. **Os comandos do drawer (10) e os agentes (7) são duas taxonomias** do mesmo desenho (CMDS × AGENTES). A spec
   escolheu os agentes. Isso não foi decidido pelo Samuel; precisa de ratificação (IA-024).
5. **"Agente Cliente" sobre todas as pastas do cliente** (MOD `cliente`): a spec deixou para decisão (D12). Muda o
   alvo da análise e o volume enviado.
6. **O e-mail ao cliente com a análise de IA** (IA-028) é a função de maior risco jurídico do desenho: envio a
   terceiro de texto gerado por IA, com CC obrigatória para um papel ("Controladoria") que o sistema não tem.
7. **Dashboard Intelligence (`bluejus-intelligence.js`)** é motor de regras (alvo ×1,05, estados .85/.65,
   sobrecarga .45, viabilidade .8/1/1,25). A paridade dos limiares é auditada na área Dashboard; aqui só se
   conferiu o rótulo (F, com a ressalva IA-032). O `MotorDeRitmo` só expõe `SOBRECARGA_RAZAO = 0.45` como
   constante (`src/Dashboard/Inteligencia/MotorDeRitmo.php:56`); vale conferir lá alvo, projeção e plano de
   crescimento.
8. **Uma ressalva já registrada nos itens F:** o title "Ler documentos e gerar relatórios com IA" do botão da pasta
   foi trocado de propósito porque seria falso hoje (regra 3 do mestre, aprovada). Se a leitura de documentos
   chegar (D13), o título do desenho volta.
9. **Fora do escopo desta área, mas citado no README e sem dono:** "X de fechar em toda janelinha" e "janelas
   arrastáveis" (R19) são comportamentos globais do `bluejus-central.js`; não pertencem à IA.
