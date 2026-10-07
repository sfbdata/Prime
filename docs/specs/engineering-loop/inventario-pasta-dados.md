# Inventário: PASTA (cabeçalho, Dados, Registro, Detalhes/observação, Timeline inteligente)

Auditoria somente leitura em 07/10/2026, master `607f2c29` (código igual ao de produção `719de0e4`).
Sem navegador. Cor, raio e sombra foram conferidos lendo o CSS, não na tela. Prefixo dos IDs: **PAS-**.
Abreviações: `dc` = `02 - EXPEDIENTES 1.2.3.dc.html`; `central` = `bluejus-central.js` (pacote do Designer).
Os caminhos do código são relativos a `app/`.

## Fontes lidas (arquivo:faixa de linhas)

**Designer**
- `dc:1045-1290`: cabeçalho, com as linhas 1, 2 e 3, o ⋮, o menu do responsável, as abas, Cadastro e Situação.
- `dc:1286-1415`: aba Dados, com o Registro, o trilho de prazos, Clientes e Documentos.
- `dc:2300-2400`: aba Detalhes (observações, "Registro da pasta", "Pontos do relatório inicial").
- `dc:2752-2786`: drawer "Histórico do sistema".
- `dc:556-600`: modal "Editar dados".
- `dc:195-214`: janela "Imprimir".
- `dc:4225-4345`: `__bjTimelineFontes`, `peVals`, `prefPasta` e `menuPasta` (duplicar, mover, excluir).
- `dc:4409-4420`: `abaMenu`.
- `dc:5826-5866`: selos e situações.
- `dc:5886-5990`: `regPodeEditar`, `comPerm`, `comExcluir`, `respEnviar`, `regsLinha` (menu do botão direito do registro) e `regsItens`.
- `dc:6140-6200`: `deadlines`, o ⋮ do prazo.
- `dc:6450-6492`: `extrasObs`.
- `dc:6612-6630`: handlers do ⋮.
- `central:3345-3452`: Timeline inteligente (`tlDetectar`, `tlEventos`, `tlFiltrar`, `tlRender`, `tlCard`).
- `central:645-648`: comandos da Timeline.
- `bj-editor.js:290-370`: compositor e modo edição (rodapé "Visível para a equipe · Cancelar · Salvar").
- `design_handoff_bluejus_intelligence/README.md:15-34, 47-77, 116-127, 145-150, 162-164, 194-198, 589-601`.
- Captura `screenshots/pasta-menu.jpg`. É da Pasta 1A, desatualizada, e foi descartada como referência.

**Mapa (ledger)**
- `docs/specs/estado-geral-claude-designer-bluejus.md` §1, §4, §9, §10 e §15.
- `docs/specs/trilha-b-inventario/pasta.md` e `auditoria-pasta-2.md`.
- `docs/specs/trilha-b-designer.md`, linhas 91-147 (B33, B35, D-META, D-PASTA6).

**Código**
- Templates:
  - `templates/pasta/_cabecalho.html.twig` (inteiro, 1-649)
  - `templates/pasta/_dados_anotacoes.html.twig` (inteiro)
  - `templates/pasta/_dados_trilho.html.twig` (inteiro)
  - `templates/pasta/_historico_drawer.html.twig` (inteiro)
  - `templates/pasta/_timeline_inteligente.html.twig` (inteiro)
  - `templates/pasta/_resp_chip.html.twig` e `templates/pasta/_resp_menu.html.twig`
  - `templates/pasta/_detalhes_obs.html.twig:1-160, 299-320, 412, 491-495`
  - `templates/pasta/show.html.twig:2850-3000` (envio, responder, editar e excluir mensagem) e `:3684-3920` (modal Editar dados e Situação)
  - `templates/pasta/resumo_impressao.html.twig:1-90, 232-243`
- JS:
  - `public/js/pasta-show.js` (inteiro)
  - `public/js/pasta-timeline.js:74-367`
  - `public/js/pasta-clientes.js:180-240, 395-420`
- CSS:
  - `public/css/pasta-show.css:1982-2045, 4112-4180, 4415-4440, 4705-4800` (busca por `@media`, `dark` e `border-radius`)
- PHP:
  - `src/Pasta/Controller/PastaTimelineController.php`, `PastaFavoritoController.php` e `PastaDuplicarController.php` (rotas e guardas)
  - `src/Pasta/Service/MontadorDaTimelineInteligente.php:25-110, 212-228` e `RegrasDaTimelineInteligente.php:25-70`
  - `src/Pasta/Service/PastaTimelineAssembler.php:171-224`
  - `src/Pasta/Entity/PastaMensagem.php` (colunas)
  - `src/Pasta/UseCase/EditarPastaUseCase.php:19-26`
  - `src/Controller/PastaController.php:1345, 1484-1510`
  - `src/Service/Audit/AuditLogSubscriber.php:25-31, 378-420`
  - `src/Entity/Notificacao.php` (campos)
  - `src/Tarefa/Controller/MetaNaListaController.php:89`
- `debug:twig`: não há função Twig de permissão por recurso, só `can_access_module`, `has_permission` e `can_administer`.

## Matriz (contagem real)

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual | 53 | 71 |
| Interações | 50 | 58 |
| Funções (persistência/backend) | 24 | 51 |
| Responsivo | 10 | 10 |
| Dark mode | 12 | 12 (só leitura de CSS, sem conferência visual) |

**Divergências: 49.** Por classe: **A 29**, **B 0**, **C 18**, **D 2**, **E 0**.
Prioridade das 29 A: P0 0 · P1 0 · P2 9 · P3 5 · P4 10 · P5 5. As C e as D estão fora dessa contagem: a prioridade delas depende da decisão.

> Nenhum P0 e nenhum P1. Não achei botão sem efeito, localStorage no lugar de persistência nem toast falso nas
> áreas auditadas. O que o desenho mostra e não existe **não é renderizado**, como manda a regra 1.4.3.

## Divergências

| ID | Elemento | Fonte (arquivo:linha) | Dim | Divergência (desenho × código) | Classe | Prior. | Evidência no código | Esf. | Proposta (se A) |
|---|---|---|---|---|---|---|---|---|---|
| PAS-001 | Nome do cliente (h1): botão direito e dica | dc:1129, 1135-1137, 6616-6618 | V/F | No desenho, o botão direito no nome abre a janela "Detalhes do cliente". Ao parar o mouse, aparece a dica "Detalhes: clique com o botão direito". No código, o h1 não tem handler nem dica; o botão direito só funciona nas linhas do trilho | A | P2 | `_cabecalho.html.twig:288-296`; `pasta-clientes.js:404-420` | P | `data-cliente-id` no `h1.ps-cab-titulo` quando há cliente. Ramo novo no `contextmenu` do `pasta-clientes.js`, que reusa `abrirJanela`. Dica em CSS. Sem migration. Teste funcional: o atributo existe só com cliente vinculado (e some sem cliente) |
| PAS-002 | Selo "ATIVO" passageiro | dc:5847-5865 (`ativoSeloMostrar`, 3,3 s) | V | Ao reativar a pasta, o desenho carimba "ATIVO" por 3,3 s. O código só retira o selo ARQUIVADO | A | P4 | `_cabecalho.html.twig:620-645` | P | Segundo modelo `data-ps-selo-ativo-modelo` no mesmo bloco `seloArquivado` e `setTimeout` no observador da pílula. Sem migration. Teste estático: o modelo existe no HTML |
| PAS-003 | Selo "0" nas abas Metas e Documentos | dc:6074-6079 | V | O desenho mostra "0". O código esconde (`{% if aba.contagem %}`). Além disso, o comentário das linhas 455-457 diz "INCLUSIVE o 0" e `mostraZero` (l.475) nunca é lido: o comentário contradiz o código | C (D-PASTA6) | — | `_cabecalho.html.twig:456, 475, 499` | P | — (corrigir o comentário de qualquer forma) |
| PAS-004 | Contagem "0" nos cartões do trilho | dc:1336 (`prazosN` sempre) | V | O desenho mostra a pílula de contagem sempre. O código a esconde com 0 em Próximos prazos, Clientes e Documentos | C (D-PASTA6, mesma família do selo "0") | — | `_dados_trilho.html.twig:25-27, 122-124, 205-207` | P | — |
| PAS-005 | Foto do cliente | dc:1127, 401-427 | V/F | O ícone é um botão com menu "Foto do cliente": carregar, ajustar, girar. No código o ícone não reage a clique | A | P2 | `_cabecalho.html.twig:286` | M | Coluna com a chave do arquivo no `Cliente` + upload pelo storage da E2 (`t/{tenant}/…`), validação de MIME e permissão de editar cliente. **Migration: sim.** Teste: UseCase (tenant, MIME), funcional cross-tenant |
| PAS-006 | Situações Suspensa e Cancelada | dc:5859-5866 (`sitOpcoes`) | F | O desenho tem 4 situações. O código tem 2 (Ativo, Arquivado) | C (D-SITUACAO) | — | `_cabecalho.html.twig:570-588` | — | — |
| PAS-007 | ⋮ "Mover para outra carteira" | dc:1100, 4328-4333 | F | Não renderizado | C (D-CARTEIRA) | — | `_cabecalho.html.twig:14-19` | — | — |
| PAS-008 | ⋮ "Link externo do Push" e botão direito na aba Push | dc:1104, 4410; README:125-127 | F | Não renderizados | C (D-LINK) | — | `_cabecalho.html.twig:14-19` | — | — |
| PAS-009 | ⋮ "Acompanhar alterações" | dc:1112, 4352-4359 | F | Não renderizado | C (D-ACOMP) | — | idem | — | — |
| PAS-010 | ⋮ Cadeado, selo do cadeado, "Pasta trancada" e "só visualizar" | dc:1108-1110, 1146-1168; README:116-123 | F | Não renderizados | C (D-MASTER) | — | idem | — | — |
| PAS-011 | Botão direito nas abas (ocultar para a equipe ou para pessoas) e indicador `bi-eye-slash` | dc:1259-1260, 4409-4419 | F | Não existe | C (D-MASTER) | — | `_cabecalho.html.twig:483-501` | — | — |
| PAS-012 | Botão Cadastro: destino | dc:1251-1255, 602-743 | F | O desenho abre um modal próprio de cadastro. O código abre a janela "Detalhes do cliente" | C (D-CAD-MODAL) | — | `_cabecalho.html.twig:511-545` | — | — |
| PAS-013 | Menu do responsável: ✓, dica do campo e chip vazio | dc:1214-1229, 2979-2985 | V | O desenho tem `bi-check2` na opção ativa, a dica "Filtrar responsável" e o chip "Sem responsável" com `bi-person-dash` e nome 14.5/700 `#6b8494`. O código usa "Filtrar…", não tem ✓, o chip usa `bi-person` e o nome fica com `text-muted`. O CSS já prevê tudo (comentário "O ✓ … e o placeholder … são do template (L1)"), falta o template | A | P4 | `_resp_menu.html.twig:16, 29-41`; `_resp_chip.html.twig:17-18`; `pasta-show.css:4112-4117, 4177` | P | O parcial é compartilhado com o Expediente: passar `contexto: 'pasta'` no include (`show.html.twig:4677`) e só então trocar o texto e incluir o ✓ (o JS do `_resp_script` liga `.ativa`). Sem migration. Teste funcional: `#pastaRespMenu .pasta-resp-busca[placeholder="Filtrar responsável"]` na pasta e "Filtrar…" no Expediente |
| PAS-014 | Itens do ⋮ sem checar a permissão do recurso | — (convenção da casa: item que o servidor recusa não aparece) | F | "Excluir pasta" e "Editar dados" aparecem para quem não tem ACTION_DELETE/EDIT. O servidor recusa (redirect com flash) | A | P3 | `_cabecalho.html.twig:166-170, 249-257`; `PastaController.php:1345, 1491` | P/M | Função Twig `pode_na_pasta(pasta, 'edit'\|'delete')` sobre `PermissionChecker::canAccessResource` e esconder os itens. Sem migration. Teste funcional: usuário sem DELETE não vê `.js-excluir-pasta` |
| PAS-015 | Imprimir resumo: a janela | dc:195-214, 4580-4621 (`impVals`) | V/F | O desenho tem a janela "Imprimir · …" com prévia, "Incluir na folha" (cabeçalho do escritório, dados, autor/data, financeiro), "Tamanho do texto" com − e +, Imprimir, Baixar PDF e Copiar texto. O código abre uma página em aba nova só com "Incluir financeiro", Imprimir e Voltar | A | P3 | `resumo_impressao.html.twig:82-85, 232-243` | M | Barra lateral na própria página: opções por `?` (o servidor já monta o `PastaResumoImpressaoOutput`), tamanho por CSS, "Copiar texto" com `textContent` da folha, "Baixar PDF" = `print()` com a nota do desenho. Sem migration. Teste funcional: a rota com as opções desligadas omite cada seção; o financeiro continua dependendo da visibilidade |
| PAS-016 | Editar dados: "Ação" só leitura ("vem do menu Processo" + Abrir) | dc:582 | F | No código a Ação é editável | C (D-META) | — | `show.html.twig:3767-3774` | — | — |
| PAS-017 | Editar dados: Tipo de documento CPF/CNPJ + documento obrigatório e validado; nome do cliente obrigatório e propagado ao Cadastro | dc:576-579, 4282-4300 | F | No código não há campo de documento, e "Nome do cliente" é o **identificador da pasta**, que não se propaga ao cadastro (decisão do dono de 01/09). Isso conflita com o desenho, em que o nome e o CPF gravam no Cadastro | C (decisão nova: o Editar dados pode alterar o cadastro do cliente?) | — | `show.html.twig:3735-3765` | — | — |
| PAS-018 | Editar dados: recusar número repetido ("Já existe outra pasta com o número N") | dc:4284 | F | No código o NUP **pode repetir** de propósito ("sync Drive: a identidade é o driveFolderId") | D (intenção original de unicidade do NUP) | — | `EditarPastaUseCase.php:25` | — | — |
| PAS-019 | Editar dados: confirmar a troca de número | dc:4292 | I | O desenho pede `confirm("Alterar o número da pasta de X para Y? Links e referências antigas…")`. O código grava direto | A | P4 | `show.html.twig:3734-3738, 3815-3870` | P | `submit` do form compara `edit_nup` com o valor inicial e pede confirmação. Sem migration. Teste: estático (sem harness JS) |
| PAS-020 | Editar dados: pílula de estado, Salvar travado e bloco "Pendências" | dc:566, 584, 588-589, 4305-4307 | V/I | O desenho tem a pílula "Alterações não salvas"/"Dados da pasta" e o Salvar desabilitado sem alteração ou com erro, com o bloco "Pendências". O código não tem nada disso. O comentário atribui ao D-META, mas o D-META do §10.3 cobre só a Ação e a meta | A | P4 | `show.html.twig:3684-3702, 3709-3790` | P | Reusar o `serializar()` que já existe (l.3826) para alternar a pílula e o `disabled` do Salvar. Erro de número vazio no bloco. Sem migration. Teste estático do markup |
| PAS-021 | Editar dados: campo Responsável com "Trocar" | dc:581, 4303 | V/I | Não existe no modal | A | P4 | `show.html.twig:3730-3790` | P | Linha só leitura com o nome e o botão "Trocar": fecha o modal e aciona `#psMenuTrocarResponsavel` (mesmo padrão `js-pe-ir`). Sem migration |
| PAS-022 | X de fechar em toda janelinha | README:145-146 | V | Menus ⋮ (pasta, prazo, observação), prioridade, situação e responsável não têm X. Fecham por Esc e clique fora | A | P4 | `pasta-show.js:126-171`; `_detalhes_obs.html.twig:127` | M | Em `popovers()` (e no menu da observação), injetar o botão X (branco sobre a tarja, cinza no fundo claro) com `data-sem-x` como saída. Sem migration |
| PAS-023 | Janelinhas arrastáveis (todas) | README:162-163 | I | Só a janela do cliente arrasta. O modal Editar dados, os menus e os drawers não | A | P5 | `pasta-clientes.js:202-221` | M | Extrair o arraste do `pasta-clientes.js` num utilitário (`data-ps-arrastar` no cabeçalho) e aplicar ao modal e às tarjas dos menus. Sem migration |
| PAS-024 | Quinas da Timeline | README:148-149; central:3402 (inline 6px) | V | O painel usa `border-radius: 6px`. A regra de quinas manda no máximo 4px em janelas. O código copiou os 6px do JS do desenho | A | P4 | `pasta-show.css:4781` | P | Trocar para 4px. Registrar o conflito entre o JS do desenho e o README |
| PAS-025 | Botão direito no registro | dc:5958 (`menu` de `regsLinha`) | I/F | O menu do desenho tem Responder, Copiar texto, Encaminhar, Destacar/Remover destaque, Editar ("· N min" ou "bloqueado" com cadeado e motivo) e Excluir comentário (idem). O código não tem `contextmenu` no Registro | A (itens Responder, Copiar, Editar, Excluir) | P2 | `_dados_anotacoes.html.twig:128-217`; `show.html.twig:2888-3000` | M | Delegação de `contextmenu` em `#timelineList`, menu `.ps-pop` com os 4 itens. Editar e Excluir chamam os mesmos botões, e o motivo do bloqueio usa o texto do `comPerm`. No toque, o mesmo menu por ⋮ ou segurar. Sem migration. Teste estático do markup + funcional da janela de 15 min (já existe) |
| PAS-026 | Destacar comentário (15 cores) e Remover destaque | dc:5953, 5976 | F | Não existe | C (D-DESTAQUE) | — | `_dados_anotacoes.html.twig:17-18` | — | — |
| PAS-027 | Encaminhar via Chat I.A (cartão, menu do registro, ⋮ do prazo e observação) | dc:1312, 5958, 6151 | F | Não existe | C (D-CONNECT) | — | `_dados_trilho.html.twig:39-43` | — | — |
| PAS-028 | Presença do autor (ponto e rótulo de status) | dc:1305-1306, 5955 | V | Não existe | C (D-CONNECT) | — | `_dados_anotacoes.html.twig:17-18` | — | — |
| PAS-029 | Etiqueta do registro (Combinado, Ligação, Atendimento, Observação) | dc:1307, 5928, 6161-6163 | F | A etiqueta só aparece nos **dados de exemplo**. O compositor do desenho não tem seletor e `regsItens` só atribui "Observação" em Detalhes. Não dá para saber quem escolhe a etiqueta nem quando. O §9 #25 a lista como autônoma, mas a fonte não sustenta isso | D | — | `PastaMensagem.php` (sem coluna) | — | — |
| PAS-030 | Responder: a caixa abre sob o cartão | dc:1316-1322, 5958 (`respOn`, Ctrl+Enter, Esc) | V/I | O desenho abre a caixa embaixo do cartão: faixa "Respondendo a NOME", textarea "Escreva a resposta…", Cancelar e Responder. O código usa o compositor de cima com a faixa "Respondendo a … · cancelar" | A | P3 | `_dados_anotacoes.html.twig:58-66`; `show.html.twig:2870-2886` | M | Montar a caixa sob `.ps-anotacao` ao clicar em Responder e postar no mesmo `pasta_enviar_mensagem` com `resposta_a`. Manter os ids da faixa que são contrato, ou migrar os testes. Sem migration. Teste: funcional do envio de resposta (já existe) + estático da caixa |
| PAS-031 | Responder a uma resposta já começa com "@Nome " | dc:5958 (`respTxt: '@'+primeiro nome`) | I | O código liga à raiz sem o prefixo | A | P5 | `show.html.twig:2870-2879` | P | Inserir uma menção real (token `PastaMencoes`) do autor da resposta. Sem migration |
| PAS-032 | Edição do registro dentro do editor | dc:1314; bj-editor.js:355-358 | V | O desenho edita no próprio editor, com o rodapé "Visível para a equipe · Cancelar · Salvar" à direita. O código põe `btn-success` e `btn-outline-secondary` fora do editor. Detalhes já usa o rodapé certo | A | P4 | `show.html.twig:2915-2935`; `_detalhes_obs.html.twig:412` | P | Reusar `.ps-anotacao-edicao-rodape` (CSS `pasta-show.css:4025-4046`). Sem migration |
| PAS-033 | Dica do "editado" | dc:~5975 (`editadoDica`) | V | O desenho mostra "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM". O código mostra "Editado em dd/mm/aaaa HH:MM" | A | P5 | `_dados_anotacoes.html.twig:180-182` (+ espelho `inserirMensagem`) | P | Só texto. Sem migration |
| PAS-034 | Confirmações e avisos | dc:5897-5899 (`confirmar`, `cfVals`), `gmAviso` | V/I | O desenho usa janela própria (título, trecho do texto, botão "Excluir", Enter e Esc) e toast. O código usa `window.confirm` e `alert` | A | P4 | `show.html.twig:2953, 2975, 2981, 2997`; `_detalhes_obs.html.twig:493` | M | Um `confirmarPs()` e um `avisarPs()` comuns em `pasta-show.js`. Sem migration |
| PAS-035 | Excluir ou editar comentário a qualquer tempo (Master/primário) | README:194-198; dc:5886-5893 | F | O código só deixa o autor, nos 15 min | C (D-MASTER) | — | `_dados_anotacoes.html.twig:145` | — | — |
| PAS-036 | Iniciais do avatar | dc:~5975 (`slice(0,2)`) | V | O desenho usa as duas primeiras palavras. O código usa o primeiro e o último nome (Registro e Detalhes) | C (D-PASTA6, item T8) | — | `_dados_anotacoes.html.twig:139-141`; `_detalhes_obs.html.twig:67-69` | — | — |
| PAS-037 | Lixeira e "· N min" sempre visíveis no cartão | dc:1309 (só o lápis, com dica "Editável por mais N minutos") | V | O desenho exclui pelo botão direito (PAS-025). O código mostra a lixeira e o texto "· N min" na linha | A (depois da PAS-025) | P5 | `_dados_anotacoes.html.twig:146-167` | P | Com o menu da PAS-025 pronto, tirar a lixeira e o texto da linha e levar os minutos para a dica do lápis. Não fazer antes: a exclusão sumiria |
| PAS-038 | ⋮ do prazo: "Alertar <primeiro nome>" | dc:6151 | F | Não renderizado, e o comentário diz "não há back-end". Mas `tarefa_alertar` existe (1 por hora, mesma guarda do concluir) | A | P2 | `_dados_trilho.html.twig:39-43`; `MetaNaListaController.php:89`; `_metas.html.twig:130` | P | Item com o mesmo `<form>` POST do sino das Metas (CSRF e retorno iguais). Sem migration. Teste funcional: o item existe no menu do prazo com a rota `tarefa_alertar` + os testes do alerta que já existem |
| PAS-039 | Responder nas observações de Detalhes | dc:2322, 2347-2353 | F | Não existe | A | P2 | `_detalhes_obs.html.twig:82-150` | M | `resposta_a` em `pasta_observacao_detalhes` + UseCase + Twig e espelho JS (§9 #19). **Migration: sim.** Teste: UseCase (pai da mesma pasta e tenant, IDOR) + funcional |
| PAS-040 | Botão direito na observação | dc:2316 (`onContextMenu`) | I | No código só o ⋮ abre o menu | A | P4 | `_detalhes_obs.html.twig:122-131, 299-320` | P | `contextmenu` delegado em `#detalhesObsLista` abre o mesmo `.ps-obs-menu`. Sem migration |
| PAS-041 | Itens do ⋮ da observação | dc:6465-6466 (`extrasObs`), 5958 | F | O desenho tem "Citar no novo registro", "Criar meta a partir deste registro", "Imprimir" e também Responder, Editar e Excluir com os minutos. O código tem só "Copiar texto", e o comentário diz que Citar não existe. O editor já tem `blockquote` (`editor-rico.js:127, 138`) | A | P2 | `_detalhes_obs.html.twig:119-131` | M | Citar: inserir `<blockquote>` no Quill de `#detalhesObsConteudo`. Criar meta: abrir o modal Nova meta com a descrição preenchida. Imprimir: folha simples. Sem migration. Teste estático + funcional do Nova meta, que já existe |
| PAS-042 | "Pontos do relatório inicial" | dc:2389-2397 | F | Não existe | C (D-RELINI) | — | `_detalhes_obs.html.twig` | — | — |
| PAS-043 | Timeline: "Enquanto você estava fora" | central:3398, 3418-3419, 3449 | F | O desenho mostra "N acontecimento(s)" desde a última visita, por categoria, com "Ver resumo" a partir dali. O código omite: falta guardar a visita por usuário | A | P2 | `_timeline_inteligente.html.twig:13-15` | M | Tabela `pasta_timeline_visita` (usuario, pasta, tenant, vista_em), gravada pelo POST ao fechar (`fecharPainel`). O montador devolve `novosDesde` e `resumoDesde` (eventos de outras pessoas). **Migration: sim.** Teste: unit `RegrasDaTimelineInteligente` (só eventos depois da visita, sem os próprios), funcional cross-tenant |
| PAS-044 | Timeline: detecção por regras (tarefa, telefone/e-mail, CNJ) | central:3356-3361, 3383-3384, 3435-3438 | F | O desenho tem os cartões "Possível tarefa identificada" (Criar tarefa / Ignorar), "Nova informação cadastral" (Validar atualização / Não é do cliente) e "Processo citado" (Ok), com estado guardado e auditado. O código omite | A | P2 | `_timeline_inteligente.html.twig:8-11` | G | Regex do desenho em serviço puro sobre o texto das mensagens e observações. Tabela de estado da sugestão (chave evento:índice, situação, por, em, tenant). "Criar tarefa" abre Nova meta preenchida, "Validar" só registra (o desenho diz "ainda precisa ser gravado no Cadastro"). **Sem ✦/IA** (regra 1.4.3). **Migration: sim.** Teste: unit das regex (a de e-mail não pega `@bluejus.`), funcional cross-tenant e da transição de estado |
| PAS-045 | Timeline: "Ver alteração / Ocultar alteração" (Antes e Depois) | central:3441, 3444 | F | O código omite. `PastaMensagem` não guarda o texto anterior. O `audit_log` guarda from/to, mas **cortado em 500 caracteres**, e o texto vai até 5000 | A | P3 | `PastaMensagem.php:40-47`; `AuditLogSubscriber.php:25, 382-398` | M | Coluna `conteudo_anterior` em `pasta_mensagem`, gravada pelo `EditarMensagemPastaUseCase`. Alternativa sem migration: ler o `audit_log` e avisar quando cortado. **Migration: sim** (opção recomendada). Teste: UseCase grava o anterior e o montador expõe `antes` |
| PAS-046 | Timeline: fontes Detalhes e Financeiro | central:3369-3372 | F | O desenho mostra "Registro em Detalhes" e "Registro em Financeiro" (Financeiro só se a aba é visível). O montador só lê mensagens de Dados, audit_log, Push, metas e pagamentos | A | P2 | `MontadorDaTimelineInteligente.php:29-33, 212-225` | P | Duas fontes: `PastaObservacaoDetalhesRepository` e `PastaObservacaoFinanceiraRepository` (esta atrás de `VisibilidadeDoFinanceiroDaPasta`), presas a tenant e pasta e com o mesmo teto. Sem migration. Teste: unit do montador + funcional (quem não vê o financeiro não recebe a fonte) + cross-tenant |
| PAS-047 | Timeline: notificações ligadas à pasta | central:3381 | F | O desenho põe as notificações do usuário sobre a pasta (crítico/importante, "pendente"). O código não inclui | A | P3 | `MontadorDaTimelineInteligente.php:53-110`; `Notificacao.php:53-82` | M | Fonte nova: notificações **do usuário logado** e do tenant com `tarefa.pasta = P` ou `url` da pasta. Pendente = não lida. Sem migration. Teste: só as do próprio usuário, cross-tenant |
| PAS-048 | Timeline: mensagens e ligações do Chat, "Ver conversa" | central:3374-3380, 3439 | F | Não existe | C (D-CONNECT) | — | `_timeline_inteligente.html.twig:8-9` | — | — |
| PAS-049 | Timeline: fundo escurecido | central:3402 (painel sem overlay) | V | O código põe overlay `rgba(12,32,45,.24)` atrás do painel | A | P5 | `pasta-show.css:4760-4769` | P | Overlay transparente que mantém o clique-fora. Sem migration |

## Itens F (agrupados, com evidência)

**Cabeçalho, linha 1 (12):**
- Voltar
- "PASTA nnnn" como itens do inline-flex (`_cabecalho:41-46`)
- Pílula e menu de prioridade com ✓ e POST (`:51-73`)
- Etiquetas coloridas, que abrem o modal de marcadores (`:77-97`; `pasta-show.js:286-292`)
- "+" tracejado
- Setas ‹ › inertes na ponta e "N de M" (`:106-140`)
- Botão ⋮
- Tarja "Pasta nnnn" (`:163-164`)
- Atalhos E e H (`pasta-show.js:268-281`) e T (`pasta-timeline.js:98-108`), com o menu aberto
- Separadores e seções Compartilhar e Preferências

**⋮ (11):**
- Editar dados
- Histórico (drawer)
- Timeline inteligente
- Duplicar: confirm com o texto do desenho; servidor com tenant + VIEW + módulo + CSRF (`PastaDuplicarController:53-79`)
- Vincular processo
- Trocar responsável (`pasta-show.js:221-228`)
- Copiar link, com "Link copiado"
- Imprimir resumo: rota com tenant + VIEW (`PastaResumoController:63-69`)
- Fixar nos favoritos: interruptor espelha a resposta do servidor; UseCase confere VIEW (`_cabecalho:596-618`; `PastaFavoritoController:46-60`)
- Arquivar/Desarquivar
- Excluir digitando o número, conferido **no servidor** (`PastaController.php:1503-1507`)

**Cabeçalho, linhas 2 e 3 (14):**
- Título do cliente (cadastro ou identificador)
- "Movimentado hoje, HH:MM" com ponto pulsante
- Documento formatado
- Intelligence, só com `modules.inteligencia.view` (`:323-335`)
- Selo carimbado ARQUIVADO, que entra e sai sem recarregar (`:346-355, 620-645`)
- Faixa Processo/Ação/Responsável, com "trocar" e "vincular"
- Chip "N vinculados" (sem tipo de apenso: 0 em prod)
- Ação "Extraído do processo vinculado"
- Chip do responsável, avatar 28px
- Abas com indicador deslizante e modo compacto (`pasta-show.js:31-87`)
- Linha de pendência vinda do servidor
- Botão Cadastro com ponto verde/âmbar (`cliente_pendencias`)
- Pílula e menu de Situação, com ✓ e tarja
- Faixa "Pasta excluída", somente leitura

**Editar dados (6):**
- Moldura de 720px, cabeçalho em gradiente, círculo e subtítulo (`show.html.twig:3706-3725`)
- Abas de atalho Cliente/Processo/Histórico, que fecham e abrem o destino, com confirmação de descarte (`:3726-3731, 3815-3870`)
- Rótulos em caixa alta
- Rodapé Fechar · Cadastro do cliente · Salvar
- Esc fecha e Enter envia (nativos)
- Situação no modal: extra do sistema, mantido pela regra "nenhuma função some"

**Drawer do Histórico (4):**
- Cabeçalho com subtítulo "N alterações…" e "×" com moldura
- Dia e item com divisória
- Hora embaixo do texto
- Esc e overlay fecham (`pasta-show.js:91-125`)

**Registro (16):**
- Título e contagem com a dica "Registros principais (respostas não contam)"
- Compositor com "Visível para a equipe" e Enviar (Ctrl+Enter)
- Linha do tempo com pílula do dia, ponto e seta
- Cartão com avatar ou foto, autor e hora
- Responder visível só no hover/foco, sempre visível sem hover (`pasta-show.css:1481-1493`)
- Respostas recuadas, faixa "Resposta a NOME" e "Resposta a uma mensagem excluída"
- Hora "dd/mm · HH:MM" quando a resposta é de outro dia
- Janela de 15 min (D-15MIN) em editar e excluir, no servidor e na tela
- "editado"
- @menção com autocomplete e notificação no envio e na edição (`EditarMensagemPastaUseCase:22-90`)
- Notificação ao autor respondido (`EnviarMensagemPastaUseCase:27-34`)
- Âncora `#pasta-msg-N`, que abre os anteriores e a aba (`pasta-show.js:328-370`)
- "Ver anotações anteriores (N)"
- Estado vazio
- Texto sanitizado
- `PastaMensagem` `Auditavel` + `TenantAware`

**Trilho e prazos (7):**
- Cabeçalho PJe com chevron para a aba
- Linha com pip e selo
- ⋮ com "Ver nas metas", ".ics" (data da meta com ano) e "Copiar" (`_dados_trilho:44-87`)
- Botão direito na linha abre o ⋮ (`pasta-clientes.js:412-419`)
- Documentos do trilho com ícone 30px do tipo, abrindo o visualizador
- Vazios

**Detalhes/observação (6):**
- Compositor "Adicionar observação…"
- Linha do tempo
- Janela de 15 min
- Edição com o rodapé do editor
- "continuar lendo" (`pasta-ler-mais.js`)
- ⋮ com "Copiar texto", com o mesmo título "Autor · data" do desenho

**Timeline inteligente (16):**
- Painel à direita, 16px das bordas, até 560px
- Cabeçalho em gradiente com subtítulo Pasta · cliente · processo e fechar (Esc)
- Busca com debounce
- Sinônimos e palavras vazias do `TL_SIN` (`RegrasDaTimelineInteligente:54-66`)
- Chips "Tudo", "⚠ Pendências" e por categoria, com contagem
- Período (5 opções)
- Pessoa
- "Resumir atividade" por regras
- "⚠ Precisa de atenção" (até 8)
- Dia em caixa alta
- Agrupamento "N … · toque para ver"
- Cartão com bola, prioridade e "editado"
- "Ver tudo / Mostrar menos" acima de 160 caracteres
- Link para a aba sem recarregar
- Vazio "Nada encontrado para …"
- Servidor com tenant + VIEW + `no-store` (`PastaTimelineController:52-66`), financeiro só para quem vê, renderização só com `textContent`

**Divergências aprovadas (contam como F):**
- Sem chip "✦ IA", sem ✦ no resumo e ícone `bi-list-ul` em "Resumir atividade": regras 1.4.3 e 1.4.5 + D-IA.
- Rodapé "Montada por regras fixas…" e "Carregar mais": convenção da casa.

**Responsivo (10):**
- Linha 1 quebra
- Título 22px
- Botões só com ícone, exceto os largos (T3)
- Drawer a 100%
- Abas rolam
- Grade de 980px
- Modal a 420px
- Responder sem hover
- Timeline `min(560px, 100vw - 32px)`
- Menus com `max-width: calc(100vw - 40px)` (`pasta-show.css:1982-2011, 4115-4122, 4346, 4415-4418, 4771-4781`)

O desenho não define layout abaixo de 980px para a Pasta, então vale a convenção da casa.

**Dark mode (12), por tokens com par escuro:**
- Cabeçalho, selo e abas (`pasta-show.css:207, 865, 1038, 1142`)
- Compositor e Registro (`:1282, 1443`)
- Vazios (`:1568`)
- Trilho e vincular (`:1707-1753`)
- Drawer (`:1970`)
- Interruptor de favoritos (C7, `:174`, `:4180`)
- Menu do responsável (tokens `.ps-body`)
- Timeline, com bloco escuro próprio (`:4736-4758`)
- Modal Editar dados (tokens `--ps-accent`)

**Quinas:**
- Tudo ≤ 4px na área, exceto a Timeline (PAS-024).
- O `_resp_estilo` (12/9/8px) é sobrescrito na Pasta por `.ps-body .pasta-resp-*` (`pasta-show.css:4119-4160`).

## Observações (riscos, conflitos Designer × sistema, dúvidas para o Farlei)

1. **Comentários desatualizados no código**, que já enganaram o ledger:
   - `_cabecalho.html.twig:455-457` afirma que o "0" aparece nas abas. O código o esconde (`:499`) e `mostraZero` (`:475`) é letra morta.
   - `_dados_trilho.html.twig:39-43` diz que "Alertar" não tem back-end. `tarefa_alertar` existe.
   - `_detalhes_obs.html.twig:119-121` diz que "Citar" não existe. O editor tem `blockquote`.
   - `show.html.twig:3684-3702` põe no D-META itens (pílula, Salvar travado, Responsável) que o D-META do §10.3 não cobre.
2. **Etiqueta do registro (PAS-029):** o §9 #25 a dá como autônoma, mas o desenho só traz a etiqueta nos dados de exemplo e não mostra **como** ela é escolhida. Pergunta ao Farlei: quem escolhe (o autor, no compositor?), quais valores e se é obrigatória.
3. **NUP único (PAS-018):** o desenho recusa número repetido e o sistema aceita de propósito por causa do sync do Drive. Pergunta ao Farlei: o NUP deve ser único por escritório? A resposta muda o `EditarPastaUseCase` e exige olhar os dados de produção antes.
4. **Editar dados × Cadastro (PAS-017):** o desenho trata "Nome do cliente + CPF" da pasta como o cadastro, e o dono decidiu em 01/09 que `nome_cliente` é o identificador da pasta. Por isso é decisão, não ajuste.
5. **Detecção na Timeline (PAS-044):** a regex de telefone e e-mail extrai PII de texto livre para um cartão. Basta mostrar o que já está no registro, sem gravar o valor fora do texto de origem, e a tabela de estado deve guardar só a chave e a situação. Não apresentar como IA.
6. **"Ver alteração" (PAS-045):** o `audit_log` parece suficiente, mas corta em 500 caracteres. Mostrar "Antes" truncado sem avisar seria dado errado com cara de certo. Por isso a proposta é a coluna.
7. **Botão direito no toque:** as PAS-025 e PAS-040 precisam de caminho por ⋮ ou toque longo (o explorador já faz isso: `pasta-explorador.js:1828-1841`), senão a função fica fora do alcance no celular.
8. **Conflito no pacote:** o JS da Timeline (`central:3402`) usa raio de 6px e o README de Quinas manda 4px. Recomendo 4px (PAS-024), porque o README é a regra transversal mais recente.
9. **PAS-037 depende da PAS-025:** tirar a lixeira do cartão antes do menu do botão direito apagaria o único caminho de excluir.
10. A captura `screenshots/pasta-menu.jpg` é da Pasta 1A (fora do escopo §1.2) e não serve de referência.
