# 02. Catálogo de interações visíveis e ocultas

> Auditoria integral da `pasta_show` contra o protótipo ONLINE `02 - EXPEDIENTES 1.2.3.dc.html` (baixado em 16/10/2026), worktree `fidelidade-designer` @ `9c081725`. Somente leitura. Índice e resumo em `00-LEIAME.md`. "dc:N" = linha do protótipo online sem os blocos injetados pelo serviço de pré-visualização.

Índices transversais dos dois lados: handlers atribuídos (clique, duplo clique, botão direito, teclado, eventos delegados e componentes criados dinamicamente), modais, menus ⋮ e de botão direito, confirmações com texto exato, atalhos de teclado, chaves de localStorage do protótipo (cada uma representa um dado que no BlueJus exigiria backend real) e as rotas que a tela do BlueJus chama.

# Parte 1. Designer: handlers e chaves das áreas A


### Lista de handlers atribuídos

Manipuladores encontrados por grep (`onContextMenu|onDoubleClick|onDblClick|onKeyDown|addEventListener|gmAbrir|cf|confirm(|prompt(|autoReg|localStorage`) e atribuídos às áreas A1 a A6. Ocorrências de outras áreas (Detalhes, Documentos, Push, IA, editor) aparecem marcadas "fora do escopo A".

#### Template (dc)

| Linha | Handler | Atribuição |
|---|---|---|
| 82 | `onKeyDown nm.tecla` | PZ-MOD-02 (Nova meta) |
| 83 | `onDoubleClick nm.recolher` (+ `onPointerDown nm.arrastar`) | PZ-MOD-01 |
| 185 | `onKeyDown nm.teclaBusca` | PZ-MOD-02 (busca de responsáveis) |
| 259-260 | `onContextMenu dest.fechar`, `onKeyDown dest.tecla` | PZ-DAD-13 (seletor de destaque) |
| 289 | `onKeyDown pvVals.tecla` | PZ-MOD-08 (Vincular processo) |
| 355 | `onKeyDown cf.tecla` | PZ-MOD-09 |
| 363 | `onContextMenu gm.fecharCtx` | PZ-CAB-N11 / PZ-CTX-18 |
| 373 | `onKeyDown vs.tecla` | fora do escopo A (visualizador) |
| 422 | `onKeyDown mk.tecla` | PZ-MOD-11 |
| 486 | `onPointerDown ft.arrastar`, `onWheel ft.roda` | PZ-CAB-N07 |
| 577 | `onKeyDown pl.tecla` | PZ-MOD-14 |
| 634 | `onKeyDown pe.tecla` | PZ-MOD-15 |
| 675 | `onKeyDown cq.tecla` | PZ-MOD-N15 (Cadastro) |
| 760 | `onKeyDown s.editTecla` | PZ-MOD-N28 (Inteligência cadastral) |
| 873 | `onContextMenu cli.fecharCtx` (+ 875 `onPointerDown cli.arrastar`) | PZ-MOD-18 |
| 922 | `onKeyDown c.tecla` | PZ-MOD-19 |
| 1126 | `onKeyDown ia.teclaChat` | fora do escopo A (IA) |
| 1255 | `onContextMenu abrirCli` | PZ-CAB-27 |
| 1332 | `onContextMenu t.menu` | PZ-CAB-46 |
| 1390 | `onContextMenu n.menu` | PZ-DAD-16 |
| 1412 | `onKeyDown n.respTecla` | PZ-DAD-11 |
| 1465 | `onContextMenu d.menu` | PZ-TRI-04 |
| 1488 | `onKeyDown cq.vincTecla` | PZ-TRI-10 |
| 1525 | `onKeyDown d.abrirTecla` | PZ-TRI-14 |
| 1555 | `onContextMenu m.menuCtx` | PZ-MET-10 |
| 1560 | `onKeyDown m.renTecla` | PZ-MET-06 |
| 1581 | `onContextMenu mt.fecharMenuCtx` | PZ-MET-10 |
| 1706 | `onContextMenu procHdMenu` | PZ-PRO-03 |
| 1716 | `onContextMenu p.menu` | PZ-PRO-10 / PZ-CTX-07 |
| 1824 | `onContextMenu n.menu` | PZ-PRO-31 / PZ-CTX-09 |
| 1840 | `onKeyDown p.nt.notaTecla` | PZ-PRO-37 |
| 1921 | `onContextMenu n.menu` | PZ-FIN-10 a 15 |
| 1943 | `onKeyDown n.respTecla` | PZ-FIN-06 |
| 1966 | `onContextMenu a.menu`, `onDblClick a.abrir` | PZ-FIN-18, PZ-CTX-11 (duplo clique provavelmente inoperante) |
| 1975 | `onContextMenu a.menu` (no ⋮) | PZ-FIN-18 |
| 1998 | `onContextMenu fin.menu` | PZ-FIN-32 |
| 2021 | `onContextMenu p.ctx` | PZ-FIN-35 a 37 |
| 2030 | `onKeyDown p.edTecla` | PZ-FIN-34 |
| 2058 | `onKeyDown fin.formTecla` | PZ-MOD-23 |
| 2161, 2298, 2342, 2370-2394 | Documentos e checklist | fora do escopo A |
| 2449, 2484 | Detalhes | fora do escopo A |
| 2641, 2666, 2682, 2789 | Push e e-mail | fora do escopo A |
| 2910-2925 | Modal Prazo: só `onClick` (sem `onKeyDown`) [NOVO-16/10] | PZ-TRI-N10 |

#### JavaScript (Component)

| Linha | Ocorrência | Atribuição |
|---|---|---|
| 3116 | `autoReg('resp')` | PZ-CAB-07 |
| 3499 | `localStorage bj-cli-alias` [NOVO-16/10] | PZ-MOD-N13 |
| 3501 | `autoReg('cad')` ×2 | PZ-MOD-N33 |
| 3539 | `localStorage bj-reus` [NOVO-16/10] | PZ-MOD-N22 a N25 |
| 3542 | `confirm()` nativo [NOVO-16/10] | PZ-MOD-N25 |
| 3593 | `localStorage bj-fin-v1` | PZ-FIN-N01 |
| 3620, 3630, 3640 | `confirmarUI` | PZ-FIN-34, PZ-FIN-37 |
| 3627, 3683 | `gmAbrir` | PZ-FIN-35 a 37, PZ-FIN-27 a 32 |
| 3059, 3073 | `gmAbrir`, `confirmarUI`, `autoReg('proc')` | PZ-PRO-18 a 30 |
| 4473 | `confirmarUI` (troca de número) | PZ-MOD-N07 |
| 4471 | `autoReg('pasta')` | PZ-MOD-N07 |
| 4553, 4558 | `autoReg('proc')` | PZ-MOD-08 |
| 4568 | `confirmarUI` (duplicar) | PZ-CAB-14 |
| 4579 | `gMenu` direto (mover) | PZ-CAB-17 |
| 4581 | `prompt()` nativo | PZ-CAB-25 |
| 4607 | `gmAbrir` (definição) | PZ-CAB-N11 |
| 4654, 4658 | `gmAbrir` | PZ-CAB-46 |
| 4682 | `gmAbrir` | PZ-MET-07 a 09 |
| 4759 | `autoReg('fase')` | PZ-PRO-02 |
| 5201, 5263, 5408 | `confirmarUI` | fora do escopo A (Documentos, e-mail, Push) |
| 5476 | `confirmarUI` (revogar) | PZ-MOD-N12 |
| 5648, 5654 | `gmAbrir` | PZ-PRO-31 a 36 |
| 5660 | `confirmarUI` (nota) | PZ-PRO-36 |
| 6135 | `autoReg('sit')` | PZ-CAB-48 |
| 6207 | `autoReg('contrato')` ×2 | PZ-CAB-39 |
| 6211, 6219 | `cf` direto (reativar, avançar, voltar, finalizar) | PZ-CAB-39 |
| 6242-6244 | `confirmarUI`, `confirmar`, `cfVals` | PZ-MOD-09, PZ-MOD-25 |
| 6251 | `confirmar` (excluir comentário) | PZ-DAD-16, PZ-FIN-15 |
| 6303 | `gmAbrir` (menu do registro) | PZ-DAD-16, PZ-FIN-10 a 15 |
| 6342 | `cf` direto (atualizar andamento) | PZ-CAB-N08 |
| 6346 | `window.BJDatas` [NOVO-16/10] | PZ-TRI-N05 |
| 6347 | `addEventListener('storage')` (bj-pasta-docs-v1) | fora do escopo A (Documentos/Peticionar) |
| 6351 | `addEventListener('bj-presenca')` | PZ-DAD-05 |
| 6356 | `document.addEventListener` click, focusin, drop, paste, beforeinput (captura) | PZ-CAB-33 |
| 6357 | `addEventListener('bj-abrir-cadeado')` | PZ-MOD-12 |
| 6358 | `addEventListener('bj-cadeado')`, `('storage')` cadeado/destaque | PZ-CAB-32/33, PZ-DAD-13 |
| 6359 | `addEventListener('keydown', _mTec)` | PZ-CAB-N02 |
| 6359 | `addEventListener('bj-ck-previsao')` | fora do escopo A (checklist) |
| 6359 | `addEventListener('bj-navegar')` | PZ-MET-N02 |
| 6359 | `addEventListener('keydown', onBuscaG)` | PZ-MOD-10 |
| 6359 | `addEventListener('bj-enviar')`, `('bj-cancelar')` | PZ-DAD-02, PZ-DAD-08, PZ-FIN-05 |
| 6359 | `addEventListener('resize')` | PZ-CAB-43 |
| 6359 | `localStorage bj-metas-pasta-v1` | PZ-MET-N01 |
| 6361-6362 | `localStorage bj-pz-manual-v1` [NOVO-16/10] | PZ-TRI-N04, N07 a N09, N14 |
| 6403, 6404, 6409 | `autoReg('prazo')` [NOVO-16/10] | PZ-TRI-N07, N09, N14 |
| 6415-6422 | `localStorage bj-prazos-pasta-v1`, `bj-ativ-pasta-v1` [NOVO-16/10] | PZ-TRI-N06 |
| 6559 | `gmAbrir` (menu do prazo, com 3 itens novos para manuais) [NOVO-16/10] | PZ-TRI-05 a 09, N07 a N09 |
| 6709 | `cf` direto + `autoReg('proc')` + `localStorage bj-pasta-apensos` | PZ-PRO-11 |
| 6952 | `gmAbrir` (arquivo financeiro) | PZ-FIN-18 a 24 |
| 6960 | `confirmarUI` (excluir arquivo) | PZ-FIN-24 |
| 6950 | `confirmarUI` (excluir comentário em Detalhes) | fora do escopo A |
| 6984 | `autoReg('probono')` | PZ-FIN-02 |
| 6996 | `autoReg('contrato')` | PZ-FIN-01 |
| 6999 | `gmAbrir` (procHdMenu), `confirmarUI` (admSPtoggle) | PZ-PRO-02 a 06 |
| 3183-3184, 3956, 3871 | `addEventListener pointermove/pointerup` | PZ-MOD-18, PZ-MOD-01, PZ-CAB-N07 |
| 7029 | `document.addEventListener('keydown', _cliEsc)` | PZ-MOD-18 |

#### Chaves de localStorage das áreas A1 a A6

| Chave | Uso | Área |
|---|---|---|
| `bj-pasta-dados-v1` | Edição de número/cliente/documento por índice | A1.6 |
| `bj-pasta-hist-v1` | Histórico de dados, cadeado, link Push (não exibido no drawer) | A1 |
| `bj-pasta-prefs-v1` | Acompanhar, Favoritos (`usuario:N`), carteira (`cart:N`) | A1.1 |
| `bj-pastas-duplicadas-v1`, `bj-pastas-lixeira-v1` | Duplicar, Excluir | A1.1 |
| `bj-pasta-cadeado-v1`, `bj-pasta-acesso-pedidos-v1` | Cadeado e pedidos | A1.7 |
| `bj-push-links-v1` | Links externos | A1.8 |
| `bj-abas-visib-v1`, `bj-abas-visib-log` | Visibilidade de abas | A1.4 |
| `bj-marcadores:N`, `bj-marcadores-catalogo` | Marcadores | A1.9 |
| `bj-foto-cliente:N`, `bj-foto-cliente:N:orig` | Foto | A1.2 |
| `bj-pasta-fases`, `bj-pasta-fases-v` | Andamento | A1.3 |
| `bj-registros-v1` | Registros e cartões de sistema | A1.3, A3.1, A6 |
| `bj-respostas-v1`, `bj-com-edit-v1`, `bj-com-destaque-v1`, `bj-itens-excluidos-v1`, `bj-comentarios-editados-log`, `bj-comentarios-excluidos-log` | Respostas, edições, destaques, exclusões | A3.1, A6 |
| `bj-clientes-v1`, `bj-cli-vinc-v1`, `bj-cli-alias` [NOVO-16/10], `bj-idint-v1`, `bj-idint-cfg` | Cadastro central, vínculos, alias, inteligência cadastral | A2, A3.4 |
| `bj-reus` [NOVO-16/10] | Réus por pasta | A2 |
| `bj-pz-manual-v1`, `bj-prazos-pasta-v1`, `bj-ativ-pasta-v1` [NOVO-16/10] | Prazos manuais, publicação de prazos e atividade | A3.3 |
| `bj-metas-pasta-v1`, `bj-metas-renomeadas-log`, `bj-metas-seq-v1`, `bj-prazo-aprendizado` | Metas | A4 |
| `bj-pasta-proc`, `bj-pasta-apensos`, `bj-pje-v2` (remove `bj-pje-v1`), `bj-push-notas-v1`, `bj-adm-sem-proc-v1`, `bj-semproc-dec-v1`, `bj-semproc-hist-v1`, `bj-semproc-envio-v1`, `bj-proc-analise-v1`, `bj-proc-ia-cache` | Processo, apensos, PJe, notas, administrativo, análise | A5 |
| `bj-fin-v1` | Financeiro | A6 |
| `bj-contas-v1` (e prefixo `bjt:<conta>:`) | Multiconta | A6 (bj-contas.js) |

---


# Parte 2. Designer: índices do arquivo inteiro


### 6. Índice de modais, janelas e drawers (arquivo inteiro)

| # | Janela | Gatilho | Conteúdo | Fecha com | Referência |
|---|---|---|---|---|---|
| 1 | Nova meta "Criar meta" (não modal, arrastável, recolhe no duplo clique) | Metas, Criar meta a partir do registro, Criar tarefa da providência, `__bjIAAcao` | contador, atalhos de prazo, calendário próprio "Escolher prazo", responsáveis, análise de prazo da IA, duplicidade | `nm.tecla` | dc:81-200 |
| 2 | Calendário "Escolher prazo" (dentro da Nova meta) | campo de prazo | grade mensal | clique | dc:100-122 |
| 3 | "Imprimir · <título>" | Imprimir (registro, resumo, relatórios) | prévia A4, opções, tamanho, Imprimir, Baixar PDF, Copiar texto | × ou fundo | dc:202-221 |
| 4 | "Análise do processo" | Ver análise completa (Push) | 16 seções Fato/Inferência/Previsão | × ou fundo | dc:225-248 |
| 5 | "Documento" | origem na análise, Abrir documento | título, sub, texto lido | × ou fundo | dc:250-256 |
| 6 | Popover "Destacar comentário" | ⋮ Destacar | 15 cores, Sem cor, aviso de destaque existente | Esc, fundo, botão direito no fundo | dc:258-267 |
| 7 | "Ocultar aba <nome>" | botão direito na aba → "Ocultar para…" | Toda a equipe, lista com OCULTA, Cancelar/Salvar | × ou fundo | dc:269-285 |
| 8 | "Vincular processo" / "Vincular apensado" | ⋮ da pasta, aba Processo | Tipo de apenso, Buscar existente, Cadastrar novo (CNJ) com abas Informações/Partes/Movimentações, Buscar no CNJ | Esc (`pvVals.tecla`), × ou fundo | dc:287-351 |
| 9 | Confirmação `cf` | 20+ ações (ver §8) | título, texto, Cancelar, ação | Esc, Enter confirma, fundo | dc:353-360 |
| 10 | Menu genérico `gm` | `gmAbrir` | título, itens, separadores | clique ou botão direito no fundo | dc:362-370 |
| 11 | Visor "Visualizar arquivo" | Documentos, Financeiro, IA | ver PZ-VIS | Esc | dc:372-400 |
| 12 | "Busca rápida" | Ctrl+K | campo, resultados, rodapé de atalhos | Esc, fundo, Ctrl+K | dc:403-418 |
| 13 | "Marcadores da pasta" | cabeçalho | busca, submarcadores, Cancelar/Salvar | `mk.tecla`, fundo | dc:420-469 |
| 14 | Menu da foto do cliente | avatar | Carregar/Ajustar/Remover foto | fundo | dc:470-480 |
| 15 | "Ajustar foto" | menu da foto | recorte/zoom | botões | dc:481-503 |
| 16 | "Cadeado da pasta" | ⋮ Cadeado | modo, níveis por pessoa, Aplicar a todos | fundo (`mousedown`) | dc:505-548 |
| 17 | "Acessos ao link" | menu Push "Ver acessos ao link" | total, último acesso, por link: estado, acessos por dia, últimos 5 | × ou fundo | dc:549-574 |
| 18 | "Link externo do Push Processual" | ⋮ "Link externo do Push", menu da aba Push | gerar, validade, código, lista, revogar, ver como terceiro | `pl.tecla`, fundo | dc:575-631 |
| 19 | "Editar dados da pasta" | ⋮ Editar dados (E) | abas, pendências, número, cliente, CPF/CNPJ | `pe.tecla`, fundo | dc:632-668 |
| 20 | "Cadastro e qualificação do cliente" com nova aba **Réu [NOVO-16/10]** (Cliente, Réu, Endereço, Contato, Documentos, Qualificação, Histórico) | cabeçalho/cliente | Réu: nome obrigatório (maiúsculas), CPF/CNPJ opcional validado (11 ou 14 dígitos), lista com subir/remover, `bj-reus` | `cq.tecla`, fundo | dc:673-830, 3469, 3534-3548 |
| 21 | "Detalhes do cliente" (flutuante arrastável) | botão direito no nome do cliente; linha do cliente | dados, copiar, pastas do CPF, contatos | Esc (`_cliEsc`), fundo, botão direito no fundo | dc:872-930, 3152-3200, 6039, 7029 |
| 22 | Drawer "BLUEJUS IA" | BLUEJUS Intelligence | ver §4 | × ou fundo (sem Esc) | dc:936-1138 |
| 23 | Menu Prioridade | cabeçalho | opções | clique | dc:1157-1180 |
| 24 | Menu Responsável | cabeçalho, ⋮ Trocar responsável | busca, lista, vazio | fundo | dc:1185-1210 |
| 25 | Menu ⋮ da pasta | botão "Mais ações" | ver §7 | fundo, Esc | dc:1216-1245 |
| 26 | Menu "Situação da pasta" | selo de situação | opções com descrição | fundo | dc:1352-1366 |
| 27 | Menu da meta (⋮/botão direito) | linha da meta | ver §7 | fundo | dc:1580-1587 |
| 28 | Drawer "Relatório da meta" | clique na meta | relatório, renomear, sino | fundo | dc:1589-1633 |
| 29 | "Adicionar pagamento" | Financeiro | tipos, base, juros, prévia | `fin.formTecla`, fundo | dc:2056-2140 |
| 30 | Menus Organizar e Visualizar | Documentos | ver PZ-DOC-04/05 | fundo | dc:2168-2202 |
| 31 | Menu de contexto do explorador | botão direito | ver PZ-DOC-15 a 19 | fundo, Esc | dc:2393-2403 |
| 32 | Menu ⋮ da análise IA do Push (inline) | ⋮ | Editar, lido, Criar tarefa, Excluir | clique | dc:2571-2578 |
| 33 | "Enviar ao cliente" | Push | ver PZ-PUSH-33 | ×, Cancelar, fundo | dc:2826-2863 |
| 34 | Drawer "Histórico do sistema" | Ver histórico, H | 6 eventos estáticos | ×, fundo | dc:2879-2907 |
| 35 | "Novo prazo ou audiência" / "Editar prazo" **[NOVO-16/10]** | "+" em Próximos prazos; Editar do menu do prazo | tipo Prazo/Audiência, DESCRIÇÃO, DATA, HORA (opcional ou obrigatória), RESPONSÁVEL, nota "Prazos do Push processual e das Metas entram sozinhos nesta lista.", erro "Informe a descrição e a data", Cancelar/Salvar | ×, Cancelar, fundo (z 9700) | dc:2910-2925, 6405-6411 |
| 36 | Despacho via Chat I.A | todo "Encaminhar via Chat I.A" | ver PZ-IA-29 | Cancelar | bluejus-central.js:6218 |
| 37 | Tela de contas (`BJContas.tela`) | sem gatilho no 02 | Minha conta, Abrir conta, Trocar conta | Esc | bj-contas.js:89-131 |
| 38 | Janela do Assistente de pastas | lançador (só na Lista 03) | ver PZ-IA-N02 | ×, Esc | bj-agente.js:186-211 |
| 39 | Menu de contexto e popovers do editor | dentro do editor | ver PZ-EDT | Esc, rolagem, clique fora | bj-editor.js:435-503, 693-762 |
| 40 | Lista de @menção | "@" | até 7 pessoas + Todos | Esc, clique fora, rolagem | bluejus-central.js:5932-5955 |

### 7. Índice de menus ⋮ e de botão direito (arquivo inteiro)

| Menu | Gatilho | Itens | Referência |
|---|---|---|---|
| ⋮ da pasta ("Mais ações") | clique | Editar dados (E), Histórico (H), Timeline inteligente (T, atalho não tratado), Duplicar pasta, Vincular processo, Trocar responsável, Mover para outra carteira; Compartilhar: copiar link, Link externo do Push (selo de ativos), Imprimir resumo; Acesso · Primário: Cadeado; Preferências: Acompanhar alterações, Fixar nos favoritos; Arquivar/Reativar pasta; Excluir pasta (`prompt` com número) | dc:1215-1245, 4564-4588, 7038-7040 |
| Mover para outra carteira (submenu `gm`) | item Mover | Contencioso cível, Condominial, Família e sucessões, Trabalhista, Previdenciário, Empresarial (atual com ✓); grava `bj-pasta-prefs-v1` `cart:<n>` | dc:4576-4579 |
| Botão direito na aba | `onContextMenu` da aba | primário: Ocultar para toda a equipe, Ocultar para…, Mostrar para todos (+ itens de link na aba Push); demais na aba Push: Copiar link externo ativo, Ver acessos ao link, Gerar link externo / "Link externo indisponível" | dc:1332, 4632-4663 |
| Botão direito no nome do cliente | `onContextMenu` | abre "Detalhes do cliente" | dc:1255, 7029 |
| Registro (Dados, Financeiro, Detalhes) | ⋮ ou botão direito no cartão | Responder, Copiar texto, Encaminhar via Chat I.A, Destacar, Remover destaque, extras (Detalhes: Citar no novo registro, Criar meta a partir deste registro, Imprimir), Editar, Excluir comentário | dc:1390, 1921, 2449, 6301-6303 |
| Linha de "Próximos prazos" **[NOVO-16/10 nos 3 primeiros]** | botão direito | se manual: Marcar como cumprido, Editar, Excluir (sem confirmação), separador; sempre: Ver nas metas, Encaminhar via Chat I.A, Alertar <nome>, Adicionar à agenda (.ics), Copiar | dc:1465, 6559 |
| Meta | ⋮ ou botão direito | Abrir relatório, Marcar como concluída / Reabrir meta, Editar nome, Duplicar, Encaminhar via Chat I.A, Copiar link, Abrir em Metas, Excluir | dc:1555, 3730-3740 |
| Sino da meta | clique | lista de responsáveis e demais, "Alertar para verificar" | dc:4673-4683 |
| ⋮ do cabeçalho de Processos | clique ou botão direito | Relatório geral de processos, Enviar à Controladoria agora, Marcar como administrativo sem processo (confirmação azul) | dc:1706, 6999 |
| Cartão de processo | botão direito / ⋮ | Adicionar nota técnica, Abrir no PJe, Ver todas as informações/Mostrar menos, Editar dados do processo, Atualizar dados do PJe, Ver movimentações (Push), Copiar número, Copiar resumo do processo, Compartilhar, Encaminhar via Chat I.A, Relatório geral de processos, Imprimir resumo, Desvincular da pasta (confirmação azul) | dc:1716, 3055-3074 |
| Nota técnica (Processo e Push) | botão direito ou ⋮ | Responder com outra nota, Copiar texto, Encaminhar via Chat I.A, Editar, Excluir | dc:1824, 2666, 5654-5660 |
| Cartão do Push | botão direito | Adicionar nota técnica, Criar tarefa, Encaminhar via Chat I.A, Marcar como lida, Abrir documento, Copiar texto | dc:2641, 5639, 5648 |
| ⋮ da análise IA do Push | clique | Editar, Marcar como lido/não lido, Criar tarefa da providência, Excluir análise | dc:2570-2578 |
| Lançamento financeiro | botão direito | Corrigir valor (confirmação azul), Excluir lançamento (confirmação vermelha) | dc:3627-3640 |
| ⋮ dos Pagamentos | clique ou botão direito | Adicionar pagamento, Editar ou corrigir valores, Copiar resumo | dc:1998, 3683-3688 |
| Linha de pagamento | botão direito | (ver parte A) | dc:2021 |
| Arquivo do Financeiro | ⋮, botão direito; duplo clique (provável inoperante) | Abrir, Baixar, Copiar nome do arquivo, Encaminhar via Chat I.A, Enviar por e-mail ao cliente, Ver em Documentos, Excluir (confirmação vermelha) | dc:1966, 1975, 6952-6960 |
| Explorador: item, vários, fundo | botão direito | ver PZ-DOC-15, 18, 19 | dc:5047-5084 |
| Organizar / Visualizar | clique | ver PZ-DOC-04, 05 | dc:2166-2202 |
| Foto do cliente | clique | Carregar/Trocar foto, Ajustar foto, Remover foto | dc:471-478 |
| Situação da pasta | clique | opções radiais | dc:1352-1366 |
| Editor rico | botão direito | ver PZ-EDT-15 | bj-editor.js:693-762 |

### 8. Confirmações (texto exato)

| Ação | Mecanismo | Texto | Ref |
|---|---|---|---|
| Excluir comentário | `cf` vermelha | "Excluir o comentário de X?" / "<quando> · "<trecho>". Ele some para toda a equipe e a exclusão fica registrada no histórico." / "Excluir" | dc:6251 |
| Atualizar andamento | `cf` azul | "Atualizar andamento da pasta?" / "Registro salvo na fase "X". Este registro conclui a fase e a pasta deve avançar para "Y"?" / "Avançar para Y" e "Manter em X" | dc:6342 |
| Avançar/Voltar/Finalizar fase | `cf` azul | "Avançar fase da pasta" etc. / "Mudar de "A" para "B"? A mudança fica registrada no Registro dos expedientes com data e hora." (+ aviso de fases puladas) | dc:6219 |
| Reativar pasta | `cf` azul | "Reativar pasta" / "A pasta está finalizada. Reativar abre duas novas etapas…" | dc:6211 |
| Excluir arquivos sugeridos | `cf` vermelha | "Excluir N arquivo(s) sugerido(s)? Você pode desfazer logo em seguida." | dc:5201 |
| Remover e-mail do cliente | `cf` vermelha | "Remover <e-mail> dos avisos ao cliente?" | dc:5263 |
| Excluir análise IA | `cf` vermelha | "Excluir esta análise da IA? As movimentações oficiais não são alteradas." | dc:5408 |
| Excluir nota técnica | `cf` vermelha | "Excluir esta nota técnica?" | dc:5660 |
| Revogar link | `cf` azul | "Revogar este link? Quem tiver o endereço perde o acesso na hora." | dc:5476 |
| Duplicar pasta | `cf` azul | "Duplicar a pasta N? A cópia leva cliente, ação, responsável e checklist; documentos, metas e financeiro não são copiados." | dc:4568 |
| Alterar número da pasta | `cf` azul | "Alterar número da pasta" / "Alterar o número da pasta de A para B? Links e referências antigas passam a apontar para o novo número." | dc:4473 |
| Desvincular processo | `cf` azul | "Desvincular o processo N da Pasta P?" / "Os dados do processo continuam salvos e você pode vincular de novo." | dc:3073 |
| Desvincular apensado | `cf` vermelha (rot "Desvincular") | "Desvincular apensado" / "Desvincular o apensado N da pasta? O registro fica no histórico." | dc:6709 |
| Corrigir valor | `cf` azul | "Corrigir "desc" de R$ A para R$ B?" / "A alteração fica registrada no histórico com seu nome, data e hora." | dc:3620 |
| Excluir lançamento | `cf` vermelha | "Excluir lançamento" / "Excluir "desc" (R$ v)?" | dc:3630, 3640 |
| Excluir arquivo do Financeiro | `cf` vermelha | "Excluir o arquivo "X" do Financeiro?" | dc:6960 |
| Marcar administrativo | `cf` azul | "Marcar como administrativo" / "A Pasta N tem o processo P vinculado. Marcar mesmo assim como "Administrativo sem processo"?…" | dc:6999 |
| Remover réu **[NOVO-16/10]** | `confirm()` nativo | "Remover <nome> dos réus desta pasta?" | dc:3542 |
| Excluir pasta | `window.prompt()` | "Excluir a pasta N (cliente)? Essa ação vai para a lixeira por 30 dias e fica registrada no histórico. Para confirmar, digite o número da pasta:"; erro "Número não confere. A pasta não foi excluída." | dc:4581 |
| Ações da IA (providências, fase, tarefa, prazo, revisão) | confirmação interna do drawer | "Confirma esta ação?" + texto da ação + Cancelar/Confirmar | dc:1110-1117 |
| Desativar checklist | painel embutido | escolha obrigatória de motivo | dc:2223-2239 |
| Alteração em lote do agente | plano + caixa | "Confirmo a alteração de N pasta(s)" (mais de 50 ou sensível) | bj-agente.js:151-153 |
| Excluir item do explorador, excluir prazo manual | **sem confirmação** (Desfazer no aviso para o explorador; nenhum para o prazo) | | dc:5031, 6404 |

### 9. Atalhos de teclado

| Tecla | Onde | Efeito | Ref |
|---|---|---|---|
| Ctrl/Cmd+K | global | abre/fecha a Busca rápida | dc:6444-6446 |
| ↑ ↓ Enter Esc | Busca rápida | navegar, abrir, fechar | dc:6447-6452 |
| E, H, Esc | com o ⋮ da pasta aberto | Editar dados, Histórico, fechar (T anunciado e não tratado) | dc:6922 |
| Enter, Esc | janela `cf` | confirmar, cancelar | dc:6244 |
| Esc | destaque, visor, cliente, vincular, marcadores, link, editar dados, cadastro, nova meta, pagamento, contas, agente, menções, editor | fechar | dc:4602, 4921, 6039; bj-contas.js:90; bj-agente.js:206 |
| ← → | visor | arquivo anterior/próximo | dc:4921 |
| Ctrl+A, Del, F2, Enter, Espaço, Esc, Backspace, Alt+←, Alt+↑, Ctrl+C, Ctrl+X, Ctrl+V, setas (+Shift) | explorador | ver PZ-DOC-22 | dc:5153-5181 |
| Enter, Esc | renomear no explorador | salvar, cancelar | dc:5187 |
| Esc | busca do explorador | limpar | dc:5091 |
| Enter, Esc | adicionar item ao checklist; cadastro de e-mail | adicionar, cancelar | dc:4225, 5266 |
| Ctrl/Cmd+Enter, Esc | resposta de registro; nota técnica | enviar, cancelar | dc:6300, 5652 |
| Enter | pergunta da IA | enviar | dc:5974 |
| Ctrl+Enter, Ctrl+K, Ctrl+H, Ctrl+Y, Ctrl+Z, Ctrl+B/I/U, Tab, Shift+Tab, Esc, Ctrl+Shift+V | editor | ver PZ-EDT-16 | bj-editor.js:674-692 |
| Tab, ↑ ↓, Enter, Esc | sugestão de palavras; @menção | aceitar, navegar, fechar | bj-editor.js:676-680; bluejus-central.js:5950-5951 |
| Enter (sem Shift) | agente | enviar pedido | bj-agente.js:197 |
| Enter, Espaço | arquivo do trilho de Dados | abrir | dc:3098 |

### 10. Chaves de localStorage (02 e dependências usadas pela pasta)

Observação: com `bj-contas.js`, fora da conta demo toda chave ganha o prefixo `bjt:<conta>:`.

| Chave | Conteúdo | Escreve | Lê |
|---|---|---|---|
| `bj-registros-v1` | registros por pasta (expedientes, observacoes, financeiro, sistema) | dc:6156-6158, 6221 | dc:6154 |
| `bj-respostas-v1` | respostas por chave de comentário | dc:6259 | dc:6258 |
| `bj-com-edit-v1` | edição de comentários de demonstração (10 versões) | dc:6241 | dc:6240 |
| `bj-comentarios-editados-log` / `bj-comentarios-excluidos-log` | logs (300) | dc:6241, 6255, 6950 | idem |
| `bj-itens-excluidos-v1` | chaves ocultadas (exclusão lógica) | dc:4690 | dc:4689 |
| `bj-com-destaque-v1` | destaque por mensagem | dc:4596-4598, 6303 | dc:4590 |
| `bj-pasta-fases`, `bj-pasta-fases-v` | andamento da pasta e versão de migração | dc:6156-6158, 6188, 6204 | dc:6156 |
| `bj-pz-manual-v1` **[NOVO-16/10]** | prazos/audiências manuais por pasta (com semente para 1180) | dc:6361-6362 | dc:6361 |
| `bj-prazos-pasta-v1` **[NOVO-16/10]** | prazos agregados por pasta (manual, Push, registros, metas) | dc:6422 | bj-agente.js:102; Lista 03 |
| `bj-ativ-pasta-v1` **[NOVO-16/10]** | dias com atividade por pasta | dc:6422 | Lista 03 |
| `bj-reus` **[NOVO-16/10]** | réus por pasta | dc:3539 | dc:3534 |
| `bj-cli-alias` **[NOVO-16/10]** | apelido de documento ao renomear cliente | dc:3499 | dc:3499, 6532 |
| `bj-metas-pasta-v1`, `bj-metas-seq-v1`, `bj-metas-renomeadas-log` | metas, numeração, log | dc:6424, 6482, 5340, 6676 | dc:6359, 5337 |
| `bj-push-ia`, `bj-push-ia-fech` | análises IA do Push e recolhidas | dc:5292, 5389 | dc:2936 |
| `bj-push-notas-v1` | notas técnicas por pasta e item | dc:5643 | dc:5642 |
| `bj-push-previsao-v1` | aprendizado da previsão | dc:5363 | dc:5360 |
| `bj-push-links-v1` | links externos do Push | dc:5416 | dc:5415 |
| `bj-cli-emails-v1` | e-mails do cliente, automações, envios | dc:5232 | dc:2935 |
| `bj-ck-cfg-v1`, `bj-ck-cob-v1` | checklist e cobranças | dc:4175, 4262, 4306, 4367 | dc:4173, 4275 |
| `bj-exp-dentro-v1` | conteúdo movido para subpastas | dc:5034 | dc:2934 |
| `bj-docs-colunas` | colunas do explorador | dc:3256 | dc:3250 |
| `bj-docs-favoritos-v1` | favoritos de documentos por usuário e pasta | dc:4686 | dc:4685 |
| `bj-pasta-docs-v1` | documentos do Peticionar | (outra tela) | dc:4506 |
| `bj-abas-visib-v1`, `bj-abas-visib-log` | visibilidade de abas | dc:4618, 4657, 4670 | dc:4617 |
| `bj-pasta-cadeado-v1`, `bj-pasta-acesso-pedidos-v1`, `bj-pasta-hist-v1` | cadeado, pedidos, histórico | dc:6115, 4467, 5451 | dc:6076, 4386 |
| `bj-pasta-prefs-v1` | acompanhar, favoritos, carteira | dc:4493, 4579 | dc:4490 |
| `bj-pastas-duplicadas-v1`, `bj-pastas-lixeira-v1` | duplicações e lixeira | dc:4570, 4583 | idem |
| `bj-pasta-dados-v1`, `bj-pasta-proc`, `bj-pasta-apensos` | dados editados, processo, apensos | dc:4463, 4556, 4552, 6709 | dc:4448, 4499, 4500 |
| `bj-clientes-v1`, `bj-cli-vinc-v1`, `bj-idint-v1`, `bj-idint-cfg`, `bj-foto-cliente:<n>`, `bj-marcadores:<n>`, `bj-marcadores-catalogo` | cadastro e cabeçalho (parte A) | dc:3328, 3549, 3343, 3404, 3881, 3839, 3832 | idem |
| `bj-fin-v1` | financeiro (parte A) | dc:3593 | dc:2933 |
| `bj-pje-v2` (`bj-pje-v1` removida) | dados do PJe | dc:2994 | dc:2937 |
| `bj-prazo-aprendizado` | aprendizado de prazo da Nova meta | dc:4038 | dc:4028 |
| `bj-adm-sem-proc-v1`, `bj-semproc-dec-v1`, `bj-semproc-hist-v1`, `bj-semproc-envio-v1`, `bj-paradas-envio-v1` | relatórios à Controladoria | dc:4758, 4760, 4817, 4825 | idem |
| `bjed-rascunho:<contexto>:<pathname>` | rascunho do editor | bj-editor.js:416 | bj-editor.js:362 |
| `bj-proc-ctx-v1` | memória documental por processo | bj-docsug.js | bj-docsug.js |
| `bj-agente-alt`, `bj-agente-log` | agente (não carregado no 02) | bj-agente.js:6 | idem |
| `bj-contas-v1` (+ `sessionStorage bj-como`) | contas e sessão | bj-contas.js:10 | idem |


# Parte 3. BlueJus: handlers, atalhos, botão direito, duplo clique, rotas e alertas nativos


### Handlers JS atribuídos (por arquivo)

Contagem de `addEventListener` e principais gatilhos (leitura estática).

| Arquivo | Total | keydown | contextmenu | dblclick | fetch | O que liga |
|---|---|---|---|---|---|---|
| pasta-show.js | 27 | 4 | 0 | 0 | 1 | indicador de abas, drawer Histórico (Esc), popovers `data-ps-pop` (clique fora, Esc, `ps:fechar-popovers`), X automático, arraste, `data-ps-ir-aba`, "Ver anotações anteriores", Trocar responsável, Copiar link, atalhos E/H, etiquetas, `data-ps-toggle`, acordeão do Push, `#aba` e `#pasta-msg-N` na URL |
| pasta-dialogos.js | 24 | 4 | 0 | 0 | 0 | contadores, seletor de responsáveis e calendário da Nova meta, janela de confirmação (Esc/Enter/Tab), interceptação de `submit` com `data-ps-cf`/`psConfirmarEnvio`, `avisarPs` |
| pasta-timeline.js | 16 | 2 | 0 | 0 | 2 | painel, atalho T, filtros, chips, resumo, cartões |
| pasta-impressao.js | 7 | 2 | 0 | 0 | 1 | intercepta o link "Imprimir resumo", janela, Imprimir do ⋮ da observação |
| pasta-clientes.js | 5 | 1 | 2 | 0 | 2 | Detalhes do cliente (clique e botão direito no nome e na linha), contatos, copiar, .ics, botão direito no prazo |
| pasta-clientes-busca.js | 5 | 1 | 0 | 0 | 2 | painel "Vincular cliente" do trilho |
| pasta-mencoes.js | 7 | 1 | 0 | 0 | 1 | @menção em todos os compositores |
| pasta-metas.js | 38 | 3 | 1 | 1 | 1 | filtros, atalhos de prazo, título parecido, renomear, drawer, botão direito, concluir/reabrir XHR com Desfazer, realce, moldura da Nova meta (duplo clique recolhe) |
| pasta-processo.js | 8 | 1 | 1 | 0 | 1 | ⋮ do cabeçalho e do cartão (botão direito também), copiar, compartilhar, imprimir, administrativo |
| nota-tecnica.js | 12 | 3 | 1 | 0 | 3 | compositor, edição, exclusão, ⋮ (botão direito), Ctrl+Enter/Esc |
| pasta-push.js | 15 | 1 | 2 | 0 | 2 | filtros, selo da aba, copiar ID, lida/não lida, Criar tarefa, menu de contexto |
| pasta-ia-push.js | 11 | 1 | 0 | 0 | 5 | gerar análise, polling, ações das análises |
| pasta-ia-agentes.js | 15 | 1 | 0 | 0 | 6 | drawer, agentes, polling, ações, copiar/baixar/imprimir, auditoria |
| pasta-explorador.js | 79 | 4 | 6 | 4 | 4 | explorador inteiro (busca, menus, seleção, atalhos, arraste, upload, lixeira, limpeza, painel) |
| pasta-explorador-visor.js | 21 | 1 | 0 | 0 | 0 | visor (←/→, zoom, Esc) |
| pasta-checklist.js | 21 | 4 | 0 | 1 | 11 | checklist e modelos (duplo clique renomeia no modo edição) |
| pasta-documentos-sugeridos.js | 6 | 0 | 0 | 0 | 1 | painel de sugeridos |
| pasta-ler-mais.js | 2 | 0 | 0 | 0 | 0 | "continuar lendo" |
| editor-rico.js | 30 | 7 | 1 | 0 | 0 | editor compartilhado (barra, menu de contexto, revisor, localizar) |
| visualizador-documento.js | 5 | 1 | 0 | 0 | 1 | `#previewDocModal` |
| show.html.twig (inline) | 59 | 4 | 1 | 0 | 16 | modal Adicionar cliente, clientes (principal/desvincular), processo (principal/desvincular/vincular, DataJud, Select2), registro (compositor, responder, editar, excluir, menu), editar dados, situação, prioridade, nova meta, upload |
| _cabecalho (inline) | 2 | 0 | 0 | 0 | 1 | restaurar, favorito, selo |
| _financeiro (inline) | 21 | 4 | 1 | 1 | 6 | contrato, pró-bono, honorários, arquivos, menus, botão direito, duplo clique |
| _financeiro_obs_script | 10 | 2 | 0 | 0 | 4 | relatório financeiro |
| _financeiro_pagamentos_script | 13 | 1 | 0 | 0 | 2 | pagamentos e modal |
| _detalhes_obs (inline) | 16 | 3 | 1 | 0 | 4 | observações de Detalhes |
| _resp_script | 5 | 1 | 0 | 0 | 1 | menu do responsável |

#### Atalhos de teclado (todos)
| Tecla | Onde | Efeito | Referência |
|---|---|---|---|
| E / H / T | ⋮ do cabeçalho aberto | Editar dados / Histórico / Timeline | pasta-show.js:471; pasta-timeline.js:116 |
| Esc | global | fecha popovers, drawer, Timeline, menus, janelas, visor, confirmação | vários |
| Enter / Esc / Tab | janela de confirmação | confirma / cancela / circula | pasta-dialogos.js:340-360 |
| Ctrl+Enter | compositores (Registro, Detalhes, Financeiro, notas, respostas) | envia | show:2927; obs_script:84; det:215; nota-tecnica.js:552 |
| ↑ ↓ Enter Esc | menu do responsável, @menção, busca de cliente | navega/escolhe/fecha | `_resp_script:220-256`; pasta-mencoes.js |
| Enter / Esc | renomear (meta, arquivo financeiro, explorador), honorários, contato, correção de valor | salva / cancela | |
| Enter/Espaço/Del/F2/Ctrl+A/C/X/V/Backspace/Alt+←/↑/setas/Home/End | explorador | ver PZ-DOC-22 | pasta-explorador.js:1647 |
| ← → − + Esc | visor do explorador | navegação e zoom | pasta-explorador-visor.js:449 |
| Ctrl+Z/Y/B/I/U/H, Ctrl+Shift+V, Tab/Shift+Tab | editor rico | formatação, localizar, colar texto, recuo | editor-rico.js |
| Ctrl+K | (ausente) | não há busca rápida na pasta | |

#### Botão direito (todos)
Nome do cliente no cabeçalho; linha do cliente; linha do prazo; cartão do Registro; cartão do processo e o próprio ⋮ (cabeçalho e cartão); nota técnica; linha da meta; registro do Relatório financeiro; linha de arquivo financeiro; linha de pagamento; ⋮ de Pagamentos; observação de Detalhes; cartão do Push; explorador (item, vários, fundo); editor rico; overlay da janela de cliente (fecha).

#### Duplo clique (todos)
Linha de arquivo financeiro (abre); explorador (abrir item, cabeçalho de coluna, árvore do Mover para); faixa da Nova meta (recolhe); item do checklist no modo edição (renomeia).

---

### Rotas chamadas pela tela

| Rota | Método | Quem chama |
|---|---|---|
| `expediente_index` | GET | Voltar (cab:48) |
| `pasta_show` (vizinhas, link copiado, duplicadas) | GET | setas, Copiar link, aviso de duplicado |
| `pasta_alterar_prioridade` | POST | popover de prioridade (show:3942) |
| `expediente_pasta_marcadores` | POST | modal Marcadores |
| `pasta_atualizar_responsavel` | PATCH | menu do responsável (`_resp_script`) |
| `pasta_duplicar` | POST form | ⋮ Duplicar |
| `pasta_resumo_imprimir` | GET (fetch e link) | ⋮ Imprimir resumo, janela de impressão |
| `pasta_resumo_pdf` | GET | Baixar PDF da janela de impressão |
| `pasta_favorito_alternar` | POST | ⋮ Fixar nos favoritos |
| `pasta_alternar_situacao` | POST | pílula de situação e ⋮ Arquivar |
| `pasta_delete` | POST form | ⋮ Excluir pasta |
| `pasta_restaurar` | POST form | Restaurar (lápide) |
| `pasta_edit` | POST form | modal Editar dados |
| `pasta_timeline` / `pasta_timeline_visita` | GET / POST | Timeline |
| `cliente_resumo` | GET | janela Detalhes do cliente |
| `cliente_contatos` | POST | contatos da janela |
| `cliente_edit`, `cliente_show` | GET (link) | Editar cadastro, abrir ficha |
| `pasta_cliente_novo` | POST fetch | modal Adicionar cliente |
| `pasta_clientes_buscar` | GET | modal e painel Vincular |
| `pasta_cliente_vincular` | POST | modal e painel Vincular |
| `pasta_cliente_principal` | POST fetch | estrela |
| `pasta_cliente_desvincular` | POST fetch | lixeira do cliente |
| `pasta_documento_view` | GET | trilho Documentos, visor |
| `pasta_enviar_mensagem` / `pasta_editar_mensagem` / `pasta_excluir_mensagem` | POST | Registro |
| `pasta_mencionaveis` | GET | @menção |
| `tarefa_show` | GET (link) | prazos, metas, histórico |
| `tarefa_alertar` | POST form | ⋮ do prazo, sino da meta |
| `tarefa_concluir` / `tarefa_reabrir` | POST XHR | Metas e drawer |
| `tarefa_renomear` | POST form | Editar nome |
| `tarefa_excluir` | POST form | Excluir meta |
| `tarefa_criar_para_pasta` | POST fetch | Nova meta |
| `pasta_buscar_processos` | GET (Select2) | modal Vincular processo |
| `api_datajud_search` | POST | botão DataJud |
| `pasta_vincular_processo` | POST fetch | modal Vincular processo |
| `pasta_definir_processo_principal` | POST fetch | ⋮ processo |
| `pasta_desvincular_processo` | POST fetch | ⋮ processo |
| `pasta_administrativa_definir` | POST fetch | interruptor Administrativo |
| `pasta_peticionar` | GET (nova aba) | Peticionar na pasta |
| `processo_show` / `processo_edit` | GET (link) | número, ⋮ processo |
| `processo_nota_tecnica_criar` / `_editar` / `_excluir` | POST | notas técnicas |
| `cobranca_objeto_show` | GET (link) | bloco Cobrança |
| `pasta_alternar_situacao_contrato` | POST | selo Contrato |
| `pasta_atualizar_pro_bono` | POST | selo Pró-bono |
| `pasta_atualizar_valor_causa` | POST | Honorários contratuais |
| `pasta_financeiro_observacao_enviar` / `_editar` / `_excluir` | POST | Relatório financeiro |
| `pasta_financeiro_upload` | POST | Adicionar arquivo |
| `pasta_financeiro_documento_view` / `_download` / `_renomear` / `_excluir` | GET/GET/POST/POST | ⋮ arquivo |
| `pasta_pagamento_parcelar` | POST | modal Adicionar pagamento |
| `pasta_pagamento_registrar` | POST | só declarada em `data-url-registrar` do cartão (fin:495); nenhum JS da página a lê (resíduo do modal antigo) |
| `pasta_pagamento_alternar_quitacao` / `_excluir` / `_corrigir_valor` | POST | linhas de pagamento |
| `pasta_detalhes_observacao_enviar` / `_editar` / `_excluir` | POST | Detalhes |
| `pasta_peticionar_upload` | POST | Anexar / soltar arquivos no explorador |
| `pasta_secao_criar` / `_renomear` / `_excluir` / `_mover` | POST | pastas do explorador |
| `pasta_documento_edit` / `pasta_documento_delete` | POST | Editar… / Excluir no explorador |
| `pasta_documento_mover_secao`, `pasta_documentos_mover_lote` | POST | arrastar, Mover para… |
| `pasta_documentos_excluir_lote`, `pasta_documentos_restaurar`, `pasta_documentos_lixeira` | POST/POST/GET | excluir em lote, desfazer, lixeira |
| `pasta_documentos_favorito`, `pasta_documentos_zip`, `pasta_documentos_copiar` | POST | estrela, .zip, copiar/colar |
| `pasta_documentos_reordenar`, `pasta_secoes_reordenar` | POST | ordem manual |
| `pasta_documento_download` | GET | baixar arquivo |
| `pasta_checklist_*` (adicionar, toggle, editar, excluir, reordenar, estado) | POST | checklist |
| `pasta_checklist_modelo_*` (listar GET, salvar, aplicar, renomear, excluir POST) | GET/POST | modelos |
| `pasta_push_teor` / `pasta_push_texto` | GET | acordeão e Copiar texto do Push |
| `pasta_push_lida` | POST | lida/não lida |
| `push_processual_index` / `push_processual_show` | GET (link) | ver no módulo, Abrir no módulo |
| `inteligencia_push_solicitar` / `inteligencia_push_listar` | POST/GET | Resumir com IA |
| `inteligencia_analise_status` | GET | polling |
| `inteligencia_analise_lida` / `_nao_lida` / `_excluir` / `_interna` / `_recolher` | POST | análises |
| `inteligencia_agentes_painel`, `inteligencia_agente_solicitar`, `inteligencia_agente_listar` | GET/POST/GET | drawer da IA |
| `inteligencia_analise_txt`, `inteligencia_analise_registro`, `inteligencia_auditoria_da_pasta` | GET/POST/GET | copiar, baixar, imprimir, auditoria |
| `app_profile_foto_serve` | GET | fotos dos avatares |

Rotas da lista `rotas-pasta.txt` **sem gatilho visto na pasta_show**: `pasta_documento_editar_texto`, `pasta_documento_exportar_texto`, `pasta_peticionar_texto`, `pasta_peticionar_upload_imagem`, `pasta_imagem_editor` (são do Peticionar); `tarefa_update_responsaveis`, `tarefa_atualizar_prazo`, `tarefa_mensagem*`, `tarefa_acompanhar` (são de `tarefa_show`), `kanban_*`, `cobranca_*` (outras telas), `app_sync_drive_pasta_raiz`, `push_processual_oabs*`, `push_processual_sincronizar`, `inteligencia_admin_config`.

---


### Confirmações e alertas nativos ainda presentes (contra PZ-MOD-26)

| Ação | Mecanismo | Referência |
|---|---|---|
| Excluir pasta | `window.prompt()` | cab:288 |
| Erro ao alterar prioridade | `alert()` | show:3956, 3974 |
| Erro ao alterar situação | `alert()` | show:3923, 3929 |
| Erro ao alterar responsável | `alert()` | `_resp_script:174` |
| Erro ao desvincular cliente / definir principal | `alert()` | show:1499, 1515, 1540, 1550 |
| Erro ao definir processo principal / desvincular processo | `alert()` | show:1626, 1584, 1592 |
| Falha do "Administrativo sem processo" | `alert()` | pasta-processo.js:298 |
| Erro ao excluir nota técnica | `alert()` | nota-tecnica.js:360, 373 |
| Contrato, pró-bono, renomear/excluir arquivo financeiro | `alert()` | fin:621-662, 1082-1147 |
| Relatório financeiro (vazio, erro) | `alert()` | obs_script:488-593 |
| Pagamentos (conflito, erro) | `alert()` | pag_script:179-275 |
| Toast do explorador ausente | `alert()` | pasta-explorador.js:2120 |
| Recuo quando `#psConfirmar` não existe | `confirm()` | todos os `confirmarUIPs`/`confirmarPs` |

Ações destrutivas ou de estado **sem nenhuma confirmação**: Desvincular cliente (lixeira do trilho), Marcar como recebido / Desfazer recebimento, troca de situação (Ativo/Arquivado), troca de prioridade, Contrato Pendente/Assinado, Pró-bono.

---
