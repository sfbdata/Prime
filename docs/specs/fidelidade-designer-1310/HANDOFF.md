# HANDOFF — Frente de fidelidade ao Claude Designer (pacote de 13/10/2026)

> **Para quem:** a próxima sessão do Claude Code que vai continuar esta frente sem ter visto a conversa anterior.
> **Escrito em:** 14/10/2026, ao fim da E5b; atualizado ao fim da E5c, da E7 e da E8 (mesmo dia) e **salvo inteiro em
> 15/10/2026** (estado de retomada na §0). Fatos de git, caminhos e contagens conferidos no momento da escrita.
> **Leia antes de qualquer coisa:** este arquivo; depois `../fidelidade-designer-1310.md` (plano + evidências por entrega);
> depois o checklist da tela em que for trabalhar.

## 0. Estado de retomada (15/10/2026) — leia primeiro

- **Entregas feitas, todas só em commit local:** E1, E2, E3, E4, E5, E5b, E5c, E7 e E8. E6 travada por P1. Próxima
  recomendada: **E9 (Peticionar)**.
- **Esperando o dono:**
  1. **Smoke da E7** (`SMOKE-E7.md`) e **smoke da E8** (13 passos no fim de `E8-PLANO.md`). O ambiente foi preparado em
     14/10 e **caiu** (o WSL reiniciou os containers): para religar, §12 "Smoke pelo navegador do Windows". Nada do
     smoke foi executado pelo Claude.
  2. Decisões abertas: P1, P2, P3; selo "9+"; `bj-dica.js`; tela de Equipe; unificar o cálculo de dias entre a linha de
     Minhas Metas e o "Precisa de atenção"; suspeita em `pasta/demandas/_resultado.html.twig:173` (só registrar).
  3. Push, PR, merge, deploy e CHANGELOG: do dono, coordenados com a Engenharia (decisão de 14/10).
- 🔴 **Regra nova do dono (15/10, CLAUDE.md do projeto): sem travessão (— ou –) em texto que o usuário vê.** Texto da
  frente que viola e precisa de ajuste **antes de integrar** (não corrigido ainda: o pedido de 15/10 foi só salvar o
  handoff):
  - `app/templates/expediente/index.html.twig` `perguntaDeExclusaoDoMarcador`: `' Os N sub-marcador(es) — nomes — também
    são excluídos,'` → trocar os travessões por dois-pontos ou parênteses (ex.: "Os 6 sub-marcador(es) (A, B, C)
    também são excluídos…"). O teste `ExpedienteListaDesenhoFolhaTest::testExclusaoDeMarcador` trava a frase atual e
    muda junto.
  - `app/templates/pasta/_tabela.html.twig` `.exp-sem-metas` mostra `–` (o "–" do desenho, dc L.168) quando não há meta.
    **Conflito desenho × regra do dono**: o desenho mostra o traço; a regra proíbe. Perguntar ao dono (sugestão: célula
    vazia com `title="Sem metas"`, ou "0"). `ExpedienteListaDesenhoTelaTest::testChipsPreservamAsRegras` só confere a
    classe `.exp-sem-metas`.
  - Não violam: o `'—'` em `expediente-lista.js` (só compara com o placeholder antigo), comentários e docs.
  - Legado com travessão (ex.: `—` de cliente/ação vazios no `_tabela`) só sai por varredura autorizada.
- **Git:** HEAD `2467352e` + o commit deste handoff; árvore limpa; **27 à frente e 17 atrás** de `origin/master` — os 17 são
  da Engenharia (CI, versão/CHANGELOG, release, proteção de tags, `register_argc_argv`) e **não tocam nenhum arquivo desta
  frente** (conferido com `comm` em 15/10).
- **Suíte completa no HEAD do código (`613eb619`): OK 9372/9372 (52.442 asserções)** no `saas_testfidelidade-designer`.
- **Checklists:** Pasta 220 ✓ · 49 ≈ · 113 ∅ (382) · Minhas Metas 71 · 44 · 42 (157) · Lista do Expediente 36 · 22 · 13 (71).

## 1. Objetivo

Reproduzir no BlueJus (Symfony 7.4 / Twig) **tudo** o que existe no protótipo do Claude Designer — telas, botões, menus
(⋮ e botão direito), ícones, estados (hover, foco, ativo, vazio), modais, tooltips, abas, Chat I.A./Central — com
fidelidade **verificada na tela**, não só em teste.

Regras que valem sempre (do dono, reafirmadas a cada entrega):

- **Não inventar funcionalidade, dado nem resultado.** Elemento sem backend pode ter interação puramente visual, mas
  nunca simular operação concluída. Elemento sem backend **não é descartado**: fica no checklist como ∅ com a dependência.
- **Desenho manda** onde mostra algo diferente; **convenção do sistema** manda onde o desenho é omisso; **desviar exige
  perguntar antes**. Em conflito com a especificação do Farlei, o README do pacote manda a **especificação prevalecer** —
  e o dono decide (registrar como pendência P#, não resolver sozinho).
- Preservar Symfony/Twig/arquitetura; sem React. Preservar funcionalidades existentes.
- **Não** alterar banco, migrations, autenticação, permissões, APIs, CI/CD, changelog nem arquivos da engenharia.
- Só **commit local**. Push, merge, rebase, reset, deploy e produção são do humano (hook `block-git-writes.py` bloqueia).
- O smoke funcional é do dono. A captura de tela com Playwright, **só para comparar com o protótipo**, foi autorizada nesta
  frente (o dono pede "compare visualmente com o Designer" em cada entrega).

## 2. Git e ambiente isolado (estado real em 15/10/2026)

| Item | Valor |
|---|---|
| Worktree | `/home/prime/projetos/jusprime/.claude/worktrees/fidelidade-designer` |
| Branch | `fidelidade-designer` (marcador `.frente` = `fidelidade-designer`) |
| Base | `origin/master` @ `a9653e66` (= produção na abertura, Engineering Loop publicado) |
| HEAD | `2467352e` (docs da E8) + o commit deste handoff; último commit de código: `613eb619` |
| Divergência | 27 commits à frente de `origin/master`; **17 atrás** (frentes da Engenharia `seg-runtime-argv`, `eng-ci-qualidade`, `eng-versionamento` e `eng-protecao-tags`: `Dockerfile`, `.github/workflows/*`, `CHANGELOG.md`, `DEPLOY.md`, `app/src/Shared/VersaoDoSistema.php` + teste, scripts e docs de release — **nenhuma interseção** com esta frente, conferido em 15/10) |
| Árvore | limpa (fora o que é gitignored: `app/.env.local`, `app/var/router-smoke.php`, `.frente`) |
| Banco de teste | `saas_testfidelidade-designer` (clone do `saas_test`; existe) |
| Banco de dev do smoke | `saas_ux` (via `app/.env.local` copiado do checkout principal) |
| Ambiente de smoke | **desligado** em 15/10 (containers reiniciados pelo WSL; proxy `smoke-fidelidade-proxy` e `php -S :8000` não existem mais) — religar pela §12 |
| Frente arquivada | `expediente-ux` (registro em `docs/frentes-ativas.md`; branch, worktree e commits `f96a044b`/`15555eb1` preservados só como referência — **não integrar**) |
| Registro da frente | linha `fidelidade-designer` em `docs/frentes-ativas.md` (nesta branch) |

Commits da frente (mais antigo → mais novo):

| Commit | Entrega |
|---|---|
| `8c964f8d` | E1 — Dashboard / Intelligence: pílulas → 4px |
| `b97719d4` | docs: registro da frente + inventário inicial |
| `83d6724d` | E2 — Pasta: 26 pílulas → 4px |
| `a3ec4143` | docs: evidência E2 + checklist da Pasta |
| `53aa6163` | E3 — Pasta: nota técnica + marca BLUEJUS Intelligence |
| `b4f5d1e1` | docs: evidência E3 |
| `47662002` | E4 — Financeiro sem rolagem, responsável 170px, situação do processo legível |
| `66e87a00` | docs: evidência E4 |
| `e227183b` | E5 — Minhas Metas: cantos, hover da linha, numeração por grupo (numeração depois substituída na E5b) |
| `dd985c0b` | docs: evidência E5 + checklist de Minhas Metas |
| `3b5e1bcd` | E5b — Minhas Metas: nº na pasta (R239), traço vermelho (R129), status de prazo, linha atrasada, caixa alta, abas no cartão, trilho fixo, indicadores, "Atualizado às" |
| `f356a571` | docs: evidência E5b + checklists |
| `4a58a4a8` | docs: este handoff |
| `9d098c55` | E5c — Minhas Metas: textos de prazo, cores das abas, tipografia e legenda do trilho, "Título · Pasta NNN", ⋮ "Opções" (relatório, aba Metas da pasta, Editar nome via `acao=renomear`) |
| `c0b28a7c` | docs: evidência E5c + checklist + handoff |
| `3713fc04` | decisões do dono sobre a E5c: "em aberto" restaurado na legenda; decisões registradas |
| `a5362fad` | E7 — janela de confirmação da Pasta com variante azul; 14 `confirm()` trocados; Editar dados destravado (estava morto) |
| `8c019bca` | docs: evidência E7 + checklist da Pasta + handoff |
| `3e82c7f5` | roteiro de smoke da E7 (`SMOKE-E7.md`) e decisões de encerramento |
| `c807cfac` | investigação e plano da E8 (`E8-PLANO.md`) |
| `1ad07b08` | `expediente-ux` arquivada no registro (branch e commits preservados) |
| `7c418cae` | E8 — filtro "Sem responsável" (única regra nova, autorizada) |
| `ad5e6932` | janela de confirmação da E7 num partial (Pasta e Expediente) |
| `ebd8fe77` | E8 — Lista do Expediente no desenho (CSS, marcação, menus, exclusão de marcador) |
| `35cbe3dc` | E8 — contagem e NUP vencendo os estilos antigos |
| `613eb619` | E8 — correções da revisão |
| (seguinte) | docs: evidência E8 + checklist da Lista + este handoff |

## 3. Onde está cada coisa

| O quê | Caminho |
|---|---|
| Pacote do Designer (fonte oficial) | `/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/` (README.md com a tabela "Telas finais" e o padrão visual) |
| Protótipos | `…/REPOSITORIO_BLUEJUS/prototipo/*.dc.html` — Pasta = `02 - EXPEDIENTES 1.2.3.dc.html`; Minhas Metas = `04 - Minhas Metas 1.0.dc.html`; Dashboard = `01 - Dashboard 1.2.2.dc.html`; Lista = `03 - EXPEDIENTE Lista 1.1.dc.html`; Perfis `05`, Trabalho Externo `06`, Peticionar `07`, `Agenda 1A`, `Push Compartilhado`, `FinBlue` (este é outro sistema: `/home/prime/projetos/finblue`) |
| Especificação do Farlei (prevalece em conflito) | `…/REPOSITORIO_BLUEJUS/especificacao/ESPECIFICACAO_ORIGINAL_FARLEI_BLUEJUS.md` (§3.2 cabeçalho, §3.3 comentários, §3.5 metas, §9 elementos sem pedido) · `COMANDOS_CHAT_IA.md` (321 comandos) · `ESPEC_AGENTE_PASTAS.md` · `ESPEC_DADOS_REAIS_MAPA.md` |
| Pacote anterior (05/10, base do Engineering Loop) | `/home/prime/projetos/jusprime/docs/design/claude-design-2026-10-05 (1)/` (não versionado; só para diff) |
| Plano + evidências por entrega | `docs/specs/fidelidade-designer-1310.md` (§2 entregas, §3 diferenças, §4–§10 evidências E1–E5b) |
| Checklist da Pasta | `docs/specs/fidelidade-designer-1310-checklist-pasta.md` (§19 ausentes por natureza, §20 atualizações + pendências P1–P6) |
| Checklist de Minhas Metas | `docs/specs/fidelidade-designer-1310-checklist-minhas-metas.md` (§11 contagens, §12 candidatos, §13 pendências, §14 notas E5b) |
| Ledger anterior (decisões C/D/E já tomadas) | `docs/specs/engineering-loop-fidelidade-designer.md` |
| Memória da frente | `~/.claude/projects/-home-prime-projetos-jusprime/memory/project_fidelidade_designer_1310.md` |

## 4. Arquivos alterados (desde `a9653e66`) e decisões técnicas

**CSS:** `dashboard-inteligencia.css` (E1) · `pasta-show.css`, `pasta-ia.css`, `pasta-ia-agentes.css`,
`pasta-documentos-sugeridos.css`, `pasta-explorador.css`, `pasta-checklist.css` (E2) · `nota-tecnica.css`,
`pasta-ia-agentes.css` (E3) · `pasta-show.css` (E4) · `minhas-metas.css` (E5, E5b).
**Twig:** `pasta/_processos_vinculados.html.twig` (E4) · `tarefa/minhas.html.twig`, `_resultado.html.twig`,
`_meta_cartao.html.twig`, `_metas_lista.html.twig` (E5, E5b).
**PHP:** `src/Processo/Service/SituacaoProcessoRotulo.php` + `src/Twig/SituacaoProcessoExtension.php` (filtro
`situacao_processo`) + `src/Pasta/Controller/PastaResumoController.php` (E4) · `src/Tarefa/Service/AgrupadorDeMetas.php`
(`classificar` pública e estática), `src/Tarefa/DTO/StatusDoPrazoDaMeta.php` (novo), `src/Tarefa/DTO/MinhasMetasOutput.php`
(`numeroNaPasta`, `statusDaMeta`), `src/Tarefa/Repository/TarefaRepository.php` (`numerosNaPasta`, leitura),
`src/Tarefa/UseCase/ListarMinhasMetasUseCase.php` (E5b).
**Testes:** `tests/Dashboard/Unit/DashboardCantosPjeIntelligenceCssTest.php`, `tests/Pasta/Unit/PastaCantosPjeCssTest.php`,
`tests/Pasta/Unit/PastaNotaTecnicaEMarcaIaCssTest.php`, `tests/Pasta/Unit/PastaFinanceiroResponsavelFocoCssTest.php`,
`tests/Processo/Unit/SituacaoProcessoRotuloTest.php`, `tests/Pasta/Functional/PastaProcessoCartaoTelaTest.php` (+2 e uma
expectativa mudada), `tests/Tarefa/Unit/MinhasMetasCantosHoverCssTest.php`, `tests/Tarefa/Unit/StatusDoPrazoDaMetaTest.php`,
`tests/Tarefa/Functional/TarefaMinhasControllerTest.php` (+6).
**Docs:** os três de `docs/specs/fidelidade-designer-1310*` e a linha em `docs/frentes-ativas.md`.
Nenhuma migration, rota, permissão, entidade ou config.

**E5c / decisões (Minhas Metas):** `src/Tarefa/DTO/TextoDoPrazoDaMeta.php` (novo), `MinhasMetasOutput.php`
(`prazoDaMeta`, `fatiasDoAndamento` com "em aberto", `totalAtrasadas`), `minhas-metas.css`, `_meta_cartao.html.twig`
(menu ⋮ Bootstrap), `_resultado.html.twig`, `public/js/pasta-metas.js` (`acao=renomear` e filtro do realce); testes
`TextoDoPrazoDaMetaTest`, `MinhasMetasAbasTrilhoPrazoCssTest`, `PastaMetaRealceVindoDeMinhasMetasJsTest`, +funcionais em
`TarefaMinhasControllerTest`.
**E7 (Pasta):** `public/js/pasta-dialogos.js` (variante azul, `confirmarUIPs`, `psConfirmarEnvio`, envio segurado em captura,
`devolverFoco`), `templates/_partials/_janela_confirmacao.html.twig` (desde a E8; antes no `pasta/show`), `pasta/show.html.twig`
(Editar dados no `DOMContentLoaded`, desvincular, número), `_cabecalho`, `_metas`, `_financeiro*`, `pasta-processo.js`,
`pasta-checklist.js`, `pasta-explorador.js`, `pasta-ia-push.js`, `pasta-ia-agentes.js`, `nota-tecnica.js`; teste
`PastaConfirmacaoDoDesenhoJsTest` + 13 testes antigos reescritos.
**E8 (Expediente):** `src/Pasta/Repository/PastaRepository.php` (filtro `responsavel=sem` — **única regra nova**, autorizada),
`pasta/_filtros.html.twig`, `public/css/expediente-lista.css` e `public/js/expediente-lista.js` (novos), `expediente/index.html.twig`,
`_acervo_geral`, `_painel_marcador`, `_paginacao`, `pasta/_tabela.html.twig`; testes `ExpedienteListaDesenhoTelaTest`,
`ExpedienteListaDesenhoFolhaTest`, +4 em `ExpedienteFiltroPastasControllerTest`. `pasta/index.html.twig` está morto
(`pasta_index` redireciona): `_tabela`, `_card`, `_filtros` só servem o Expediente.

Decisões técnicas que a próxima sessão precisa respeitar:

1. **Pareamento linha a linha antes de mexer em CSS.** O inventário automático errou 4 pares de raio na E2. Script usado
   (difflib nas linhas trocadas entre o protótipo de 05/10 e o de 13/10) está descrito em `fidelidade-designer-1310.md` §5.
   O protótipo **mantém** 999px em interruptores, preenchimentos de barra e selos de status montados em JS.
2. **Tokens, não valores soltos;** tema escuro: o desenho não tem, os tokens escuros seguem a convenção (não travados em teste).
3. **Arquivo compartilhado só com decisão do dono:** `templates/base.html.twig`, `public/css/app.css`,
   `_partials/_filtro_barra.html.twig` + `filtro-tabela.js/css` (usados por outras telas), `_resp_estilo.html.twig`
   (chip de responsável, compartilhado com a lista do Expediente). Por isso ficaram de fora: selo "9+" global e `bj-dica.js`.
4. **Apresentação nunca muda dado:** `SituacaoProcessoRotulo` mapeia só os 4 valores do cadastro; desconhecido aparece como veio.
5. **Uma regra para grupo e pílula:** `StatusDoPrazoDaMeta` só traduz `AgrupadorDeMetas::classificar`.
6. **Número da meta na pasta = posição por ordem de criação na pasta**, mesma regra de `PastaMetasResumoOutput`; teste
   compara Minhas Metas com a aba Metas da pasta.
7. **Sticky:** o AdminLTE (CDN) põe `overflow:auto` em `main.app-main` (≥992px) e prende qualquer `position: sticky`;
   escopar `body.layout-fixed .app-main:has(.<página>) { overflow: visible }` no CSS da página.
8. **Teste de folha CSS não vê cascata** — duas vezes nesta frente o teste passou e a tela estava errada (faixa anulada por
   regra posterior; sticky preso). **Medir na tela é obrigatório** antes de declarar conforme.

## 5. Resultado de cada etapa

| Etapa | Entregue | Testes da etapa | Comparação na tela (1440×900, valores calculados) |
|---|---|---|---|
| E1 | 6 seletores do painel Intelligence → 4px | 9/9 novo; `tests/Dashboard` 478/478 | ✓ chips, nível, botão Modo avançado; interruptor segue pílula |
| E2 | 26 seletores da Pasta → 4px; 12 seguem pílula | 38/38 novo; Pasta+Inteligencia 3458/3458 | ✓ 16 de 38 visíveis com os dados do dev; hover/foco de filtros de metas e busca de documentos |
| E3 | Nota técnica (#f6fafd, #b9d6e8, 4px, conector 1.5px #c9dfec) + marca IA (10.5px/700/.12em #0a6fa0; 14.5px) | 5/5 novo; Pasta+Inteligencia+Processo 3677/3677 | ✓ idêntico ao protótipo; nota inserida só no DOM (dev não tem nota técnica) |
| E4 | Financeiro sem rolagem; responsável 170px no cabeçalho; "Em andamento" (cartão, painel, resumo, PDF) | 10 + 3 novos, +2 funcionais; Pasta+Processo+Inteligencia+Twig 3730/3730; lint:twig/container OK | ✓ os três; observações copiadas só no DOM |
| E5 | Minhas Metas: cantos, hover #3b8fc2, numeração por grupo | 16 + 1 novos; Tarefa+Twig 344/344 | ✓ hover valor a valor; numeração depois trocada (P5) |
| E5b | ver commit `3b5e1bcd` | 8 + 7 + 5 novos; mutação provada; Tarefa+Twig+Dashboard+aba Metas da Pasta 851/851; lint OK | ✓ medido; 3 defeitos achados só na tela e corrigidos (faixa, sticky, abas no celular) |
| E5c | ver commit `9d098c55` e `fidelidade-designer-1310.md` §11 | 11 + 15 + 2 novos, +5 funcionais; 3 mutações provadas; revisão read-only com 4 correções | ✓ medido valor a valor contra o protótipo; menu fora do recorte; `acao=renomear` abre a edição na pasta |
| **E5c (antes da revisão)** | — | **suíte completa: OK 9306/9306 (52.021 asserções, 07:35)** no `saas_testfidelidade-designer`, rodada sozinha em 14/10; depois das correções da revisão: `tests/Tarefa` 360/360 + rodada Tarefa+Pasta+Twig+Dashboard **3708/3708** | — |
| E7 | ver commit `a5362fad` e `fidelidade-designer-1310.md` §12 | 38 novos + 13 reescritos; 4 mutações provadas; revisão read-only com 5 correções | ✓ Duplicar, Alterar número, Descartar e Desvincular exercitados com POST interceptado (0 antes do "sim", 1 depois); valores do `cf` medidos; escuro e 390px |
| **HEAD `a5362fad`** | — | **suíte completa: OK 9345/9345 (52.136 asserções)** no `saas_testfidelidade-designer`, rodada sozinha em 14/10 | — |
| E8 | ver `fidelidade-designer-1310.md` §13 e `E8-PLANO.md` | 23 novos + 4 do filtro; 2 mutações; revisão com 11 correções | ✓ medido; menus, teclado e exclusão com escrita interceptada; escuro e 390px |
| **HEAD `613eb619`** | — | **suíte completa: OK 9372/9372 (52.442 asserções)** | — |

**Limitações da validação** (não esconder):
- O banco de dev (`saas_ux`) **não tem**: nota técnica, pagamentos na maioria das pastas, meta com prazo futuro, push com itens
  em várias pastas. Por isso parte dos elementos foi medida com nós **inseridos só no DOM** (nada gravado) e parte só pelo teste.
- Celular e tema escuro só foram olhados pontualmente (E5b: 1100 e 390px sem rolagem horizontal). Tema escuro não foi capturado.
- Comparação feita em Chromium headless (Playwright). Bordas de 1.5px aparecem como 1px nos dois lados (arredondamento do navegador).
- O modal de alerta do ponto foi **removido do DOM** nas capturas, sem registrar batida (ponto é risco ALTO — não interagir).

## 6. Decisões P1–P6

| # | Assunto | Estado |
|---|---|---|
| **P1** | Faixa Processo · Ação · Responsável no cabeçalho da Pasta — o desenho de 13/10 tira; a especificação §3.2 (R087) pede, nessa ordem | **ABERTA** — E6 (reorganizar cabeçalho) travada |
| **P2** | Caixa "Como usar esta aba" (aba Detalhes) — o desenho tira; §3.3 (R215) pede | **ABERTA** — caixa mantida |
| **P3** | "Andamento da pasta" (trilha de fases) — está no desenho, sem pedido do Farlei (§9) e depende de backend | **ABERTA** |
| P4 | Foco do botão BLUEJUS Intelligence — o desenho declara anel que não renderiza | Resolvida: preservar o indicador atual (contorno do navegador + fundo), divergência registrada e travada em teste |
| P5 | Numeração da meta | Resolvida: R239 — nº na pasta ✓ (E5b); nº global = dependência de backend |
| P6 | Traço sob o título de Minhas Metas | Resolvida: R129 — vermelho ✓ (E5b) |

Também decidido pelo dono: R238 (concluídas mais claras) preservado; o ⋮ do protótipo de Minhas Metas (que só alterna o
acompanhamento) **não** é reproduzido; "vence hoje" segue a regra existente (`AgrupadorDeMetas`).
Abertas desde a E1 (dependem do dono, tocam arquivo compartilhado): selo "9+" com 4px (topo global, `app.css`) e
`bj-dica.js` (dica padronizada global, `base.html.twig`; o README diz "fundo azul, letra branca", o script usa fundo
azul-claro e letra azul-escura).

## 7. Inventário (contado nos arquivos em 14/10)

| Tela | ✓ conforme | ≈ diverge | ∅ ausente | Total | Arquivo |
|---|---|---|---|---|---|
| Pasta | 220 | 49 | 113 | **382** | `fidelidade-designer-1310-checklist-pasta.md` |
| Minhas Metas | 71 | 44 | 42 (38 do Chat I.A.) | **157** | `fidelidade-designer-1310-checklist-minhas-metas.md` |
| Lista do Expediente | 36 | 22 | 13 | **71** | `fidelidade-designer-1310-checklist-lista.md` (novo na E8) |

Minhas Metas: 119 linhas de tabela + 38 itens do Chat I.A. em texto (§10); o defeito do ⋮ (MM-MENU-06) não entra.
Evolução de Minhas Metas: levantamento 25/72/57 → E5 27/72/56 → E5b 53/58/46 → E5c 72/43/42 → **decisões 71/44/42** ("em aberto" restaurado: TRI-06 ≈).
Evolução da Pasta: levantamento 377 → E3 379 → E4 218/49/113 (380) → **E7 220/49/113 (382)** (PZ-MOD-25/26 novos ✓; PZ-MOD-15 era ✓ no papel e morto na tela — agora de verdade).
Telas ainda **sem checklist próprio**: Dashboard (só o delta de 13/10 foi tratado), Lista do Expediente 1.1, Perfis,
Trabalho Externo, Peticionar, Agenda, Push Compartilhado — o inventário inicial delas está em `fidelidade-designer-1310.md` §1–§3.

## 8. Pendências de backend (fora desta frente — entregar à engenharia)

- **Número global da meta por escritório** (R239: `{nº na pasta} Título {nº global}`): não existe sequência por tenant; o id
  é global e revelaria volume de outros escritórios (PMC-035). Precisa de coluna + migration + regra de atribuição. (MM-LIS-37)
- Pasta: andamento em fases (P3), apensos com tipo e "Vincular apensado", Push por processo/apenso, registros automáticos do
  sistema na linha do tempo, exclusão de comentário em 60 min (hoje 15), cadeado/sigilo, destaque de comentário (R §3.7),
  situações Suspenso/Cancelado, link externo do Push, Master/primário (R §3.12), foto do cliente.
- Minhas Metas: prioridade **por meta** (hoje é a da pasta), busca por nº da pasta e cliente, itens do ⋮ que pedem backend
  ("Encaminhar via Chat I.A").
- **Chat I.A./Central** inteiro (≈38 itens por tela + comandos "/"): servidor, tempo real, provedor de IA.
- Telas novas sem backend: Trabalho Externo (módulo inteiro), painel Master de Perfis (mapa, contas, planos, cotas).

## 9. Integração futura com a Engenharia

- Integração em série, um piloto de git por vez; **antes** de integrar, trazer o `origin/master` para dentro da frente e rodar
  de novo (`scripts/frente-fechar.sh fidelidade-designer` faz o ritual e para antes do merge). Em 15/10 a frente está 17
  atrás, sem interseção de arquivos. O master agora tem a política de versões/CHANGELOG (`eng-versionamento`): o PR desta frente
  pode precisar de entrada no CHANGELOG — esta frente **não** mexe no changelog (regra do §1); combinar com a engenharia.
- Arquivos desta frente que outras frentes também podem tocar (conferir antes de integrar): `public/css/pasta-show.css`,
  `templates/pasta/_processos_vinculados.html.twig`, `src/Pasta/Controller/PastaResumoController.php`,
  `src/Tarefa/Repository/TarefaRepository.php`, `src/Tarefa/Service/AgrupadorDeMetas.php`, `templates/tarefa/*`,
  `docs/frentes-ativas.md` (só a linha desta frente). Da E7: `public/js/pasta-dialogos.js`, `templates/pasta/show.html.twig`,
  `_cabecalho`, `_metas`, `_financeiro*`, `pasta-processo.js`, `pasta-checklist.js`, `pasta-explorador.js`,
  `pasta-ia-push.js`, `pasta-ia-agentes.js`, `nota-tecnica.js`.
- Arquivos que esta frente **evitou** e que exigem combinar com o dono/engenharia: `templates/base.html.twig`,
  `public/css/app.css`, `templates/_partials/_filtro_barra.html.twig`, `public/js/filtro-tabela.js`,
  `public/css/filtro-tabela.css`, `templates/pasta/_resp_estilo.html.twig`.
- Sem migration nesta frente. Publicação: push + `scripts/deploy-prod-tls.sh` + smoke são do dono.

## 10. E5c (Minhas Metas) — ✅ feita em `9d098c55`

Evidência em `fidelidade-designer-1310.md` §11; notas no checklist §15. Abaixo, o escopo como foi pedido (cumprido;
desvios: menu é dropdown do Bootstrap, não `ps-pop` — o `ps-pop` depende do JS da pasta; "Editar nome" leva à pasta
em vez de editar ali, para não mudar o `tarefa_renomear`; a legenda perdeu a fatia "em aberto", como o desenho).
**Decisões do dono sobre a E5c (14/10)** — checklist de Minhas Metas §16: clique na linha e no item de atenção seguem
abrindo a página da meta; "Editar nome" fica na Pasta; "em aberto" restaurado na legenda; ⋮ em Bootstrap aceito;
unificar o cálculo de dias entre linha e "Precisa de atenção" fica como pendência registrada.
Sobra de Minhas Metas para uma E5d, se o dono quiser: §12 do checklist (cartão por grupo, nº da pasta em negrito,
marcador, H1 36px…), com duas decisões antes: destino do clique na linha/no item de atenção (o desenho abre a
**pasta**) e se "Editar nome" deve editar na própria Minhas Metas (exige o `tarefa_renomear` voltar para cá).

Só visual, com dado que já existe; manter o padrão: teste antes (vermelho), implementação, suíte proporcional, **medir na
tela** contra o protótipo, atualizar o checklist e a spec, commit local de código e de docs separados.

1. **Textos de prazo** (MM-LIS-20 a 25): "vence em N dia(s) · dd/mm/aaaa", "vence hoje", "sem prazo definido",
   "concluída", "N dia(s) atrasado"; cor #4a6373 quando não atrasado e #a3232b negrito quando atrasado (protótipo JS
   `prazoTxt`, `prazoCor`). Derivar do mesmo `AgrupadorDeMetas::classificar`/prazo real. `Tarefa::getPrazoInfo` é usado
   em outras telas: não mudar o texto dele sem conferir os usos.
2. **Cores das abas** (MM-ABA-02/03/04/09): inativa #34505f 13px normal; ativa fundo #fff, **texto #0a6fa0** negrito,
   sombra 0 1px 3px; contador ativo #e5f1f7/#0a6fa0, inativo #dfe6eb/#4a6373 (dc L68-72).
3. **Tipografia do trilho** (MM-TRI-03/04/05/06/10/11/12): "X de Y" 34px negrito; barra verde #1e7b45 com transição
   .4s; legenda com "(s)"; "Título · Pasta NNN" no "Precisa de atenção"; ícone #a3232b. TRI-07/13 são regra (não mexer).
4. **Menu ⋮ só com ações que já existem** (MM-MENU-08/09/10): Abrir relatório completo, Ir para a aba Metas da pasta,
   Editar nome (R118/R236). Reaproveitar o menu `ps-pop` da aba Metas da pasta (`templates/pasta/_metas.html.twig:218-262`).
   "Encaminhar via Chat I.A" (R223) **fica ∅** (sem backend). Não reproduzir o defeito do ⋮ do protótipo.
   Atenção: hoje no lugar do ⋮ há a seta "Abrir a meta" (decisão antiga); trocar exige manter o acesso à meta.

## 11. E7 (janela de confirmação da Pasta) — ✅ feita em `a5362fad`

Evidência e inventário em `fidelidade-designer-1310.md` §12. Para quem continuar:

- **Use `confirmarUIPs(msg, perigo[, foco])`** para qualquer pergunta nova na Pasta (regra do dc: 1ª linha = título;
  `perigo` = vermelho; sem perigo = azul). Formulário comum: `data-ps-cf="Título\nTexto"` (+ `data-ps-cf-perigo`) e um
  `onsubmit="return !!window.confirmarUIPs || window.confirm(this.dataset.psCf);"` de reserva. Nunca `confirm()` direto:
  o `PastaConfirmacaoDoDesenhoJsTest` derruba.
- 🪤 **Script inline no corpo da página roda ANTES do Bootstrap** (o bundle carrega no fim do `base.html.twig`). Guarda
  `typeof bootstrap === 'undefined'` no topo de um IIFE mata o bloco em silêncio — foi o que deixou o Editar dados morto.
  Use `DOMContentLoaded`. Mesmo padrão suspeito em `pasta/demandas/_resultado.html.twig:173` (outra tela, não mexido).
- **Smoke do dono:** os 4 atalhos do Editar dados que voltaram a funcionar (Cliente, Processo, Histórico, "Trocar"
  responsável), o Salvar que agora só acende com alteração, e as perguntas que o dev não tinha dado para exercitar
  (excluir meta, checklist, Financeiro, análises da IA, nota técnica).
- **Decisões do dono no encerramento da E7 (14/10):** (1) a frase "O andamento passa para a via administrativa." do dc
  fica **fora até P3**; (2) a suspeita em `pasta/demandas/_resultado.html.twig:173` fica **registrada para investigação
  futura, sem alterar a tela**; (3) sem push, PR, merge ou deploy; (4) **CHANGELOG intocado** — integração coordenada com a
  Engenharia. Roteiro de smoke e testes comportamentais candidatos: `SMOKE-E7.md` (nesta pasta).

## 11a. E8 (Lista do Expediente) — ✅ feita em `7c418cae`…`613eb619`

Plano, decisões e **smoke do dono** em `E8-PLANO.md`; evidência em `fidelidade-designer-1310.md` §13. Para quem continuar:
- O visual da Lista mora em `public/css/expediente-lista.css`, todo sob `.exp-body` (classe do `<body>` do Expediente). O
  CSS antigo inline de `expediente/index` usa `!important` em várias propriedades — medir na tela antes de crer no teste.
- Menus de botão direito em `public/js/expediente-lista.js`: cada item aciona um controle que já existe, **depois** do
  clique (senão o menu de responsável fecha no mesmo clique). Ação nova = decisão do dono.
- `pasta/index.html.twig` está morto (`pasta_index` redireciona): `_tabela`, `_card`, `_filtros` só servem o Expediente.
- Pendências da Lista: cabeçalho fixo da tabela, colunas 2:1.5, pops de filtro, moldura vermelha do dc na exclusão — e o
  `.active` que o clique no marcador não deixa no link (anterior).

## 11b. Próximas entregas

- **Pasta** (`fidelidade-designer-1310.md` §2): E9 (Peticionar), E10 (Agenda), E11 (Perfis). E6 continua travada por P1.
- Reduzir os **113 ∅ da Pasta** pela §19 do checklist: primeiro os que não pedem dado (botão direito sobre os ⋮ de
  Processos e Pagamentos — PZ-CTX-08; "Encaminhar via Chat I.A" visível e desabilitado com dica nos ~15 menus; aviso
  estático "Integração com o PJe não configurada"; situação legível também no Peticionar e nas telas de Processo).
- **Chat I.A.:** casca visual possível (botão flutuante, janela arrastável/minimizável, abas vazias com estado "sem conexão
  com a Central") — **sem** respostas, conversas, contagens ou comandos simulados. Confirmar com o dono antes de começar.
- Pendente de decisão do dono: P1, P2, P3; selo "9+"; `bj-dica.js`; tela de Equipe (status ainda com lógica própria);
  unificar o cálculo de dias entre a linha de Minhas Metas e o "Precisa de atenção" (`getPrazoInfo` não zera a hora).

## 12. Comandos

```bash
# Ambiente (containers não sobem sozinhos após reiniciar o WSL)
docker ps --format '{{.Names}}' | grep jusprime           # esperar jusprime_php_dev, jusprime_db_dev, jusprime_nginx_dev
docker start jusprime_db_dev jusprime_php_dev jusprime_nginx_dev

# Estado da frente
cd /home/prime/projetos/jusprime/.claude/worktrees/fidelidade-designer
git status --short && git branch -vv | grep '^\*' && git log --oneline -3
git log --oneline HEAD..origin/master                      # o que chegou no master depois do corte
docker exec jusprime_db_dev psql -U symfony -d postgres -tAc \
  "select datname from pg_database where datname='saas_testfidelidade-designer'"

# Testes — SEMPRE pelo script da frente (o phpunit do repo principal testa o checkout principal, não a worktree)
cd /home/prime/projetos/jusprime
scripts/frente-testar.sh fidelidade-designer                                   # suíte completa (rodar sozinha)
scripts/frente-testar.sh fidelidade-designer tests/Tarefa tests/Twig           # Minhas Metas
scripts/frente-testar.sh fidelidade-designer tests/Pasta tests/Processo tests/Inteligencia
scripts/frente-testar.sh fidelidade-designer --filter testNumeroDaMetaNaPastaComoNaAbaMetas tests/Tarefa/Functional/TarefaMinhasControllerTest.php
#   (no --filter não use "|": o script repassa sem aspas e o shell vira pipe — rode um filtro por vez)
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/fidelidade-designer/app && php -d memory_limit=1G bin/console lint:twig templates && php -d memory_limit=1G bin/console lint:container'

# Comparação na tela (servidor da worktree; o nginx :8080 serve o checkout PRINCIPAL)
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/fidelidade-designer/app && APP_ENV=dev php -d memory_limit=1G bin/console cache:clear'
docker exec -d -e APP_ENV=dev -e PHP_CLI_SERVER_WORKERS=4 jusprime_php_dev bash -c \
  'cd /var/www/.claude/worktrees/fidelidade-designer/app && php -d memory_limit=512M -S 0.0.0.0:8000 -t public var/router-smoke.php > /tmp/smoke-fidelidade.log 2>&1'
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' jusprime_php_dev   # era 172.20.0.3
python3 -m http.server 8765 --bind 127.0.0.1 --directory /home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo
#   protótipo em http://127.0.0.1:8765/<arquivo>.dc.html (não abre por file://)
#   login dev: farlei.rocha@gmail.com / Prime123! (escritório "Farlei Assis da Rocha Sociedade Individual")
#   Playwright sem navegador → npx @playwright/mcp install-browser chrome-for-testing
# Encerrar depois: matar o php -S pelo /proc no container; parar o http.server; fechar o navegador.
```


### Smoke pelo navegador do Windows (receita usada em 14/10 — o dono executa, o Claude só prepara)

O nginx `:8080` serve o **checkout principal** (sem esta frente). O servidor da worktree roda dentro do container e o
Windows não alcança a rede do Docker: um proxy descartável publica só em `127.0.0.1:8000`.

```bash
docker start jusprime_db_dev jusprime_php_dev jusprime_nginx_dev            # se o WSL reiniciou
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/fidelidade-designer/app && APP_ENV=dev php -d memory_limit=1G bin/console cache:clear'
docker exec -d -e APP_ENV=dev -e PHP_CLI_SERVER_WORKERS=4 jusprime_php_dev bash -c \
  'cd /var/www/.claude/worktrees/fidelidade-designer/app && php -d memory_limit=512M -d upload_max_filesize=70M -d post_max_size=70M -S 0.0.0.0:8000 -t public var/router-smoke.php > /tmp/smoke-fidelidade.log 2>&1'
docker run -d --rm --name smoke-fidelidade-proxy --network jusprime_saas_net -p 127.0.0.1:8000:8000 \
  alpine/socat tcp-listen:8000,fork,reuseaddr tcp-connect:jusprime_php_dev:8000
# conferir do Windows: deve dar 200 na 8000 e 404 na 8080 (prova de que a 8000 é a frente)
/mnt/c/Windows/System32/curl.exe -s -o NUL -w '%{http_code}\n' http://127.0.0.1:8000/css/expediente-lista.css
/mnt/c/Windows/System32/curl.exe -s -o NUL -w '%{http_code}\n' http://127.0.0.1:8080/css/expediente-lista.css
# desligar depois
docker exec jusprime_php_dev bash -c 'for p in /proc/[0-9]*; do c=$(tr "\0" " " < $p/cmdline 2>/dev/null); case "$c" in "php -d memory_limit=512M"*) kill ${p#/proc/};; esac; done; true'
docker rm -f smoke-fidelidade-proxy
```

- URL para o dono: **`http://127.0.0.1:8000/expediente`** (não `localhost`: no Windows resolve para IPv6 e trava).
- Login de dev: `farlei.rocha@gmail.com` / `Prime123!`, escritório "Farlei Assis da Rocha Sociedade Individual de Advocacia".
  O aviso "Você ainda não registrou sua entrada hoje" aparece: fechar sem registrar (gravaria batida no `saas_ux`).
- Dados do `saas_ux` úteis (14/10): 1.060 pastas no escritório, 57 sem responsável, 1 excluída (NUP 1214); pasta 304
  (Duplicar, Editar dados), 588 (processo vinculado), 1170/1171 (itens de checklist), 433 (comentário do Financeiro);
  marcador "2. Distribuir" (id 7) com 6 sub-marcadores.
- 🪤 Matar processo no container: `pkill`/`ps` não existem, e um laço que casa `-S 0.0.0.0:8000` mata o próprio shell
  (o comando contém o padrão) — case pelo início da linha (`"php -d memory_limit=512M"*`). No host, `pkill -f "http.server 8765"`
  também se mata: use `kill $(pgrep -f "http.server 876[5]")`.

Armadilhas já pagas: `frente-abrir.sh` aborta no `cache:clear` do composer (128 MB) — completar à mão; `cd app` relativo
pode cair no checkout principal (use caminho absoluto); o hook confunde `-n` de `grep`/`head` com `--no-verify` e bloqueia
qualquer `git checkout` (reverter teste de mutação por cópia no scratchpad, não por git); `git stash` é compartilhado entre
worktrees (não usar).
