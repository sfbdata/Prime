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
| checkpoint 4 (final da rodada) | `e5b7aae5` | **6674/6674** (26.450 asserções, 03:43) · lint:twig 198 · lint:yaml 25 · lint:container OK · schema:validate OK | 85 commits sobre `beff02fd`, 9 migrations novas |
| checkpoint 3 | `dda8a012` | **6413/6413** (24.814 asserções, 03:49) | `memory_limit` da suíte em 768M (`4b840a77`) |
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
| B23 | BlueJus IA na aba Push (1B): botão "Resumir com IA"/"Gerar nova análise" com motivo real quando indisponível, cartão "✦ Análise IA · Gerado por IA · não é movimentação oficial", polling com pausa fora da aba e backoff, criar tarefa da providência (+7 dias), item admin | dc 1.2.3 aba Push; spec 1B | — | real, sem provedor mostra "IA não configurada nesta instalação" | D | _push_processual, pasta-ia-push.js, pasta-ia.css, PastaController::show, _sidebar | Inteligencia 148/148 | `04c2ef7d`, `1efb0107`, `c1d25b0f` | depende de D-IA para funcionar |
| B24 | Segurança da IA (revisão Fable): todo dado externo delimitado e neutralizado, máscara de telefone com borda, retry não duplica análise, dispatch falho com EM fechado → 503 honesto | revisão | — | real | D | src/Inteligencia | 141/141 | `d59cc17d` | — |
| B25 | Painel "BlueJus Intelligence" no Dashboard — leitura por REGRAS dos números reais (motor de ritmo + Modo avançado), legenda honesta "Leitura automática dos números do período, por regras fixas", sem "IA"; frases sem lastro removidas na revisão | `bluejus-intelligence.js`, `bluejus-avancado.js` | — | regras portadas para PHP; descartadas as que exigem dado inexistente (lista no painel "O que este painel não faz") | A | src/Dashboard/Inteligencia, _inteligencia, dashboard-inteligencia.css | Dashboard 217 | `c52333d6`, `98a2d343`, `8fcbc5e3` | `bluejus-equipe.js` (rótulos de RH) fora — D-EQUIPE |
| B26 | Selects e calendário próprios nos filtros do Dashboard (avatar/foto, boneco pastel sem foto, contagem por cargo) | README dashboard | selects nativos | nativos continuam no DOM (contrato) | C | index, dashboard-filtros.js | Dashboard OK | `f53574c9`, `55…`(boneco), `7881b231` | — |
| B27 | Busca inline de cliente no cartão do trilho, com máscara de CPF/CNPJ no servidor (inteiro só quando a busca é o documento inteiro), selo completo/incompleto/já vinculado | dc 1.2.3 l.1357–1372, 3304 | só no modal | real; modal mantido | B | _dados_trilho, pasta-clientes-busca.js, PastaController::buscarClientes | 7 testes | `a96677dc` | recarrega a página após vincular |
| B28 | Editor: 18 símbolos, data de hoje, revisão (ortografia, repetida, espaço duplo) e autocorreção ao digitar com "Desfazer" | `bj-editor.js` L322, 331, 503–534, 141–146 | — | só o que passa no sanitizador | C | editor-rico.js/css | EditorRico OK | `a2641fd3` | a revisão do desenho marca plural como erro ("recursos→recurso") — risco do próprio desenho |
| B29 | Visualizador: ODT, RTF, PPTX/PPSX (texto), EML, ZIP (lista) sem biblioteca nova (DecompressionStream/DOMParser), teto contra zip bomb | `bj-visualizar.js` | — | real | C | visualizador-documento.js/css | 36 | `3a8769b0` | ZIP64, ODP |
| B30 | Metas parte 2: renomear na lista, reabrir, sino "Alertar para verificar" (notificação real, 1/h por meta e destinatário) | dc 1.2.3 aba Metas | — | `MetaNaListaController` (`tarefa_renomear`/`reabrir`/`alertar`) | C | src/Tarefa, _metas, pasta-metas.js | Tarefa 233, Pasta OK | `11bf7d27`, `f11110a9` | "Alertado às HH:MM" no sino; quem pode renomear/reabrir = guarda do concluir |
| B31 | Duplicados de arquivo: `pasta_documento.sha256` (migration `Version20261006134500`), hash do binário FINAL armazenado em todos os caminhos de gravação, aviso `duplicadoDe` no upload (mesmo tenant + permissão por pasta), comando `app:documentos:calcular-hash` | `bj-visualizar.js`, dc 1.2.3 Documentos | sem hash | real; o comando de preenchimento é do dono (≈23 mil arquivos, 26 GB, ~5–15 min) | D | PastaDocumento, UploadPecaUseCase, PastaController, Reconciliador, CopiarArquivosAcervo, comando | 132 + 62 | `845ec3ad`, `bc437d5e`, (fixture), (legados) | rodar `app:documentos:calcular-hash --dry-run --limite=500` em prod (dono) |
| B32 | Dashboard — 2ª auditoria (13 itens): Total alinhado, boneco pastel, destaque da ordenação padrão, ícone de sócio, "· Busca" no PDF, vazio com cabeçalho, balão do Intelligence | `auditoria-dashboard-2.md` | — | aplicado | A/B | dashboard/* | 217 | `7881b231`, `8fcbc5e3` | links "ver pastas/metas" dos cards ficam fora (o número não bate com a lista) |
| B33 | Pasta — 2ª auditoria (86 itens; 22 A / 64 B): L0 CSS + L2 Dados (clientes todos visíveis), L3 Metas, L4 notas técnicas, L5 Financeiro (selo Vencida, ícone do tipo, 3 próximos), L6 edição em Detalhes, L7 Push (cartão, cor da pílula por tipo) | `auditoria-pasta-2.md` | — | aplicado; L1 (cabeçalho/modal Editar dados/drawer) pendente | A/B | pasta-show.css + parciais | Pasta 1188 | `3d8ba25a`, `1b86203a`, `835f70f4`, `f73e05b7`, `67f643ce`, `3cadacff`, `dda8a012` | L1 depois da IA fatia 2 (mesmo `_cabecalho`); 6 itens do dono (D-PASTA6) |
| B34 | BlueJus IA — fatia 2: 7 agentes da pasta (gestor, processual, documental, prazos, relatórios, cliente, jurídico) no drawer "BlueJus Intelligence" do cabeçalho; cada um lê só o que a spec diz; financeiro só se a pessoa o vê NA SOLICITAÇÃO; clientes sem CPF/contatos; hash estável; limite por agente | spec `inteligencia-agentes-da-pasta.md` | — | real, sem provedor = indisponível honesto | D | src/Inteligencia, templates/inteligencia, _cabecalho (botão), migration `Version20261006150000` | Inteligencia 258 | `e9589edc`, `76a6c2fb`, `22a14391` | o 1º commit não passa sozinho no bisect (teste de tela entrou antes da UI) — história já integrada |
| B35 | Pasta — L1 da 2ª auditoria: modal "Editar dados" com a moldura do desenho (campos e contrato intactos; Ação segue editável), PASTA/número como itens, interruptor de favoritos por token, drawer de histórico | `auditoria-pasta-2.md` C2/C5/C7/D4/D5 | — | aplicado; C4 (selo "0") revertido → D-PASTA6 | A/B | _cabecalho, _historico_drawer, show (modal) | Pasta OK | `93192ee4`, `be7aaeb7` | abas do modal = atalhos (função nova) |
| B36 | "Administrativo sem processo" (interruptor na aba Processo, confirmação quando já há processo, vincular continua possível) | dc 1.2.3 l.1580, `admSPtoggle` l.6588 | — | `pasta.administrativa` (migration `Version20261006160000`) | D | Pasta, DefinirPastaAdministrativaUseCase, PastaAdministrativaController, _processos_vinculados | 21 | `5d4c53cf` | troca sem recarregar exige interceptar no show |
| B37 | Sugerir documentos pelo catálogo (`bj-docsug`) — fase pela classe do processo, faltantes, "Adicionar N faltante(s)" ao checklist real; rótulo honesto | `bj-docsug.js` | — | real, por regras | A | Pasta/Service/SugestorDeDocumentos, parcial novo | 28 | `bb2fd5dc` | CONTRATO mapeado para "honorários" |
| B38 | Preferências pessoais (`preferencia_usuario`, migration `Version20261006161500`) + menu ⋮ da tabela Desempenho: densidade, animações, setas, colunas ocultas, restaurar | README dashboard (menu ⋮) | — | lista fechada validada no servidor; classes no `.db-page` | D | src/Dashboard/Entity/PreferenciaDoUsuario, index, dashboard-preferencias.js | Dashboard OK | `098e82c6`, `81fce914`, `738f600e` | sons, "Adicionar coluna", zerar (dono) |
| B39 | Foto diária do estoque do Dashboard (`dashboard_foto`, migration `Version20261006171500`, `app:dashboard:fotografar`) e tendência de Vencidas/Prazos a partir da foto de `data_de − 1` | README "Tendência na linha de Total" | só 3 tendências | +2 tendências com lastro; ativas fora (bases diferentes) | D | src/Dashboard (entidade, comando), UseCase, _resultado, _desempenho_cards | Dashboard 306 | `413ea35f`, `1e6b7d89`, `e5b7aae5` | **cron do dono**: `55 2 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:dashboard:fotografar` (o `-w` é obrigatório: o WORKDIR do serviço php de prod é /var/www) (02:55 UTC = 23:55 BRT) |

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
| D-PASTA6 | 6 itens da 2ª auditoria da Pasta | — | P1 faixa "Notas técnicas 0" sob todo processo; P14 rótulo "Distribuição"; F19 placeholder do Financeiro; T8 iniciais nas observações; U4 texto do vazio do Push; U9 rótulo "Lida"; **C4** selo "0" em Metas/Documentos (o desenho mostra; a revisão da Trilha A tratou como bug) | aceitar o desenho? | ver `auditoria-pasta-2.md` | seguir o desenho nos 6 | — |
| D-PERM-META | Quem renomeia/reabre meta | guarda do "concluir" | inventário pedia confirmar | ok? | — | manter | — |
| D-CRON | Foto diária do Dashboard (B39) | comando pronto | agendar o cron na VPS | quando | — | `55 2 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:dashboard:fotografar` (UTC; sem `-w` não roda) | sem foto, as tendências de Vencidas/Prazos não aparecem |
| D-HASH | Rodar `app:documentos:calcular-hash` em produção | comando pronto, idempotente, `--dry-run` | execução em prod é do dono | quando | — | `--dry-run --limite=500` primeiro, depois fatias com `nice/ionice` fora de hora | duplicados antigos não são detectados |
| D-LIBRE | DOC/PPT/MSG no visualizador | DOCX/XLSX/texto feitos | exige LibreOffice no container | instalar? | — | sim, na imagem de prod (custo ~300 MB) | — |

## 5. Handoff — próxima ação exata

**Estado (06/10/2026, fim da rodada):** master local em `e5b7aae5`, 85 commits à frente de `origin/master`
(`beff02fd`, que é o que está em produção). **Nada publicado, nenhum deploy.** Árvore limpa (só o pacote do
Designer, não versionado). Suíte 6674/6674.

**Migrations novas (9), todas aplicadas no dev e no `saas_test`:** `Version20261006002519` (nota_tecnica) ·
`010231` (pasta_mensagem.resposta_a_id/eh_resposta) · `120000` (inteligencia_analise/configuracao + 2
permissões) · `121500` (pasta_favorita) · `134500` (pasta_documento.sha256) · `150000`
(inteligencia_analise.agente/texto_da_analise) · `160000` (pasta.administrativa) · `161500`
(preferencia_usuario) · `171500` (dashboard_foto). Ordem dos timestamps ≠ ordem de integração: o
`doctrine:migrations:migrate` de prod executa todas as pendentes; conferir `migrations:status` antes.

**Para publicar (dono):**
1. `git push` do master (ver bloco abaixo). 2. `scripts/deploy-prod-tls.sh` na VPS (rebuild; o entrypoint
de prod roda as migrations com 512 MB). 3. Smoke em prod — lista do que olhar no §6. 4. Cron da foto
(D-CRON). 5. `app:documentos:calcular-hash --dry-run --limite=500` (D-HASH). 6. Decisões do §4.

```
# Execute manualmente no terminal externo
cd /home/prime/projetos/jusprime && git status && git log --oneline origin/master..master | wc -l
git push origin master
```

**Próxima rodada (sem depender do dono):** terceira passada de fidelidade nas duas telas (as auditorias 2
estão aplicadas, salvo C4 e os 6 itens do dono); "Alertado às HH:MM" no sino das metas; estrela de favorito
no modo cartão do Expediente; troca sem recarregar do interruptor "Administrativo"; extrair a expressão CASE
duplicada das setas/posição (`PastaRepository`). **Com o dono:** tudo do §4 — em especial D-IA (provedor),
D-MASTER, D-SITUACAO, D-LINK.

**Armadilhas medidas nesta rodada:** setters gravam texto em MAIÚSCULAS (4 lotes caíram nisso — memória
`feedback_setters_gravam_maiusculas`); `MockClock('Y-m-d H:i')` assume UTC; o hook lê `sed -n`/`grep -n`
como `--no-verify` quando há `git commit` no mesmo comando; rename de migration de outra worktree pode
APAGAR a do master com o mesmo nome (aconteceu: `75fb38c0` → restaurada em `7279c012`); `cache:clear` e
`lint:twig` do dev precisam de `-d memory_limit=512M` (o entrypoint de prod já usa 512M); a suíte precisa
de 768M (`phpunit.dist.xml`).

## 6. Smoke do dono (o que olhar na tela — suíte verde não diz nada sobre aparência)

- **Pasta, cabeçalho:** "N de M" entre as setas; selo ARQUIVADO; ⋮ com Duplicar, Imprimir resumo, Fixar
  nos favoritos; excluir pede o número; botão "BlueJus Intelligence" abre o drawer dos 7 agentes (sem
  provedor: botão desabilitado com o motivo); modal Editar dados com a moldura nova.
- **Dados:** clientes todos visíveis com ícone de cadastro, busca inline no cartão (CPF mascarado),
  janela "Detalhes do cliente" (botão direito), ⋮ do prazo (.ics); Registro com Responder; "· N min".
- **Metas:** filtros, numeração, concluir/renomear/reabrir/sino na lista, atalhos de prazo no modal.
- **Processo:** dados do processo + "Ver todas", ⋮, notas técnicas, interruptor "Administrativo".
- **Financeiro:** ⋮ por arquivo, Imprimir extrato, selo Vencida, ícone do tipo, 3 próximos.
- **Detalhes:** "continuar lendo"; edição dentro da caixa.
- **Push:** filtro Todas/Novas, cartão com cor por tipo, Copiar ID/Abrir no PJe, marcar lida, criar tarefa,
  BlueJus IA (botão e lista).
- **Documentos:** visualizador DOCX/planilha/texto/ODT/RTF/PPTX/EML/ZIP; aviso de duplicado no upload;
  "Documentos sugeridos".
- **Editor:** realce, desfazer, contagem, localizar (Ctrl+H), símbolos, data, revisão/autocorreção.
- **Dashboard:** largura/números/legendas, PDF (Exportar), busca, "Sem cargo", tendências (com período),
  números clicáveis → `/tarefas/equipe` e Acervo, painel BlueJus Intelligence (texto "por regras fixas"),
  selects e calendário próprios, menu ⋮ (densidade/colunas), celular (cards), tema escuro.
- **Demandas/Processos:** filtro de data (Firefox) por causa do `filtro-tabela.css`/`.js` (opção opt-in).

## 7. Homologação local (06/10/2026)

**Limpeza:** 43 worktrees `agent-*` desta rodada removidas depois da prova de conteúdo: cada commit delas
está no master, por patch-id (`git cherry`) ou, nos 7 que tiveram conflito resolvido na integração, por
assunto idêntico. Também foram removidos a worktree `smoke-trilha-a`, o container `smoke-trilha-a-proxy`
(resíduos da Trilha A, já publicada) e o banco `saas_testinteligencia-agentes`. Ficaram as branches
`worktree-agent-*` (apagar branch é do humano) e a worktree `agent-a40e8d8ebf3d119ca`, anterior à rodada e
com um commit de cobrança que não está no master.

**Auditoria:** os 72 hashes do §3 são ancestrais do HEAD. As 9 migrations só criam/acrescentam no `up()`.
Não há segredo no diff nem mudança de env, Docker, scripts ou composer. Em prod o provedor resolve para
`ProvedorNaoConfigurado` e `ia_habilitada` é false sem `.env`.

**Revisões:** três revisões independentes, read-only (Fable para ações destrutivas e migrations, Fable
para autorização/tenant/IDOR/CSRF, Opus para regressão e IA desligada). Nenhuma achou bloqueante.
Corrigido, cada item com teste provado por reintrodução:

| Commit | Correção |
|---|---|
| `a587c62f` | foto do autor no Registro/Detalhes com o nome cru no `src` (avatar quebrado + 500 em `/pasta/<hash>.jpg`) — **defeito da Trilha A, já em produção**; achado no smoke |
| `218a1482` | Pasta não consulta as tabelas da IA quando a plataforma não tem IA (deploy parcial não derruba a tela) |
| `a153af13` | falha ao reler o hash depois da compressão não derruba mais o upload (sha256 fica null) |
| `b8a907b1` | busca de cliente: o documento só casa quando digitado inteiro (a máscara era derrotável por trechos) |
| `2f1cb03e` | renomear/reabrir/alertar meta exigem editar a pasta (IDOR irmão) |
| `a2e658a2` | nota técnica recusa pasta excluída (lápide) |
| `64251001` | deep-link do Expediente sai da URL depois de consumido (não atropela o estado salvo) |
| `dbb0dc3b` | cron da foto com `-w /var/www/app` |

**Ficam como decisão ou registro, sem código:**
- abrir o teor do Push (GET) marca a publicação como lida — contrato herdado do módulo;
- "Duplicar" não copia `administrativa`, porque o desenho não lista;
- as rotas de leitura da IA checam o módulo, não a disponibilidade da plataforma; só expõem análises já
  gravadas do próprio escritório.

**Testes:** suíte completa 6682/6682 (26.523 asserções).

**Smoke no navegador** (Playwright headless, só leitura, banco `saas_ux`, pastas 223 e 1025): 52/52, sem
nenhum 500 e sem erro de JavaScript. Os 404 restantes são de `/perfil/foto/*`, porque o dev não tem os
arquivos de foto, e de `/clientes`, que não tem rota de listagem nem no `origin/master`.
