# Trilha B — Claude Designer → BlueJus real (fidelidade + funções novas)

Início: 05/10/2026 (madrugada) · Base: `master` @ `beff02fd` (= produção, Trilha A publicada como entrega
intermediária) · Risco: BAIXO nas telas; MÉDIO quando tocar permissão; ALTO não entra sem spec própria.

> **Este arquivo é o LEDGER da rodada.** Uma sessão nova continua daqui sem depender da conversa.
> Atualizado durante a execução. A Trilha A (`docs/specs/trilha-a-visual-pje.md`) continua valendo para as
> regras absolutas (§1), o inventário de preservação (§2) e as decisões já tomadas (§5).

## 0. Regras desta rodada (herdadas do dono, 05/10)

1. O Designer define o comportamento; a engenharia acha a implementação. Desvio visual do desenho
   aprovado exige perguntar antes (CLAUDE.md da raiz).
2. **Nada fake**: nenhum botão sem função, dado fictício, localStorage no lugar de persistência, IA simulada.
   Elemento que depende de função ainda não feita **não é renderizado**.
3. Nenhuma função atual some. Ids, classes, `data-*`, XHRs e fragmentos do §2 da Trilha A são contrato.
4. Isolamento por escritório em toda consulta nova (TenantFilter + guarda IDOR + teste cross-tenant).
5. Sem deploy, sem push, sem ação em produção nesta rodada. Commits locais pequenos.
6. Escopo: Dashboard 1.2.2 + Expedientes 1.2.3 + editor + Push (notas técnicas, Compartilhado) +
   Chat/Central + Connect + permissões + relatórios PDF + visualizador/duplicados + padrão PJe.
   **Fora:** Carteira, Objeto, Pasta 1A, redesenho da Agenda.

Fonte: `docs/design/claude-design-2026-10-05 (1)/` (não versionado; abrir pela raiz).
Relatórios das investigações desta rodada: resumidos no §2; o detalhe ficou no scratchpad da sessão
(não versionado) — o que importa para continuar está copiado aqui.

## 1. Estado do repositório

| Quando | HEAD | Suíte | Observação |
|---|---|---|---|
| início | `beff02fd` | 5633/5633 (Trilha A) | árvore limpa; só o pacote do Designer untracked |
| checkpoint 1 | `b9236379` | 5817/5818 → a falha era real (purga sem `nota_tecnica`), corrigida | suíte 04:43 |

## 2. Inventário Designer × BlueJus (classificação A/B/C/D/E)

A = já existe · B = parcial · C = implementável com infra atual · D = infra interna nova sem decisão
externa · E = depende de decisão/credencial/integração externa.

_(preenchido após as investigações)_

## 3. Entregas (ledger)

| # | Funcionalidade | Fonte no Designer | Antes | Agora | Classe | Arquivos | Testes | Commit | Pendência / próxima ação |
|---|---|---|---|---|---|---|---|---|---|
| B1 | Janela de 15 min para o autor editar/excluir comentário da pasta (chat, Detalhes, Financeiro) + "· N min" | README intelligence ("autor só nos 15 minutos… o menu mostra · N min"); decisão do dono 05/10 | 24h, regra duplicada em 6 UseCases + 3 templates | 15 min num só serviço (`Pasta\Service\JanelaDeEdicaoDeComentario`) + funções Twig `comentario_editavel`/`comentario_minutos_restantes` | B | 6 UseCases, 5 templates, 2 classes novas, 11 testes | Pasta 936/936; prova por reintrodução (21 caem com PT24H) | `01ae95a0` | bypass Master/primário do desenho → depende da spec Master (§4) |
| B2 | "N de M" entre as setas do cabeçalho da pasta | dc 1.2.3 ~l.5792 `pastaPos` | só setas | `PastaRepository::posicaoNoAcervo` (mesma chave/conjunto das setas) + `.ps-cab-pos` | C | PastaRepository, PastaVizinhasOutput, PastaController::show, _cabecalho, pasta-show.css | 12 testes; sentido das setas provado por reintrodução (ASC/DESC trocados → cai) | `6d21afe1`, `edb1573e` | expressões CASE duplicadas de `vizinha()` (extrair) |
| B3 | "continuar lendo (N parágrafos) / mostrar menos" nas observações de Detalhes | dc 1.2.3 l.2347, 6479 (só em Detalhes; Registro tem `temMais:false`) | não existia | `pasta-ler-mais.js` (rico e legado), singular corrigido, hover #0b5596 | C | _detalhes_obs, pasta-ler-mais.js, pasta-show.css | PastaLerMaisTelaTest 3 | `1f170e08`, `edb1573e` | — |
| B4 | Dashboard: fidelidade da casca/cards + Exportar PDF | dc 1.2.2 + README dashboard | divergências medidas (`docs/specs/trilha-b-inventario/dashboard.md` §1) | 11 itens aplicados (largura 1500, número 48px, 5 regras mortas de `.db-pronto` corrigidas, legendas em toque, <720px, Total, <1100px "Nome S.", "Limpar filtros" via opt-in `data-ft-rotulo-limpar` no `filtro-tabela.js`, entrada do título, `@media print` A4) | A/B/C | dashboard.css/js, index/_resultado, filtro-tabela.js (opt-in) | Dashboard 71/71 | `7d83a416` | smoke do dono nos 2 temas + impressão |
| B5 | Dashboard servidor: busca, "Sem cargo", período anterior (3 métricas) | README "Tendência na linha de Total" | não existia | `busca`, `cargo=__sem__`, `totaisAnteriores`/`*Anterior` (mesma duração imediatamente antes de `data_de` — regra explícita do README) | C | Dashboard Controller/UseCase/DTO | Dashboard 96/96 | `e2eb5133` | as outras 4 tendências (ativas, vencidas, prazos) exigem histórico → D/E |
| B6 | `/tarefas/equipe` (destino dos números de metas do Dashboard) | dc 1.2.2 números clicáveis | não existia | `TarefaEquipeController` + `ListarMetasDaEquipeUseCase`, mesmos critérios das contagens, guarda `bi` | D | src/Tarefa (novo), templates/tarefa/equipe | Tarefa 197/197 (lista = número do Dashboard provado) | `b30dc265` | linha Total não vira link (soma duplica meta com 2 responsáveis) |
| B7 | Visualizador: DOCX (mammoth 1.13.0), planilhas (SheetJS CE 0.20.3), texto; 3 cópias unificadas | `bj-visualizar.js` | só PDF/imagem/áudio/vídeo, JS copiado em 3 telas | `visualizador-documento.js` em iframe `sandbox` vazio + CSP; XSS antigo do nome fechado; AbortController; tetos 15 MB DOCX / 5 MB planilha | C | pasta/cliente/tarefa show (só preview), js/css novos, vendor/ | 18 testes ligados | `bdfe985f`, `254d704f` | DOC/PPT/MSG exigem LibreOffice (E); URL assinada do R2 (E4) quebraria o fetch |
| B8 | Editor rico: realce (8 cores do desenho), desfazer/refazer, contagem com limite 5000, localizar/substituir, barra do desenho | `bj-editor.js` | Quill básico | mesma API; só classes `ql-*` que o sanitizador aceita | B | editor-rico.js/css, pasta-show.css (compositor sem overflow) | 58 (EditorRico/Sanitizador) | `e4388922`, `e575d2d3` | link, imagem, hr, tabela, fonte/tamanho, ditado, rascunho → decisão do dono/sanitizador |
| B9 | Financeiro: ⋮ por arquivo (Abrir, Baixar, Copiar nome, Ver em Documentos, Renomear, Excluir), botão direito/duplo clique, ⋮ de Pagamentos e Imprimir extrato | dc 1.2.3 `gmVals` | ações só no hover | menus reais; extrato por `@media print` | C | _financeiro*, pasta-show.css | 59 (Financeiro/Pagamento) | `4fa83ebb`, `a89cbe33` | Encaminhar via Chat, e-mail, editar valores → função nova |
| B10 | Expediente: deep-link `?painel=acervo-geral` + filtro `criado_por` (sem lápide) | dc 1.2.2 números clicáveis | não existia | lista = contagem do Dashboard (provado) | C | ExpedienteController, PastaRepository::aplicarFiltrosPasta, expediente/index | Expediente 37/37 | `9aa10f50` | `carregarPainel` apaga o `active` do menu (pré-existente) |
| B11 | Nota técnica do PROCESSO (aba Processo + teor do Push), editor rico, 15 min do autor | dc 1.2.3 l.5389–5406; decisão do dono 05/10 | não existia | entidade `NotaTecnica` (+migration `Version20261006002519`), 3 UseCases, controller JSON, função Twig; purga inclui a tabela | C | src/Processo (novo), templates/processo/_notas*, _processos_vinculados, _push_teor, nota-tecnica.js/css | 56 + prova por reintrodução (tenant, processo, CNJ mascarado) | `ff2d6cbd`, `c9bc7881`, `b9236379`, `800e0551` | versões a cada edição (D); Master/primário sem limite (§4) |
| B12 | Dashboard UI: busca recolhida (pílula), "Sem cargo", tendência (3 métricas) no Total e nas células, números de metas → `/tarefas/equipe`, números de pastas → Acervo | README dashboard | não existia | só números por pessoa viram link (Total/cards somados não, para bater com a lista); Urgentes leva o período (§5 Trilha A) | C | dashboard/*, dashboard.js/css | Dashboard 110/110 | `aa7046b4`, `65051476` | tendência de ativas/vencidas/prazos (D/E) |
| B13 | Metas: filtros Abertas/Atrasadas/Concluídas/Todas, numeração local, "concluída no prazo"/"com N dia(s) de atraso", concluir na lista (endpoint real), atalhos de prazo e aviso de título repetido no modal | dc 1.2.3 aba Metas | lista simples | parcial próprio `_metas.html.twig` | C | _metas, show (modal), PastaMetasResumoOutput, pasta-metas.js | Pasta OK | `090ef40a`, `261b3fe9` | "Nº global" fora (o `tarefa.id` expõe o volume de outros escritórios); renomear/sino/reabrir (onda 2) |
| B14 | Cabeçalho: selo carimbado ARQUIVADO, excluir digitando o número (validado no servidor), Imprimir resumo (`pasta_resumo_imprimir`) | dc 1.2.3 `seloEl`, `gmImprimir` | — | real | C | _cabecalho, PastaController::delete, PastaResumoController | Pasta OK | `3f68af0b`, `ca4c93c6` | "Financeiro oculto sem permissão": `modules.financeiro.view` não existe (§4) |
| B15 | Push: filtro Todas/Novas, Copiar ID, Abrir no PJe, marcar lida/não lida (`pasta_push_lida`), criar meta a partir da publicação | dc 1.2.3 aba Push | — | real | C | _push_processual/_teor, PastaPushProcessualController, pasta-push.js | Pasta OK | `808d4636` | "Geram prazo", "Encaminhar" (função nova) |
| B16 | Processo: órgão, distribuição, instância, valor da causa, "Ver todas as informações", ⋮ (nota, Push, copiar número/resumo, Compartilhar só com `navigator.share`) | dc 1.2.3 ~l.2892 | cartão mínimo | real | B | _processos_vinculados, pasta-processo.js | Pasta OK | `0c8a4ec9` | "Não informado" × ocultar vazio; "Dados do PJe salvos em…" (E: PJe) |
| B17 | Clientes do trilho: cadastro completo/incompleto (`PendenciasDoCadastro`), Copiar qualificação, janela "Detalhes do cliente" (`cliente_resumo`, pastas filtradas por permissão POR PASTA), ⋮ do prazo (.ics, Copiar) | dc 1.2.3 l.779–836, 3249–3290 | — | real | C | Cliente/Service, ClienteResumoController, parciais do trilho | 31 testes | `f132577a`, `5e825b75` | regra/qualificação de PJ precisa de aval (§4); número/bairro/sexo/nacionalidade sem lastro |
| B18 | Dashboard no celular: cards por pessoa, ordenar por select | README dashboard | tabela espremida | real; PDF segue com a tabela | C | dashboard/* | Dashboard 121/121 | `6fce5547` | toque longo, troféu |
| B19 | Duplicar pasta (número novo pelo caminho normal; leva identificador, ação, clientes, responsável, prioridade, checklist pendente) | dc 1.2.3 l.1097, 4324 | — | real | C | DuplicarPastaUseCase, PastaDuplicarController, _cabecalho | 18 testes | `9c2b4adf` | herda o despacho ao Drive do `pasta_new` |
| B20 | BlueJus IA — fundação (1A): domínio `Inteligencia`, provedor `ProvedorNaoConfigurado` (falha honesta), análise persistida com status, worker Messenger, contexto com tenant explícito, sigilo bloqueia, PII mascarada, cota por escritório, limiter por usuário, admin `/admin/inteligencia`, `app:inteligencia:status` | spec `inteligencia-resumo-do-push.md` | nada | cadeia inteira provada com `ProvedorFalso` só em teste | D | src/Inteligencia (51), config, migration `Version20261006120000` | 100/100 + purga | `f05bf1f8`…`0841cfe0` | 1B (UI) em andamento; correções da revisão de segurança (injeção fora do delimitador, máscara de telefone, retry) em andamento; **provedor = §4 D-IA** |
| B21 | Favoritos de pasta por usuário (⋮ "Fixar nos favoritos", estrela, sobem no Acervo) | dc 1.2.3 l.4270, 4440 | — | `pasta_favorita` (+migration `Version20261006121500`), `INSERT … ON CONFLICT DO NOTHING` | D | PastaFavorita*, PastaRepository::aplicarOrdenacao, _tabela, _cabecalho | Pasta 1109 | `f854fb4c`, `75fb38c0`, `7279c012` | estrela no modo cartão; setas ignoram favoritos |
| B22 | Responder no Registro (recuo, "Resposta a X", órfã "Resposta a uma mensagem excluída") | dc 1.2.3 l.1311, 5954 | — | `pasta_mensagem.resposta_a_id` + `eh_resposta` (migration `Version20261006010231`) | D | PastaMensagem, Enviar UseCase, assembler, _dados_anotacoes | Pasta 1134 | `f58437a8` | @menção que notifica (frente própria) |

## 4. DECISÕES/BLOQUEIOS DO SAMUEL

| # | Funcionalidade | Já feito | Ponto exato do bloqueio | Decisão | Opções | Recomendação | Impacto de adiar |
|---|---|---|---|---|---|---|---|
| D-IA | BlueJus IA (Resumir Push, depois agentes/chat) | fundação 1A completa + UI 1B; sem provedor a tela diz "IA não configurada nesta instalação" | `services.yaml` alias `ProvedorDeLinguagem` → `ProvedorNaoConfigurado`; falta o adaptador + `IA_API_KEY`/`IA_MODELO`/`IA_HABILITADA=1` | provedor, custo/cota, LGPD (Anexo I + comunicação prévia aos contratantes), o que pode sair | Anthropic, OpenAI, Gemini, Azure OpenAI (ver `trilha-b-inventario/inteligencia.md` §5 D1–D10) | um provedor comercial com zero-retention, só sob demanda, cota baixa, PII mascarada, sigilo nunca | toda função de IA fica desligada (honestamente) |
| D-IA2 | Quem usa a IA | permissões `modules.inteligencia.view` e `admin.inteligencia.manage` criadas | nenhum papel recebe por padrão; só o Administrador do Escritório (bypass) e o super admin | a quais papéis conceder | manual por escritório × migration concedendo ao admin | manual (é envio de dado a terceiro) | — |
| D-MASTER | "Master" e "usuário primário" (desenho) | nada (não existe no código) | destrava: edição sem limite de tempo, cadeado por pessoa, ocultar abas, zerar relatório | o que é cada um | Master = `ROLE_SUPER_ADMIN`; primário = `Tenant.criadoPor` × campo novo × todo admin | Master = super admin (sem editar comentário de outro escritório); primário = campo novo explícito; spec MÉDIO antes | B1 fica só "autor 15 min" |
| D-15MIN | Janela de 15 min fora da Pasta | Pasta: 15 min (B1) | Tarefa e Kanban não têm janela; Cobrança usa 48h | vale para eles? | sim/não por módulo | sim para Tarefa/Kanban; Cobrança à parte | inconsistência entre módulos |
| D-SITUACAO | Suspenso/Cancelado com carimbo | selo ARQUIVADO feito | 27 usos de `situacao`; regra de lista/alertas/Dashboard | suspensa/cancelada saem das listas? somente leitura? | — | Suspensa: fica nas listas, sem alertas; Cancelada: como arquivada | sem as duas situações |
| D-DESTAQUE | Destacar comentário (13 cores) | — | o protótipo guarda por pessoa (localStorage) | o destaque é da equipe ou de cada um? | coluna na mensagem × tabela por usuário | por pessoa | — |
| D-LINK | Push Compartilhado (link público) | investigado (`inteligencia.md` §6) | rota sem login expondo dado de cliente | o que vai no link, validade, código, LGPD | — | snapshot no servidor, token + código de 6 dígitos com hash, limite por token, revogação, auditoria | sem link |
| D-EDITOR | Editor: link, imagem, `<hr>`, tabela, fonte/tamanho, ditado, rascunho | realce/desfazer/contagem/localizar | o sanitizador `textoRico` não aceita `style`/`a`/`img`/`table`/`hr` | liberar no sanitizador? | por item | link com `rel=noopener` e `https` só; imagem só por upload interno; resto fora | — |
| D-DASH | Dashboard: troféu, período padrão, zerar, acesso restrito, tendência de ativas/vencidas/prazos | 3 tendências reais | critério do troféu; "zerar" apaga histórico?; tendência de estoque exige foto mensal (`dataConclusao` nula no legado) | — | — | troféu = mais metas concluídas no período; foto mensal por cron (D) | — |
| D-EQUIPE | Classificação de pessoas ("crítico", "necessita acompanhamento" — `bluejus-equipe.js`) | fora | rotular colaborador é decisão de RH | aceitar? | — | não rotular pessoa; mostrar só números | — |
| D-PRAZOS | Motor de prazos/fases do `bj-processo` | fora | conselho jurídico automático; calendário de feriados incompleto | aceitar? | — | não sem spec + feriados por tribunal | — |
| D-FIN | Permissão de Financeiro | `podeVerFinanceiro()` = true (B14) | `modules.financeiro.view` existe só como "futuro" | criar a permissão? | — | criar quando houver papel sem acesso ao financeiro | resumo impresso sempre leva financeiro |
| D-PJ | Pendências de cadastro e qualificação de PJ | regra comum aplicada | desenho só tem PF | aval do texto | — | — | — |
| D-RELINI | "Pontos do relatório inicial" + linha vermelha em Detalhes | — | acende a linha vermelha em quase todas as pastas | aceitar? | — | só o aviso, sem a linha | — |
| D-META | Nova meta: prazo e responsáveis obrigatórios; "Ação" só leitura no Editar dados | não mudou | desvio de função existente | aceitar o desenho? | — | — | — |
| D-ACOMP | Acompanhar alterações (padrão LIGADO no desenho) | — | notificação por pasta = barulho | padrão ligado/desligado | — | desligado | — |
| D-CARTEIRA | "Mover para outra carteira" | — | a Pasta não tem carteira/área | conceito novo? | — | não | — |
| D-BARRA | Barra global (topo + sub-nav) | medido (Trilha A §5) | `base.html.twig`/`app.css` de todas as telas | mudar o sistema inteiro? | — | sim, numa frente própria | divergência visível em todas as telas |
| D-CONNECT | Chat I.A/Central, ligações, BlueJus Connect | investigado | produto próprio; ligações exigem servidor de chamadas | escopo | — | frente própria | — |
| D-PJE | Dados processuais automáticos (PJe/MNI, "Ler PDF") | DJEN + Datajud existentes | credencial MNI por tribunal / OCR no servidor | fonte | PJe/MNI × Datajud × terceiro | Datajud já cobre capa; MNI só com credencial | — |
| D-LIBRE | DOC/PPT/MSG no visualizador | DOCX/XLSX/texto feitos | exige LibreOffice no container | instalar? | — | sim, na imagem de prod (custo ~300 MB) | — |

## 5. Handoff — próxima ação exata

_(atualizado a cada checkpoint)_
