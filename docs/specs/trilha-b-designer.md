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

## 4. DECISÕES/BLOQUEIOS DO SAMUEL

_(cada item: funcionalidade · o que já foi feito · ponto exato do bloqueio · decisão necessária ·
opções · recomendação · impacto de adiar)_

## 5. Handoff — próxima ação exata

_(atualizado a cada checkpoint)_
