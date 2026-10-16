# 01. Inventário integral da pasta_show

> Auditoria integral da `pasta_show` contra o protótipo ONLINE `02 - EXPEDIENTES 1.2.3.dc.html` (baixado em 16/10/2026), worktree `fidelidade-designer` @ `9c081725`. Somente leitura. Índice e resumo em `00-LEIAME.md`. "dc:N" = linha do protótipo online sem os blocos injetados pelo serviço de pré-visualização.

Três inventários independentes, levantados por leitura de código:

1. **Designer, parte A**: cabeçalho, cadastro do cliente, Dados e trilho (com Prazos e audiências), Metas, Processo, Financeiro.
2. **Designer, parte B**: o que o 02 carrega, Detalhes e editor, Documentos, Push, Intelligence e Chat I.A., elementos globais.
3. **BlueJus, camada de tela**: Twig, JavaScript e CSS da `pasta_show`, por área, com a lista do que só o BlueJus tem.
4. **BlueJus, backend**: leitura da página, prazos/réu/situação no domínio, testes existentes.

A matriz de comandos que gravam (segurança) está em `05-mapa-dependencias.md`; os índices de interações (menus, atalhos, modais, confirmações, localStorage, handlers) em `02-catalogo-interacoes.md`.

# Parte 1. Designer, parte A



> Fonte: versão online baixada em 16/10/2026 (`scratchpad/proto-online/`, 7.048 linhas). Template nas linhas 80 a 2927;
> classe `Component` de 2929 a 7046 (`renderVals` em 6481). Referências no formato `dc:LINHA` (arquivo .dc.html) ou
> `arquivo.js:LINHA`. Levantamento estático (leitura do código), sem execução.
>
> Convenções desta tabela:
> - **ID**: reaproveita o ID do checklist de 13/10 (`docs/specs/fidelidade-designer-1310-checklist-pasta.md`) quando o elemento
>   já constava. Elemento sem ID próprio no checklist recebe sufixo N (ex.: PZ-CAB-N01). O sufixo N **não** quer dizer novo
>   no protótipo: quer dizer sem linha própria no checklist.
> - **[NOVO-16/10]**: só o que entrou entre o pacote local de 13/10 e a versão online (diff em `diff-02-local-x-online.txt`).
> - **Confirmação**: `cf` = janela própria do protótipo (`confirmarUI`/`state.cf`, dc:353-361, `cfVals` dc:6244); `confirm()`/`prompt()` = nativa do navegador.
> - **localStorage**: o protótipo grava tudo no navegador. Atenção: `bj-contas.js` sobrescreve `Storage.prototype.getItem/setItem`
>   e, fora da conta demo, prefixa toda chave com `bjt:<conta>:` (bj-contas.js:35-38). As chaves abaixo são as da conta demo.

---

### A1. Cabeçalho da pasta

#### A1.1 Contêiner, linha 1 (voltar, número, prioridade, marcadores, responsável, setas, ⋮)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-N01 | Contêiner | Cartão do cabeçalho (fundo branco, `data-cad-area`) | Nenhum (estrutural) | Envolve linhas 1 a 3; `data-cad-area` delimita a área onde o guarda de "somente visualizar" do cadeado intercepta cliques (ver PZ-CAB-33) | Não | Nenhuma | dc:1140-1142 |
| PZ-CAB-01 | Linha 1 | Botão "Voltar" `bi-arrow-left` | Clique | **Sem handler** (nenhum `onClick`): só visual no protótipo; `title="Voltar à lista de pastas"` | Não | Rota da lista do Expediente | dc:1145 |
| PZ-CAB-02 | Linha 1 | "PASTA nnnn" | Nenhum | Número vem de `Component.PASTAS[pastaI][0]` via `navVals().pastaNum`; muda ao navegar ou ao editar dados (PE_KEY) | Não | Pasta (número) | dc:1147; `navVals` dc:6062 |
| PZ-CAB-03 | Linha 1 | Pílula de prioridade `bi-flag-fill` + chevron | Clique | `togglePrio` abre/fecha menu (fecha o ⋮). 3 opções Normal, Prioridade, Urgente com ✓ na atual; `p.select` faz `setState({prio, prioOpen:false})`. **Só estado React**: não grava em localStorage, não chama `autoReg` (embora exista `SYS.prio`), não persiste ao recarregar e é a mesma prioridade para todas as pastas (não indexada por número) | Não | Campo prioridade da pasta + registro de alteração | dc:1151-1167; dc:6602-6620, 6920 |
| PZ-CAB-04 | Linha 1 | Etiquetas de marcador ativas `bi-tag-fill` | Clique | Cada etiqueta abre o modal Marcadores (`mk.abrir`). Lista vem de `marcadoresAtivos()` (catálogo fixo `MARC` + criados) filtrado pela seleção; submarcador aparece como "Pai · Sub"; prefixo numérico "1. " removido do rótulo | Não | Marcadores da pasta e catálogo do escritório | dc:1170-1172; dc:3796-3800, 6622-6627 |
| PZ-CAB-05 | Linha 1 | "+" Marcadores da pasta (borda tracejada) | Clique | `mk.abrir`: copia seleção para `marcDraft`, limpa busca e foca o campo (60 ms) | Não | Idem | dc:1173; `marcVals.abrir` dc:3827 |
| PZ-CAB-06 | Linha 1 | Chip do Responsável (avatar 28px, nome cortado em 170px, chevron) | Clique | `toggleResp` abre listbox, limpa busca, fecha ⋮ e Situação, foca input (60 ms). Sem responsável: avatar com `bi-person-dash` e texto "Sem responsável" | Não | Colaboradores do escritório | dc:1177-1184; `respVals` dc:3110-3131 |
| PZ-CAB-07 | Linha 1 | Menu do responsável: busca "Filtrar responsável", lista com "Sem responsável" + 11 nomes, ✓ `bi-check2`, vazio "Nenhum colaborador encontrado" | Clique fora (overlay `fecharResp`), digitação (`onRespBusca`), clique no item | Filtro sem acento; escolher grava `respMap[num]` (estado), fecha e, se mudou, `autoReg('resp', 'alterado de "X" para "Y".')` (cartão de sistema no Registro). Lista de equipe fixa no código (EQ). `respMap` **não** persiste em localStorage | Não | `GET` colaboradores; `PATCH` responsável da pasta; registro de auditoria | dc:1185-1205; dc:3112-3121 |
| PZ-CAB-08 | Linha 1 | ‹ `bi-chevron-left` · "N de M" · › | Clique | `pastaAnt/pastaProx` (`ir(±1)`): troca `pastaI`, fecha menus, volta para aba Dados, aplica `sitMap[num]` ou "ativo". Desabilita nas pontas; `title` "Cliente anterior: NOME (pasta N)" / "Primeiro cliente da lista" | Não | Ordem/filtro da lista do Expediente | dc:1209-1213; `navVals` dc:6044-6070 |
| PZ-CAB-09 | ⋮ | Botão "Mais ações" `bi-three-dots-vertical` | Clique | `toggleMenu`: se a pasta está oculta pelo cadeado, só avisa "Pasta trancada pelo usuário primário"; senão guarda o retângulo do botão (`_menuBtnRect`, usado por Mover) e alterna o menu (fecha prioridade). Overlay fixo fecha (`fecharMenu`) | Não | Permissão (cadeado) | dc:1214-1217; dc:6923 |
| PZ-CAB-N02 | ⋮ | Atalhos de teclado com o ⋮ aberto: E, H, Esc | Teclado (`window keydown`, registrado em `componentDidMount`) | `_mTecFn` (registrado via `_reg` no `renderVals`): só age com `menuOpen`, sem Ctrl/Alt/Meta e fora de input/textarea/contenteditable. E → `peAbrir()`; H → abre Histórico; Esc → fecha. **T não tem atalho** apesar do rótulo "T" na Timeline | Não | Nenhuma | dc:6922, 6924 (duplicata `menuTecla` sem uso no template); listener dc:6359 |
| PZ-CAB-10 | ⋮ | Tarja "Pasta nnnn" `bi-folder2-open` | Nenhum | Cabeçalho do menu, gradiente 135deg #0d80a3→#0b6688 | Não | Nenhuma | dc:1219 |
| PZ-CAB-11 | ⋮ | Editar dados (E) `bi-pencil` | Clique ou tecla E | `mEditar` → `peAbrir()`: fecha ⋮ e abre o modal "Editar dados da pasta" (ver A1.6) | Não | Ver A1.6 | dc:1220; dc:4449, 7030 |
| PZ-CAB-12 | ⋮ | Histórico (H) `bi-clock-history` | Clique ou tecla H | `mHist`: fecha ⋮, abre drawer "Histórico do sistema" (ver PZ-MOD-20) | Não | Ver PZ-MOD-20 | dc:1221; dc:7031 |
| PZ-CAB-13 | ⋮ | Timeline inteligente (T) `bi-activity` (ícone azul #0a7aad) | Clique | `mTimeline`: fecha `expMenu` e chama `BJCentral.timeline.abrir()` (painel do `bluejus-central.js`). **Não fecha o ⋮** (só `expMenu`) | Não | Linha do tempo agregada (central) | dc:1222; dc:7040 |
| PZ-CAB-14 | ⋮ | Duplicar pasta `bi-files` | Clique | `menuPasta('duplicar')`: confirmação; ao confirmar grava cópia em `bj-pastas-duplicadas-v1` {origem, cliente, doc, resp, acao, em}, dispara `bj-pasta-duplicar` e avisa "Cópia da pasta N criada em Expediente, aguardando número" | `cf` azul: "Duplicar a pasta N? A cópia leva cliente, ação, responsável e checklist; documentos, metas e financeiro não são copiados." | `POST` duplicar pasta | dc:1223; dc:4567-4573 |
| PZ-CAB-15 | ⋮ | Vincular processo `bi-link-45deg` | Clique | `menuPasta('processo')` → `procAbrirVinc()`: vai para aba Processo e abre o modal Vincular processo (modo principal) | Não | Ver A5 | dc:1224; dc:4516, 4573 |
| PZ-CAB-16 | ⋮ | Trocar responsável `bi-person-gear` | Clique | `menuPasta('resp')`: após 30 ms abre o menu do responsável (PZ-CAB-07) e foca a busca | Não | Idem PZ-CAB-07 | dc:1225; dc:4574 |
| PZ-CAB-17 | ⋮ | Mover para outra carteira `bi-folder-symlink` | Clique | `menuPasta('mover')`: abre menu `gm` "Mover pasta N para" com 6 carteiras fixas (Contencioso cível, Condominial, Família e sucessões, Trabalhista, Previdenciário, Empresarial); atual com "· atual" e `bi-check2`. Escolher grava `bj-pasta-prefs-v1['cart:N']`, dispara `bj-pasta-mover` e avisa | Não | Carteiras do escritório; `PATCH` carteira da pasta | dc:1226; dc:4575-4579 |
| PZ-CAB-N03 | ⋮ | Seção "Compartilhar" (rótulo) | Nenhum | Rótulo 10.5px/700 uppercase #8496a3 | Não | Nenhuma | dc:1228 |
| PZ-CAB-18 | ⋮ | Copiar link da pasta (vira "Link copiado" + `bi-check2`) | Clique | `mCopiar`: copia `origin + /expediente/pasta/N`, mostra estado copiado e fecha o menu após 900 ms | Não | URL canônica da pasta | dc:1229; dc:7034-7037 |
| PZ-CAB-19 | ⋮ | Link externo do Push `bi-broadcast` + contagem de links ativos | Clique | `mPushLink` → `plAbrir()`: fecha ⋮, abre modal "Link externo do Push" (A1.8). Selo numérico = links ativos não revogados e não expirados da pasta (`pl.nAtivos`) | Não | Links públicos do Push | dc:1230; dc:5417, 5425-5429 |
| PZ-CAB-20 | ⋮ | Imprimir resumo `bi-printer` | Clique | `mImprimir`: fecha ⋮ e `imprimirResumo()` monta HTML (Dados da pasta, Pendências, Metas abertas até 12, Últimas 5 movimentações, Financeiro) a partir de `window.__bjIAFontes()` e abre a prévia de impressão (PZ-MOD-06) | Não | Agregado da pasta; geração de PDF no servidor | dc:1231; dc:4830-4844 |
| PZ-CAB-21 | ⋮ | Seção "Acesso · Primário" + Cadeado com selo Livre/Restrito/Todos | Clique | Só aparece para o primário (`cd.master`). `cd.abrir` → `cdAbrir()` abre modal Cadeado (A1.7). Ícone `bi-unlock`/`bi-lock-fill`; selo "Livre" (#eef2f5/#6b8494) ou "Restrito"/"Todos" (#ece8fb/#4a3d80) | Não | Papel do usuário; tabela de acesso por pasta | dc:1233-1237; dc:6097-6098, 6114 |
| PZ-CAB-22 | ⋮ | Acompanhar alterações `bi-bell` + interruptor | Clique | `prefPasta('seguir')`: alterna (padrão ligado), grava `bj-pasta-prefs-v1['<usuário>:N'].seguir`, aviso "Você vai receber no Chat I.A as alterações da pasta N" / "Você deixou de acompanhar...", dispara `bj-pasta-pref` | Não | Preferência por usuário e pasta; notificações | dc:1239; dc:4491-4497, 7038 |
| PZ-CAB-23 | ⋮ | Fixar nos favoritos `bi-star` + interruptor | Clique | `prefPasta('fav')`: idem com chave `fav` (padrão desligado); avisos "Pasta N fixada nos favoritos" / "removida dos favoritos" | Não | Idem | dc:1240; dc:7039 |
| PZ-CAB-24 | ⋮ | Arquivar pasta / Desarquivar pasta (`bi-archive` / `bi-box-arrow-up`) | Clique | `alternarArquivo` → `set('arquivado'|'ativo')` da Situação (mesmo efeito do PZ-CAB-48), **sem confirmação** | Não | `PATCH` situação; registro | dc:1242; dc:6144-6147 |
| PZ-CAB-25 | ⋮ | Excluir pasta `bi-trash3` (vermelho #a3232b) | Clique | `menuPasta('excluir')`: **`window.prompt` nativo** pedindo o número; se confere grava `bj-pastas-lixeira-v1[N]`, dispara `bj-pasta-excluir`, muda situação para "cancelado" e avisa "Pasta N enviada para a lixeira (restaurável por 30 dias)"; se não confere avisa "Número não confere. A pasta não foi excluída." | `prompt()` nativo: "Excluir a pasta N (CLIENTE)?\nEssa ação vai para a lixeira por 30 dias e fica registrada no histórico.\n\nPara confirmar, digite o número da pasta:" | `DELETE` lógico (lixeira 30 dias) | dc:1243; dc:4580-4586 |

#### A1.2 Linha 2 (foto, nome, documento, IA, faixas de cadeado, selos)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-26 | Linha 2 | Foto do cliente (botão 38px) | Clique | `ft.abrirMenu`: menu fixo abaixo do botão com "Carregar foto"/"Carregar nova foto", e com foto: "Ajustar foto", "Remover foto" | Não | Armazenamento de imagem do cliente | dc:1253; `fotoVals` dc:3844-3887 |
| PZ-CAB-N04 | Linha 2 | Menu da foto: Carregar | Clique | Abre `<input type=file accept=image/*>`; ao escolher, lê como DataURL e abre o editor | Não | Upload | dc:470-474; dc:3857-3859 |
| PZ-CAB-N05 | Linha 2 | Menu da foto: Ajustar foto | Clique | Reabre editor com o original (`bj-foto-cliente:N:orig`) ou a foto atual | Não | Idem | dc:476; dc:3860 |
| PZ-CAB-N06 | Linha 2 | Menu da foto: Remover foto | Clique | Remove `bj-foto-cliente:N` e `:orig`, aviso "Foto removida". **Sem confirmação** | Não | `DELETE` foto | dc:477; dc:3861 |
| PZ-CAB-N07 | Linha 2 | Modal "Ajustar foto do cliente": arrastar, roda do mouse (zoom), controle zoom 1 a 3, Girar 90°, Trocar imagem, Cancelar, Salvar foto | Ponteiro (arrastar), roda (`onWheel`), input range, cliques | Salvar recorta em canvas 320×320 JPEG 0,88 e grava `bj-foto-cliente:N` (+ original até 1,5 MB em `:orig`); aviso "Foto do cliente salva". **Observação**: `state.fotoCli` é lido uma única vez (quando `undefined`) e não é reindexado ao trocar de pasta, então a foto da primeira pasta aberta permanece ao navegar (inconsistência do protótipo) | Não | Upload/processamento de imagem | dc:481-504; dc:3846, 3862-3885 |
| PZ-CAB-27 | Linha 2 | Nome do cliente (h1) | Botão direito (`onContextMenu`), hover | `abrirCli`: impede menu nativo, registra `keydown` Esc (`_cliEsc`), abre a janela "Detalhes do cliente" na posição do cursor (+4px) e fecha ⋮/Situação/Responsável. Nome = cadastro central pelo CPF original (`cliDe(cur[2]).nome`) ou o da pasta | Não | Cadastro do cliente | dc:1255; dc:6063, 7029 |
| PZ-CAB-28 | Linha 2 | Dica "Detalhes: clique com o botão direito" `bi-mouse2` | Hover (350 ms) | `tipOn` agenda `tipVis`; `tipOff` esconde; não aparece com a janela aberta | Não | Nenhuma | dc:1261-1263; dc:7026-7028 |
| PZ-CAB-29 | Linha 2 | "Movimentado hoje, 14:54" com ponto pulsante | Nenhum | **Texto fixo** no template (não calculado) | Não | Data da última movimentação da pasta | dc:1255-1258 |
| PZ-CAB-30 | Linha 2 | Documento "CPF …" | Nenhum | `cliDoc`: CPF do cadastro central pelo CPF original da pasta, senão `cur[2]`. **Não aplica o alias `bj-cli-alias`** (ver PZ-MOD-N13) | Não | Cadastro do cliente | dc:1259; dc:6063 |
| PZ-CAB-31 | Linha 2 | Botão BLUEJUS Intelligence (estrela SVG animada) | Clique | `ia.abrir` abre o painel da IA (fora do escopo A; ver área IA) | Não | IA | dc:1266-1271 |
| PZ-CAB-32 | Linha 2 | Sobreposição "Pasta trancada" (ícone 92px com ondas, texto, "Motivo (opcional)", "Pedir acesso ao primário", "Pedido enviado às HH:MM...", recusado) | Clique em Pedir; digitação no motivo | Aparece quando o cadeado oculta a pasta para o usuário. `cd.pedir` → `BJCentral.pedirAcessoPasta({pasta, cliente, nivel:'editar', motivo})`; pedido pendente vale 24 h; mostra recusa anterior. Posição recalculada no resize (ref) | Não | Pedido de acesso ao primário; notificação | dc:1274-1292; dc:6087-6099 |
| PZ-CAB-33 | Linha 2 | Faixa "só visualizar" `bi-lock-fill` + "Pedir para editar" / "Pedido enviado" | Clique; guarda global | Nível "ver": faixa azul; `cd.pedirEd`. Guarda `_cadGuard` em captura (`click`, `focusin`, `drop`, `paste`, `beforeinput` no `document`) bloqueia, dentro de `[data-cad-area]`, campos de edição e botões cujo texto/título casa com verbos de edição (adicionar, editar, excluir, salvar, enviar, vincular, arquivar...) e avisa "Somente visualização: o usuário primário trancou esta pasta" | Não | Permissão por pasta aplicada no servidor | dc:1293-1295; dc:6351-6356 |
| PZ-CAB-34 | Linha 2 | Selo carimbado ARQUIVADO (ondas) | Automático pela situação | `seloEl()`: carimbo 150×104, zoom .8, rotação -14°, 3 ondas, textura por máscara; cor #5d7383 | Não | Situação | dc:1297; dc:6116-6128 |
| PZ-CAB-35 | Linha 2 | Selo ATIVO por 3,3 s | Ao montar (1,4 s), ao voltar para Ativo, ao trocar de pasta ativa | `ativoSeloMostrar` liga `ativoSelo` e desliga após 3300 ms; animação `seloBate` + `seloSai`; cor #1f9d61 | Não | Nenhuma | dc:6129, 6349, 6424 |
| PZ-CAB-36 | Linha 2 | Selos SUSPENSO (#c27c0e) e CANCELADO (#c62828) | Automático | Mesmo carimbo de PZ-CAB-34 | Não | Situação | dc:6118 |
| PZ-CAB-37 | Linha 2 | Selo do cadeado (64px com ondas) | Clique | Visível só ao primário quando há cadeado; desloca para `right:136px` se houver selo de situação; clique abre o modal Cadeado | Não | Cadeado | dc:1298; dc:6096 |
| PZ-CAB-41 | Faixa | Faixa Processo vinculado · Ação · Responsável no cabeçalho | Nenhum | **Ausente na versão online** (Ação e Processo ficam no trilho da aba Dados) | Não | Nenhuma | (não encontrado) |
| PZ-CAB-42 | Faixa | Link "trocar" `bi-arrow-left-right` | Nenhum | **Ausente na versão online** (0 ocorrências) | Não | Nenhuma | (não encontrado) |

#### A1.3 Andamento da pasta (trilha de fases)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-38 | Linha 2 | Trilha de fases (10 fases judiciais: Abertura, Documentação, Contrato, Distribuído, Protocolo, Em andamento, Decisão, Sentença, Cumprimento, Finalizada; via administrativa troca para Requerimento, Em análise, Exigência, Parecer) | Nenhum (exibição) | Ícone por fase (Decisão/Sentença com martelo SVG por máscara), data dd/mm/aa, contador de registros da fase (bolha #0a6fa0), "N dias" entre fases, "N dias (atual)" desde o último andamento, duração total, fase pulada em vermelho ("pulada"), finalizada verde com animação `faseFim`, atual com `faseOnda`. Fase atual inferida se não houver registro (`faseInferir`: 0 sem registros, 1 com registros, 5 com processo, última se processo arquivado/baixado/transitado). Rola sozinha até a fase atual (ResizeObserver). Persistência `bj-pasta-fases` (chave `N` ou `N:adm`) com migração de versões `bj-pasta-fases-v` | Não | Andamento da pasta com histórico por fase | dc:1300-1314; `navVals` dc:6047-6061; dc:6155-6165, 6190-6197 |
| PZ-CAB-39 | Linha 2 | Clique na fase | Clique | `faseMarcar(i)`: se fase atual, nada. Abre confirmação com título "Avançar fase da pasta" / "Voltar fase da pasta" / "Finalizar pasta"; avisa quantas fases intermediárias ficam puladas. Ao confirmar grava `bj-pasta-fases`, cria cartão de sistema "Andamento" no Registro (`bj-registros-v1`, `sistema:'fase'`) e sincroniza Contrato (passa a Assinado se a fase Contrato foi concluída; volta a Pendente se o andamento recuou antes de Contrato, com `autoReg('contrato')`). Clique em "Finalizada" já finalizada: "Reativar pasta" adiciona etapas Reativada + Em andamento + Finalizada | `cf` azul: "Mudar de "X" para "Y"? A mudança fica registrada no Registro dos expedientes com data e hora." (com prefixo "Atenção: N fase(s) intermediária(s) ficarão marcadas em vermelho como puladas." quando salta); Finalizar: "Marcar a pasta como finalizada? Depois, se precisar, clique em "Finalizada" para reativar."; Reativar: "A pasta está finalizada. Reativar abre duas novas etapas: "Em andamento" (reativada) e um novo "Finalizada". O histórico anterior é mantido." | Andamento + registro + contrato | dc:1307; dc:6198-6220 |
| PZ-CAB-40 | Linha 2 | Faixa "Avançar para "X"?" + motivo + Avançar / Ignorar | Clique | `faseSugestao()`: sugere a menor fase à frente por Push (sentença, trânsito/cumprimento, decisão, distribuição), documentos novos anexados, processo vinculado ou contrato assinado. Avançar → `faseMarcar(i, false, true)` (abre a mesma confirmação, sem pular fases). Ignorar grava `fsIgn[N][fase]` só no estado | Avançar: mesma `cf` de PZ-CAB-39 | Leitura do Push; documentos | dc:1315-1321; dc:6060, 6174-6183 |
| PZ-CAB-N08 | Registro | Pergunta automática após novo registro: "Atualizar andamento da pasta?" | Evento `bj-enviar` do editor (contextos expedientes, observacoes, financeiro) | 350 ms após salvar registro, se a fase não é a última, abre `cf` com botões "Avançar para PRÓXIMA" / "Manter em ATUAL" | `cf` azul: "Registro salvo na fase "X". Este registro conclui a fase e a pasta deve avançar para "Y"?" | Idem | dc:6342 |
| PZ-CAB-N09 | Andamento | Mudança automática de fase | Eventos de sistema | `faseAuto`: vincular processo → "Em andamento"; arquivar pasta → "Finalizada"; marcar contrato assinado → "Contrato" (sem confirmação, motivo registrado) | Não | Regras de andamento | dc:4561, 6135, 6173, 6184-6189 |

#### A1.4 Linha 3 (abas, Cadastro, Situação)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-43 | Linha 3 | Abas: Dados da pasta, Metas (contagem), Processo, Financeiro, Detalhes, Documentos ("224" fixo), Push Processual (novos); indicador deslizante; modo compacto | Clique | `t.select`: troca aba, incrementa `switches` (anima `tabInA/tabInB`), direção `tabDir`. `measure()` liga modo compacto (só ícone nas inativas) quando o trilho transborda e desliga com folga de 160px; indicador = borda inferior que desliza com mola | Não | Contagens reais por aba | dc:1328-1340; dc:2960-2971, 6484-6508 |
| PZ-CAB-44 | Linha 3 | Selo numérico nas abas | Nenhum | Metas = `metasData.length`; Documentos = "224" fixo; Push = novidades `pushNovos()` (regex de datas 01/10 e 02/10 fixas) | Não | Contagens | dc:1337; dc:6486-6491, 5507 |
| PZ-CAB-45 | Linha 3 | Linha vermelha animada de pendência na aba | Automático | `tabPendencias()`; animação `metaAlerta` 7 s, forte `metaAlertaForte` 3,5 s por 30 s após criar meta; indicador vira 3px #c0392f quando a aba atual tem pendência | Não | Pendências por aba | dc:1334; dc:6494-6497, 6907-6908 |
| PZ-CAB-46 | Linha 3 | Botão direito na aba: Ocultar para toda a equipe / Ocultar para… / Mostrar para todos; indicador `bi-eye-slash`; aba Push para não primário mostra itens do link externo | Botão direito | `abaMenu`: não primário recebe aviso "Só o usuário primário pode ocultar abas" (exceto Push, que abre o menu de link externo). Grava `bj-abas-visib-v1` e log `bj-abas-visib-log`; "Ocultar para…" abre modal por pessoa (`av`) | Não | `PUT` permissões de abas | dc:1332-1333; dc:4653-4671 |
| PZ-CAB-47 | Linha 3 | Botão "Cadastro" `bi-person-vcard` com ponto verde/âmbar | Clique | `cq.abrirAtual` → `cadAbrir(soDig(cur[2]), {nome, cpf})`; ponto verde se cadastro sem pendências (`cliPend`), âmbar caso contrário; dica "Cadastro completo · abrir qualificação do cliente" ou "Cadastro incompleto: N pendências · clique para completar". Abre pelo CPF **original** da pasta (não usa alias) | Não | Cadastro central do cliente | dc:1342-1345; `cadVals` dc:3439-3449 |
| PZ-CAB-48 | Linha 3 | Pílula e menu de Situação (Ativo, Suspenso, Cancelado, Arquivado com descrição e ✓; ponto com ondas quando Ativo) | Clique | `toggleSit`; `o.escolher` → `set(v)`: grava `situacao` e `sitMap[N]` (estado React, **não persiste em localStorage**), mostra selo ATIVO, arquivar dispara `faseAuto('Finalizada')`, registra `autoReg('sit', 'alterada de "X" para "Y".')`. **Sem confirmação** em nenhuma transição | Não | `PATCH` situação; regras (listas de trabalho, Push indisponível em cancelada/arquivada) | dc:1346-1368; dc:6130-6148 |
| PZ-CAB-N10 | Linha 3 | Efeitos colaterais da situação | Automático | Cancelada/arquivada bloqueia o link externo do Push (`pushLinkRegra`); excluir pasta força "cancelado" | Não | Regras | dc:4627-4628, 4585 |

#### A1.5 Busca global e menus compartilhados usados pelo cabeçalho

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-10 | Global | Busca rápida Ctrl+K "Buscar pasta, cliente, CPF ou processo" | Teclado Ctrl/Cmd+K (`window keydown`), ↑ ↓ Enter Esc | `onBuscaG` alterna; resultados até 8 entre Pasta, Cliente, Processo (dígitos ≥3 casam CPF/CNJ); Enter abre a pasta na aba Dados | Não | Busca no servidor | dc:403-419; dc:6444-6478 |
| PZ-MOD-09 | Global | Janela de confirmação própria (`cf`) | Clique; Esc fecha; Enter confirma | Cabeçalho #0b6a96 com `bi-exclamation-triangle`, texto, Cancelar (rótulo `canc` customizável) e botão com ícone; foco automático | É a própria confirmação | Nenhuma | dc:353-361; dc:6242-6244 |
| PZ-MOD-25 | Global | Variante azul (#0a6fa0, `bi-check2`) para ação sem perigo; vermelha (#b3262e, `bi-trash3` ou `bi-exclamation-triangle`) no perigo; título pela 1ª linha da mensagem quando há `\n` antes do caractere 90 | Programático | `confirmarUI(msg, ok, perigo)` | Idem | Nenhuma | dc:6242 |
| PZ-MOD-26 | Global | "Nenhum `confirm()` nativo" | Verificação | **Não vale mais na versão online**: há `confirm()` nativo em Remover réu [NOVO-16/10] (dc:3542) e `prompt()` nativo em Excluir pasta (dc:4581) | n/a | n/a | dc:3542, 4581 |
| PZ-CAB-N11 | Global | Menu de contexto compartilhado `gm` | Clique ou botão direito; botão direito fora fecha | `gmAbrir(e, título, itens)`: em clique posiciona sob o botão, em contextmenu no cursor; largura 230px; item perigoso em #a3232b; separador '-' | Não | Nenhuma | dc:362-371; dc:4607-4615 |
| PZ-GLB-12 | Global | Toast padrão (fundo #12242f, ✓ #5fd39a, 2,6 s) | Programático | `gmAviso(t)` | Não | Nenhuma | dc:669; dc:4687 |

#### A1.6 Modal "Editar dados da pasta" (PZ-MOD-15, PZ-MOD-16)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-15 | Editar dados | Modal: título "Pasta N", sub "CLIENTE · DOC", pílula "Dados da pasta" (verde) / "Alterações não salvas" (âmbar); clique no fundo fecha; Esc fecha; Enter em input salva | Clique, teclado | `peVals`; foco automático no número com seleção | Não | Ver abaixo | dc:632-668; dc:4450-4489 |
| PZ-MOD-N01 | Editar dados | Abas internas: Dados da pasta (ativa), Cliente, Processo, Histórico | Clique | Cliente fecha e abre o Cadastro pelo CPF; Processo vai para a aba Processo; Histórico abre o drawer | Não | Nenhuma | dc:644; dc:4479 |
| PZ-MOD-N02 | Editar dados | Campo Número da pasta * | Digitação | Só dígitos, até 8; erro "Informe o número da pasta." ou "Já existe outra pasta com o número N." | Não | Unicidade do número no tenant | dc:648; dc:4453-4457, 4482 |
| PZ-MOD-N03 | Editar dados | Campo Nome do cliente * | Digitação | Erro "Informe o nome do cliente." se menos de 3 caracteres | Não | Nenhuma | dc:649; dc:4457 |
| PZ-MOD-16 | Editar dados | Tipo CPF/CNPJ (segmentado) + campo documento * | Clique, digitação | CPF validado por dígito verificador (`cpfValido`), CNPJ só 14 dígitos; erros "CPF inválido." / "CNPJ deve ter 14 dígitos." Placeholder muda | Não | Validação de documento | dc:652-653; dc:4454-4457, 4483 |
| PZ-MOD-N04 | Editar dados | Responsável (só leitura) + "Trocar" | Clique | Fecha e abre o menu do responsável | Não | Idem PZ-CAB-07 | dc:656; dc:4485 |
| PZ-MOD-N05 | Editar dados | "Ação · vem do menu Processo" (só leitura) + "Abrir" | Clique | Fecha e vai para a aba Processo | Não | Processo vinculado | dc:657; dc:4484-4485 |
| PZ-MOD-N06 | Editar dados | Faixa "Pendências" com erro | Automático | Só aparece quando há alteração com erro | Não | Nenhuma | dc:659; dc:4476 |
| PZ-MOD-N07 | Editar dados | Salvar (travado sem alteração ou com erro), Fechar, "Cadastro do cliente" | Clique | Salvar grava `bj-pasta-dados-v1[índice]`, propaga nome ao cadastro central pelo CPF, registra `bj-pasta-hist-v1`, migra chaves `bj-registros-v1`, `bj-pasta-fases`, `bj-pasta-proc` quando o número muda, dispara `bj-pasta-editada`, aviso "Dados da pasta atualizados" e `autoReg('pasta', 'dados editados: …')` | Troca de número: `cf` azul "Alterar número da pasta" / "Alterar o número da pasta de A para B? Links e referências antigas passam a apontar para o novo número." | `PATCH` pasta; migração de referências | dc:662-664; dc:4458-4474, 4486 |

#### A1.7 Modal Cadeado (PZ-MOD-12)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-12 | Cadeado | Modal "Cadeado da pasta N" · "Exclusivo do usuário primário · vale para toda a equipe" | Clique (menu ⋮, selo, evento `bj-abrir-cadeado`) | `cdAbrir`: não primário recebe "Só o usuário primário pode trancar a pasta"; inicia níveis (padrão Editar sem cadeado, Ocultar com cadeado) | Não | Tabela `pasta_acesso` aplicada em todas as rotas | dc:505-548; dc:6100-6115, 6357 |
| PZ-MOD-N08 | Cadeado | Modos: "Escolher por pessoa" / "Ocultar para todos" | Clique | Por pessoa mostra tabela; todos mostra aviso "Ninguém além do usuário primário verá a pasta…" | Não | Idem | dc:515-537; dc:6106-6107 |
| PZ-MOD-N09 | Cadeado | "Aplicar a todos" (Ocultar, Visualizar, Editar) e por colaborador (3 botões 56×28) | Clique | Ajusta `cadForm.niveis`; resumo "N editam, N só visualizam, N não veem. O usuário primário sempre vê e edita tudo." | Não | Idem | dc:522-529; dc:6108-6110 |
| PZ-MOD-N10 | Cadeado | Trancar / Remover cadeado / Cancelar; clique no fundo fecha | Clique | Grava `bj-pasta-cadeado-v1[N]` {modo, niveis, em, por}, registra `bj-pasta-hist-v1` e auditoria; avisos "Pasta N trancada" / "Cadeado removido da pasta N". **Remover sem confirmação** | Não | Idem + auditoria | dc:541-544; dc:6111-6112, 6115 |

#### A1.8 Link externo do Push e acessos (PZ-MOD-13, PZ-MOD-14)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-14 | Link Push | Modal "Link externo do Push": aviso sem processo; opções Exigir código, Mostrar nome do cliente, Incluir Análises IA (desabilitada sem análise); validade fixa 30 dias; "Gerar e copiar link" | Clique; Esc; fundo | `gerar`: token aleatório 20 caracteres, código de 6 dígitos opcional, grava `bj-push-links-v1[token]` com movimentações e análises, copia URL (curta via `BJLink` se houver domínio público, senão completa), registra `bj-pasta-hist-v1` | Não | Serviço público de links; domínio curto | dc:575-631; dc:5417-5480 |
| PZ-MOD-N11 | Link Push | Após criar: URL somente leitura, Copiar, Copiar link completo, "ativa com o domínio", tamanho do link, código, validade, "Ver como terceiro", "Criar outro" | Clique | Ver como terceiro abre `Push Compartilhado.dc.html?terceiro=1&push=` | Não | Idem | dc:587-600; dc:5460-5467 |
| PZ-MOD-N12 | Link Push | Lista "Links desta pasta" com estado (Ativo, Revogado, Expirado, Renovação pedida), acessos por dia, renovar 30 dias, revogar, copiar | Clique | Revogar marca `revogado` | Revogar: `cf` azul "Revogar este link? Quem tiver o endereço perde o acesso na hora." | Idem | dc:609-622; dc:5469-5477 |
| PZ-MOD-13 | Link Push | Painel "Acessos ao link do Push" (total, último acesso, por link, por dia, últimos 5) | Clique (Ver acessos) | Só leitura de `bj-push-links-v1[*].acessos` | Não | Contagem de acessos no servidor público | dc:549-574; dc:4640-4652 |

#### A1.9 Histórico, Detalhes do cliente, Impressão, Marcadores

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-20 | Histórico | Drawer "Histórico do sistema" · "10 alterações registradas automaticamente" | Clique (⋮, tecla H, aba do Editar dados); overlay fecha; × fecha | **Conteúdo fixo**: 6 eventos codificados (`events`) agrupados por dia; o contador "10" também é fixo. Não lê `bj-pasta-hist-v1` nem os cartões de sistema | Não | Trilha de auditoria da pasta | dc:2879-2908; dc:6586-6600, 7017-7018 |
| PZ-MOD-18 | Detalhes do cliente | Janela arrastável (cabeçalho), copiar Nome e Documento (ícone vira ✓ por 1,4 s), números (pastas, ativas, com processo), "Pastas com o mesmo CPF" (abre outra pasta), "Cliente desde…" | Arrastar (ponteiro), clique, Esc, clique ou botão direito fora | Abrir pasta de outra ação adiciona pasta extra a `PASTAS` quando não existe | Não | `GET` clientes por documento com pastas | dc:872-935; `cliVals` dc:3152-3205 |
| PZ-MOD-19 | Detalhes do cliente | Contatos (telefone/e-mail): copiar, editar, salvar, remover, cancelar, "+ Telefone", "+ E-mail" | Clique, Enter salva, Esc cancela | Máscara de telefone; e-mail validado; vazio remove. **Só estado** (`state.contatos`), não persiste | Remover: não | Contatos do cliente | dc:913-932; dc:6004-6038 |
| PZ-MOD-06 | Impressão | Prévia: Incluir na folha (Cabeçalho do escritório, Dados da pasta e do processo, Autor/data/tipo, Financeiro se a aba não estiver oculta), tamanho 9 a 16 pt, Imprimir, Baixar PDF, Copiar texto | Clique | `impVals`, `impMontar`, `pdfBaixar` (html2pdf com fallback de baixar HTML) | Não | Geração de PDF | dc:202-224; dc:4861-4882, 4806-4808 |
| PZ-MOD-11 | Marcadores | Modal: busca "Buscar marcador…", contagem de selecionados, 8 marcadores fixos (2 com submarcadores), expandir, "Criar marcador "X"", Cancelar, Salvar (Ctrl+Enter), Esc | Clique, teclado | Criar grava `bj-marcadores-catalogo` (cor rotativa de 4); Salvar grava `bj-marcadores:N` e aviso "Marcadores salvos". **Observação**: após salvar, `state.marcSel` passa a valer para todas as pastas na sessão (não é indexado por pasta) | Não | Catálogo de marcadores do escritório; marcadores por pasta | dc:420-468; dc:3775-3841 |

---

### A2. Cadastro do cliente (modal "Cadastro e qualificação do cliente", PZ-MOD-17)

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-17 | Cadastro | Modal 720px: cabeçalho com nome (ou "Novo cliente"), "CPF …" ou "CPF não informado", pílula "Cadastro completo"/"Cadastro incompleto · N pendências"; × Fechar | Abertura: botão Cadastro (PZ-CAB-47), nome do cliente no trilho, "Cadastrar cliente", Editar dados → Cliente | `cadAbrir(cpfKey, pre, retorno)`: clona o registro do cadastro central `bj-clientes-v1` (semente gerada de `PASTAS` + 2 clientes) ou cria vazio | Não | `GET/PUT` cliente central por CPF | dc:673-842; dc:3431-3532 |
| PZ-MOD-N14 | Cadastro | Fechar com alterações não salvas | Clique em Fechar, ×, fundo ou Esc | 1º clique mostra "Há alterações não salvas. Clique em Fechar de novo para descartar." (janela de 4 s); 2º fecha | Confirmação por duplo clique em Fechar (mensagem no rodapé) | Nenhuma | dc:3507 |
| PZ-MOD-N15 | Cadastro | Atalho Ctrl/Cmd+S salva; Esc fecha | Teclado (`onKeyDown` do diálogo) | Ver salvar | Não | Nenhuma | dc:675; dc:3528 |
| PZ-MOD-N16 | Cadastro | Abas: Cliente, Réu [NOVO-16/10], Endereço, Contato, Documentos, Qualificação, Histórico; ponto âmbar na aba com pendência | Clique | Rótulo "Dados pessoais" virou "Cliente" na versão online [NOVO-16/10]; ativa com sublinhado #0a7aad | Não | Nenhuma | dc:684-688; dc:3469, 3515 |
| PZ-MOD-N17 | Cadastro · Cliente | Campos: Nome completo* (6 col.), CPF* (máscara 000.000.000-00), RG*, Nascimento (date), Estado civil* (select), Profissão*, Nacionalidade (padrão "brasileiro(a)"), Gênero (select) | Digitação; blur no CPF | Máscara CPF; aviso "CPF inválido: confira os dígitos." com borda #e3a39b; blur do CPF detecta CPF já cadastrado e abre o alerta de duplicidade | Não | Validação de CPF; unicidade | dc:723-737; dc:3475-3482 |
| PZ-MOD-N18 | Cadastro · Endereço | CEP* (máscara 00000-000), Logradouro*, Número*, Complemento, Bairro*, Cidade*, UF* (select) | Digitação; blur no CEP | Blur no CEP com 8 dígitos e logradouro vazio consulta `viacep.com.br` e preenche logradouro, bairro, cidade, UF; mensagem "Endereço preenchido pelo CEP. Confira e informe o número." | Não | Serviço de CEP | dc:3475, 3550-3553 |
| PZ-MOD-N19 | Cadastro · Contato | Telefone, WhatsApp (máscara (00) 00000-0000), E-mail | Digitação | E-mail inválido: "E-mail em formato inválido."; pendência "Nenhum contato informado" se os três vazios | Não | Nenhuma | dc:3473-3475, 3480, 3409 |
| PZ-MOD-N20 | Cadastro · Réu [NOVO-16/10] | Texto "Parte adversa desta pasta. O primeiro réu aparece na coluna Réu da lista do Expediente; havendo mais de um, aparece "e outros"." | Nenhum | Réus são da **pasta** (não do cliente) | Não | Partes da pasta | dc:701-703; dc:3535-3548 |
| PZ-MOD-N21 | Cadastro · Réu [NOVO-16/10] | Campos "Nome / razão social *" e "CPF / CNPJ" (opcional) | Digitação (`onChange`) | Guarda rascunho em `cad.reu`; documento só valida tamanho: erro "CPF com 11 ou CNPJ com 14 dígitos" (sem dígito verificador, sem máscara durante a digitação) | Não | Validação de documento | dc:705-706, 709; dc:3540, 3545 |
| PZ-MOD-N22 | Cadastro · Réu [NOVO-16/10] | Botão "Adicionar réu" `bi-plus-lg` | Clique | Normaliza nome em MAIÚSCULAS; bloqueia vazio ou doc inválido; duplicado por nome ou documento só limpa o formulário (sem aviso); grava **imediatamente** `bj-reus[N]` (independe do Salvar do cadastro), `forceUpdate`. `reuTecla` (Enter) existe mas **não está ligado** no template. Não gera registro (`autoReg`) | Não | `POST` parte ré da pasta | dc:707; dc:3539, 3546-3547 |
| PZ-MOD-N23 | Cadastro · Réu [NOVO-16/10] | Lista de réus: avatar com iniciais (#fbeceb/#b03a2e), nome, selo "Principal" no 1º, "CPF …"/"CNPJ …" formatado ou "Documento não informado"; vazio "Nenhum réu cadastrado nesta pasta." | Nenhum | Ordem define o principal | Não | Idem | dc:710-720; dc:3541 |
| PZ-MOD-N24 | Cadastro · Réu [NOVO-16/10] | "Tornar principal / subir" `bi-arrow-up` | Clique | Troca com o anterior e grava `bj-reus`; no 1º não faz nada | Não | `PATCH` ordem das partes | dc:715; dc:3543 |
| PZ-MOD-N25 | Cadastro · Réu [NOVO-16/10] | "Remover réu" `bi-trash3` | Clique | Remove e grava `bj-reus` | **`confirm()` nativo**: "Remover NOME dos réus desta pasta?" | `DELETE` parte | dc:716; dc:3542 |
| PZ-MOD-N26 | Cadastro · Documentos | Cartões "Documento de identificação" (CNH ou RG) e "Comprovante de residência": Anexar; com arquivo: Visualizar, Baixar, Substituir, Excluir | Clique; input file `image/*,.pdf` | Até 1,5 MB vira DataURL; maior fica só em blob da sessão ("Arquivo grande: no protótipo ele fica disponível só nesta sessão."). Visualizar abre nova janela com embed/img. Excluir **sem confirmação** | Não | Upload e armazenamento de documentos do cliente | dc:790-802; dc:3483-3487, 3554-3563 |
| PZ-MOD-N27 | Cadastro · Documentos | Leitura automática (OCR) do documento de identificação | Automático ao anexar identidade | Sem `window.BLUEJUS_OCR_URL`: mensagem "O serviço de leitura (OCR) não está configurado neste ambiente…". Com serviço: lista Nome, CPF, RG, Nascimento; "Conferi, aplicar ao cadastro" / "Descartar" | Não | `POST` OCR multipart | dc:803-810; dc:3525-3526, 3565-3573 |
| PZ-MOD-N28 | Cadastro · Documentos | "Inteligência cadastral": sugestões por documento (EXTRACOES de referência), confiança %, Validar, Editar (Enter salva, Esc cancela), Manter anterior, Rejeitar, "Validar todas"; alertas (homônimo, mesmo CPF com outro nome, identidade não confirmada); "Encontrados no menu Documentos" + "Vincular ao cadastro"; "Ver linha do tempo da identidade (N)"; interruptor do primário "Atualizar sozinho quando a confiança for alta" | Clique, teclado | Decisões em `bj-idint-v1`; configuração em `bj-idint-cfg`; atualização automática ≥90% sem conflito; cada aplicação escreve no Histórico do cadastro com a fonte | Não | Extração por IA a cada upload (`/api/identidade/sugestoes`) | dc:740-789; dc:3341-3406 |
| PZ-MOD-N29 | Cadastro · Qualificação | Texto jurídico gerado (Georgia 15px justificado) com flexão de gênero | Nenhum | `Component.qualificacao(f)` | Não | Nenhuma | dc:813-818; dc:3414-3430 |
| PZ-MOD-N30 | Cadastro · Histórico | Lista "data · texto" das alterações; vazio "Nenhuma alteração registrada ainda." | Nenhum | Até 40 entradas, mais recente primeiro | Não | Auditoria do cliente | dc:819-824; dc:3498, 3517 |
| PZ-MOD-N31 | Cadastro | Bloco "Pendências" (cliPend): campos obrigatórios, contato, identidade e residência; cada item leva à aba | Clique no item | Oculto na aba Histórico | Não | Nenhuma | dc:825-832; dc:3407-3413, 3518 |
| PZ-MOD-N32 | Cadastro | Alerta "Este CPF já possui cadastro no sistema." com Abrir cadastro, Utilizar cadastro existente, Cancelar | Blur do CPF ou Salvar | Abrir carrega o existente; Utilizar fecha e vincula à pasta (ou devolve ao chamador); Cancelar restaura o CPF anterior | Não | Unicidade de CPF no cadastro central | dc:690-700; dc:3519-3522 |
| PZ-MOD-N33 | Cadastro | Rodapé: mensagem de estado, Fechar, "Copiar qualificação" (com fallback `execCommand`), Salvar (azul quando alterado ou novo) | Clique | Salvar exige nome e CPF válido ("Informe o nome completo." / "Informe um CPF válido. Ele identifica o cliente no sistema."), registra histórico "X alterou campos" ou "X criou o cadastro", grava `bj-clientes-v1` (sem `url` dos documentos se estourar a cota), `autoReg('cad', …)` quando é o cliente da pasta, mensagem "Cadastro salvo." (+ " Ainda há pendências.") | Não | `PUT` cliente; auditoria | dc:834-839; dc:3488-3506, 3528-3529 |
| PZ-MOD-N13 | Cadastro | Alias de CPF [NOVO-16/10] | Salvar com CPF alterado | Ao trocar o CPF de um cadastro existente remove a chave antiga e grava `bj-cli-alias[antigo] = novo` (encadeia aliases). O trilho Clientes passa a exibir o CPF novo. **Não aplicado** em: documento do cabeçalho (PZ-CAB-30), nome do cabeçalho, botão Cadastro do cabeçalho (abre pelo CPF antigo e cai em "Novo cliente"), Detalhes do cliente | Não | Chave estável do cliente (id), não CPF | dc:3499, 6532 |
| PZ-TRI-10 | Cadastro (vínculo) | Ver A3 (vincular cliente pela busca, "Cliente ainda não cadastrado", "Cadastrar cliente") | | | | | dc:1482-1500 |

---

### A3. Aba "Dados da pasta" e trilho direito

#### A3.1 Registro dos expedientes

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-DAD-01 | Registro | Título "Registro dos expedientes" + contador (dica "Registros principais (respostas não contam)") | Nenhum | `notesN = _regsPrincipais.dados` | Não | Registros da pasta | dc:1377-1381; dc:6283, 6903 |
| PZ-DAD-02 | Registro | Compositor `bj-editor` "Adicionar…" contexto `expedientes` | Evento `bj-enviar` | `onEditorEnviar`: grava `bj-registros-v1[N]` {id, ctx, html, texto, criado, autor, fase}; aviso "Registro adicionado"; abre a pergunta de andamento (PZ-CAB-N08). Detalhes do editor em PZ-EDT | Não | `POST` registro | dc:1383; dc:6327-6345 |
| PZ-DAD-03 | Registro | Linha do tempo: pílula do dia ("26 ago 2026"), linha vertical 2px, ponto por tipo, seta do cartão | Nenhum | Ordenação só pela data do registro principal; respostas não reordenam | Não | Nenhuma | dc:1385-1389; dc:6269-6299 |
| PZ-DAD-04 | Cartão | Avatar 24px, autor, hora, megafone, corpo rico `bj-rich` | Nenhum | Registros semeados (4 fixos) + gravados | Não | Registros | dc:1390-1407; dc:6568-6573 |
| PZ-DAD-05 | Cartão | Presença do autor (ponto + rótulo) | Clique no rótulo | `BJCentral.presenca`; o próprio usuário clica para abrir o Chat | Não | Presença em tempo real | dc:1396-1397; dc:6300 |
| PZ-DAD-06 | Cartão | Etiqueta (Combinado, Ligação, Atendimento, Observação) | Nenhum | Cores por etiqueta (COR) | Não | Nenhuma | dc:1398; dc:6273 |
| PZ-DAD-07 | Cartão | "editado" com dica | Hover | Dica "Publicado às HH:MM · última edição …" ou "Editado por X em … · N versão(ões) anterior(es) guardada(s)" | Não | Versões do registro | dc:1399; dc:6305, 6321 |
| PZ-DAD-08 | Cartão | Lápis "Editável por mais N min" | Clique | Só registros gravados (`edit:`) dentro de 15 min (primário e master sem limite); abre editor inline; `setInterval` 20 s atualiza contagem | Não | Regra de edição | dc:1401, 1406; dc:6222, 6315-6322, 6359 |
| PZ-DAD-09 | Cartão | Responder `bi-reply-fill` | Clique | Abre caixa de resposta sob o cartão (resposta a resposta prefixa "@Nome ") | Não | Respostas | dc:1402; dc:6287, 6299 |
| PZ-DAD-10 | Cartão | Encaminhar via Chat I.A (ícone espelhado) | Clique | `despachar({tipo:'registro', …})` | Não | Chat I.A | dc:1403; dc:6300 |
| PZ-DAD-11 | Cartão | Caixa de resposta "Escreva a resposta…", Cancelar, Responder (desabilitado se vazio) | Clique; Ctrl/Cmd+Enter envia; Esc cancela | `respEnviar`: grava `bj-respostas-v1[chave]`, notifica o autor pelo Chat ("Resposta publicada. X foi avisado(a) pelo Chat I.A") | Não | Respostas + notificação | dc:1409-1415; dc:6260-6268, 6300 |
| PZ-DAD-12 | Cartão | Resposta recuada com faixa "Resposta a NOME" e conector | Nenhum | Data exibida quando difere do pai | Não | Nenhuma | dc:1392; dc:6280, 6296-6308 |
| PZ-DAD-13 | Cartão | Destaque de cor (15 cores) | Menu de contexto | Fundo da cor no cartão; regra de 1 destaque por aba (o anterior é desfeito com aviso); `bj-com-destaque-v1`; `PATCH` opcional se `BLUEJUS_API_BASE` | Não | Destaque por mensagem | dc:1390; dc:4588-4606, 6304 |
| PZ-DAD-14 | Registro | Cartão de sistema "Andamento da pasta · fase" e demais sistemas (Processo, Responsável, Situação, Contrato, Pró-bono, Prioridade, Dados da pasta, Cadastro) com "de → para" | Nenhum | `SYS` + `autoReg`; faixa #2b8ac0. **Tipo `prazo` [NOVO-16/10] não está em `SYS`**: cartões de prazo saem como texto "Alteração: Prazo adicionado…" sem faixa de sistema | Não | Auditoria | dc:1391; dc:6166-6171, 6318 |
| PZ-DAD-15 | Registro | "Ver anotações anteriores (6)" | Clique | **Sem handler** e contagem fixa "6" | Não | Paginação | dc:1419 |
| PZ-DAD-16 / PZ-CTX-05 | Cartão | Botão direito no cartão: Responder, Copiar texto, Encaminhar via Chat I.A, Destacar / Destacar · cor, Remover destaque, Editar · N min / Editar · bloqueado, Excluir comentário · N min / Excluir · permanente | Botão direito | Editar fora do prazo avisa o motivo; Excluir respeita `comPermExc` (autor ou primário até 60 min, master sempre) e grava `bj-itens-excluidos-v1` + log `bj-comentarios-excluidos-log` | Excluir: `cf` vermelha (via `confirmar`) título "Excluir o comentário de NOME?" texto "QUANDO · "TRECHO". Ele some para toda a equipe e a exclusão fica registrada no histórico."; Remover destaque: não | Permissões; auditoria | dc:1390; dc:6301-6303, 6248-6257 |
| PZ-DAD-17 | Registro | @menção com notificação | Digitação no editor | Notificada pelo módulo global (`bj-enviar` em bluejus-central.js) | Não | Notificações | dc:6343 |

#### A3.2 Trilho: Ação e Processo vinculado

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-TRI-01 | Trilho | Cartão "Ação" `bi-bank2` ("Extraído do processo vinculado") | Hover (title) | Ação do processo principal ou `EXTRA_ACAO`; vazio "Vincule um processo para definir" em cinza | Não | Processo principal | dc:1423-1428; dc:6064-6065 |
| PZ-TRI-02 | Trilho | Cartão "Processo vinculado": número (link "Abrir processo"), classe · tribunal; chip "N apensado(s)" com chevron; lista de apensos (tipo, número, ação, classe · tribunal · situação); vazio "Nenhum processo. abrir menu Processo" | Clique | Número e "abrir menu Processo" vão para a aba Processo; chip alterna `apAberto[N]` (estado); apenso clicado vai para a aba Processo | Não | Processo e apensos | dc:1429-1454; dc:6998-7002, 3132 |

#### A3.3 Trilho: Próximos prazos e audiências [NOVO-16/10]

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-TRI-03 | Prazos | Título "Próximos prazos" + contagem + chevron "Abrir metas em aberto" | Clique no chevron | Contagem = total de `pzLista()` [NOVO-16/10] (antes era o número de linhas). Chevron `irPrazos` abre Metas filtro Abertas e rola ao topo | Não | Agenda de prazos | dc:1455-1462; dc:6900, 7021 |
| PZ-TRI-N01 | Prazos [NOVO-16/10] | Botão "+" "Adicionar prazo ou audiência" `bi-plus-lg` | Clique | `pzAbrirNovo`: abre o modal com tipo Prazo, campos vazios e **Responsável pré-preenchido com `PASTAS[i][2]`, que é o documento do cliente (ex.: "CPF 006.462.251-70"), não o responsável (índice 3)** | Não | Agenda de prazos | dc:1460; dc:6900 |
| PZ-TRI-N02 | Prazos [NOVO-16/10] | Vazio "Nenhum prazo ou audiência. Use + para registrar ou aguarde a leitura do Push processual." | Automático | `pzVazio` | Não | Nenhuma | dc:1463 |
| PZ-TRI-04 | Prazos | Linha do prazo (pip colorido, título, meta "RESP · dd/mm HH:MM · ORIGEM", selo, ⋮) | Botão direito ou clique no ⋮ | Até 8 linhas (`slice(0,8)`); título com prefixo "Audiência · " quando tipo audiência [NOVO-16/10]; "Sem responsável" quando vazio | Não | Idem | dc:1464-1474; dc:6553-6566 |
| PZ-TRI-N03 | Prazos [NOVO-16/10] | Selos: "Hoje", "Faltam N dia(s)", "Vencido há N dia(s)"; tom vermelho (vencido ou ≤3 dias), âmbar (≤7), cinza | Automático | "Hoje" é novo; dias contados em corridos até a data | Não | Nenhuma | dc:6555-6556 |
| PZ-TRI-N04 | Prazos [NOVO-16/10] | Origens da lista (`pzLista`): Manual (`bj-pz-manual-v1`, não cumpridos), "Push processual" ou "(estimado)" (prazos de `BJProcesso.analisar` sobre movimentações, exceto cumpridos/encerrados/decorridos; audiência pelo nome do ato), Registro ("Registro dos expedientes", "Financeiro", "Registro" + " (estimado: conferir)") pelo leitor `lerDatas` sobre registros não sistêmicos, "Meta" (metas abertas com prazo dd/mm/aaaa) | Automático | Deduplica por tipo+data nos registros e por título+prazo nas metas; ordena por dias. Semente: 3 prazos manuais da pasta 1180 (+2, +8, +20 dias) gravados uma vez (`_seed`). **Observação**: a data base do leitor usa `r.em || r.data || r.criadoEm`, mas registros têm `criado`; "em 15 dias" é contado a partir de hoje, não da data do registro. Registros de Detalhes (`ctx:'observacoes'`) saem com origem genérica "Registro" | Não | Agenda unificada; leitura de prazos no servidor | dc:6391-6402, 6360-6361 |
| PZ-TRI-N05 | Prazos [NOVO-16/10] | Leitor de datas `Component.lerDatas(txt, base)` exposto como `window.BJDatas.ler` (com `somarDias`, `diaUtil`) | Programático (`componentDidMount`) | Divide em frases; tipos por regex (Audiência, Perícia, Sessão de julgamento, Sustentação oral, Prazo para contestação, Prazo recursal, Prazo para manifestação, Protocolo, Vencimento de pagamento, Reunião, prazo genérico); datas numéricas e por extenso (ano seguinte se já passou), "prazo de N dias úteis/corridos" (estimado), "amanhã", "hoje"; redesignação usa a data após "para"; ignora contexto passado (realizado, cumprido, protocolado em…); extrai hora "às 14h30". Dias úteis: fim de semana, 9 feriados fixos MM-DD e recesso 20/12 a 20/01 | Não | Motor de prazos no servidor | dc:6346, 6363-6390 |
| PZ-TRI-N06 | Prazos [NOVO-16/10] | Publicação para outras telas | Automático (montagem +1,2 s; troca de pasta, metas, análise ou prazos; demais atualizações com atraso de 2 s) | `pzPublicar`: grava `bj-prazos-pasta-v1[N]` (lista resumida) e `bj-ativ-pasta-v1[N]` (dias com atividade: registros criados/editados, metas, prazos criados/cumpridos), com `_em`; só grava se mudou | Não | Endpoint de prazos/atividade por pasta para Lista e Agenda | dc:6412-6424 |
| PZ-TRI-N07 | ⋮ prazo [NOVO-16/10] | Marcar como cumprido `bi-check2-circle` (só manual) | Clique no item | `pzCumprir`: marca `cumprido`, some da lista, `autoReg('prazo','Prazo cumprido: …')`, aviso "Prazo marcado como cumprido". **Sem confirmação e sem desfazer** | Não | `PATCH` prazo | dc:6559; dc:6403 |
| PZ-TRI-N08 | ⋮ prazo [NOVO-16/10] | Editar `bi-pencil` (só manual) | Clique | Abre o modal com os dados (título "Editar prazo") | Não | Idem | dc:6559 |
| PZ-TRI-N09 | ⋮ prazo [NOVO-16/10] | Excluir `bi-trash3` (só manual) | Clique | `pzExcluir`: remove de `bj-pz-manual-v1`, `autoReg('prazo','Prazo excluído: …')`, aviso "Prazo excluído". **Sem confirmação** e item sem estilo de perigo | Não | `DELETE` prazo | dc:6559; dc:6404 |
| PZ-TRI-05 | ⋮ prazo | Ver nas metas `bi-check2-square` | Clique | Vai para a aba Metas | Não | Nenhuma | dc:6559 |
| PZ-TRI-06 | ⋮ prazo | Encaminhar via Chat I.A `bi-send` | Clique | `despachar({tipo:'meta', …})` | Não | Chat | dc:6559 |
| PZ-TRI-07 | ⋮ prazo | Alertar PRIMEIRONOME `bi-bell` | Clique | `BJCentral.notificar({tipo:'toque', titulo:'Alerta de prazo · Pasta N'})`; avisos "Alerta enviado para X" / "Responsável não encontrado" / "Não consegui enviar o alerta" | Não | Notificações | dc:6559 |
| PZ-TRI-08 | ⋮ prazo | Adicionar à agenda (.ics) | Clique | Gera VEVENT de dia inteiro com o ano corrente e baixa `prazo-N.ics` | Não | Nenhuma (local) | dc:6558-6559 |
| PZ-TRI-09 | ⋮ prazo | Copiar `bi-clipboard` | Clique | Copia "título · selo · meta · Pasta N", aviso "Prazo copiado" | Não | Nenhuma | dc:6559 |
| PZ-TRI-N10 | Modal prazo [NOVO-16/10] | Janela "Novo prazo ou audiência" / "Editar prazo" (420px, cabeçalho #0a6fa0, `bi-calendar-event`, ×) | Clique no fundo fecha; clique interno para (`pzParar`) | **Sem tecla Esc e sem Enter** (não há `onKeyDown`) | Não | Nenhuma | dc:2910-2925; dc:6901 |
| PZ-TRI-N11 | Modal prazo [NOVO-16/10] | Seletor Prazo `bi-hourglass-split` / Audiência `bi-people` | Clique | Ativo fundo #0a6fa0 texto branco | Não | Nenhuma | dc:2915; dc:6902 |
| PZ-TRI-N12 | Modal prazo [NOVO-16/10] | DESCRIÇÃO (placeholder "Ex.: Protocolar contestação"), DATA (date), HORA (time, rótulo "(opcional)" no prazo e sem sufixo na audiência), RESPONSÁVEL (texto livre "Nome do responsável") | Digitação (`onInput`) | Hora não é exigida nem na audiência; responsável é texto livre (sem vínculo a colaborador) | Não | Colaboradores | dc:2916-2918; dc:6903 |
| PZ-TRI-N13 | Modal prazo [NOVO-16/10] | Nota "Prazos do Push processual e das Metas entram sozinhos nesta lista." | Nenhum | Informativa | Não | Nenhuma | dc:2919 |
| PZ-TRI-N14 | Modal prazo [NOVO-16/10] | Erro "Informe a descrição e a data"; Cancelar; Salvar | Clique | `pzSalvar`: novo ou edição em `bj-pz-manual-v1[N]` {id, tipo, titulo, resp, data, hora, criadoEm, cumprido:null}; `autoReg('prazo', 'Prazo adicionado: / Audiência agendada: / Prazo editado: TÍTULO · dd/mm/aaaa HH:MM')`; avisos "Prazo registrado" / "Audiência registrada" | Não | `POST/PATCH` prazo | dc:2920-2921; dc:6405-6411 |

#### A3.4 Trilho: Clientes e Documentos

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-TRI-N15 | Clientes | Contagem dinâmica `cliN` [NOVO-16/10] | Automático | Número de linhas do trilho (4 fixos + vinculados); antes era "4" fixo. Conta duplicados | Não | Clientes da pasta | dc:1480; dc:6900 |
| PZ-TRI-10 | Clientes | "+" Vincular cliente; busca "Nome ou CPF do cliente" (autoFocus); resultados com selo Já vinculado / Incompleto / Completo e CPF mascarado ("***.462.251-**" salvo se digitado completo); vazio "Cliente ainda não cadastrado." + "Cadastrar cliente" | Clique; digitação; Enter escolhe o 1º; Esc fecha | Busca a partir de 2 caracteres (nome sem acento ou dígitos ≥3); escolher grava `bj-cli-vinc-v1[N]`; Cadastrar abre o Cadastro e vincula ao salvar ("Cliente X cadastrado e vinculado à pasta.") | Não | Busca no cadastro central; vínculo cliente-pasta | dc:1482-1500; dc:3450-3465, 3549 |
| PZ-TRI-11 | Clientes | Linha do cliente: iniciais (principal em #0a7aad), nome (botão abre o Cadastro), ícone de cadastro verde/âmbar com dica, selo Principal, CPF (com alias) e pasta "Abrir cliente" com animação | Clique no nome; hover/foco na pasta | **Sem botão direito** na linha (não há `onContextMenu`), apesar do checklist. Link "Abrir cliente" tem `href="#"` e **nenhum `onClick`** (só anima no hover/foco) | Não | Cadastro | dc:1501-1514; dc:6525-6546 |
| PZ-TRI-12 | Clientes | Selo "CPF duplicado" (dica "Outro cliente vinculado tem o mesmo CPF") | Automático | Contagem por CPF em `cliBase` | Não | Nenhuma | dc:1507; dc:6531-6534 |
| PZ-TRI-13 | Clientes | Estrela e lixeira na linha do cliente | Nenhum | **Não existem no online** (extra do sistema) | Não | Nenhuma | (não encontrado) |
| PZ-TRI-14 | Documentos | Cartão "Documentos" (contagem "6" fixa), chevron "Abrir aba Documentos", 3 documentos com ícone de tipo 30px | Clique; Enter/Espaço (`onKeyDown`) | Abre o visualizador (`abrirArquivo`) com a lista dos 3; chevron vai para a aba Documentos | Não | Documentos da pasta | dc:1517-1533; dc:6575-6584, 7022 |

---

### A4. Aba Metas

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MET-01 | Metas | Título "Metas da pasta" | Nenhum | | Não | | dc:1543 |
| PZ-MET-02 | Metas | Filtros Abertas · Atrasadas · Concluídas · Todas com contagem | Clique | `metaFilter` (estado; padrão Abertas) | Não | Nenhuma | dc:1544-1548; dc:6631-6657 |
| PZ-MET-03 | Metas | Nova meta `bi-plus-lg` | Clique | `nm.abrir`: abre a janela Nova meta com prazo padrão hoje+7 | Não | Ver PZ-MOD-01..05 | dc:1550; dc:4042 |
| PZ-MET-04 | Linha | Ícone concluída, nº local "N.", título, criador `bi-person`, responsáveis `bi-people`, prazo ("vence dd/mm/aaaa", "N dias em atraso", "concluída no prazo …"), selo de situação, datas "prazo · mod. data" | Clique abre o relatório | Ordem pelo número local; borda esquerda 3px por estado; fundo verde claro concluída, rosado atrasada | Não | Metas da pasta | dc:1554-1574; dc:6661-6689 |
| PZ-MET-05 | Linha | Nº global "Nº 0042" com dica "Meta N desta pasta · nº G na sequência geral do sistema" | Hover | `metaNums` com `bj-metas-seq-v1` | Não | Sequência global de metas | dc:1557; dc:5336, 6680 |
| PZ-MET-06 | Linha | Editar nome inline (input maxLength 160, Salvar, Cancelar) | Menu "Editar nome"; Enter salva; Esc cancela | Vazio: aviso "O nome não pode ficar vazio"; salva `renomeada` e log `bj-metas-renomeadas-log` (até 200), aviso "Nome da meta alterado" | Não | `PATCH` meta + auditoria | dc:1558-1564; dc:6676-6677 |
| PZ-MET-07 | Linha | Sino "Alertar para verificar" (fica laranja #e8590c e balança; dica "Alertado: X às HH:MM") | Clique | Só metas abertas e com `BJCentral`; abre `gm` | Não | Notificações | dc:1575; dc:4673-4683 |
| PZ-MET-08 | Menu do sino | Responsáveis "NOME · responsável" | Clique | `C.alertar({…, chave:'meta:N:id'})`; aviso "🔔 X foi alertado para verificar" ou motivo da recusa | Não | Idem | dc:4681 |
| PZ-MET-09 | Menu do sino | Separador + demais usuários | Clique | Idem | Não | Idem | dc:4682 |
| PZ-MET-10 | ⋮ meta / botão direito | Abrir relatório `bi-file-earmark-text` | Clique no ⋮ ou botão direito na linha | Menu próprio `mt` (220px) posicionado no cursor (contextmenu) ou sob o botão; overlay fecha com clique ou botão direito | Não | Nenhuma | dc:1576, 1555, 1580-1588; dc:6663, 3731 |
| PZ-MET-11 | ⋮ meta | Marcar como concluída / Reabrir meta | Clique | Alterna `concluida`, `mod` = hoje, zera atraso; notifica responsáveis (`meta_concluida`); toast com Desfazer | Não (só desfazer) | `PATCH` meta; notificação | dc:3722, 3732 |
| PZ-MET-12 | ⋮ meta | Editar nome `bi-pencil` | Clique | Liga PZ-MET-06 | Não | | dc:3733 |
| PZ-MET-13 | ⋮ meta | Duplicar `bi-copy` | Clique | Cria "TÍTULO (cópia)" aberta; toast "Meta duplicada" com Desfazer | Não | `POST` meta | dc:3723, 3734 |
| PZ-MET-14 | ⋮ meta | Encaminhar via Chat I.A | Clique | `despachar({tipo:'meta', …, link})` | Não | Chat | dc:3735 |
| PZ-MET-15 | ⋮ meta | Copiar link | Clique | Copia `origin + /tarefas/equipe?pasta=N&meta=id`; toast "Link da meta copiado" | Não | Rota do módulo Metas | dc:3725-3726, 3736 |
| PZ-MET-16 | ⋮ meta | Abrir em Metas | Clique | **Só mostra toast** "Abrindo em Metas: /tarefas/equipe?…" (não navega) | Não | Módulo Metas | dc:3727, 3737 |
| PZ-MET-17 | ⋮ meta | Excluir (vermelho) | Clique | Remove da lista, fecha o relatório se aberto; toast "Meta excluída" com Desfazer (4,2 s). **Sem confirmação** | Não (só desfazer) | `DELETE` meta | dc:3724, 3739 |
| PZ-MET-18 | Metas | Toast com "Desfazer" (concluir, reabrir, duplicar, excluir) | Clique | Restaura (`rest`), remove (`del`) ou reinsere (`ins`) e mostra "Desfeito" | Não | Idem | dc:1634-1636; dc:3720, 3771 |
| PZ-MET-19 | Metas | Drawer "Relatório da meta" (460px): posição "N de M", ▲ ▼, ×, selo, título, prazo, campos (Criada por, Responsáveis, Prazo, Última modificação, Pasta, Situação), Histórico, Outras metas desta pasta, Marcar como concluída/Reabrir, "Abrir em Metas" | Clique; overlay fecha | Navegação percorre todas as metas (não só o filtro). **Esc não implementado** apesar do `title="Fechar (Esc)"` (nenhum handler de tecla para `metaAberta`) | Não | Metas | dc:1589-1633; dc:3742-3769 |
| PZ-MET-20 | Metas | Vazio "Nenhuma meta neste filtro" + "Troque o filtro acima ou crie uma nova meta." | Automático | | Não | | dc:1638-1644 |
| PZ-MET-21 | Trilho | Andamento das metas: "C/T concluídas", barra 7px, legenda concluídas, pendentes, atrasadas | Nenhum | Calculado de `metasData` | Não | Nenhuma | dc:1648-1662; dc:7010-7014 |
| PZ-MET-22 | Trilho | Precisa de atenção (metas atrasadas) + nota "Metas atrasadas continuam contando dias até serem concluídas." | Nenhum | `atencao` | Não | Nenhuma | dc:1664-1678; dc:6693-6696 |
| PZ-MET-23 | Trilho | Responsáveis nas metas (3 nomes e contagens **fixos**) | Nenhum | Lista codificada (Mariana Costa 3, Farlei 1, Samuel 1), não calculada | Não | Agregado por responsável | dc:1680-1692; dc:6697-6706 |
| PZ-MET-24 | Trilho | Botão de chat por pessoa `bi-chat-dots` | Clique | `BJCentral.conversar(nome)` | Não | Chat | dc:1684; dc:6704 |
| PZ-MET-25 | Metas | Meta nova realçada (animação `metaNova` 4 s) e alerta forte na aba por 30 s | Automático após criar | `metaDestaque`, `metaNovaAte` | Não | Nenhuma | dc:4055-4056, 6675 |
| PZ-MET-N01 | Metas | Persistência `bj-metas-pasta-v1` {metas, em} | Automático | Carrega na montagem (mescla com as sementes por id), grava em `renderVals` e em `componentDidUpdate` quando `metasData` muda. **Não é indexada por pasta**: as mesmas metas aparecem em todas as pastas | Não | Metas por pasta | dc:6359, 6424, 6482 |
| PZ-MET-N02 | Metas | Navegação por evento `bj-navegar` {pasta, aba, meta, filtro} | Evento global | Abre pasta/aba/meta; meta inexistente mostra "Meta não encontrada. Ela pode ter sido excluída." | Não | Deep link | dc:4435-4442 |
| PZ-MOD-01 | Nova meta | Janela não modal 580px: cabeçalho arrastável (pode sair da tela mantendo 48px), recolher (botão ou duplo clique no cabeçalho), × | Ponteiro, duplo clique, clique | `nmVals` | Não | Nenhuma | dc:81-83; dc:3940-3961 |
| PZ-MOD-02 | Nova meta | Título, Descrição (contador "/600"), Prazo com calendário próprio (D S T Q Q S S, fim de semana em cinza, passado indisponível, Hoje, Limpar, ‹ ›), atalhos Hoje/Amanhã/+5 úteis/+15 úteis, chips de responsáveis com busca (Enter adiciona o 1º, Esc fecha), aviso de meta repetida ("Já existe a meta aberta…" + "Abrir meta existente"), "Criar meta" | Clique, digitação, teclado (Esc fecha lista ou janela; Enter em input salva) | Atalhos contam só fins de semana; data informativa "segunda-feira, dd de mês, em N dias. Cai em fim de semana." | Não | Colaboradores | dc:84-198; dc:4061-4166 |
| PZ-MOD-03 | Nova meta | Análise da I.A. do prazo jurídico (chip "I.A. dd/mm", prioridade, confiança, Prazo jurídico editável, Dias úteis, Folga, dicas no hover, "Conferir" com tabela e "Confirmar análise"/"Corrigir informações", "+ Informar origem do prazo" com Data da publicação e da ciência, "Antes de salvar, confira") | Clique, hover, foco | `IA_PRAZO` e aprendizado (`bj-prazo-aprendizado`). **Observação**: `Component.util`/`somaUteis` usados pela análise comparam `YYYY-MM-DD` com `Component.FERIADOS`, mas a versão online redeclara `static FERIADOS` em formato `MM-DD` (dc:6365) depois da lista com anos (dc:3962); a última declaração prevalece, então feriados nacionais (inclusive Carnaval e Corpus Christi) deixam de ser considerados nessa análise [NOVO-16/10] | Não | Motor de prazos | dc:93-169; dc:3962-4039, 4098-4147 |
| PZ-MOD-04 | Nova meta | Obrigatórios título, descrição, prazo e responsável; rodapé "Preencha: …" / "Criar meta e notificar os responsáveis"; botão cinza travado | Automático | `inval`, `falta` | Não | Nenhuma | dc:4044-4047, 4070-4071 |
| PZ-MOD-05 | Nova meta | Notificação dos responsáveis | Ao criar | Evento `bj-evento` {tipo:'meta_nova', para, titulo "Nova meta atribuída a você · PRIORIDADE", texto, ctx url} | Não | Notificações | dc:4057-4059 |

---

### A5. Aba Processo e `bj-processo.js`

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-PRO-01 | Cabeçalho | "Processos vinculados" + contagem | Nenhum | Contagem **fixa "1"** (não reflete apensos nem ausência de processo) | Não | Processos da pasta | dc:1702-1703 |
| PZ-PRO-02 | Cabeçalho | Interruptor "Administrativo sem processo" (laranja #e8873a ligado) | Clique | `admSPtoggle`: ligar com processo vinculado pede confirmação; `spMarcar` grava `bj-adm-sem-proc-v1[N]`, troca a trilha para a via administrativa (`autoReg('fase', …)`), grava decisão em `bj-semproc-dec-v1` quando há termos, audita, aviso | Ligar com processo: `cf` azul "Marcar como administrativo" / "A Pasta N tem o processo X vinculado. Marcar mesmo assim como "Administrativo sem processo"? O andamento passa para a via administrativa." | Classificação da pasta | dc:1705; dc:6999, 4757-4762 |
| PZ-PRO-03 | Cabeçalho | ⋮ do cabeçalho (também pelo botão direito sobre o próprio ⋮) | Clique ou botão direito | `procHdMenu` abre `gm` "Processos · Pasta N" | Não | | dc:1706; dc:6999 |
| PZ-PRO-04 | ⋮ cabeçalho | Relatório geral de processos `bi-file-earmark-bar-graph` | Clique | `spPDF()` abre a prévia de impressão com `spHTML(spAnalisar())` | Não | Relatório consolidado | dc:6999; dc:4819 |
| PZ-PRO-05 | ⋮ cabeçalho | Enviar à Controladoria agora `bi-send` | Clique | `spEnviar(true)` → `BJCentral.semProcNotificar`; avisos "Relatório enviado à Controladoria pelo Chat I.A" / "Chat I.A indisponível" | Não | Notificação à controladoria | dc:6999; dc:4816-4818 |
| PZ-PRO-06 | ⋮ cabeçalho | Marcar / Desmarcar como administrativo sem processo `bi-briefcase` | Clique | `spMarcar(n, !on)` **sem** a confirmação do interruptor | Não | Idem PZ-PRO-02 | dc:6999 |
| PZ-PRO-07 | Cabeçalho | Vincular processo `bi-link-45deg` | Clique | `mVincProc` → modal Vincular (modo principal) | Não | Ver PZ-MOD-08 | dc:1707 |
| PZ-PRO-08 | Cabeçalho | Vincular apensado `bi-paperclip` | Clique | `vincApenso` → modal Vincular (modo apenso) | Não | Idem | dc:1708; dc:4515 |
| PZ-PRO-09 | Cabeçalho | Peticionar na pasta (botão azul uppercase) | Clique | Navega para `./07 - Peticionar 1.0.dc.html?pasta=N` | Não | Tela de peticionamento | dc:1709; dc:3133 |
| PZ-PRO-10 | Cartão | Pílula "Vinculado em dd/mm/aaaa" (ou "Processo vinculado"), nº CNJ (link sem ação), copiar, selo Principal `bi-star-fill`, ⋮; botão direito no cartão | Clique; botão direito | Copiar: aviso "Número do processo copiado". Link do número tem `href="#"` sem handler | Não | | dc:1715-1726, 1817; dc:3030, 3050 |
| PZ-PRO-11 | Cartão | Selo do apenso (tipo) + X "Desvincular este apensado" | Clique | Marca `removido` em `bj-pasta-apensos[N]`, `autoReg('proc','apensado X desvinculado.')` | `cf` vermelha: título "Desvincular apensado", texto "Desvincular o apensado X da pasta? O registro fica no histórico.", botão "Desvincular" | `DELETE` vínculo | dc:1723; dc:6709 |
| PZ-PRO-12 | Cartão | Ação, Classe, Tribunal (derivado do CNJ), Situação | Nenhum | Situação com ponto verde pulsante **sempre verde**, mesmo "Suspenso" ou "Aguardando audiência" | Não | | dc:1728-1748; dc:4504 |
| PZ-PRO-13 | Cartão | Órgão julgador, Valor da causa, Última distribuição com "Não informado" em itálico | Nenhum | Manual prevalece sobre PJe | Não | `GET /processos/{n}/pje` | dc:1752-1759; dc:3002-3006, 3038 |
| PZ-PRO-14 | Cartão | Marcas (Justiça gratuita, Tutela/liminar, Prioridade, Segredo de justiça, Juízo 100% digital) | Nenhum | Só as verdadeiras | Não | Idem | dc:1760-1766; dc:3039-3040 |
| PZ-PRO-15 | Cartão | Linha de fonte ("Dados do PJe salvos em…", "Preenchido manualmente em…", "Integração com o PJe não configurada. Preencha manualmente.", "Consultando o PJe…"), "Atualizar", "Buscando dados no PJe…" | Clique em Atualizar | Com `BLUEJUS_API_BASE` busca sozinho na primeira vez; Atualizar zera `pjeEm` e rebusca | Não | Integração PJe/MNI | dc:1749-1751, 1767-1773; dc:2995-3001, 3016-3018, 3035 |
| PZ-PRO-16 | Cartão | Edição inline (11 campos: classe, assuntos (um por linha), jurisdição, órgão, distribuição, valor, 5 flags Sim/Não/Não informado), Cancelar, Salvar; selo "manual" | Menu Editar | Salvar grava só o que difere do PJe em `bj-pje-v2[n].manual` + `manualEm` | Não | `PATCH` dados manuais do processo | dc:1792-1814; dc:3019-3025, 3047-3048 |
| PZ-PRO-17 | Cartão | Painel "Ver todas as informações" / "Mostrar menos" | Clique | `procExp[n]` (estado) | Não | | dc:1768, 1774-1791; dc:3034 |
| PZ-PRO-18 | ⋮ processo | Adicionar nota técnica | Clique | Abre o editor de nota sob o cartão e foca | Não | Notas técnicas | dc:3060; dc:5647 |
| PZ-PRO-19 | ⋮ processo | Abrir no PJe | Clique | `pjeAbrir()`: copia o número; com `BLUEJUS_PJE_DOC_URL` abre nova aba, senão aviso "ID N copiado. Abra no PJe do tribunal (endereço do PJe ainda não configurado)" | Não | URL do PJe por tribunal | dc:3061; dc:5573 |
| PZ-PRO-20 | ⋮ processo | Ver todas as informações / Mostrar menos | Clique | Alterna painel | Não | | dc:3062 |
| PZ-PRO-21 | ⋮ processo | Editar dados do processo | Clique | Abre PZ-PRO-16 | Não | | dc:3063 |
| PZ-PRO-22 | ⋮ processo | Atualizar dados do PJe (só com integração) | Clique | Idem Atualizar | Não | PJe | dc:3064 |
| PZ-PRO-23 | ⋮ processo | Ver movimentações (Push) | Clique | Vai para a aba Push | Não | | dc:3065 |
| PZ-PRO-24 | ⋮ processo | Copiar número | Clique | Aviso "Número do processo copiado" | Não | | dc:3067 |
| PZ-PRO-25 | ⋮ processo | Copiar resumo do processo | Clique | Texto com Ação, Classe, Tribunal, Situação, Órgão, Valor, Pasta; aviso | Não | | dc:3057, 3068 |
| PZ-PRO-26 | ⋮ processo | Compartilhar | Clique | `navigator.share` ou copia "Resumo copiado para compartilhar" | Não | | dc:3069 |
| PZ-PRO-27 | ⋮ processo | Encaminhar via Chat I.A | Clique | `despachar({tipo:'processo'})` | Não | Chat | dc:3070 |
| PZ-PRO-28 | ⋮ processo | Relatório geral de processos | Clique | `spPDF()` | Não | | dc:3071 |
| PZ-PRO-29 | ⋮ processo | Imprimir resumo | Clique | `gmImprimir('Processo N', tabela)` | Não | | dc:3071 |
| PZ-PRO-30 | ⋮ processo | Desvincular da pasta `bi-x-lg` | Clique | Marca `procDesv[N]` (**só estado**, volta ao recarregar), `autoReg('proc','processo X desvinculado da pasta.')`, auditoria, aviso. **Observação**: o mesmo menu aparece nos cartões de apenso; lá "Desvincular da pasta" esconde o **principal** (a chave é a pasta, não o número) | `cf` vermelha: título "Desvincular o processo X da Pasta N?" texto "Os dados do processo continuam salvos e você pode vincular de novo." | `DELETE` vínculo | dc:3073; dc:6709 |
| PZ-PRO-31 | Notas | Nota técnica sob o cartão: selo "Nota técnica" #0a5f86, avatar, autor, "hoje, HH:MM · editada", ⋮ "Opções da nota" | Clique ou botão direito | `pushNotaVals('proc:'+n)`; armazenamento `bj-push-notas-v1[N]['proc:n']` | Não | Notas técnicas | dc:1820-1836; dc:5644-5662 |
| PZ-PRO-32 | ⋮ nota | Responder com outra nota | Clique | Abre editor novo | Não | | dc:5655 |
| PZ-PRO-33 | ⋮ nota | Copiar texto | Clique | Aviso "Nota copiada" | Não | | dc:5656 |
| PZ-PRO-34 | ⋮ nota | Encaminhar via Chat I.A | Clique | `despachar({tipo:'push'})` | Não | | dc:5657 |
| PZ-PRO-35 | ⋮ nota | Editar / Editar · bloqueado | Clique | Autor até 15 min; primário sem limite; bloqueado avisa a regra | Não | Regras de edição | dc:5659 |
| PZ-PRO-36 | ⋮ nota | Excluir / Excluir · bloqueado | Clique | Remove, audita, aviso "Nota técnica excluída" | `cf` vermelha: "Excluir esta nota técnica?" (título "Confirmar exclusão") | `DELETE` nota | dc:5660 |
| PZ-PRO-37 | Notas | Editor "Nova nota técnica"/"Editar nota técnica" (placeholder "Explique o processo ou o despacho… Use @ para mencionar alguém."), Cancelar, Salvar nota | Clique; Ctrl/Cmd+Enter salva; Esc cancela | Vazio só fecha; edição guarda versões | Não | | dc:1837-1846; dc:5649-5652 |
| PZ-PRO-38 | Notas | Primário edita e exclui sem limite | Regra | `pode = prim || autor e <15 min` | Não | Papéis | dc:5646 |
| PZ-PRO-39 | Notas | Faixa "Notas técnicas 0" sob todo cartão | Nenhum | **Não existe** no template online (só a lista quando há notas) | Não | | (não encontrado) |
| PZ-PRO-40 | Aba | Nota de rodapé "O processo principal é o que aparece no cabeçalho da pasta e nas listagens. Uma pasta pode ter vários processos vinculados." | Nenhum | Sem estado vazio específico quando não há processo | Não | | dc:1850-1853 |
| PZ-PRO-41 | Cartão | Situação legível ("Em andamento") | Nenhum | Usa `proc.situacao` | Não | | dc:1745 |
| PZ-MOD-08 | Vincular | Modal "Vincular processo"/"Vincular apensado": abas Buscar existente / Cadastrar novo; busca no catálogo (pastas, apensos, Push) com lista; seleção com limpar; Cadastrar novo com subabas Informações/Partes/Movimentações; número CNJ com máscara e tribunal automático; "Buscar no CNJ" (simulado 700 ms com catálogo, memória da análise e Push); Órgão*, Classe*, Assunto*, Situação, Instância, Distribuição, Valor (máscara R$), Jurisdição, 5 flags; partes (tipo, nome, doc com máscara, papel) e movimentações (data, texto, tipo, órgão) com adicionar/remover; tipo de apenso (Embargos, Agravo de instrumento, Cumprimento de sentença, Execução, Impugnação, Recurso, Cautelar, Outro); Esc fecha | Clique, digitação, Esc | Salvar: valida "Selecione um processo para vincular." / "Informe o número completo no formato NNNNNNN-DD.AAAA.J.TT.OOOO." / "Preencha: número do processo, órgão julgador, classe processual, assunto processual."; apenso não pode ser o principal nem repetido; grava `bj-pasta-apensos` ou `bj-pasta-proc`, dados manuais em `bj-pje-v2`, `autoReg('proc', …)`, auditoria, fase "Em andamento" automática | Não | Consulta CNJ/DataJud; cadastro de processo | dc:287-352; dc:3133-3149, 4507-4563 |
| PZ-PRO-N01 | bj-processo.js | Motor `window.BJProcesso` | Programático | API: `lerPDF` (pdf.js 3.11.174 do jsDelivr), `lerZip`, `segmentar`, `capa`, `deMovimentacoes`, `classificar`, `determinacoes`, `analisar`, `refinarIA` (usa `window.claude.complete`, cache `bj-proc-ia-cache`), `memGravar`/`memLer`/`historico` (`bj-proc-analise-v1`, compactação progressiva), `util`, `somaUteis`, `proxUtil`, `uteisAte`, `setSuspensoes` | Não | Leitura de autos e análise no servidor | bj-processo.js:7-358 |
| PZ-PRO-N02 | bj-processo.js | `analisar(entrada, ctx)` | Programático | Deduplica documentos, extrai determinações, calcula termo (disponibilização/intimação; estimado sem disponibilização), cruza com manifestações (cumprido, fora, andamento, decorrido, possível decurso, sem efeito, indefinido), pendências ordenadas, fase processual (inicial a trânsito), próximo ato e cadeia, inconsistências, riscos, providências; devolve `{prazos, pendencias, fase, proximo, …}` | Não | Idem | bj-processo.js:245-308 |
| PZ-PRO-N03 | Processo | Uso da análise na pasta | Automático | `procAtual()` lê a memória; os prazos alimentam o trilho Prazos [NOVO-16/10] (origem "Push processual"); o disparo da leitura ("Buscar agora", ler PDF) fica na aba Push (dc:5702) e no checklist de Documentos (dc:4246), fora deste escopo; relatório `pa` "Análise do processo" (fato/inferência por camada, origem clicável) e visor `pd` de documento | Não | Idem | dc:5509-5576, 225-257, 6394 |
| PZ-PRO-N04 | Processo | Dias úteis em `bj-processo.js` | Programático | Feriados `dd/mm` e móveis 2026/2027 com ano, suspensões por tribunal (vazio) | Não | Calendário do tribunal | bj-processo.js:15-26 |

---

### A6. Aba Financeiro e `bj-contas.js`

| ID | Área | Elemento | Gatilho | Comportamento | Confirmação | Dependências (backend) | Referência |
|---|---|---|---|---|---|---|---|
| PZ-FIN-01 | Faixa | Contrato: selo Pendente (vermelho) ⇄ Assinado (verde) | Clique | `toggleContrato`: alterna, sincroniza a fase Contrato (`contratoFase`), `autoReg('contrato', 'marcado como assinado.' / 'reaberto como pendente.')`. **Sem confirmação**. Estado `contrato` não persiste em localStorage (só reflete via fases) | Não | Contrato da pasta | dc:1863-1868; dc:6988-6996, 6184-6189 |
| PZ-FIN-02 | Faixa | Pró-bono: "Pró-bono ativo" / "Não é pró-bono" | Clique | `toggleProBono`: alterna (estado), `autoReg('probono', …)`. Sem confirmação; não persiste | Não | Flag da pasta | dc:1870-1875; dc:6974-6987 |
| PZ-FIN-03 | Faixa | Valor da causa = soma dos lançamentos (exceto % e previstos), "total dos pagamentos · sincronizado" com dica | Nenhum | Campo de edição presente mas com `sc-if value=false` (desligado); `vcEditar/vcSalvar` existem sem uso | Não | Cálculo financeiro | dc:1877-1892; dc:3599, 3643, 3692-3695 |
| PZ-FIN-04 | Faixa | Média por CPF "R$ 3.215,00" · "4 clientes vinculados" | Nenhum | **Valores fixos** no template | Não | Cálculo por cliente | dc:1894-1900 |
| PZ-FIN-05 | Relatório | "Relatório financeiro" + contador **fixo "2"** + compositor "Adicionar observação…" (contexto `financeiro`) | Evento `bj-enviar` | Grava registro `ctx:'financeiro'`; aviso "Anotação financeira registrada"; pergunta de andamento | Não | Registros | dc:1907-1915; dc:6342-6344 |
| PZ-FIN-40 | Relatório | Lista de observações sem rolagem interna | Nenhum | Cresce com a página | Não | | dc:1916-1949 |
| PZ-FIN-06 | Cartão | editado, lápis, Responder e caixa de resposta | Clique; Ctrl+Enter; Esc | Igual PZ-DAD-07 a 11 (aba "Financeiro") | Não | | dc:1930-1946; dc:6964-6967 |
| PZ-FIN-07 | Cartão | Presença do autor | Clique | Igual PZ-DAD-05 | Não | | dc:1927-1928 |
| PZ-FIN-08 | Cartão | Encaminhar (botão) | Clique | Igual PZ-DAD-10 | Não | | dc:1934 |
| PZ-FIN-09 | Relatório | Cartão de sistema "Andamento da pasta" | Nenhum | Faixa fixa "Andamento da pasta" | Não | | dc:1922 |
| PZ-FIN-10 a 15 / PZ-CTX-10 | Botão direito no registro | Responder, Copiar texto, Encaminhar, Destacar / Remover destaque, Editar · N min / bloqueado, Excluir comentário · N min / permanente | Botão direito | Mesmo `regsLinha.menu` de PZ-DAD-16 com aba "Financeiro" | Excluir: `cf` vermelha igual PZ-DAD-16 | | dc:1921; dc:6303 |
| PZ-FIN-16 | Arquivos | "Arquivos" + contagem + "Adicionar" | Clique em Adicionar | **Adicionar sem handler**. Lista 3 arquivos fixos menos os ocultados (`bj-itens-excluidos-v1['fin:N:nome']`); **não há "até 3 + ver os outros N"** no online | Não | Upload de arquivos financeiros | dc:1956-1982; dc:6731-6738, 6950-6962 |
| PZ-FIN-17 | Arquivos | Selo "Assinado" | Nenhum | Nenhum dos 3 fixos é assinado | Não | | dc:1972-1974 |
| PZ-FIN-18 | ⋮ arquivo (e botão direito) | Abrir | Clique | Visualizador com a lista | Não | | dc:6953 |
| PZ-CTX-11 | Arquivos | Duplo clique na linha abre | Duplo clique | Template usa `onDblClick`; o runtime do Designer (`support.js` EVENT_MAP) só mapeia `ondoubleclick`, e o fallback gera `onDblclick`, que o React ignora: **duplo clique provavelmente inoperante** (não verificado em execução) | Não | | dc:1966; support.js EVENT_MAP |
| PZ-FIN-19 | ⋮ arquivo | Baixar | Clique | Com `BLUEJUS_API_BASE` vai para `/arquivos/download?nome=`; senão "Download disponível quando o arquivo vier do servidor" | Não | Download | dc:6954 |
| PZ-FIN-20 | ⋮ arquivo | Copiar nome do arquivo | Clique | Aviso "Nome copiado" | Não | | dc:6955 |
| PZ-FIN-21 | ⋮ arquivo | Encaminhar via Chat I.A | Clique | `despachar({tipo:'documento'})` | Não | | dc:6956 |
| PZ-FIN-22 | ⋮ arquivo | Enviar por e-mail ao cliente | Clique | `mailto:` com o 1º e-mail ativo de `cliMail`; sem e-mail vai para Dados e avisa "Cadastre um e-mail do cliente para enviar" | Não | E-mails do cliente | dc:6957 |
| PZ-FIN-23 | ⋮ arquivo | Ver em Documentos | Clique | Vai para a aba Documentos | Não | | dc:6958 |
| PZ-FIN-24 | ⋮ arquivo | Excluir (vermelho) | Clique | Oculta (`gmOcultar('fin:N:nome')`), aviso "Arquivo excluído" | `cf` vermelha: "Excluir o arquivo "NOME" do Financeiro?" (título "Confirmar exclusão") | `DELETE` arquivo | dc:6960 |
| PZ-FIN-25 | Arquivos | Interruptor "Reduzir tamanho ao enviar" + nota (perda de assinatura digital / mantém original) | Clique | Estado `reduzir` (não persiste) | Não | Compressão no upload | dc:1983-1989; dc:6968-6973 |
| PZ-FIN-26 | Pagamentos | Título + total de lançamentos; "R$ X" (botão) "recebidos de R$ Y"; barra; "+ R$ Z previsto em êxito e sucumbência"; "Próximos vencimentos" + "N já pagos"; linhas com estado Pago/Previsto/Vencida/Pendente | Clique no valor recebido | Clique no valor entra no modo edição (`edEntrar`: `finModoEd` e ver todos). Sem "ver todos": só 3 não pagos | Não | Lançamentos | dc:1992-2046; dc:3588-3641, 3673-3680 |
| PZ-FIN-27 | ⋮ Pagamentos | Adicionar pagamento | Clique | Abre o formulário (PZ-MOD-23) | Não | | dc:3684 |
| PZ-FIN-28 | ⋮ Pagamentos | Editar ou corrigir valores / Concluir edição | Clique | Alterna `finModoEd` (lápis nos valores e lixeiras) | Não | | dc:3685 |
| PZ-FIN-29 | ⋮ Pagamentos | Ver todos os lançamentos / Mostrar só os próximos | Clique | `finTodos` | Não | | dc:3686 |
| PZ-FIN-30 | ⋮ Pagamentos | Copiar resumo | Clique | Texto com cada lançamento; aviso "Resumo copiado" | Não | | dc:3688 |
| PZ-FIN-31 | ⋮ Pagamentos | Imprimir extrato | Clique | `gmImprimir('Extrato de pagamentos', …)` | Não | | dc:3689 |
| PZ-FIN-32 | ⋮ Pagamentos | Botão direito sobre o próprio ⋮ | Botão direito | Mesmo menu | Não | | dc:1998 |
| PZ-FIN-33 | Pagamentos | Botão "Concluir edição" `bi-check2` | Clique | `edSair` | Não | | dc:1997 |
| PZ-FIN-34 | Pagamentos | Correção inline (R$, input decimal, ✓, ×), "corrigido · era R$ X" com histórico na dica | Clique no valor com lápis; Enter salva; Esc cancela | Valor ≤0 marca erro; igual fecha; diferente pede confirmação e grava `ajustes` {de, para, por, em} em `bj-fin-v1[N]`, auditoria. (`histAdd` chamado não existe: sem registro no Registro) | `cf` azul: "Corrigir "DESC" de R$ A para R$ B?\n\nA alteração fica registrada no histórico com seu nome, data e hora." (título "Confirmar", porque a quebra está no fim do primeiro trecho) | `PATCH` lançamento + auditoria | dc:2027-2039; dc:3617-3636 |
| PZ-FIN-35 | Botão direito na linha | Corrigir valor | Botão direito | Abre a correção inline e foca | Idem PZ-FIN-34 ao salvar | | dc:2021; dc:3628 |
| PZ-FIN-36 | Botão direito na linha (e chip de estado) | Marcar como recebido / Desfazer recebimento | Clique no chip ou menu | Alterna `pago`/`pagoEm`; percentual vira valor ao receber. **Sem confirmação e sem registro** | Não | `PATCH` lançamento | dc:2042; dc:3629, 3639 |
| PZ-FIN-37 | Botão direito na linha (e lixeira no modo edição) | Excluir lançamento | Clique | Remove de `bj-fin-v1[N]` | `cf` vermelha: título "Excluir lançamento", texto "Excluir "DESC" (R$ X)?" | `DELETE` lançamento | dc:2041; dc:3630, 3640 |
| PZ-FIN-38 | Pagamentos | Sufixo "· juros N% a.m." | Nenhum | Quando o lançamento tem juros | Não | | dc:3616 |
| PZ-FIN-39 | Pagamentos | Vazio "Nenhum lançamento. Use Adicionar pagamento." + botão "Adicionar pagamento" | Clique | `fin.abrir` | Não | | dc:2047-2053 |
| PZ-MOD-23 | Adicionar pagamento | Modal 540px "Adicionar pagamento" · "Valor da causa R$ X · cálculo automático": Tipo (Contratuais, Êxito, Sucumbência, Custas com dica), Base do valor (Valor fixo / % da causa), Valor total ou %, Entrada, Parcelas 1 a 60, 1º vencimento, Juros (Sem/Com + % a.m.), "Entrada já recebida hoje", Prévia (até 5 + "+ N parcelas"), erro, Cancelar, "Gerar N lançamentos"/"Adicionar" | Clique; digitação; Esc; clique no fundo | Tabela Price quando há juros; última parcela absorve centavos; entrada com vencimento hoje; grava `bj-fin-v1[N]`; liga "ver todos". Erros "Informe o valor total." / "Informe o 1º vencimento." | Não | Lançamentos | dc:2056-2140; dc:3645-3716 |
| PZ-MOD-24 | Adicionar pagamento | Êxito e Sucumbência | Clique no tipo | Pede só %; "Informe um percentual entre 0 e 100."; lançamento previsto sem vencimento (sucumbência padrão 10%, demais 20%) | Não | | dc:2115-2122; dc:3650-3652, 3700-3703 |
| PZ-FIN-N01 | Financeiro | Persistência `bj-fin-v1` {N: {valorCausa, itens}} com semente `FIN_SEED` (4 lançamentos) | Automático | | Não | | dc:2933, 3574-3581, 3592-3593 |
| PZ-FIN-N02 | Financeiro | Financeiro na impressão (`impFinanceiro`) | Opção "Financeiro" da prévia | Tabela com situação Recebido/Vencido/Previsto/A receber e totais | Não | | dc:4845-4860 |
| PZ-GLB-17 | bj-contas.js | Multiconta (não é financeiro): contas = tenants simulados, papel primário/secundário, MASTER único, isolamento por prefixo `bjt:<conta>:` no localStorage, telas de convite/remoção/exclusão de conta | Clique (tela própria) | Remover pessoa e excluir conta usam **`confirm()` nativo** ("Remover esta pessoa da conta?", "Excluir esta conta e todos os dados dela? Esta ação não pode ser desfeita.") | `confirm()` nativo | Tenants e `tenant_users` | bj-contas.js:1-139 (84, 86) |

---

### Valores visuais-chave por bloco (inline no template)

| Bloco | Valores |
|---|---|
| Página | Fundo raiz #eaeef1, texto #12242f, fonte padrão Arial; contêiner max-width 1500px, padding 20px 26px 60px (dc:1140, 6904) |
| Cartão do cabeçalho | #fff, borda 1px #dde5eb, raio 4px, padding 20px 24px 0, sombra 0 1px 2px rgba(16,42,58,.04) (dc:1142) |
| Voltar | altura 30px, padding 0 11px 0 9px, 12.5px/500 #455c6b, ícone 13px, hover #e5f1f7/#0a7aad (dc:1145) |
| PASTA nnnn | 15px/700, letter-spacing .06em, #12242f, tabular (dc:1147) |
| Separadores linha 1 | 1×16px #dde5eb (dc:1150, 1169) |
| Prioridade | 11px/600 .05em uppercase, padding 3px 9px, raio 3px; Normal #4a6274/#eef2f5/#dde5eb; Prioridade #8a5a12/#fdf1dc/#f0dcb4; Urgente #a3232b/#fdeceb/#f2c9c6; menu 176px, item 13.5px padding 8px 10px (dc:6602-6619) |
| Marcadores | 11px/600 .04em uppercase, padding 3px 9px, raio 3px, ícone 9px opacidade .75; "+" 24×24 borda 1px dashed #cdd7de raio 3px (dc:1173, 6624) |
| Responsável | avatar 28px gradiente 135deg #1597bd→#0c7a9c 11px/700; nome 14.5px/700 máx. 170px; margin-right 22px; menu 310px com faixa gradiente #0d80a3→#0b6688, input 36px, lista máx. 300px, linha 7px 9px 13px, selecionada #e3f0f8/700 (dc:1177-1205, 3115-3126) |
| Setas | 32×32 raio 4px, #455c6b (desab. #c5d0d8), "N de M" 11.5px #8496a3, divisória border-right 1px #e6edf2 (dc:1209-1212, 6045) |
| ⋮ e menu | botão 34×32 15px; menu 260px top 40px, faixa gradiente 10.5px/700 .1em uppercase; item 13.5px #243845 padding 8px 10px, ícone 14px #5f7684; atalho 11px #8496a3; rótulos de seção 10.5px/700 .08em #8496a3; divisória 1px #eef2f5 margin 4px 8px; Excluir #a3232b hover #fdf1f0; interruptor 30×17 (ligado #0a7aad, desligado #cfd9e0, bola 13px) (dc:1215-1243, 6150-6151) |
| Linha 2 | foto 38px gradiente #e3f1f7→#cfe6f0 ícone 21px #0c7a9c; h1 28px/600 line-height 1.25 uppercase máx. min(50vw,640px); "Movimentado" 12.5px/500 #6b8494 com ponto 8px; documento 12px .02em #8496a3; dica #e3f0f8/#0b5f86 12.5px/700; IA altura 32px, SVG 22px, BLUEJUS 10.5px/700 .12em #0a6fa0, Intelligence 14.5px/700 (dc:1253-1271) |
| Cadeado (faixas) | sobreposição gradiente branco→#eaeef1 com blur 6px; título 17px/700; texto 13.5px #4f6878; botão 38px #0a7aad; faixa "só visualizar" #eef5fa/#0b5f86 12.5px/600 padding 8px 12px (dc:1275-1294) |
| Andamento | contêiner máx. max(820px, n×104px), margin -10px 150px 0 0; fase flex 1 0 86px; bolinha 26px borda 1.5px; feita #2b8ac0, atual borda #2b8ac0, finalizada #1e9b57, pulada/reativada #c0392b, futura borda #d6dee4 ícone #b3c0c9; linha 2px #2b8ac0/#e3e9ee; rótulo 11.5px; data 10px #9aabb7; bolha 14px #0a6fa0 9px bold; faixa sugestão #f2f8fc borda #d6e7f2 12.5px #0b4f73 máx. 720px, botão 26px #0a6fa0 (dc:1300-1320, 6050-6055) |
| Selos | carimbo 150×104, zoom .8, rotação -14°, faixa 34px 15/16px/900 .08em; ATIVO #1f9d61, CANCELADO #c62828, SUSPENSO #c27c0e, ARQUIVADO #5d7383; cadeado 64px gradiente 160deg #16a7c9→#2f7fc0→#6a54b8 (dc:6082, 6118-6127) |
| Abas | trilho #eef2f5 borda #e0e7ec raio 4px padding 4px gap 3px; aba 12.5px uppercase .03em padding 9px 10px, ativa 600 #0a7aad, inativa 500 #4f6878; ícone 14px (ativo accent, inativo #8496a3); selo 10.5px/600 padding 1px 6px raio 3px; indicador borda inferior 2.5px #0a7aad (pendência 3px #c0392f) com mola .62s (dc:1329, 6502-6506, 6908) |
| Cadastro e Situação | Cadastro 44px padding 0 15px 13px/700 .06em uppercase #eef2f5/#34505f, ponto 8px #1f9d61/#d39222; Situação 44px padding 0 14px 13.5px/700: Ativo #e6f4ec/#186c47 (ponto #1f9d61), Suspenso #fdf3e1/#8a5a00 (#d08a12), Cancelado #fdecea/#a3232b (#c0392b), Arquivado #eef2f5/#4f6878 (#8496a3); menu 236px (dc:3442-3443, 6132-6142) |
| Modal Editar dados / Cadastro | Cadastro 720px, máx. 92vh, cabeçalho gradiente 180deg #0d80a3→#0b6f8f, título 15px/700 uppercase; abas 40px 12.5px/700 (ativa #0a7aad com inset 2px); grade 6 colunas gap 10px 12px; campo 34px borda #d3dde4 13.5px; rótulo 10.5px/700 .08em #6b8494; foco borda #0a7aad + anel 3px rgba(10,122,173,.14); Réu: campos 36px, avatar 30px #fbeceb/#b03a2e, "Adicionar réu" 36px #0a7aad; Editar dados: abas 40px 13px/700 (dc:673-839, 4475-4483) |
| Dados: Registro | grade minmax(0,1fr) 356px gap 18px; cabeçalho padding 15px 20px #f5f6f7 borda #dfe4e8 h2 14px/600; contador 11px #7b93a2; editor padding 16px 20px #fafcfd; linha do tempo #eef2f5 padding 6px 18px 10px 46px, linha 2px #d3dbe1 em left 27px; pílula do dia 13px/700 #3c4a54 #dde3e8 padding 5px 16px; cartão padding 12px 16px; avatar 24px; autor 13px/700; botões 28×26 #7fb3cf hover #0a7aad; hora 12px #5f7684; ponto 13px; etiqueta 10.5px/700; faixa de sistema #2b8ac0 11px/700; "Ver anotações" 13px/600 #0a7aad padding 13px (dc:1374-1419, 6273-6308) |
| Dados: trilho | Ação/Processo padding 12px 16px, título 10.5px/700 .1em #7b93a2 ícone 12px #0c7a9c, ação 14.5px/700 (vazio 13.5px/500 #8a9aa6), número 14.5px/700, sub 12.5px #6b8494, chip apensos 22px #eaf3f8/#0b5f86 11.5px/700; cabeçalhos Prazos/Clientes/Documentos min-height 46px padding 8px 17px #eef2f5 borda #e0e7ec h2 13.5px/700, contador 11px/700 #0b5f86/#e5f1f7 padding 1px 8px; "+" 30×28 (Clientes 32×32) 16px #0a7aad; linha de prazo padding 12px 17px, pip 7px, título 13.5px/500, meta 12px #7b93a2, selo 11px/600 padding 3px 9px (vermelho #a3232b/#fdeceb, âmbar #8a5a12/#fdf1dc, cinza #4a6274/#eef2f5), ⋮ 22×22; cliente padding 11px 17px, avatar 28px (principal #0a7aad), nome 13.5px/500, CPF 11.5px, Principal 10px/700 #8a5a12/#fdf1dc, CPF duplicado #b03a2e/#fbeceb, pasta 32×32 ícone 17px #7fa6c2; documento padding 11px 17px nome 13px/500 (dc:1423-1533, 6540-6564) |
| Modal Prazo [NOVO-16/10] | 420px, raio 4px, sombra 0 26px 60px -20px rgba(12,48,72,.45); cabeçalho #0a6fa0 padding 12px 16px título 14.5px; tipo 34px borda #c9d6df, ativo #0a6fa0/#fff, inativo #fff/#34505f 13px bold; rótulos 11.5px bold .5px #4a6373; campos 36px borda #c9d6df 14px; hora 120px; nota 12px #7b93a2; erro 12.5px #a3232b; Cancelar 34px #e6edf2/#243845, Salvar 34px #0a6fa0 hover #0b5f86; fundo rgba(14,37,51,.35) z-index 9700 (dc:2910-2925) |
| Metas | cabeçalho padding 15px 20px; filtro 12.5px padding 5px 11px raio 4px (ativo borda accent, #e5f1f7); Nova meta 36px #0a7aad 13px/700; cartão padding 17px 22px margin-top 16px borda esquerda 3px (#1f9d61, #c0392f, #c8952a), concluída #f3faf6, atrasada #fdf2f1, hover translateY(-2px) scale(1.012); título 14.5px/600; nº local 14.5px/700 #0b6a96; nº global 11px/700 #e9f1f6/#4f6878; linha de dados 12.5px #6b8494; selo 11px/600 raio 999px; sino 30×30; trilho: progresso 26px/500, barra 7px #1f9d61, avatar 27px; drawer 460px gradiente #0d80a3→#0b5f86 título 18px/700 (dc:1541-1692, 6644-6687) |
| Processo | cabeçalho padding 15px 20px; interruptor 34×18 (ligado #e8873a, rótulo #b4460a); botões 32px #e5f1f7/#0b5f86 13px/500; Peticionar #0a7aad 13px/600 uppercase .03em; cartão padding 16px 18px; número 17px/500; rótulos 10.5px/700 .1em #7b93a2; valores 14px/500; situação 14px/600 #186c47; marcas 24px #e5f1f7/#0b5f86 11.5px/700; selo Principal #eaf2fa/#2a5f8f borda #cfe0f0; nota técnica #f6fafd borda #b9d6e8 selo #0a5f86 10px/800; conector 1.5px #c9dfec (dc:1700-1853) |
| Financeiro | KPIs grade minmax(200px,1fr) gap 12px; cartão padding 13px 16px; rótulo 10.5px/700 .1em #7b93a2 (Média #a08444); valor 24px/600; chips 30px 12px/600 .04em uppercase (Pendente #a3232b/#fdeceb/#f2c9c6, Assinado e Pró-bono ativo #186c47/#e2f4ea/#bde3cd, Pró-bono inativo #5f7684/#eaeef1/#dde5eb); interruptor reduzir 38×22 (ligado #1f9d61); barra 6px #1f9d61; chip de estado 10.5px/600 (Pago verde, Previsto #0b5f86/#e5f1f7, Vencida #b03a2e/#fbeceb, Pendente #8a5a12/#fdf1dc); linha padding 10px 17px; modal 540px (dc:1861-2140, 3608-3615, 6721-6726, 6976-6991) |

---


### Limitações

1. Levantamento estático. Não abri o protótipo no navegador; comportamentos que dependem do runtime (`support.js`), de `bluejus-central.js` (1,4 MB, não lido por inteiro: `BJCentral.eu`, `notificar`, `alertar`, `timeline`, `presenca`, `pedirAcessoPasta`, `semProcNotificar`, `despachar` destino) ou de `bj-editor.js` foram descritos pela chamada, não pelo efeito final.
2. O duplo clique no arquivo financeiro (`onDblClick`) foi classificado como provavelmente inoperante pela leitura do EVENT_MAP de `support.js`; **não verificado estaticamente** em execução.
3. Valores visuais vêm do estilo inline; animações (`@keyframes` em dc:21-79) e o resultado de `style-hover`/`style-focus` não foram medidos. Elementos com estilo calculado (`{{ ...St }}`) foram resolvidos pelo código, sem renderização.
4. A área IA (painel BLUEJUS Intelligence), editor rico, Detalhes, Documentos e Push só aparecem onde tocam as áreas A1 a A6; o disparo da leitura de processo ("Buscar agora", PDF) fica na aba Push e não foi inventariado aqui.
5. Templates muito longos de Nova meta (dc:81-200) e Vincular processo (dc:287-352) foram inventariados pelos handlers e textos; valores visuais finos desses dois modais não foram tabelados.
6. Contagens: as linhas de tabela foram contadas por área (ver resumo devolvido). IDs com sufixo N indicam ausência de linha própria no checklist de 13/10, não novidade no protótipo; novidade é só a marca [NOVO-16/10].
7. O checklist de 13/10 cita itens que não existem na versão online (PZ-CAB-41, PZ-CAB-42, PZ-CAB-49, PZ-TRI-13, PZ-PRO-39, "até 3 + ver os outros N" de PZ-FIN-16); foram mantidos com a marca "ausente" para rastreabilidade.


# Parte 2. Designer, parte B



Fonte: `proto-online/02 - EXPEDIENTES 1.2.3.dc.html` baixado em 16/10/2026 (7.048 linhas; template nas linhas 1 a 2927; classe `Component` de 2929 em diante). Referências `dc:N` são linhas desse arquivo; `arquivo.js:N` são linhas das dependências na mesma pasta. Base de IDs: `docs/specs/fidelidade-designer-1310-checklist-pasta.md` (13/10). Itens novos levam sufixo N. A tag **[NOVO-16/10]** marca o que veio do diff local x online (`diff-02-local-x-online.txt`, `diff-bj-agente.txt`).

Leitura estática apenas: nada foi executado. Onde o comportamento depende de execução, está escrito "não verificado estaticamente".

### 0. O que o 02 carrega de fato

| Arquivo | Como entra | Prova |
|---|---|---|
| `support.js` | `<script src>` estático (runtime do Designer: sc-if, sc-for, x-import, mapa de eventos) | dc:8 |
| `bj-dica.js` | estático | dc:13 |
| `bj-contas.js` | estático, antes do central (sobrescreve `Storage.prototype` para isolar chaves por conta) | dc:15; bj-contas.js:30-33 |
| `bluejus-central.js` | estático | dc:16 |
| `bj-link.js` | estático (link público do Push, não é de Documentos) | dc:17 |
| `bj-processo.js` | estático | dc:18 |
| `bj-docsug.js` | estático | dc:19 |
| `bj-visualizar.js` | estático (`BJVisor`, `BJImprimir`) | dc:20 |
| `bj-editor.js` | `<x-import component-from-global-scope="bj-editor">` (6 instâncias: expedientes, financeiro, observacoes e 3 de edição) | dc:1383, 1406, 1914, 1937, 2441, 2469 |
| `bluejus-docs.js` | dinâmico, injetado pelo central ao carregar ("Motor Universal de Análise") | bluejus-central.js:5960-5961 |
| html2pdf.js 0.10.1, mammoth 1.8.0, jszip 3.10.1 (CDN) | dinâmico sob demanda (Baixar PDF; leitura de .docx/.odt no Push) | dc:4811, 5543, 5546 |
| `bj-agente.js` | **não carregado pelo 02** (só pelo `03 - EXPEDIENTE Lista 1.1`, linha 17). Depende de `window.BJPastasPonte`, que o 02 não define | grep sem ocorrência no 02 |
| `bluejus-intelligence.js` | **não carregado pelo 02** (o Dashboard 01 faz `import()`); no 02 "BLUEJUS Intelligence" é o drawer próprio `iaVals` | dc:1266, 5887 |
| `bluejus-avancado.js`, `bluejus-equipe.js` | **não carregados pelo 02** | grep sem ocorrência de `src=`/`import` |

### 1. Aba Detalhes (PZ-DET) e editor rico (PZ-EDT)

Visual do bloco (dc:2430-2524): grade `minmax(0,1fr) 356px`, gap 18px, `data-r="grid2"` (vira 1 coluna abaixo de 980px). Cartão principal `#fff`, borda `1px #dde5eb`, raio 4px, sombra `0 1px 2px rgba(16,42,58,.04)`; cabeçalho padding 15px 20px, fundo `#f5f6f7`, borda inferior `#dfe4e8`; título h2 14px/600; contador 11px `#7b93a2`. Linha do tempo: fundo `#eef2f5`, padding `6px 18px 10px 46px`, trilho vertical 2px `#d3dbe1` em left 27px, pílula do dia 13px/700 `#3c4a54` sobre `#dde3e8` raio 4px. Cartão do registro: padding 12px 16px, raio 4px, sombra `0 1px 2px rgba(16,42,58,.08)`; avatar 24px gradiente `#1597bd → #0c7a9c`, iniciais 9.5px/700; autor 13px/700 `#12242f`; hora 12px `#5f7684`; ⋮ 28x28 `#8496a3`. Trilho direito: cartões padding 17px; botão "Ver histórico" altura 32px, fundo `#e5f1f7`, texto `#0b5f86` 13px/500.

| ID | Área | Elemento | Gatilho | Comportamento completo | Confirmação | Dependências | Referência |
|---|---|---|---|---|---|---|---|
| PZ-DET-01 | Detalhes | Painel "Relatório inicial de Atendimento" com contador | renderização | Contador = nº de registros principais da aba (respostas não contam) ou nº de registros de demonstração | não | registros por pasta | dc:2433-2437, 6934 |
| PZ-DET-02 | Detalhes | Compositor `bj-editor` contexto `observacoes`, placeholder "Adicionar observação…", botão "Enviar" | clique em Enviar ou Ctrl+Enter | Evento `bj-enviar` → `onEditorEnviar` grava em `bj-registros-v1[pasta]` `{id, ctx:'observacoes', html, texto, criado, editado:null, autor, fase}`; aviso "Registro adicionado" por 3 s; rascunho do editor apagado | não | endpoint de criar registro; autor da sessão | dc:2441, 6327-6345 |
| PZ-DET-N01 | Detalhes | Janela "Atualizar andamento da pasta?" após cada novo registro | criada dinamicamente 350 ms depois de salvar (contextos expedientes, observacoes, financeiro), se a fase atual não for a última | Botões "Avançar para <fase seguinte>" (azul, ícone signpost) e "Manter em <fase atual>"; avançar chama `faseMarcar(fa+1, true)` e grava registro de sistema | janela `cf` azul: título "Atualizar andamento da pasta?", texto "Registro salvo na fase "X". Este registro conclui a fase e a pasta deve avançar para "Y"?" | regra de fases (sem pedido do Farlei, ver checklist §0) | dc:6342 |
| PZ-DET-N02 | Detalhes | Linha do tempo no padrão Push/PJe | renderização | Ordena pela data de criação do registro principal; respostas ficam em thread logo abaixo (recuo 36px, conector em L `#b9cfdc`) e nunca reordenam; bolinha por tag (Combinado `#1e7b45`, Ligação `#c76a12`, Atendimento `#0a6a96`, Observação `#6b5bb0`, padrão `#0a7aad`) | não | não | dc:2444-2448, 6269-6310 |
| PZ-DET-03 | Detalhes | Cartão: "editado" com dica, lápis (só registro novo editável), Responder, ⋮ "Mais ações da observação" | clique; botão direito no cartão abre o mesmo menu | Lápis aparece só se `ehReg && editavel && pode`; dica "Editável por mais N minutos"; "editado" mostra "Publicado às HH:MM · última edição …" ou "Editado por X em D às H · N versão(ões) anterior(es) guardada(s)" | não | permissões por autor/tempo | dc:2449-2463, 6305, 6318-6322 |
| PZ-DET-03 | Detalhes | Caixa de resposta | botão Responder (hover) ou item Responder do menu | Abre "Respondendo a X", textarea autofocus "Escreva a resposta…"; Ctrl/Cmd+Enter envia, Esc cancela; botão "Responder" desabilitado vazio; responder a uma resposta pré-preenche "@Nome "; grava `bj-respostas-v1[chave]`; chama `BJCentral.notificarResposta`; aviso "Resposta publicada. Fulano foi avisado(a) pelo Chat I.A" ou "Resposta publicada" | não | notificação ao autor; endpoint de resposta | dc:2478-2484, 6258-6268, 6287, 6299-6300 |
| PZ-DET-04 | Detalhes | Presença do autor | renderização; clique no rótulo | Ponto 9px na cor do status + rótulo (verde `#1e7b45`, ocupado `#b3363b`, ausente `#a86a12`, offline `#8a9aa6`); clique abre o Chat I.A só se o autor é o próprio usuário; atualiza no evento `bj-presenca` | não | presença em tempo real (`BJCentral.presenca`) | dc:2454-2455, 6292, 6300, 6351 |
| PZ-DET-05 | Detalhes | Encaminhar no hover | hover/foco no cartão (CSS: ações `data-acao` com opacidade 0 → 1; sempre visíveis em `hover:none`) | `despachar({tipo:'registro', titulo:'Comentário de X · quando', sub:160 caracteres})` → `BJCentral.despachar` abre a tela "Despachar via Chat I.A" (ver PZ-IA-29) | não | Chat I.A | dc:23, 2458, 6300; bluejus-central.js:6218-6236 |
| PZ-DET-06 | ⋮ / botão direito | "Editar · N min" / "Editar · bloqueado" (ícone cadeado) | item do menu | Regra `comPerm`: Master sem restrição; primário sem limite de tempo; autor só 15 min; demais não. Bloqueado mostra aviso com o motivo ("O prazo de 15 minutos para editar ou excluir terminou…" ou "Só o autor pode editar ou excluir…"). Comentário de demonstração edita no lugar com editor `cedit:<chave>` rotulado "Salvar" + "Cancelar", grava `bj-com-edit-v1` (html, texto, em, por, até 10 versões) e log `bj-comentarios-editados-log` (300); registro novo usa `edit:<id>` e guarda `versoes` (10) no próprio registro. Avisos: "Comentário atualizado", "Registro atualizado" ou "O prazo de 15 minutos para editar terminou" | não | permissão por papel; histórico de versões no servidor | dc:6222-6241, 6301, 6326-6340 |
| PZ-DET-07 | ⋮ | Copiar texto | item | Copia o texto sem espaços duplos; aviso "Texto copiado" | não | não | dc:6303 |
| PZ-DET-08 | ⋮ | Encaminhar via Chat I.A | item | `despachar({tipo:'registro', titulo:'Registro de X · quando', sub:140})` | não | Chat I.A | dc:6303 |
| PZ-DET-09 | ⋮ | Citar no novo registro (`bi-quote`) | item | Dispara `bj-editor-inserir` `{contexto:'observacoes', html:'<blockquote>…</blockquote><p><br></p>'}`; o editor da aba recebe e insere no fim; aviso "Citação inserida no editor" | não | não | dc:6872-6873; bj-editor.js:398-399 |
| PZ-DET-10 | ⋮ | Criar meta a partir deste registro | item | Vai para a aba Metas e abre "Nova meta" com descrição = 600 caracteres do texto, prazo +7 dias, sem responsável | não | metas | dc:6873 |
| PZ-DET-11 | ⋮ | "Enviar no Chat I.A" (`bi-chat-dots`) | **inalcançável**: existe só em `menuObs`, cujo `menu` é sobrescrito pelo menu de `regsLinha`; dispara `bj-chat-anexar-texto`, que nenhum ouvinte trata | (código morto) | não | não | dc:6862-6870, 6897, 6303 |
| PZ-DET-12 | ⋮ | Imprimir | item | `gmImprimir('Registro do expediente', html, {autor, quando, tag})` abre a janela Imprimir (ver Índice de modais) | não | não | dc:6873, 4829 |
| PZ-DET-13 | ⋮ | "Excluir comentário · N min" / "Excluir · permanente" | item (vermelho) | Regra `comPermExc` com `EXC_MIN = 60`: Master sempre; autor ou primário só nos 60 minutos após publicar; depois "Registro permanente: passaram 60 minutos da publicação. Somente o Master pode excluir." Efeito: não apaga do `bj-registros-v1`; oculta pela chave em `bj-itens-excluidos-v1` e registra em `bj-comentarios-excluidos-log` (300); aviso "Comentário excluído" | janela `cf` vermelha: título "Excluir o comentário de X?", texto "<quando> · "<140 caracteres>". Ele some para toda a equipe e a exclusão fica registrada no histórico.", botão "Excluir" | exclusão lógica + auditoria no servidor | dc:6232-6239, 6248-6257, 6303 |
| PZ-DET-14 | ⋮ | "Destacar" / "Destacar · <cor atual>" e "Remover destaque" | item | Abre popover "Destacar comentário" (ver Índice de modais): 15 cores + "Sem cor"; só 1 destaque por pasta e aba (o anterior é desfeito com aviso); grava `bj-com-destaque-v1` `{cor, pasta, aba, por, em, autor}`; se `BLUEJUS_API_BASE` existir faz `PATCH /mensagens/:id/destaque` e reverte em erro; auditoria `MESSAGE_HIGHLIGHTED/CHANGED/REMOVED`; cartão recebe fundo da cor e faixa `inset 3px`; em cor escura o texto vira branco (cálculo de contraste WCAG) | não | endpoint PATCH destaque com tenant | dc:258-267, 4589-4606, 6303-6304, CSS dc:24-28 |
| PZ-DET-15 | Cartão | "continuar lendo (N parágrafos)" / "mostrar menos" | clique | Só nos registros de demonstração com mais de 3 parágrafos; estado `obsAbertas` (não persiste) | não | não | dc:2472-2474, 6886-6893 |
| PZ-DET-16 | Trilho | Registro da pasta: Criada em, Modificada em, Criada por | renderização | Valores fixos de demonstração (`criadaEm: '14/08/2026 09:02'`) | não | dados de auditoria da pasta | dc:2489-2508, 6837, 6935 |
| PZ-DET-17 | Trilho | Ver histórico do sistema | clique; tecla H com o ⋮ da pasta aberto | Drawer 430px "Histórico do sistema", subtítulo fixo "10 alterações registradas automaticamente" mas a lista é estática com 6 eventos; fecha no × ou no fundo | não | log de auditoria real | dc:2510, 2879-2907, 6587-6600, 7018 |
| PZ-DET-18 | Trilho | "Pontos do relatório inicial" + "Completar no relatório" | renderização; clique | 3 itens (Objetivos do cliente, Acordos iniciais, Resultado esperado pelo cliente) detectados por expressão regular no texto dos registros de Detalhes; selo "Completo" (`#1e7b45` sobre `#e6f4ec`) ou "N pendente(s)" (`#a3232b` sobre `#fdecea`); botão foca o editor de observações e, se vazio, digita "Objetivos do cliente: / Acordos iniciais: / Resultado esperado pelo cliente: "; também acende o traço vermelho da aba, notifica o responsável 4 s após abrir (`relInicialAlertar`) e entra no contexto da @IA | não | detecção por IA no servidor; notificação | dc:2512-2520, 3097, 4382, 5484-5489, 5502, 6930 |
| PZ-DET-19 | Trilho | "Como usar esta aba" | (ausente) | Confirmado: nenhuma ocorrência de "Como usar" no arquivo online | não | não | grep |
| PZ-DET-N03 | Detalhes | Datas escritas nos registros viram prazos **[NOVO-16/10]** | automático a cada renderização | `pzLista` lê todos os registros não sistema da pasta (inclui Detalhes) e aplica `lerDatas`: reconhece audiência, perícia, sessão de julgamento, contestação e outros; datas numéricas, por extenso, "prazo de N dias (úteis/corridos)", "amanhã", "hoje"; ignora passado ("realizada", "cumprida", "protocolada em"…); "redesignada para" pega a data depois de "para". Entra em "Próximos prazos" e em `bj-prazos-pasta-v1`. Defeito: o mapa de origem usa a chave `detalhes`, mas o contexto gravado é `observacoes`, então a origem aparece "Registro" | não | motor de datas no servidor | dc:6368-6390, 6395-6398 |
| PZ-DET-N04 | Global | `window.BJDatas = {ler, somarDias, diaUtil}` **[NOVO-16/10]** | `componentDidMount` | Leitor central de datas exposto a outras telas; dia útil exclui fim de semana, 9 feriados nacionais fixos e recesso 20/12 a 20/01 | não | calendário forense real (feriados locais, suspensões) | dc:6346, 6364-6367 |
| PZ-DET-N05 | Detalhes | Aviso de limite de armazenamento | falha em `localStorage.setItem` | Aviso "Não foi possível salvar: o conteúdo passou do limite de armazenamento. Reduza as imagens e envie de novo." por 6 s | não | não se aplica no servidor | dc:6221 |
| PZ-DET-N06 | Detalhes | Contagem regressiva dos minutos | `setInterval` de 20 s | Re-renderiza enquanto houver registro editável ou em edição, atualizando "N min" | não | não | dc:6359 |
| PZ-DET-N07 | Detalhes | Versões anteriores | (sem tela) | Até 10 versões guardadas por comentário/registro, mas não há tela para vê-las; só a contagem na dica | não | tela de versões | dc:6241, 6305, 6337 |

#### Editor rico `bj-editor.js` (PZ-EDT)

Visual (bj-editor.js:9-40): borda `1px #d3dde5`, raio 4px, foco `#8fbde6` + anel `rgba(15,111,196,.12)`; barra fundo `#eef2f5` com raio `4px 9px 0 0` (9px acima do teto de 4px da PZ-GLB-16); botões 30x28 raio 4px `#4f6878`, hover `#fff`/`#0f6fc4`, ativo `#dcebf6`/`#0b5f86`, 34x32 em `hover:none`; conteúdo 14.5px/1.55 `#12242f`, padding 13px 15px, altura 70 a 420px; h2 18px, h3 15.5px `#0b5f86`, citação fundo `#f3f7fa` itálica; rodapé `#fafcfd` raio `0 0 9px 9px` com "Visível para a equipe", contagem de palavras e status; botão Enviar `#0f6fc4` 13px/600 padding 8px 18px.

| ID | Área | Elemento | Gatilho | Comportamento completo | Confirmação | Dependências | Referência |
|---|---|---|---|---|---|---|---|
| PZ-EDT-01 | Barra | Desfazer (Ctrl+Z), Refazer (Ctrl+Y), Estilo do parágrafo (Normal, Título 1, Título 2, Citação), Negrito (Ctrl+B), Itálico (Ctrl+I), Sublinhado (Ctrl+U), Tachado, Cor da fonte (padrão `#c0392f`), Cor do realce (padrão `#f2d24b`), Numeração, Marcadores, Diminuir recuo (Shift+Tab), Aumentar recuo (Tab), Alinhamento (popover), Citação, Inserir símbolo "§", Limpar formatação | clique (mousedown não rouba foco) | `execCommand`; estado dos botões atualizado em `selectionchange`; Desfazer/Refazer desabilitam conforme `queryCommandEnabled` | não | sanitização no servidor | bj-editor.js:301-339, 423-433 |
| PZ-EDT-02 | Barra | Inserir link (Ctrl+K) | clique ou atalho | Popover com URL e texto; Enter aplica, Esc fecha | não | não | bj-editor.js:322, 478-503, 682 |
| PZ-EDT-03 | Barra | Imagem: botão, colar, arrastar; 7 layouts (Alinhado com o texto, Quadrado, Justo, Através, Superior e inferior, Atrás do texto, Na frente do texto); redimensionar; Delete remove; Esc desseleciona | clique, Ctrl+V, soltar | Aceita png, jpeg, webp, gif (múltiplos); `BJRich.prepararImagem` comprime; placeholder "carregando imagem"; avisos em chip | não | armazenamento de imagens | bj-editor.js:323-325, 370-386, 803-852, 959-1020 |
| PZ-EDT-04 | Barra | Inserir tabela | clique | Popover de grade; seleção de célula; redimensionar coluna com o mouse | não | não | bj-editor.js:326, 1022-1065 |
| PZ-EDT-05 | Barra | Quebra de página, Linha horizontal | clique | `<hr data-quebra="1">` e `insertHorizontalRule` | não | não | bj-editor.js:327, 332 |
| PZ-EDT-06 | Barra | Fonte e tamanho ("Aa") | clique | Popover de família e tamanho | não | não | bj-editor.js:328, 931-939 |
| PZ-EDT-07 | Barra | Espaçamento entre linhas e parágrafos | clique | Popover | não | não | bj-editor.js:329, 940-947 |
| PZ-EDT-08 | Barra | Modo documento (A4/A3, margens, régua, zoom, cabeçalho e rodapé editáveis) | clique | Serializa como `<div data-doc="tam,ori,margens" data-cab data-rod>` | não | não | bj-editor.js:330, 344-347, 887-930 |
| PZ-EDT-09 | Barra | Ditar por voz | clique (só se `SpeechRecognition` existir) | Reconhecimento de voz do navegador | não | API do navegador | bj-editor.js:336-337, 585-595 |
| PZ-EDT-N01 | Barra | Inserir data de hoje | clique | Insere `dd/mm/aaaa` | não | não | bj-editor.js:331 |
| PZ-EDT-N02 | Barra | Revisar texto | clique | Popover com problemas encontrados e substituições | não | não | bj-editor.js:334, 504-537 |
| PZ-EDT-N03 | Barra | Localizar e substituir (Ctrl+H) | clique ou atalho | Barra com contagem de ocorrências; Enter vai à próxima/substitui; Esc fecha | não | não | bj-editor.js:335, 538-584, 683 |
| PZ-EDT-10 | Digitação | Autocorreção com chip "Desfazer" (3,6 s) e "Você quis dizer X?" com Corrigir/Ignorar (6 s); maiúscula no início da frase | ao digitar espaço/pontuação | Dicionários internos AC/VF/VOCAB | não | não | bj-editor.js:610-664 |
| PZ-EDT-11 | Digitação | Sugestão de palavras (a partir de 3 letras, até 4 opções) | ao digitar | Tab aceita; ↑/↓ navega; Enter aceita depois de navegar; Esc fecha | não | não | bj-editor.js:640-654, 676-680 |
| PZ-EDT-12 | Digitação | Autoformatação "1. ", "1) ", "* ", "- ", "> " | espaço depois do marcador | Converte em lista numerada, marcadores ou citação; chip "Formatação automática aplicada" com Desfazer | não | não | bj-editor.js:618-626 |
| PZ-EDT-13 | Colar | Chip "Colar": Manter formatação, Mesclar formatação, Somente texto (7 s) | Ctrl+V com HTML | Sanitiza; imagens do Word/e-mail recuperadas de `clipboard.files`; aviso "N imagem(ns) não pôde(puderam) ser colada(s)…" | não | não | bj-editor.js:763-795, 948-958 |
| PZ-EDT-14 | Rodapé | Rascunho automático | 600 ms após digitar (fora do modo edição) | Chave `bjed-rascunho:<contexto>:<pathname>`; status "Rascunho salvo", "Rascunho recuperado", "Rascunho grande demais para guardar neste navegador"; apagado ao enviar ("Enviado") | não | rascunho no servidor (opcional) | bj-editor.js:362-364, 401, 412-421, 873-874 |
| PZ-EDT-15 | Botão direito | Menu no padrão do Word: mini barra (B, I, U, S, realce, cor, marcadores, numeração, título, limpar), sugestões de correção, Recortar/Copiar (desabilitados sem seleção), Colar, Colar somente texto (Ctrl+Shift+V), Parágrafo (4 alinhamentos, 2 recuos), Sinônimos, Definir (dicio.com.br), Traduzir (Google), Pesquisar na web, Inserir qualificação do cliente (se `__bjQualificacao`), Hiperlink, Localizar e substituir, Revisar texto, Selecionar tudo | `contextmenu` no conteúdo | Shift+botão direito abre o menu do navegador; Esc ou rolagem fecha | não | qualificação do cliente | bj-editor.js:693-762; dc:4426 |
| PZ-EDT-16 | Teclado | Ctrl+Enter envia; Tab insere 4 espaços (em lista recua; Shift+Tab desrecua); Ctrl+Y refaz; Esc fecha popover/chip; Enviar desabilitado vazio; espera imagem terminar | teclado | `bj-enviar` `{contexto, html, texto, edicao}`; em edição mostra "Cancelar" (`bj-cancelar`) | não | não | bj-editor.js:356-357, 674-692, 860-876 |
| PZ-EDT-17 | Global | @menção | digitar "@" em qualquer editor, input de texto ou textarea (exceto senha, e-mail, CPF, telefone, valor, data) | Lista de até 7 pessoas + "Todos" ("Mencionar · notifica no Chat I.A"); ↑/↓, Enter/Tab escolhe; ao publicar, cada citado recebe notificação "Menção" "X mencionou você na Pasta N · <aba>" | não | notificações; diretório de usuários do tenant | bluejus-central.js:5916-5958 |
| PZ-EDT-18 | Global | Citar → `<blockquote>` | evento `bj-editor-inserir` | Ver PZ-DET-09 | não | não | bj-editor.js:398-399 |
| PZ-EDT-N04 | Editor | Anexo de arquivo | (ausente) | O editor só aceita imagens; não há anexo de documento | não | não | bj-editor.js:323-325 |

### 2. Aba Documentos e explorador (PZ-DOC, PZ-VIS)

Visual: cabeçalho padding 15px 20px, fundo `#f5f6f7`, título 14px/600; busca altura 32px, fundo `#f1f5f8`, raio 4px, min 230px; "Nova pasta" fundo `#e5f1f7` texto `#0b5f86` 13px/500; "ANEXAR" fundo `#0a7aad` branco 13px/600 maiúsculas; checklist em cartão `margin 16px 20px`, sombra `0 1px 3px rgba(0,0,0,.16)`, faixa padding 13px 16px fundo `#fafcfd`; seleção de item `#dcebf6` com contorno `#9fc3e3`; alvo de soltar `#cfe6f7` + `#0a7aad`; duplicado `#fffaf0` com faixa `#e9a23b`; pasta `#f2b93b`; menu de contexto 240px; aviso inferior `#12242f` com "Desfazer" `#7cc4ff`.

| ID | Área | Elemento | Gatilho | Comportamento completo | Confirmação | Dependências | Referência |
|---|---|---|---|---|---|---|---|
| PZ-DOC-01 | Aba | Cabeçalho "Documentos" + contagem; rodapé | renderização | Contagem fixa "224"; rodapé "N arquivos e M pastas · 224 no total, contando subpastas" ou, dentro de subpasta, "<pasta> · N itens nesta pasta" | não | contagem real | dc:2152-2156, 5118 |
| PZ-DOC-02 | Aba | Busca "Buscar arquivos e pastas…" + limpar | digitação; Esc; × | Normaliza acentos e `_ - .`; também casa pelo fim do nome sem extensão; limpa a seleção; "N resultados para "q""; vazio "Nenhum arquivo ou pasta com esse nome."; realce `<mark>` no nome | não | busca no servidor | dc:2159-2163, 2321-2322, 4966-4968, 5088-5092 |
| PZ-DOC-03 | Aba | "Nova pasta" e "ANEXAR" (`bi-paperclip`) | **sem `onClick`**: botões inertes | Não há upload nem input de arquivo no explorador; "Nova pasta" só funciona pelo botão direito no fundo | não | upload real | dc:2164-2165 |
| PZ-DOC-04 | Aba | Organizar: Ordem das colunas (←/→), Classificar por (Nome, Tipo, Tamanho, Modificado, com seta), Tipo de documento (Todos os itens, Pastas, PDF, Word, Excel, Imagens, Compactados, Outros, com contagem; desabilitado se 0), Restaurar padrão; selo "1" quando há filtro | clique | Colunas e larguras gravadas em `bj-docs-colunas`; título da coluna arrastável; alça de largura arrastável e duplo clique volta ao padrão; filtro de tipo e ordem ficam só no estado | não | preferência por usuário | dc:2166-2189, 2342, 3240-3316 |
| PZ-DOC-05 | Aba | Visualizar: 8 modos (Ícones extra grandes, grandes, médios, pequenos, Lista, Detalhes padrão, Blocos, Conteúdo) + "Painel de detalhes" | clique | Estado `expModo`/`expPainel` (não persiste) | não | não | dc:2191-2202, 3209-3212, 5190-5193 |
| PZ-DOC-06 | Checklist | Faixa "Checklist de documentação" + "ok/total itens"; ✦ Sugerir documentos; ✎ editar (lixeira por item); + adicionar ("Nome do documento, ex.: Certidão de casamento", Enter/Esc, Adicionar); interruptor Ativo/Desativado (`#1f9d61`); marcar item (riscado) | clique, Enter, Esc | Itens em `bj-ck-cfg-v1`; duplicado ignorado; aviso "Adicionado ao checklist: X"; desativar exige escolher motivo (Não se aplica a esta pasta; Documentos controlados em outro sistema; Pasta administrativa ou consultiva; Pasta encerrada) com texto da BlueJus IA e botões "Atualizar o checklist", "Manter ativo", "Desativar"; desativado mostra "Checklist desativado · motivo · por em data" e "Reativar"; notifica a Controladoria e agenda lembrete de 30 dias; marcar item roda `ckAuditar` | confirmação embutida (escolha de motivo), não usa `cf` | conformidade, notificações | dc:2207-2315, 4173-4275, 6742-6754 |
| PZ-DOC-07 | Checklist | Selo "cobrado Nx" / "sem anexo" (megafone) e cobrança automática | automático (`__bjCobrarChecklist` chamado pelo central) | Plano diário em dias úteis com peso por documento; cobranças em `bj-ck-cob-v1`; vermelho a partir de 3 cobranças; traço vermelho na aba | não | rotina agendada no servidor; Chat I.A | dc:2309, 4275-4370, 4424, 5494-5496, 6744-6746 |
| PZ-DOC-08 | Sugeridos | Painel "Análise documental do processo": identificação, Atualizar, ×, conflito de número, Contexto (fase, Confiança, resumo, prazos em curso), chips 🔴🟠🟡🟢, grupos (Exigido pelo juízo, Obrigatório, Recomendável, Opcional, Já existente, Não aplicável), item com porquê, localização, "Origem" (abre o documento), "+ Checklist"/"já no checklist", "Possíveis arquivos duplicados", "Organização sugerida para esta fase", "Adicionar N faltante(s) ao checklist" | clique | `BJDocSug.analisar` com o contexto do `BJProcesso` (mesmo do Push e do Chat); memória `bj-proc-ctx-v1`; Atualizar roda `procRodar` | não | motor de análise processual; documentos reais | dc:2247-2295, 4191-4221; bj-docsug.js:1-6, 134 |
| PZ-DOC-09 | Sugeridos | Camada de IA e capa do PDF | automático | Sem leitura prévia usa `BJProcesso.analisar` sobre as movimentações; com PDF lido usa a capa e `refinarIA` (`window.claude.complete`) | não | IA; leitura de PDF | dc:4196-4197, 5525 |
| PZ-DOC-10 | Aba | "Mostrando somente <tipo>" + × | renderização | Remove o filtro de tipo | não | não | dc:2320 |
| PZ-DOC-11 | Aba | Barra de seleção: "N selecionado(s) · tamanho", Baixar / "Baixar N itens (.zip)" / "Baixar (.zip)", Copiar, Recortar, Renomear (1 item), Selecionar tudo, Excluir; "Limpar seleção (Esc)" | clique | Mesmas ações do menu de contexto | não | não | dc:2323-2328, 5093-5103 |
| PZ-DOC-12 | Aba | Trilha com ↑ e migalhas | clique; Backspace, Alt+←, Alt+↑ | Volta um nível; migalha clicável | não | não | dc:2330-2338, 5119-5120, 5162 |
| PZ-DOC-13 | Aba | Faixa "Ganhe espaço" (`bi-stars`): cópias idênticas, vazios, cópias do processo, muito grandes, "libera cerca de X"; Revisar/Fechar; × "Agora não"; lista com caixas; "Excluir N selecionado(s) · X"; selos "Idêntico", "Vazio", "Ver no PJe", "Muito grande" nas linhas | clique | Hash SHA-256 só para arquivos soltos na sessão; demais usam `EXP_META`; dispensar fica só no estado; exclusão vai com Desfazer e auditoria | janela `cf` vermelha "Confirmar exclusão": "Excluir N arquivo(s) sugerido(s)? Você pode desfazer logo em seguida." | hash e nº de páginas gravados no upload | dc:2345-2369, 4932-4949, 5195-5201 |
| PZ-DOC-14 | Lista | Seleção (clique, Ctrl, Shift), laço a partir do espaço vazio com rolagem automática, arrastar nome/ícone para pasta, estrela de favorito, colunas | mouse | Favoritos em `bj-docs-favoritos-v1[usuario][pasta]` sobem ao topo na ordem marcada (aviso "★ "X" foi para o topo da lista" / ""X" saiu dos favoritos"); não há ⋮ por item no toque | não | favoritos por usuário | dc:2370-2390, 4685-4686, 4993, 5001-5013, 5125-5152 |
| PZ-DOC-15 | Botão direito no item | Abrir (Enter), Visualizar (Espaço, só arquivo), Baixar / Baixar como .zip, Compartilhar link, Recortar (Ctrl+X), Copiar (Ctrl+C), Colar (Ctrl+V, só em pasta, desabilitado sem área de transferência), Copiar caminho, Encaminhar via Chat I.A, Marcar como favorito / Tirar dos favoritos, Renomear (F2), Excluir (Del), Propriedades | `contextmenu` no item | Seleciona o item se ainda não estava | não | não | dc:2373, 5009, 5059-5076 |
| PZ-DOC-16 | Botão direito | Compartilhar link(s) | item | Copia `location.origin/pastas/<n>/documentos/<nome>`; aviso "Link copiado"/"Links copiados" | não | link autenticado real | dc:5049, 5063 |
| PZ-DOC-17 | Botão direito | Encaminhar via Chat I.A | item | `despachar({tipo:'pasta' ou 'documento', titulo:nome, sub:'BlueJus › Pasta N › Documentos › nome'})` | não | Chat I.A | dc:5069 |
| PZ-DOC-18 | Botão direito com vários itens | Baixar como .zip (N), Compartilhar links, Recortar, Copiar, Copiar caminhos, Excluir N itens, Propriedades | `contextmenu` com seleção múltipla | | não | não | dc:5047-5058 |
| PZ-DOC-19 | Botão direito no fundo | Nova pasta, Colar, Selecionar tudo, Classificar por nome/data/tamanho/tipo, Mostrar/Ocultar painel de detalhes | `contextmenu` no fundo | Nova pasta cria "Nova pasta (n)" já em renomeação (não persiste) | não | criação de pasta no servidor | dc:2370, 5077-5084, 5184 |
| PZ-DOC-20 | Aba | Aviso de 4,2 s com "Desfazer" | após excluir, mover, copiar, colar | Desfazer só restaura a lista de excluídos | não | lixeira/restauração | dc:2404-2406, 5021, 5185 |
| PZ-DOC-21 | Aba | Painel de propriedades | seleção | 1 arquivo: ícone 64px, Tipo, Categoria, Tamanho, Modificado; pasta: Tipo, Conteúdo; vários: Pastas, Arquivos, Tamanho dos arquivos; vazio "Selecione uma pasta ou arquivo para ver os detalhes." | não | metadados | dc:2407-2421, 5202-5203 |
| PZ-DOC-22 | Aba | Atalhos: Ctrl+A, Del, F2, Enter, Espaço, Esc, Backspace, Alt+←, Alt+↑, Ctrl+C, Ctrl+X, Ctrl+V, setas (com Shift estende) | `keydown` no contêiner com `tabIndex=0` | Ignorados durante renomeação ou com foco em input; setas respeitam grade, lista em colunas ou lista; rola o item para a vista | não | não | dc:2370, 5153-5181 |
| PZ-DOC-23 | Aba | Lixeira | (ausente) | Não existe no protótipo | não | não | grep |
| PZ-DOC-24 | Aba | Escrita bloqueada para quem só lê | captura global de `click`, `focusin`, `drop`, `paste`, `beforeinput` em `[data-cad-area]` | Com cadeado em "ver", bloqueia controles e botões cujo texto indica edição (adicionar, novo, editar, excluir, salvar, anexar, mover, renomear…), exceto busca/visualizar/abrir/copiar; aviso "Somente visualização: o usuário primário trancou esta pasta" | não | autorização no servidor | dc:6351-6356 |
| PZ-DOC-25 | Aba | Vazio de subpasta | renderização | "Pasta vazia. Arraste arquivos para cá ou use Colar (Ctrl+V)." ou "Esta pasta tem X no servidor…" | não | não | dc:2371, 5121-5122 |
| PZ-DOC-N01 | Lista | Renomear no lugar | F2, item Renomear, botão da barra | Input com foco e seleção; Enter ou perder foco salva; Esc cancela; nome fica só no estado `expNomes` (some ao recarregar) | não | renomear no servidor | dc:2377, 5033, 5086, 5186-5189 |
| PZ-DOC-N02 | Lista | Mover (arrastar para pasta ou Recortar + Colar em pasta) | soltar; item Colar | Único efeito persistido do explorador: `bj-exp-dentro-v1`; aviso ""X" movido para Y" com Desfazer. Defeito: Desfazer devolve o item à raiz mas não o tira da pasta destino | não | mover no servidor | dc:5034, 5040, 5185 |
| PZ-DOC-N03 | Lista | Copiar + Colar | Ctrl+C/Ctrl+V; menu | Colar no fundo cria "(cópia N)"; colar cópia dentro de uma pasta só mostra aviso, sem efeito | não | cópia no servidor | dc:5030, 5037-5045 |
| PZ-DOC-N04 | Lista | Baixar | menu, barra | 1 arquivo: busca `it.url` ou `GET {API}/arquivos/download?pasta&caminho`, senão conteúdo de demonstração; vários ou pasta: ZIP montado no navegador "Pasta N - <sub> - dd-mm-aaaa.zip" com LEIA-ME para pastas não carregadas | não | endpoint de download e zip | dc:3905-3934 |
| PZ-DOC-N05 | Lista | Documentos do Peticionar na raiz | automático; evento `storage` de `bj-pasta-docs-v1` | Lê `bj-pasta-docs-v1` e re-renderiza quando outra aba grava | não | documentos gerados | dc:4506, 6347 |
| PZ-DOC-N06 | Lista | Persistência efêmera | (comportamento) | Excluir, renomear, nova pasta e colar ficam só no estado; recarregar desfaz | não | tudo no servidor | dc:2932-2934 (estado inicial) |
| PZ-VIS-01 | Visor | Tela cheia (fundo `#525659`, z 190), cabeçalho com ícone, nome, "meta · Pasta N · cliente", "i de N", ← →, × | duplo clique, Enter, Espaço, item Visualizar; Esc, ←, → | Foco no visor; navegação dentro da lista filtrada atual | não | não | dc:372-400, 4884-4924 |
| PZ-VIS-02 | Visor | Zoom −/+ de 50 a 200%, passo 10 | clique | Só para grupos suportados pelo `BJVisor` (pdf, img, tiff, docx, odt, rtf, doc, planilha, pptx, texto, xml, eml) | não | não | dc:385-389, 4895, 4905 |
| PZ-VIS-03 | Visor | Baixar, Imprimir, Abrir em nova aba | clique | Imprimir via `BJImprimir` com tarja "BlueJus · Pasta N · nome · impresso em …" (exige conteúdo aberto, senão aviso "Abra o conteúdo do arquivo para imprimir"); sem URL: avisos "Download de X iniciado" e "No sistema, o arquivo abre em uma nova aba" | não | URL `{API}/arquivos/:id/conteudo` | dc:4907-4911 |
| PZ-VIS-04 | Visor | Formatos no navegador | `BJVisor.render` | Carrega pdf.js, mammoth, SheetJS, JSZip, UTIF sob demanda | não | conversão no servidor para DOC, PPT, RTF, ODP, HEIC, TIFF, MSG | bj-visualizar.js:1-5 |
| PZ-VIS-06 | Visor | Soltar arquivo no visor | `drop` | Insere o arquivo depois do atual e abre | não | não | dc:397, 4914-4916 |
| PZ-VIS-07 | Visor | Aviso do visor | ações | Toast `#12242f` por 2,6 s | não | não | dc:400, 4896 |

### 3. Aba Push processual (PZ-PUSH)

Visual: faixa altura mín. 46px, padding 8px 17px, fundo `#eef2f5`; título 13.5px/700; contagem 11px/700 `#0b5f86` sobre `#e5f1f7`; filtros 28px, 12px/600 (ativo branco com `#0a7aad`; filtro de processo ativo `#0a6fa0`); cartão de análise IA gradiente `#f8f5ff → #f4f8fd`, contorno `rgba(205,191,241,.55)`; cartão da movimentação branco raio 3px, novo com faixa `inset 3px #0a7aad`; chips de tipo (Intimação `#b42318/#fdecea`, Decisão/Despacho/Sentença `#0b5f86/#e3f0f8`, Juntada `#4f6878/#eef2f5`, Citação `#1e7b45/#e6f4ec`); trilho em cartões `#fff` borda `#dde5eb` padding 16px gap 12px; interruptores 34x20 `#0a7aad`/`#cfd9e0`.

| ID | Área | Elemento | Gatilho | Comportamento completo | Confirmação | Dependências | Referência |
|---|---|---|---|---|---|---|---|
| PZ-PUSH-01 | Faixa | "Movimentações recebidas" + contagem | renderização | Total de movimentações do principal e dos apensos monitorados | não | captura | dc:2538-2539, 5679 |
| PZ-PUSH-02 | Faixa | ✦ "Resumir com IA" / "Gerar nova análise" / "Analisando…" | clique | Só com processo e movimentações; prompt com regras (não inventar prazo, não seguir instruções do texto, não repetir), marca [NOVA]; resposta JSON `{resumo, pontos≤5, quem}`; etapas a cada 1,3 s; grava `bj-push-ia[numero]` no topo; falha: aviso "Não foi possível gerar a análise agora. Tente novamente." | não | IA (`window.claude.complete` → gateway) | dc:2541-2542, 5293-5315, 5378-5384 |
| PZ-PUSH-03 | Faixa | Filtros Todas · Novas · Geram prazo | clique | "Novas" = não lidas **e** data 01 ou 02/10 (regra fixa de demonstração); "Geram prazo" = tipo Intimação ou Decisão | não | regra real de novidade | dc:2548, 5667-5668, 5682 |
| PZ-PUSH-04 | Faixa | Filtros por processo "Todos os processos · N", "Principal · N", "<tipo de apenso> · N" | clique | Aparece só se houver apenso | não | vínculo de apensos | dc:2545, 5681 |
| PZ-PUSH-05 | Análise IA | Cartão: "✦ Análise IA", "Gerado por IA · não é movimentação oficial · quando", "editado", "lido", cadeado, recolher com prévia | clique | Cadeado alterna `privada` (fora dos links compartilhados) com aviso; recolher grava `bj-push-ia-fech`; prévia "resumo · N ponto(s)"; pontos com chips Prazo, Atenção, Providência, Informação, Sem providência; "Quem deve agir"; "Base: N movimentações. Confira o documento original antes de agir." | não | não | dc:2554-2598, 5385-5396 |
| PZ-PUSH-06 | ⋮ análise | Editar (resumo em textarea, pontos editáveis com ×, nota "Conteúdo originalmente gerado por IA. Sua edição fica registrada como "editado"."), Salvar, Cancelar | menu ⋮ ou botão à vista | Salva com `editado:true, editadoEm` | não | não | dc:2573, 2600-2621, 5398-5403 |
| PZ-PUSH-07 | ⋮ análise | Marcar como lido / não lido | menu ou botão | Alterna `lido` (cartão a 82% de opacidade) | não | não | dc:2574, 2615, 5405-5406 |
| PZ-PUSH-08 | ⋮ análise | Criar tarefa da providência | menu | Abre "Nova meta" com o 1º ponto de prazo/providência (90 caracteres) e prazo +7 dias | não | metas | dc:2575, 5407 |
| PZ-PUSH-09 | ⋮ análise | Excluir análise | menu ou botão | Remove de `bj-push-ia` | janela `cf` vermelha "Confirmar exclusão": "Excluir esta análise da IA? As movimentações oficiais não são alteradas." | não | dc:2576, 2614, 5408 |
| PZ-PUSH-10 | Análise IA | "Enviar ao cliente" / "Reenviar ao cliente" + "Enviado dd/mm às hh:mm" com histórico na dica | clique | Sem e-mail cadastrado abre o formulário com erro "Cadastre um e-mail para enviar a análise."; senão abre a janela "Enviar ao cliente" (PZ-PUSH-33) | não | e-mail | dc:2616-2617, 5239-5246 |
| PZ-PUSH-11 | Cartão | Pílula do dia e ponto colorido com dica | hover (dica) | Juízo `#0f6ea8`, polo ativo `#22a447`, polo passivo `#ee7d4f`, cartório `#e0261b`, sem documento `#c3ccd2` (classificação por `BJProcesso.classificar` + regras) | não | não | dc:2638-2640, 5617-5627 |
| PZ-PUSH-12 | Cartão | Título = texto da movimentação, selo "NOVO", megafone espelhado | renderização | | não | não | dc:2642, 5632 |
| PZ-PUSH-13 | Cartão | "ID - Tipo" com Abrir documento, Copiar ID, Abrir no PJe | clique | Abrir mostra o texto lido (modal Documento) ou cai em `pjeAbrir`: copia o ID e abre `BLUEJUS_PJE_DOC_URL`, senão aviso "ID X copiado. Abra no PJe do tribunal (endereço do PJe ainda não configurado)" | não | URL do PJe por tribunal | dc:2643-2648, 5572-5573, 5633 |
| PZ-PUSH-14 | Cartão | Encaminhar (`bi-send`) | clique | `despachar({tipo:'push', titulo:'Tipo de data', sub:160})` | não | Chat I.A | dc:2656, 5637 |
| PZ-PUSH-15 | Cartão | Ações à vista: Criar tarefa, Encaminhar, Marcar como lida, hora | clique | Só para itens do Push (não para itens do PDF) | não | não | dc:2654-2659 |
| PZ-PUSH-16 | Cartão | "Criar tarefa" → "Tarefa criada" | clique | Cria meta direto "Providência: <tipo> de <data>", criador "Push processual", responsável da pasta, prazo = data da movimentação; marca como lida; estado `pushTarefas` não persiste | não | metas | dc:2655, 5635-5636 |
| PZ-PUSH-17 | Cartão | "Marcar como lida" / "Lida" | clique | `pushLidas` (estado, não persiste) | não | leitura por usuário | dc:2657, 5638 |
| PZ-PUSH-18 | Notas | Notas técnicas penduradas sob o cartão | renderização | `bj-push-notas-v1[pasta][mov:id ou pdf:id]`; "Nota técnica", iniciais, autor, quando, "· editada" | não | notas no servidor | dc:2662-2676, 5642-5661 |
| PZ-PUSH-19 | Notas | Editor "Explique o despacho: o que significa, o que precisa ser feito, prazo… Use @ para mencionar alguém." + "Salvar nota" / "Cancelar" | clique; Ctrl+Enter salva; Esc cancela | Guarda versões ao editar; auditoria `nota-tecnica`; avisos "Nota técnica adicionada/atualizada" | não | menções | dc:2679-2686, 5647-5652 |
| PZ-PUSH-20 | Notas | ⋮ da nota: Responder com outra nota, Copiar texto, Encaminhar via Chat I.A, Editar / "Editar · bloqueado", Excluir / "Excluir · bloqueado" | clique no ⋮ ou botão direito na nota | Autor 15 min, primário sem limite; bloqueado avisa "Só o autor edita/exclui a nota, em até 15 minutos (o usuário primário não tem limite)" | Excluir: janela `cf` vermelha "Confirmar exclusão": "Excluir esta nota técnica?" | permissões | dc:2666-2672, 5653-5660 |
| PZ-PUSH-21 | Botão direito no cartão | Adicionar nota técnica | `contextmenu` fora de link/botão/nota | Abre o editor de nota com foco | não | não | dc:2641, 5647-5648 |
| PZ-PUSH-22 | Botão direito no cartão | Criar tarefa / "Tarefa já criada", Marcar como lida / "Já lida", Abrir documento (se houver) | idem | | não | não | dc:5639 |
| PZ-PUSH-23 | Botão direito no cartão | Encaminhar via Chat I.A | idem | | não | Chat I.A | dc:5639 |
| PZ-PUSH-24 | Botão direito no cartão | Copiar texto | idem | Aviso "Texto copiado" | não | não | dc:5648 |
| PZ-PUSH-N01 | Linha do tempo | Itens vindos do PDF do processo | após leitura de arquivos | Fonte "PDF do processo · pág."; botão direito: Abrir documento, Abrir no PJe, Encaminhar via Chat I.A | não | leitura de PDF | dc:5621, 5629-5630 |
| PZ-PUSH-25 | Aba | Textos dos vazios | renderização | "Vincule um processo na aba Processo para receber o push das movimentações.", "Nenhuma movimentação neste filtro.", "Nenhuma movimentação recebida ainda." | não | não | dc:2551-2552, 5680 |
| PZ-PUSH-26 | Aba | "Exibindo as N mais recentes" | (ausente) | Não existe no arquivo online | não | não | grep |
| PZ-PUSH-27 | Trilho | Monitoramento: ponto 10px `#1f9d61` pulsando, "Monitoramento ativo/pausado"/"Sem monitoramento", subtítulo "Captura automática nos diários e no PJe · N processos", interruptor, PRINCIPAL, apensos com interruptor "monitorado/pausado", "Última verificação: hoje, 09:12" | clique | `pushOff` só no estado; pausar apenso tira as movimentações dele da lista | não | robô de captura; preferência persistida | dc:2696-2707, 5692-5699 |
| PZ-PUSH-28 | Trilho | Buscar agora, Cancelar a leitura, "Atualizar com arquivos do processo" (input múltiplo .pdf .zip .docx .txt .html .htm .rtf .md .odt image/*), zona de soltar | clique; `drop` | Leitura em 12 etapas com barra; ZIP descompactado; PDF página por página; imagem/digitalizado avisa que no sistema real entra OCR; arquivos lidos e descartados; Cancelar: "Leitura cancelada; nada foi alterado"; ao fim aviso com versão da análise e auditoria | não | captura PJe; OCR; leitura no servidor | dc:2709-2713, 2750-2752, 5511-5571, 5675-5678, 5700-5702 |
| PZ-PUSH-29 | Trilho | Previsão: "Próximo ato provável", "Data provável", Confiança, alerta de inconsistência, "Última análise: … Previsão da I.A, não é ato oficial.", "Ver análise completa" | renderização; clique | Usa a análise gravada do `BJProcesso` | não | motor processual | dc:2716-2727, 5604-5614 |
| PZ-PUSH-30 | Trilho | Modal "Análise do processo" (Fato, Inferência, Previsão) | "Ver análise completa" | 16 seções (O que mudou, 1 a 14, Linha do tempo, Aprendizado) com link de origem que abre o modal Documento | não | motor processual | dc:225-248, 5575-5603 |
| PZ-PUSH-N02 | Trilho | Cartão "Situação atual" (Situação, Último evento, Próximo ato, Quem age, Prazo, Previsão, Próxima etapa provável, Confiança, Base, nota, aprendizado) | renderização | Só aparece com monitoramento ativo e sem análise do `BJProcesso`; regras CPC (`REGRAS_PROC`), dias úteis com feriados nacionais e recesso; aprendizado em `bj-push-previsao-v1` | não | motor processual | dc:2730-2747, 5316-5372, 5674 |
| PZ-PUSH-31 | Trilho | "Avisar quando chegar": Responsável da pasta, Por e-mail, Análise da BlueJus IA | clique | Só alterna `pushAvisoOff` no estado; sem efeito | não | notificações | dc:2755-2759, 5703 |
| PZ-PUSH-32 | Trilho | "Avisar cliente": contagem, "Cadastrar e-mail" (rótulos Pessoal, Trabalho, Familiar, Outro), lista (estrela principal, interruptor pausar, ×), "Cópia para a Controladoria · Sempre", "Envio automático" (Novas movimentações, Audiências e intimações, Resumo semanal), "Análise da BlueJus IA · Só manual", histórico de envios | clique; Enter/Esc no campo | Validações "Digite um e-mail válido.", "Este e-mail já está cadastrado.", "Limite de 5 e-mails por cliente."; grava `bj-cli-emails-v1[documento]`; envio automático sem e-mail abre o formulário | Remover: janela `cf` vermelha "Confirmar exclusão": "Remover <e-mail> dos avisos ao cliente?" | e-mail transacional; CC obrigatório no servidor | dc:2764-2820, 5223-5291 |
| PZ-PUSH-33 | Trilho | Modal "Enviar ao cliente" | Enviar/Reenviar ao cliente | Para (alternar), CC Controladoria travado, Assunto, Mensagem montada, caixa "Revisei e corrigi o conteúdo. Autorizo o envio ao cliente em nome do escritório."; erros "Escolha ao menos um destinatário.", "Assunto e mensagem não podem ficar vazios.", "Confirme que revisou o conteúdo antes de enviar.", "Falha no envio. O texto foi mantido; tente de novo."; "Enviando…"; usa `BJMail.enviar` ou simula 700 ms; registra o envio na análise e no histórico | a própria caixa de autorização | serviço de e-mail | dc:2826-2862, 5270-5289 |
| PZ-PUSH-N03 | Aba | Traço vermelho da aba Push | renderização | "N movimentação(ões) nova(s) sem leitura" | não | não | dc:5503, 5507 |
| PZ-PUSH-N04 | Aba | Origem dos prazos **[NOVO-16/10]** | automático | Prazos calculados pelo `BJProcesso` (não cumpridos/encerrados) entram em "Próximos prazos" com origem "Push processual" (ou "(estimado)") e em `bj-prazos-pasta-v1` | não | motor de prazos | dc:6394, 6412-6423 |
| PZ-PUSH-N05 | Aba | Link externo do Push | botão direito na aba Push; ⋮ da pasta "Link externo do Push" | Ver Índice de menus e de modais (Link externo, Acessos ao link); regras de bloqueio (aba oculta, sem processo, cancelada, arquivada, sem movimentações) | Revogar: janela `cf` azul "Revogar este link? Quem tiver o endereço perde o acesso na hora." | página pública, tokens | dc:4623-4652, 5415-5480; bj-link.js |

### 4. BLUEJUS Intelligence, agente e Chat I.A (PZ-IA)

Visual do drawer: fundo `rgba(10,30,45,.18)` z 95; painel 460px z 96 fundo `#f7fafc`, sombra `-24px 0 60px -30px rgba(10,50,80,.5)`, entrada `iaIn .38s`; cabeçalho gradiente `#0d80a3 → #0b6688`, padding 14px 16px 12px, "BLUEJUS IA" 10px/800 espaçamento .18em; abas 32px 12px/700 (ativa branca com `#0b6688`); CTA gradiente `#1597bd → #0b6688` altura 42px. Botão de abrir no cabeçalho: altura 32px, estrela SVG gradiente `#1fa6cc → #0b5f86`, rótulo "BLUEJUS" + "Intelligence".

| ID | Área | Elemento | Gatilho | Comportamento completo | Confirmação | Dependências | Referência |
|---|---|---|---|---|---|---|---|
| PZ-IA-01 | Drawer | Abrir/fechar "BLUEJUS IA · Assistente jurídica do escritório" | clique em "BLUEJUS Intelligence"; × ou clique no fundo | Com cadeado "oculto" avisa "Pasta trancada pelo usuário primário" e não abre; **não há Esc** para fechar | não | IA | dc:936-946, 1266-1271, 5940-5941 |
| PZ-IA-02 | Drawer | Chips de contexto: Pasta, cliente, processo, responsável | renderização | Dica "Contexto carregado automaticamente desta tela" | não | não | dc:948-949, 5942 |
| PZ-IA-03 | Drawer | "O que a IA sabe desta pasta": documentos, tarefas, prazos, anotações, fontes | renderização | Contagens locais | não | não | dc:957-958, 5947 |
| PZ-IA-04 | Drawer | Abas Análise, Documentos, Relatórios, Perguntar | clique | Trocar aba limpa resultado, confirmação e aviso | não | não | dc:952, 5914, 5943 |
| PZ-IA-05 | Análise | Fase identificada (Confiança), porquê, momento, "Ver como a IA chegou a esta conclusão", "Fase errada?" (select), "INCONSISTÊNCIA IDENTIFICADA" (Dados do sistema x Evidências) + "Sugerir atualização" | clique; `change` | Corrigir grava na memória `iaMem` e `iaFaseCorr` (estado, não persiste) e auditoria; Sugerir abre confirmação interna; confirmar mostra "Atualização de fase enviada para aprovação." | confirmação interna do drawer "Confirma esta ação?" + Cancelar/Confirmar | fluxo de aprovação | dc:962-991, 5948-5952, 5992 |
| PZ-IA-06 | Análise | "Próximas providências sugeridas" marcáveis + "Criar N tarefa(s) em Metas" | clique | Confirmar cria metas com criador "BlueJus IA", prazo +3 dias, aviso "N tarefa(s) criada(s) na aba Metas." (3,2 s) | confirmação interna: "N tarefa(s) será(ão) criada(s) na aba Metas para <resp>, com prazo de 3 dias úteis: …" | metas | dc:994-1003, 5953-5956, 5987-5990 |
| PZ-IA-07 | Análise | "Situação da pasta" (nível) + achados + "Como cheguei a esta conclusão?" | clique | Lista de fontes | não | não | dc:1007-1020, 5945-5946 |
| PZ-IA-08 | Análise | Achados e fontes | renderização | idem | não | não | dc:1012-1019 |
| PZ-IA-09 | Análise | "Memória de aprendizado do escritório" | renderização | Só itens desta sessão | não | memória persistente | dc:1023-1026, 5957 |
| PZ-IA-10 | Análise | 10 comandos: Analisar esta pasta, Identificar riscos, Verificar prazos, Processo parado?, Próximas providências, Briefing para o advogado, Linha do tempo, Analisar histórico, Raio-X jurídico, Análise jurídica profunda | clique | `iaOrquestrar(agente, …)`: agentes Gestor, Processual, Documental, Prazos, Relatórios, Cliente, Jurídico; etapas a cada 850 ms; regras `IA_REGRAS` (hierarquia de fontes, sem inventar, formato CONCLUSÃO/EVIDÊNCIAS…); sem provedor cai em texto local com rodapé "Análise montada localmente…" | não | gateway de IA (`BLUEJUS_IA_GATEWAY` POST) ou `window.claude.complete` | dc:1029-1035, 5709-5730, 5839-5862, 5892-5903, 5958 |
| PZ-IA-11 | Resultado | Copiar, Baixar .txt, Imprimir | clique | Copiar mostra ✓ por 1,4 s; .txt "BLUEJUS IA / título / texto / origem"; Imprimir abre janela nova e chama `print()`; auditoria | não | não | dc:1094-1096, 5995-5999 |
| PZ-IA-12 | Resultado | Criar tarefa, Criar prazo, Enviar para revisão | clique | Só confirmam e mostram aviso ("Tarefa criada para…", "Prazo criado como pendente de conferência", "Análise enviada para revisão"); **não criam meta nem prazo de fato** | confirmação interna com texto da ação | metas, prazos, fluxo de revisão | dc:1103-1105, 5980-5993 |
| PZ-IA-13 | Perguntar | "Pergunte sobre esta pasta..." + Enviar; 4 sugestões (Esse processo está parado?, O que falta fazer?, Veja se existe prazo, Existe algum risco?) | Enter; clique | Histórico das últimas 8 mensagens; fallback local por palavra-chave com rodapé "(Resposta montada localmente…)" | não | IA | dc:1070-1079, 1124-1127, 5918-5936, 5970-5976 |
| PZ-IA-14 | Documentos | Selecionar todos, lista com caixas, "Ler N documento(s)", "Índice inteligente" | clique | Agente Documental | não | IA, OCR | dc:1039-1054, 5959-5966 |
| PZ-IA-N01 | Relatórios | 6 modelos (Relatório da pasta, do cliente, Resumo para o cliente, de prazos, de risco, Linha do tempo) + "Gerar …" | clique | Resumo para o cliente pede linguagem leiga em até 8 frases | não | IA | dc:1057-1068, 5904-5912, 5967-5969 |
| PZ-IA-15 | Drawer | "Registro de auditoria (N)" | clique | Últimas 50 ações da sessão (estado) | não | auditoria persistente | dc:1131-1134, 5849, 6000-6001 |
| PZ-IA-N02 | Agente | `bj-agente.js` "Assistente de pastas" (janela lateral 400px, lançador fixo) | **não carregado pelo 02** | Ferramentas e risco: buscar_pastas (leitura), contar_pastas (leitura), definir_responsavel (alteração), definir_marcador (alteração), definir_situacao (alteração), definir_prioridade (alteração), arquivar_pastas (sensível), **prazos_pastas (leitura) [NOVO-16/10]**, ler_documentos (leitura, indisponível), anexar_documento (alteração, indisponível), excluir_documento (sensível, indisponível). Alteração só para primário/Master; plano antes de executar; mais de 50 pastas ou sensível exige caixa "Confirmo a alteração de N pasta(s)"; execução em lotes com barra; Desfazer por 10 min; auditoria `bj-agente-log` (300); alterações em `bj-agente-alt`; Esc fecha | plano + caixa de confirmação | endpoints de pastas em lote (ESPEC_AGENTE_PASTAS.md) | bj-agente.js:6, 13, 30-42, 115-170, 186-211 |
| PZ-IA-N03 | Agente | Ferramenta `prazos_pastas` **[NOVO-16/10]** | pedido com "prazo/venc/vence" sem verbo de alteração | Período hoje, amanhã, semana (até domingo, padrão), mês (30 dias), vencidos ou N dias; lê `bj-prazos-pasta-v1` (gravado pelo 02 em `pzPublicar`); lista até 12 com "Vencido há N dias/Hoje/Amanhã/Faltam N dias" e cores; "Mostrar na lista"; nota de fonte (Próximos prazos, Push, Metas abertas) | não | contrato de dados entre telas | bj-agente.js:58, 97-115, 132; dc:6412-6423 |
| PZ-IA-N04 | Agente | Exemplo "Quais pastas têm prazo esta semana?", lançador esmaece para 35% após 4 s, evento `bj-ai-fx` ao abrir **[NOVO-16/10]** | hover/foco/clique | | não | não | bj-agente.js:193, 202-205 |
| PZ-IA-N05 | Pasta → agente | `pzPublicar` grava `bj-prazos-pasta-v1[nup]` e `bj-ativ-pasta-v1[nup]` (dias com atividade) **[NOVO-16/10]** | 1,2 s após montar; a cada mudança de pasta, metas, análise ou prazo; senão a cada 2 s de atualização | Só regrava se mudou; consumido pelo agente e pela Lista 03 | não | endpoint de prazos agregados | dc:6359, 6412-6424 |
| PZ-IA-16 | Chat | Botão flutuante, selos, janela "Central de Comunicação" | central | Fornecido pelo `bluejus-central.js`; não detalhado aqui além do que a pasta usa | não verificado estaticamente | Chat | bluejus-central.js |
| PZ-IA-25 | Chat | Comandos "/" | digitação no Chat | Catálogo `COMANDOS_IA`: 302 comandos em 49 grupos pela contagem estática (a especificação cita 321 em 51) | não | IA | bluejus-central.js:397 |
| PZ-IA-29 | Chat | Destino de todo "Encaminhar via Chat I.A" | `despachar` | Abre o Chat em "Despachar via Chat I.A": cartão do item, "Instrução (opcional). Ex.: favor concluir até sexta.", prioridade Normal/Urgente/Para hoje, "Para quem?" (conversas e usuários); envia "🔴 URGENTE · Despacho: …" com contexto da pasta; urgente também notifica; auditoria; aviso "Despachado para X." | não | Chat I.A | dc:4883; bluejus-central.js:6216-6236, 6642 |
| PZ-IA-N06 | Chat | Contexto da pasta exposto ao Chat | ganchos globais definidos em `centralSync` | `__bjContexto`, `__bjIAFontes` (respeita cadeado: só nome e CPF), `__bjTimelineFontes`, `__bjIAAcao` (abrir_meta, abrir_processo, abrir_push, abrir_documentos, criar_meta, criar_meta_direta, remover_metas_ia), `__bjDocs`, `__bjFinanceiro`, `__bjCarteira`, `__bjParadas*`, `__bjSemProc*`, `__bjQualificacao`, `__bjClientes`, `__bjEvidencia`, `__bjCobrarChecklist` | não | API de contexto filtrada por permissão | dc:4371-4430 |
| PZ-IA-N07 | Chat | Navegação vinda do Chat | evento `bj-navegar` `{pasta, meta, aba, filtro}` | Troca de pasta e aba; meta inexistente avisa "Meta não encontrada. Ela pode ter sido excluída." (4,2 s) | não | não | dc:4435-4442; bluejus-central.js:5026, 5047, 5420 |
| PZ-IA-31 | Chat | Alertas de meta e prazo | sino da meta; item "Alertar <nome>" do prazo | `BJCentral.alertar` / `notificar({tipo:'toque'})`; avisos "🔔 X foi alertado para verificar", "Alerta enviado para X" | não | notificações | dc:4673-4683, 6559 |
| PZ-IA-N08 | Relatórios | Relatórios mensais enviados à Controladoria (pastas paradas, pastas sem processo) | `setInterval` de 60 s: na 1ª segunda-feira do mês após 10h, 1 vez por mês | Chaves `bj-paradas-envio-v1`, `bj-semproc-envio-v1`, histórico `bj-semproc-hist-v1` (24 meses); `BJCentral.paradasNotificar`/`semProcNotificar` | não | agendador no servidor | dc:4374, 4378, 4816-4828 |

### 5. Elementos globais da página (PZ-GLB, PZ-MOD)

| ID | Área | Elemento | Gatilho | Comportamento completo | Confirmação | Dependências | Referência |
|---|---|---|---|---|---|---|---|
| PZ-GLB-01 a 09 | Topo | Menu `bi-list`, logo, "Pastas", escritório `bi-building` "Farlei Assis da Rocha S…", sino "9+", tela cheia, nome + avatar "SF" + caret, navegação Expediente/Demandas/Processos | **nenhum tem handler** (estáticos) | Em até 640px escondem escritório e nome; navegação rola na horizontal | não | menu, notificações, perfil | dc:844-869, 73-78 |
| PZ-GLB-10 | Dicas | Todo `title` vira dica azul-clara | `mouseover` com 280 ms; some em mousedown, keydown, wheel, scroll, contextmenu | Fundo `#e5f1f7`, texto `#0b4f73` 600 12px/1.4, borda `#b9d6e8`, raio 4px, máx 300px, abaixo do alvo ou acima se não couber; `data-tip-proprio` desliga | não | não | bj-dica.js:1-42 |
| PZ-GLB-12 | Aviso | `gmAviso`: toast padrão | ~85 chamadas | Fundo `#12242f`, ✓ `#5fd39a`, 13px, inferior central, 2,6 s (o mesmo `edAviso` usa 3 s ou 6 s em casos do editor) | não | não | dc:669, 4687 |
| PZ-GLB-12 | Aviso | `BJCentral.aviso` | e-mail, checklist, análise | Toast do central (estilo próprio, não verificado estaticamente) | não | não | dc:5252, 5288 |
| PZ-MOD-09 | Confirmação | Janela `cf` (alertdialog 420px, cabeçalho `#0b6a96` com ícone de alerta, texto, Cancelar `#eef2f5`, botão de ação) | `confirmarUI(msg, ok, perigo)`, `confirmar(t, x, ok, rot)` ou `setState({cf})` | Enter confirma, Esc fecha, foco automático, clique no fundo fecha; mensagem com "\n" vira título + texto; sem título usa "Confirmar exclusão" (perigo) ou "Confirmar" | é a própria | não | dc:353-360, 6242-6244 |
| PZ-MOD-25 | Confirmação | Variante azul (`#0a6fa0`, ✓) para ação sem perigo; vermelha `#b3262e` com lixeira | `azul: !perigo` | | | | dc:6242, 6244 |
| PZ-MOD-26 | Confirmação | Ausência de `confirm()` nativo | | **Quebrada no online**: 1 `confirm()` nativo novo em "Remover réu" e 1 `window.prompt()` em "Excluir pasta" **[NOVO-16/10 para o confirm]** | `confirm('Remover <nome> dos réus desta pasta?')`; `prompt('Excluir a pasta N (cliente)? … digite o número da pasta:')` | | dc:3542, 4581 |
| PZ-MOD-10 | Busca rápida | "Buscar pasta, cliente, CPF ou processo" | Ctrl+K / Cmd+K abre e fecha; ↑/↓, Enter, Esc | Até 8 resultados (Pasta, Cliente, Processo); dígitos casam a partir de 3; abrir troca de pasta na aba Dados | não | busca global | dc:403-417, 6444-6478 |
| PZ-GLB-15 | Atalhos | E, H com o ⋮ da pasta aberto; Esc fecha o menu | `keydown` global `_mTec` | **"T" (Timeline inteligente) aparece no menu mas não é tratado** no atalho; ignora com Ctrl/Alt ou foco em campo | não | não | dc:1220-1222, 6359, 6922 |
| PZ-GLB-N01 | Duplo clique | Inventário de duplo clique | `onDoubleClick` | Cabeçalho da Nova meta recolhe (dc:83); alça de coluna volta ao padrão (dc:2342); item do explorador abre (dc:2373); estrela bloqueia o duplo clique (dc:2374); arquivo do Financeiro usa `onDblClick`, nome que o runtime não mapeia (provável inoperante, não verificado estaticamente) (dc:1966). Não há duplo clique global | não | não | dc:83, 1966, 2342, 2373-2374; support.js:317-345 |
| PZ-GLB-N02 | Botão direito | Botão direito fora fecha o menu aberto (PZ-CTX-18) | `contextmenu` nos fundos de `gm`, explorador, metas, cliente, destaque | | não | não | dc:259, 363, 873, 1581, 2394 |
| PZ-GLB-N03 | Permissões | Perfis simulados | `BJCentral.ehMaster`, `ehPrimario`, `eu()`; `?como=<id>` na URL ou `sessionStorage bj-como` | Master: edita e exclui sem restrição; primário: edita sem limite, exclui só em 60 min, oculta abas, tranca a pasta, alterações em lote; autor: 15 min para editar, 60 min para excluir; secundário: lê | não | papéis por tenant no servidor | dc:6222-6239, 4619-4622; bj-contas.js:27-28 |
| PZ-MOD-12 | Cadeado | Níveis por colaborador (oculto, ver, editar) ou "todos" | ⋮ da pasta "Cadeado" (só primário) ou evento `bj-abrir-cadeado` | `bj-pasta-cadeado-v1`; histórico em `bj-pasta-hist-v1`; auditoria; "ver" ativa a guarda de escrita (PZ-DOC-24); "oculto" bloqueia a IA | não | ACL por pasta | dc:505-548, 6076-6116, 6357-6358 |
| PZ-CTX-04 | Abas | Ocultar aba por pessoa | botão direito na aba (só primário; aba Push para os demais mostra itens de link) | "Ocultar para toda a equipe", "Ocultar para…" (janela), "Mostrar para todos"; `bj-abas-visib-v1` + log `bj-abas-visib-log` (100); não primário: aviso "Só o usuário primário pode ocultar abas" | não | permissão por aba | dc:1332, 4617-4672 |
| PZ-GLB-17 | Contas | Multiconta (`bj-contas.js`) | carregado antes do central | Isola chaves do `localStorage` com prefixo `bjt:<conta>:` (a conta demo mantém as chaves originais); tela com abas "Minha conta", "Abrir conta", "Trocar conta" existe em `BJContas.tela()`, mas **nada no 02 a abre**; trocar conta recarrega a página; Master único por e-mail; nomes reservados bloqueados | não | tenants, tenant_users, RLS | bj-contas.js:1-33, 81, 89-139 |
| PZ-GLB-N04 | Impressão | Janela "Imprimir · <título>" | `gmImprimir`, "Imprimir resumo", relatórios | "Incluir na folha" (Cabeçalho do escritório, Dados da pasta e do processo, Autor/data/tipo, Financeiro se visível), tamanho do texto 9 a 16 pt, "Papel A4…", Imprimir (`BJImprimir` ou `window.print`), Baixar PDF (html2pdf com rodapé paginado, fallback HTML), Copiar texto; auditoria `impressao` | não | geração de PDF no servidor | dc:202-221, 4829-4882, 4806-4811 |
| PZ-GLB-N05 | Tema | Tema escuro | (ausente) | Nenhuma `prefers-color-scheme` no 02 ou dependências | não | não | grep |
| PZ-GLB-N06 | Responsivo | Media queries | CSS | `(hover:none)` mostra ações do cartão; `prefers-reduced-motion` desliga 4 grupos de animação; `max-width:980px` grade 1 coluna; `max-width:640px` cabeçalho compacto, esconde escritório e nome, navegação rolável; `overflow-x:clip` no html/body; editor 34x32 em toque | não | não | dc:23, 40, 47, 67, 69, 71-78; bj-editor.js |
| PZ-GLB-N07 | Notificações | Notificações recebidas | Chat I.A | Sino do topo é estático; notificações reais ficam no Chat (menções, respostas, despachos, checklist, alertas) | não | notificações | ver PZ-IA |
| PZ-GLB-N08 | Favoritos | "Fixar nos favoritos" e "Acompanhar alterações" da pasta; favoritos de documentos | ⋮ da pasta; estrela no explorador | `prefPasta` grava `bj-pasta-prefs-v1[usuario:pasta]` e dispara `bj-pasta-pref`; documentos ver PZ-DOC-14 | não | preferências por usuário | dc:1239-1240, 4490-4498, 7038-7039 |
| PZ-GLB-N09 | Eventos | Eventos emitidos pela pasta | `dispatchEvent` | `bj-pasta-duplicar`, `bj-pasta-mover`, `bj-pasta-excluir`, `bj-pasta-editada`, `bj-pasta-pref`, `bj-evidencia` (1,5 s após cada atualização), `bj-editor-inserir`, `bj-chat-anexar-texto` (sem ouvinte), `bj-evento` | não | barramento real | dc:4426 a 6424 (grep) |
| PZ-GLB-N10 | Sincronização | Ouvintes `storage` | outra aba grava | `bj-pasta-docs-v1`, `bj-pasta-cadeado-v1`/`acesso`, `bj-com-destaque-v1` re-renderizam | não | tempo real no servidor | dc:6347, 6358 |


### 11. Limitações

1. Leitura estática: animações, posição real de menus, comportamento do runtime `support.js` (por exemplo se `onDblClick` é ou não ligado) e da `BJVisor` não foram executados.
2. `bluejus-central.js` (1,4 MB) foi lido só nos pontos que a pasta usa (API `window.BJCentral`, despacho, menções, navegação, catálogo de comandos). A janela da Central, selos, sons, baixas e ligações (PZ-IA-16 a 28, 30, 32) não foram reinventariados.
3. A contagem de comandos "/" (302 em 49 grupos) usa expressão regular sobre o formato `{ c: '…', g: '…' }`; entradas em outro formato não entram.
4. A parte A cobre cabeçalho, cadastro, Dados, Metas, Processo e Financeiro; aqui esses blocos aparecem só nos índices.
5. Valores visuais calculados em tempo de execução (`{{ … }}St`) foram lidos no código, não medidos.


# Parte 3. BlueJus, camada de tela



> Worktree `fidelidade-designer`, HEAD `9c081725`. Levantamento estático (leitura de Twig, JS e CSS), sem
> execução, sem navegador, sem banco. Referências no formato `arquivo:linha` relativas a `app/`
> (`show` = `templates/pasta/show.html.twig`, `cab` = `templates/pasta/_cabecalho.html.twig`).
>
> Convenções:
> - **ID**: reaproveita o ID do inventário do protótipo (parte A, `inv-designer-A.md`) ou do checklist de
>   13/10 quando o elemento corresponde. Elemento sem correspondente recebe `PZ-<AREA>-Sxx` (S = só do BlueJus
>   ou sem linha própria).
> - **[SÓ-BLUEJUS]**: existe no BlueJus e não no protótipo (funcionalidade real a preservar). Consolidado no fim.
> - **Confirmação**: `cfPs` = `window.confirmarPs({...})` (janela `#psConfirmar`, `_partials/_janela_confirmacao`);
>   `cfUI` = `window.confirmarUIPs(msg, perigo)` (mesma janela; 1ª linha da mensagem vira título; perigo = botão
>   vermelho "Confirmar" com `bi-exclamation-triangle`, senão azul `bi-check2`); `data-ps-cf` = form interceptado
>   em captura por `pasta-dialogos.js` (`ligarEnvioConfirmado`) que pergunta por `cfUI`; nativo = `confirm()`,
>   `prompt()` ou `alert()` do navegador.
> - **Permissão**: `podeEditarPasta` = `can_access_resource('pasta', id, 'edit')` (calculado uma vez em `show:44`
>   e repassado); `podeExcluirPasta` = `can_access_resource('pasta', id, 'delete')` (`cab:41`); `lápide` =
>   `pasta.estaExcluida`.
> - Decisões já tomadas, apenas registradas: P1 faixa Processo · Ação · Responsável preservada no cabeçalho;
>   P2 "Como usar esta aba" preservada (Detalhes); P3 "Andamento da pasta" não implementado.

---

### 0. Montagem da página (o que carrega)

| Ordem | Recurso | Onde é incluído | Observação |
|---|---|---|---|
| CSS | `css/pasta-explorador.css`, `css/pasta-show.css`, `_resp_estilo` | `show:10-20` (head) | |
| CSS | `quill.snow.css` + `css/editor-rico.css` | `_dados_anotacoes:42`, `_detalhes_obs:40`, `_financeiro:20` (3 vezes) | carregado em triplicata no corpo |
| CSS | `css/nota-tecnica.css` | `_processos_vinculados:49` | |
| CSS | `css/pasta-ia.css` | `_push_processual:312` | |
| CSS | `css/pasta-ia-agentes.css` | `inteligencia/_agentes_drawer:63` | só com módulo inteligencia |
| CSS | `css/pasta-checklist.css`, `css/pasta-documentos-sugeridos.css` | `_documentos_checklist:238`, `_documentos_sugeridos:229` | |
| CSS | `css/pasta-impressao.css` | `_impressao_modal:15` | |
| CSS | `css/visualizador-documento.css` | `show:1909` | |
| JS | `quill.js`, `editor-rico.js` (defer), `pasta-mencoes.js` (defer) | `_dados_anotacoes:44-47` (e repetidos em Detalhes/Financeiro) | |
| JS | `pasta-clientes.js`, `pasta-clientes-busca.js` (defer) | `_dados_trilho:274-275` | |
| JS | `nota-tecnica.js`, `pasta-processo.js` | `_processos_vinculados:50-53` | |
| JS | `pasta-push.js`, `pasta-ia-push.js` | `_push_processual:309-313` | |
| JS | `pasta-ia-agentes.js` (defer) | `inteligencia/_agentes_drawer:64` | |
| JS | `pasta-ler-mais.js` | `_detalhes_obs:47` | |
| JS | `pasta-explorador-visor.js` | `_documentos_explorador:365` | |
| JS | `pasta-documentos-sugeridos.js` | `_documentos_sugeridos:230` | |
| JS | `pasta-impressao.js` (defer) | `_impressao_modal:58` | |
| JS | `visualizador-documento.js` | `show:2325` | |
| JS | `pasta-metas.js`, Sortable 1.15.6 (jsDelivr), `pasta-checklist.js` | `show:4343-4347` | |
| JS | `_resp_script` (inline), `_janela_confirmacao` (inline) | `show:4356-4362` | |
| JS (bloco javascripts) | `pasta-explorador.js`, `pasta-show.js`, `pasta-timeline.js`, `pasta-dialogos.js` | `show:4396-4402` | depois do Bootstrap |
| NÃO carregado | `pasta-arquivos.js` / `pasta-arquivos.css` | só Cobrança (`cobranca/objeto/show:2371`) | comentário em `pasta-show.js:10` e `cab:520` ainda cita o pasta-arquivos (texto desatualizado) |
| Inline | ~13 blocos `<script>` em `show` (clientes, registro, processo, editar dados, situação/prioridade, nova meta, upload) | `show:900-4340` | |
| Inline morto | CSS `.doc-folder-*` e `.pasta-secao-card*` | `show:56-194` | classes do layout antigo de documentos; nenhum template da aba atual as usa (o explorador é `.pex-*`) |

---

### A1. Cabeçalho da pasta (`_cabecalho.html.twig`)

#### A1.1 Linha 1 (voltar, número, prioridade, marcadores, setas, ações)

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-N01 | Contêiner | `<header class="ps-card ps-cabecalho">` | estrutural | Envolve linhas 1 a 3. Não existe `data-cad-area` (não há cadeado) | Não | sempre | cab:43 |
| PZ-CAB-01 | Linha 1 | Botão "Voltar" `bi-arrow-left` (rótulo visível "Voltar") | clique | Link GET `expediente_index` (não history.back) | Não | sempre | cab:48-50 |
| PZ-CAB-02 | Linha 1 | "PASTA" + `pasta.nup` (`.ps-cab-nup`, `.ps-num`) | nenhum | Texto | Não | sempre | cab:54 |
| PZ-CAB-03 | Linha 1 | Pílula de prioridade `bi-flag-fill` + rótulo + `bi-chevron-down` (`.ps-prio--normal/prioridade/urgente`) | clique | Abre popover `#psPrioridade` (Normal, Prioridade, Urgente, cada um com `bi-flag-fill` e ✓). Escolher: `fetch POST pasta_alterar_prioridade` (FormData `prioridade`, `_token` `pasta_prioridade_<id>`); troca a classe da pílula pelo valor escolhido e o rótulo pelo `json.label`; move o ✓; fecha o popover | Não. Erro: `alert()` nativo "Erro ao alterar prioridade." | `podeEditarPasta`; sem permissão vira `<span>` estático sem chevron | cab:60-90; show:3942-3977 |
| PZ-CAB-04 | Linha 1 | Etiquetas de marcador `bi-tag-fill` + nome (cor via `--etq`) | clique | Simula clique no "+" (abre o modal de marcadores) | Não | sempre (uma por `pasta.marcadores`) | cab:94-100; pasta-show.js:487-492 |
| PZ-CAB-05 | Linha 1 | "+" Marcadores da pasta (`.ps-etiqueta-add.js-mover-para`) | clique | Abre `#modalMoverPara` (ver PZ-MOD-11) | Não | sempre (sem checagem de permissão no Twig) | cab:104-111; `_partials/modal_mover_marcador:429` |
| PZ-CAB-06 | Faixa (linha 2) | Chip do Responsável (avatar 24px, nome, chevron) | clique | Abre `#pastaRespMenu` (busca + lista). Fica na faixa de dados, não na linha 1 | Não | sempre (o servidor recusa sem edit) | cab:486-494; `_resp_chip` |
| PZ-CAB-07 | Faixa | Menu do responsável: busca "Filtrar responsável", "Sem responsável" + usuários do escritório, ✓ no atual | clique numa opção, ↑/↓, Enter, Esc | `fetch PATCH pasta_atualizar_responsavel` (`_token` `pasta_responsavel_<id>`, `responsavel_id`); troca otimista do chip, desfaz em erro | Não. Erro: `alert()` nativo | sempre | `_resp_menu`; `_resp_script:156-262` |
| PZ-CAB-08 | Linha 1 | Setas ‹ › e "N de M" | clique | Links GET `pasta_show` da vizinha anterior/próxima (ordem do acervo, `vizinhas`); sem vizinha vira `<span>` inerte com `aria-disabled`; `title` = rótulo da vizinha | Não | sempre; "N de M" só com `vizinhas.total` | cab:122-153 |
| PZ-CAB-09 | ⋮ | Botão "Mais ações" `bi-three-dots-vertical` | clique | Abre popover `#psMenuAcoes` (pasta-show.js `popovers`); arrastável pela tarja; ganha X de fechar automático | Não | não lápide | cab:178-183; pasta-show.js:204-283 |
| PZ-CAB-N02 | ⋮ | Atalhos E, H, T com o ⋮ aberto; Esc fecha | tecla | E/H clicam o item `[data-ps-atalho]` (pasta-show.js:471-483); T abre a Timeline (pasta-timeline.js:116-127); ignorados em campo de texto ou com Ctrl/Meta/Alt | Não | idem ⋮ | |
| PZ-CAB-10 | ⋮ | Tarja "Pasta nnnn" `bi-folder2-open` | arraste | Alça de arraste do menu | Não | | cab:184 |
| PZ-CAB-11 | ⋮ | Editar dados (E) `bi-pencil` | clique | Abre `#modalEditarPasta` (Bootstrap) | Não | `podeEditarPasta` | cab:188-194 |
| PZ-CAB-12 | ⋮ | Histórico (H) `bi-clock-history` (`#psHistoricoAbrir`) | clique | Alterna o drawer `#psHistorico` | Não | sempre | cab:197-201 |
| PZ-CAB-13 | ⋮ | Timeline inteligente (T) `bi-activity` (`#psTimelineAbrir`) | clique | Abre o painel `#psTimeline` (ver PZ-CAB-S05) | Não | sempre | cab:204-208 |
| PZ-CAB-14 | ⋮ | Duplicar pasta `bi-files` | submit | Form POST `pasta_duplicar` (`_token` `pasta_duplicar_<id>`), recarrega para a cópia | `data-ps-cf`: "Duplicar a pasta N? A cópia leva cliente, ação, responsável e checklist; documentos, metas e financeiro não são copiados." (azul, sem perigo). Fallback `confirm()` no `onsubmit` | `can_access_module('pastas')` | cab:213-225 |
| PZ-CAB-15 | ⋮ | Vincular processo `bi-link-45deg` | clique | Abre `#modalVincularProcesso` | Não | `podeEditarPasta` | cab:227-230 |
| PZ-CAB-16 | ⋮ | Trocar responsável `bi-person-gear` | clique | Simula clique no chip do responsável (abre PZ-CAB-07) | Não | `podeEditarPasta` | cab:231-233; pasta-show.js:426-432 |
| PZ-CAB-17 | ⋮ | Mover para outra carteira | ausente | Não existe no BlueJus | | | |
| PZ-CAB-N03 | ⋮ | Seção "Compartilhar" | rótulo | | | sempre | cab:236-237 |
| PZ-CAB-18 | ⋮ | Copiar link da pasta `bi-link` | clique | `navigator.clipboard.writeText(url absoluta)`; rótulo vira "Link copiado" + `bi-check2` por 900 ms e o menu fecha; falha: "Não foi possível copiar" | Não | sempre | cab:239-242; pasta-show.js:438-468 |
| PZ-CAB-19 | ⋮ | Link externo do Push | ausente | Não existe | | | |
| PZ-CAB-20 | ⋮ | Imprimir resumo `bi-printer` | clique simples / Ctrl+clique | `<a target=_blank href=pasta_resumo_imprimir>`; clique simples é interceptado por `pasta-impressao.js` e abre a janela `#psImpressao` (PZ-MOD-06); Ctrl/Meta/Shift abre a página em nova aba | Não | sempre | cab:247-250; pasta-impressao.js:352-367 |
| PZ-CAB-21 | ⋮ | Acesso · Primário / Cadeado | ausente | Não existe | | | |
| PZ-CAB-S01 | ⋮ | Seção "Preferências" (rótulo) [SÓ-BLUEJUS no nome; o desenho não tem esse rótulo] | rótulo | | | sempre | cab:252-253 |
| PZ-CAB-22 | ⋮ | Acompanhar alterações | ausente | Não existe | | | |
| PZ-CAB-23 | ⋮ | Fixar nos favoritos `bi-star` + interruptor 30×17 | clique | `fetch POST pasta_favorito_alternar` (`_token` `pasta_favorito_<id>`); o interruptor espelha `dados.favorita` da resposta (não o clique) | Não. Erro: `avisarPs(..., 'erro')` | sempre | cab:257-264, 701-740 |
| PZ-CAB-24 | ⋮ | Arquivar pasta / Desarquivar pasta (`bi-archive` / `bi-box-arrow-up`) | clique | Mesmo handler da pílula de situação (PZ-CAB-48): `fetch POST pasta_alternar_situacao` | Não. Erro: `alert()` nativo | `podeEditarPasta` | cab:269-276; show:3909-3936 |
| PZ-CAB-25 | ⋮ | Excluir pasta `bi-trash3` (`.ps-pop-item--perigo`) | submit | Form POST `pasta_delete` (`_token` `delete_pasta_<id>`, `confirmar_nup`) | `window.prompt()` nativo: "Excluir a pasta N (cliente)? A exclusão fica registrada no histórico. Para confirmar, digite o número da pasta:"; número errado: `avisarPs('Número não confere. A pasta não foi excluída.')` | `podeExcluirPasta` | cab:285-295 |
| PZ-CAB-S02 | Linha 1 (lápide) | Botão "Restaurar" `bi-arrow-counterclockwise` [SÓ-BLUEJUS] | submit | Form POST `pasta_restaurar` (`_token` `restaurar_pasta_<id>`) | `cfPs` azul: "Restaurar a pasta N?" rótulo "Restaurar" | lápide e `podeExcluirPasta` | cab:156-171, 676-699 |
| PZ-CAB-S03 | Linha 1 (lápide) | Botão "Histórico" rotulado (no lugar do ⋮) [SÓ-BLUEJUS] | clique | Abre o drawer do histórico | Não | lápide | cab:172-176 |
| PZ-CAB-S04 | Faixa | Aviso "Pasta excluída em dd/mm/aaaa às HH:MM por NOME. Ela continua aqui porque o número N não pode ser reaproveitado, já existem pastas posteriores. Somente leitura até ser restaurada." [SÓ-BLUEJUS] (o texto original contém um travessão, legado) | nenhum | | | lápide | cab:302-315 |

#### A1.2 Linha 2 (cliente, movimentação, documento, IA, selos)

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-26 | Linha 2 | Ícone do cliente `bi-person-fill` (não é foto, não é botão) | nenhum | Sem menu de foto (Carregar/Ajustar/Remover não existem) | | sempre | cab:323 |
| PZ-CAB-N04 a N07 | Linha 2 | Menu e modal de foto do cliente | ausente | Não existem | | | |
| PZ-CAB-27 | Linha 2 | Nome do cliente `<h1 class="ps-cab-titulo">` (cliente principal; senão `pasta.nomeCliente`; senão "Cliente não informado") | botão direito | Abre a janela "Detalhes do cliente" no cursor (`cliente_resumo`), salvo se houver texto selecionado | Não | `data-cliente-id` só com cliente vinculado | cab:329-338; pasta-clientes.js:394-404 |
| PZ-CAB-28 | Linha 2 | Dica "Detalhes: clique com o botão direito" `bi-mouse2` | hover 350 ms no nome | CSS puro (`:has(:hover)`), some em `hover: none` | | com cliente | cab:360-362, 657-674 |
| PZ-CAB-29 | Linha 2 | "Movimentado hoje, HH:MM" / "Movimentado em dd/mm/aaaa" com ponto | nenhum | `title` "Última movimentação: …" (fonte: `pasta.modificadoEm`) | | com `modificadoEm` | cab:341-351 |
| PZ-CAB-30 | Linha 2 | Documento "CPF …"/"CNPJ …" (`documento_br_rotulo`); sem cliente vinculado: "não vinculado ao cadastro de clientes" | nenhum | | | | cab:353-357 |
| PZ-CAB-31 | Linha 2 | Botão BLUEJUS Intelligence (estrela SVG com gradiente `#1fa6cc`→`#0b5f86`) | clique | Abre o drawer `#psIaDrawer` (pasta-ia-agentes.js); `title` mostra a mensagem de indisponibilidade quando a IA não está disponível | Não | `can_access_module('inteligencia')` | cab:369-383 |
| PZ-CAB-32, 33, 37 | Linha 2 | Pasta trancada, faixa "só visualizar", selo do cadeado | ausente | Não existem (sem cadeado) | | | |
| PZ-CAB-34 | Faixa | Selo carimbado ARQUIVADO (3 ondas, aro, faixa `bi-archive-fill`) | troca de situação | Renderizado se arquivada; MutationObserver na classe da pílula insere/remove sem recarregar | | situação arquivado | cab:392-415, 742-779 |
| PZ-CAB-35 | Faixa | Selo ATIVO por 3,3 s | desarquivar | Inserido ao voltar de arquivado para ativo e removido por `setTimeout(3300)` | | | cab:404-412, 770-774 |
| PZ-CAB-36 | Faixa | Selos SUSPENSO / CANCELADO | ausente | A situação só tem Ativo e Arquivado | | | |
| PZ-CAB-41 | Faixa (P1) | Faixa Processo vinculado · Ação · Responsável (+ Cobrança) | | Preservada (P1) | | sempre | cab:413-513 |
| PZ-CAB-S06 | Faixa | Número CNJ do processo principal (link `processo_show`) + `bi-copy` copiar (`.copy-btn`, vira `bi-check-lg` 1,5 s) | clique | Copia `numeroProcesso` | Não | com processo | cab:418-422; show:2331-2352 |
| PZ-CAB-S07 | Faixa | Sub-linha classe · tribunal | nenhum | | | | cab:423-429 |
| PZ-CAB-42 | Faixa | Link "trocar" `bi-arrow-left-right` | clique | Vai à aba Processo (`data-ps-ir-aba="processo-tab"`) | Não | com processo | cab:432-434 |
| PZ-CAB-S08 | Faixa | Chip "N vinculado(s)" `bi-paperclip` + chevron [SÓ-BLUEJUS] | clique | Alterna `#psProcessosVinculados` (lista dos demais processos com link) | Não | com outros processos | cab:437-462; pasta-show.js:495-503 |
| PZ-CAB-S09 | Faixa | "Nenhum processo. vincular" | clique | Abre `#modalVincularProcesso` | Não | sem processo; botão só com `podeEditarPasta` | cab:464-468 |
| PZ-CAB-S10 | Faixa | Ação: `pasta.nomeAcao`; senão classe/assunto do processo com `title` "Extraído do processo vinculado"; senão "Não informada"/"Vincule um processo para definir" | nenhum | | | | cab:472-484 |
| PZ-CAB-S11 | Faixa | Bloco "Cobrança" `bi-cash-coin` com link à unidade cobrada e credor [SÓ-BLUEJUS] | clique | GET `cobranca_objeto_show` | Não | `unidadeCobrada` e `can_access_module('cobrancas')` | cab:501-512 |
| PZ-CAB-38 a 40, N08, N09 | Andamento | Trilha de fases | ausente (P3) | Não implementado (decisão P3) | | | |

#### A1.3 Linha 3 (abas, Cadastro, Situação)

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-CAB-43 | Linha 3 | Abas (controle segmentado com indicador deslizante): Dados da pasta `bi-folder2-open`, Metas `bi-check2-square`, Processo `bi-bank`, Financeiro `bi-cash-coin`, Detalhes `bi-info-circle`, Documentos `bi-paperclip`, Push Processual `bi-broadcast` | clique | Bootstrap `data-bs-toggle="tab"`; indicador reposiciona em `shown.bs.tab` e `resize`; modo compacto (só ícone + selo) quando não cabe; `#<aba>` na URL abre a aba na carga | Não | sempre | cab:529-563; pasta-show.js:106-159, 558-564 |
| PZ-CAB-44 | Linha 3 | Selo numérico: Metas = `pasta.tarefas|length`; Documentos = `pasta.documentos|length`; Push = não lidas | | Some com 0 | | | cab:532-537, 560 |
| PZ-CAB-45 | Linha 3 | Linha vermelha de pendência (`.ps-aba--pend` + `.ps-aba-pend`), texto da pendência no `title` | | Vem de `pendencias.porAba[aba]` do controller | | quando houver | cab:545-556 |
| PZ-CAB-46 | Linha 3 | Botão direito na aba (ocultar para equipe) | ausente | Não existe | | | |
| PZ-CAB-47 | Linha 3 | Botão "Cadastro" `bi-person-vcard` com ponto verde (completo) ou âmbar (pendente) | clique | **Abre a janela "Detalhes do cliente" (`cliente_resumo`), não um modal de cadastro**; `title` "Cadastro completo · abrir qualificação do cliente" ou "Cadastro incompleto: N pendência(s) · clique para completar" | Não | com cliente principal | cab:576-590; pasta-clientes.js:381-386 |
| PZ-CAB-48 | Linha 3 | Pílula de Situação (ponto + rótulo + chevron) e menu `#psSituacao` (tarja "Situação da pasta" `bi-toggles`; Ativo "Pasta em andamento"; Arquivado "Sai das listas de trabalho"; ✓ `bi-check2`) | clique na opção diferente da atual | `fetch POST pasta_alternar_situacao` (`_token` `pasta_alternar_situacao_<id>`); aplica `data.novaSituacao` na pílula, no ✓ e no item Arquivar/Desarquivar do ⋮; dispara o selo | Não. Erro: `alert()` nativo | `not lápide and podeEditarPasta`; senão pílula estática | cab:599-641; show:3869-3936 |
| PZ-CAB-N10 | Linha 3 | Efeitos colaterais da situação | | Só troca o estado; nada de prazos/metas automáticos na tela | | | |

#### A1.4 Painéis do cabeçalho

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MOD-20 | Histórico | Drawer `#psHistorico` "Histórico do sistema" + sub "N alterações registradas automaticamente" / "Nenhuma alteração registrada"; grupos por dia ("Hoje, dd/mm" ou dd/mm/aaaa), pip, autor, título, detalhe (com link `tarefa_show` quando é meta), hora; vazio "Nenhum evento registrado / O sistema anota aqui cada alteração da pasta." | ⋮ Histórico, H, `[data-ps-historico]` (Detalhes), "Histórico" do modal Editar | Abre/fecha (classe `is-aberto`), overlay fecha, X `×` fecha, Esc fecha; foco volta; arrastável pelo cabeçalho; dados vêm prontos no HTML (`eventosHistorico`) | Não | sempre | `_historico_drawer`; pasta-show.js:162-201 |
| PZ-CAB-S05 | Timeline | Painel `#psTimeline` "Timeline inteligente" (sub "Pasta N · cliente · CNJ"): busca (placeholder com exemplos), chips por categoria, período (Todo/Hoje/Ontem/Esta semana/Este mês), pessoa, "Resumir atividade", lista, "Carregar mais", rodapé "Montada por regras fixas a partir do que o sistema registrou." | ⋮ Timeline, T | Abre: `POST pasta_timeline_visita` (CSRF) e depois `GET pasta_timeline?…` (JSON); caixas "Enquanto você estava fora: N acontecimento(s)" + "Ver resumo", "Resumo da atividade", "⚠ Precisa de atenção"; cartão com hora, título, selo de prioridade, "Marco · …", "editado", texto cortado em 160 com "Ver tudo/Mostrar menos", "Ver alteração/Ocultar alteração" (Antes/Depois/Alterado em), link "Abrir" (link para aba desta pasta troca a aba sem recarregar) | Não | sempre | `_timeline_inteligente`; pasta-timeline.js |
| PZ-MOD-06 | Impressão | Janela `#psImpressao` "Imprimir": palco com a folha (fetch `pasta_resumo_imprimir?secao=`), "Incluir na folha" (Cabeçalho do escritório, Dados da pasta e do processo, Autor/data/tipo do registro, Financeiro só se a folha tiver), "Tamanho do texto" − 11,5 pt +, nota "Papel A4…", Imprimir (iframe), Baixar PDF (`pasta_resumo_pdf`), Copiar texto | ⋮ Imprimir resumo; "Imprimir" do ⋮ da observação; `PastaImpressao.extrato()` (Financeiro) | Esc fecha (captura), clique no fundo fecha | Não | sempre | `_impressao_modal`; pasta-impressao.js |
| PZ-MOD-10 | Global | Busca rápida Ctrl+K | ausente na pasta_show | Não há handler de Ctrl+K nos JS da página | | | |

#### A1.5 Janela de confirmação, aviso e utilitários globais

| ID | Área | Elemento | Gatilho | Comportamento real | Referência |
|---|---|---|---|---|---|
| PZ-MOD-09 | Global | Janela `#psConfirmar` (`role=alertdialog`), 420px, cabeçalho `#0b6a96`, texto 13,5px, rodapé com Cancelar e OK | `confirmarPs(opções)` / `confirmarUIPs(msg, perigo, foco)` | Promise; Esc e clique no fundo cancelam; Enter confirma (salvo com foco no Cancelar); Tab circula entre botões; foco inicial no Cancelar; com modal Bootstrap aberto a janela é hospedada dentro dele; ao fechar devolve o foco ao gatilho ou ao botão que abre o menu de onde veio | `_partials/_janela_confirmacao`; pasta-dialogos.js:262-389 |
| PZ-MOD-25 | Global | Variante azul (`#0a6fa0`, `bi-check2`) e vermelha (`#b3262e`, `bi-trash3`) | `variante: 'azul'` | | `_janela_confirmacao` |
| PZ-MOD-S01 | Global | Moldura "alerta" (faixa 5px `#c0392b`, círculo 64px ⚠, título 17px centralizado) | `moldura: 'alerta'` | Existe no componente; nenhum uso dentro da pasta_show encontrado | `_janela_confirmacao` |
| PZ-MOD-26 | Global | "Nenhum `confirm()` nativo" | | **Não cumprido** na pasta_show: `prompt()` no Excluir pasta; `alert()` em prioridade, situação, responsável, desvincular cliente, principal, processo principal, desvincular processo; `confirm()` como recuo quando a janela não existe | ver tabela "Confirmações e alertas nativos" |
| PZ-GLB-12 | Global | Aviso `#psAviso` (fundo `#12242f`, ✓ `#5fd39a`, erro `#ff8a80`, 13px, rodapé central, 2,6 s), com regiões vivas `status`/`alert` | `avisarPs(msg, 'erro'?)` | | show:4363-4376; pasta-dialogos.js:420-466 |
| PZ-GLB-13 | Global | X automático em todo popover `.ps-pop` e nos menus `#psRegMenu`, `.ps-obs-menu`, `#pastaRespMenu` | abertura | X dispara Esc sintético | pasta-show.js:261-343 |
| PZ-GLB-14 | Global | Arraste (`PsArrastar`) de menus com tarja, menus sem tarja pela 1ª seção, `#psRegMenu`, `.ps-obs-menu`, `#pastaRespMenu`, drawer, Timeline, modal Editar dados, janela Detalhes do cliente, janela Nova meta | pointerdown | Desloca por `translate` (ou `posicionar`); clique após arrastar é engolido; volta à âncora ao reabrir | pasta-show.js:27-86, 349-395 |
| PZ-CAB-N11 | Global | Menu de contexto compartilhado `gm` | não existe como componente único | Cada área tem o próprio menu (registro, observação, processo, nota, metas, financeiro, explorador, push) | |

#### A1.6 Modal "Editar dados da pasta" (`#modalEditarPasta`, PZ-MOD-15/16)

| ID | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|
| PZ-MOD-15 | Cabeçalho: ícone `bi-folder2-open`, "Pasta N", sub "CLIENTE · CPF …" (nome vindo do identificador), pílula de estado "Dados da pasta" / "Alterações não salvas", X | abertura | Cabeçalho arrastável; o modal volta ao centro ao fechar | | aberto pelo ⋮ (edit) | show:3586-3608 |
| PZ-MOD-N01 | Abas internas: Dados da pasta (ativa), Cliente, Processo, Histórico | clique | São atalhos: fecham o modal e depois abrem o destino (Cadastro do cabeçalho; aba Processo; drawer Histórico) | Com alteração não salva: `cfPs` vermelho "Descartar as alterações?" / "As alterações não salvas dos dados da pasta serão perdidas." rótulo "Descartar" `bi-x-circle` | | show:3609-3614, 3801-3865 |
| PZ-MOD-N02 | Número da pasta * (`nup`, required) | input | Erro "Informe o número da pasta." no bloco Pendências | Trocar o número pede `cfUI` azul "Alterar número da pasta / Alterar o número da pasta de A para B? Links e referências antigas passam a apontar para o novo número." (via `psConfirmarEnvio`) | | show:3619-3625, 3776-3799 |
| PZ-MOD-N03 | Nome do cliente (`nome_cliente`, não obrigatório). Se o identificador vem da cobrança: texto só leitura "Montado a partir do credor da carteira e do responsável cobrado…" [SÓ-BLUEJUS]. Chips dos clientes cadastrados + nota "Para alterá-los, use a seção de Clientes na aba Dados." | | | | | show:3633-3663 |
| PZ-MOD-16 | Tipo CPF/CNPJ segmentado + documento | ausente | O modal não edita documento | | | |
| PZ-MOD-N04 | Responsável (só leitura, nome sincronizado do chip ao abrir) + "Trocar" | clique | Fecha e abre o menu do responsável | idem descarte | Trocar só com edit e fora da lápide | show:3674-3682, 3821-3832 |
| PZ-MOD-N05 | Ação | input | **Campo editável** `nome_acao` (o desenho tem só leitura "vem do menu Processo") | | | show:3684-3689 |
| PZ-MOD-S02 | Situação (select Ativo/Arquivado) [SÓ-BLUEJUS] | change | Vai no POST | | | show:3691-3697 |
| PZ-MOD-N06 | Faixa "Pendências" `bi-x-circle-fill` | input | Só aparece com alteração e erro | | | show:3703-3706 |
| PZ-MOD-N07 | Rodapé: Fechar, "Cadastro do cliente" `bi-person-vcard`, Salvar `bi-check2` (desabilitado sem alteração ou com erro; `title` "Nenhuma alteração para salvar") | submit | Form POST `pasta_edit` (`_token` `edit_pasta_<id>`), recarga completa | ver N02 | | show:3709-3715, 3753-3774 |

#### A1.7 Modal Marcadores (`#modalMoverPara`, PZ-MOD-11)

| ID | Elemento | Comportamento real | Referência |
|---|---|---|---|
| PZ-MOD-11 | Modal 440px "Marcadores da pasta" `bi-tags`; nome da pasta; busca "Buscar marcador…" com limpar; árvore de marcadores do escritório (pai/filho, checkbox); vazio "Nenhum marcador criado ainda."; contador; Cancelar; Salvar | Salvar: `fetch POST expediente_pasta_marcadores` (`marcadores[]`, `_token` `pasta_marcadores_<id>`); erro no próprio modal. **Sem callback na pasta_show** (`onSucessoCallback` não é passado): as etiquetas do cabeçalho só se atualizam ao recarregar | `_partials/modal_mover_marcador`; show:3982-3986 |

#### A1.8 Modal Vincular processo (`#modalVincularProcesso`, PZ-MOD-08)

| ID | Elemento | Comportamento real | Referência |
|---|---|---|---|
| PZ-MOD-08 | Modo segmentado "Buscar processo existente" / "Cadastrar novo (CNJ)" | Existente: Select2 AJAX `GET pasta_buscar_processos`. Novo: abas internas Informações / Partes / Movimentações; botão DataJud (`POST api_datajud_search`) preenche os campos; tribunal detectado pelo número (só leitura); Órgão, Classe, Assunto, Situação, Instância obrigatórios; "+ parte"/"+ movimentação" com remover. Submit: `fetch POST pasta_vincular_processo`, resposta troca a aba Processo in-place (`mpSwapProcessos`) | show:1696-1906, 3173-3534 |
| PZ-MOD-S03 | Vincular apensado | ausente (o desenho tem "Vincular apensado") | |

#### A1.9 Modal Adicionar cliente (`#modalAdicionarCliente`) [SÓ-BLUEJUS na forma]

| ID | Elemento | Comportamento real | Referência |
|---|---|---|---|
| PZ-MOD-17 (≈) | Modal 560px "Adicionar Cliente": passo 1 escolhe Pessoa Física / Pessoa Jurídica ou busca "Buscar por nome, CPF ou CNPJ…" (chips de selecionados, vínculo múltiplo "Vincular Cliente"); PF com abas Dados Pessoais / Contato / Endereço; PJ com Dados da Empresa / Representante Legal / Contato; `fieldset disabled` alterna o bloco ativo | Novo: `fetch POST pasta_cliente_novo` (`_token` `pasta_cliente_novo_<id>`); busca: `GET pasta_clientes_buscar?q=`; vincular: `POST pasta_cliente_vincular`. **Não é o modal "Cadastro e qualificação" do desenho**: não tem abas Réu, Documentos (OCR), Qualificação, Histórico, Pendências, alias de CPF, Ctrl+S | show:552-1694 |
| PZ-MOD-N13 a N33 | Cadastro e qualificação do desenho (Réu, OCR, Inteligência cadastral, texto de qualificação, pendências, CPF duplicado, Copiar qualificação) | Ausentes como modal. Equivalentes parciais: "Copiar qualificação" e "Editar cadastro" (link `cliente_edit`) na janela Detalhes do cliente; `data-editor-qualificacao` (texto de qualificação servido para o editor) | show:4382-4390; `cliente/_resumo` |

#### A1.10 Janela "Detalhes do cliente" (`cliente/_resumo.html.twig`, PZ-MOD-18/19)

| ID | Elemento | Gatilho | Comportamento real | Referência |
|---|---|---|---|---|
| PZ-MOD-18 | Janela 320px "Detalhes do cliente" (avatar, nome), arrastável pelo cabeçalho, overlay (clique ou botão direito fecha), X, Esc | Botão Cadastro, ícone de cadastro da linha do cliente, botão direito no nome do cabeçalho ou na linha do cliente | `GET cliente_resumo?pasta=` (HTML); posiciona dentro da tela | pasta-clientes.js:145-220 |
| PZ-MOD-18 | Campos Nome e Documento com copiar (`js-ps-copiar`); números (pastas, ativas, com processo); "Pastas com o mesmo CPF/CNPJ" (atual marcada, demais com link); "Cliente desde dd/mm/aaaa" | clique copiar | Copia e mostra aviso curto | `cliente/_resumo` |
| PZ-MOD-19 | Contatos: copiar, lápis editar (Enter salva, Esc cancela), remover, "+ Telefone", "+ E-mail"; vazio "Nenhum contato informado" | | `POST cliente_contatos` (`campo`, `valor`, `_token`, `pasta`); a resposta é a janela inteira, trocada no lugar; e-mail não pode ficar vazio; sessão expirada detectada | pasta-clientes.js:222-366 |
| PZ-MOD-S04 | "Copiar qualificação" e "Editar cadastro" (link `cliente_edit`) [SÓ-BLUEJUS na janela] | | | `cliente/_resumo` |

---

### A3. Aba "Dados da pasta" e trilho

#### A3.1 Registro dos expedientes (`_dados_anotacoes.html.twig` + script inline `show:2380-3131`)

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-DAD-01 | Registro | "Registro dos expedientes" + contador `#timeline-count` (dica "Registros principais (respostas não contam)") | | Contador atualizado no envio/exclusão | | sempre | anot:106-110 |
| PZ-DAD-02 | Compositor | Editor rico (Quill, `data-editor-rico`) placeholder "Adicionar…", nota "Visível para a equipe", Enviar (`title` "Enviar (Ctrl+Enter)"), @menções | submit / Ctrl+Enter | `fetch POST pasta_enviar_mensagem` (`_token` `pasta_mensagem_<id>`, `conteudo` com tokens de menção); insere o cartão sem recarregar; erro em `#timelineMensagemErro` | Não | não lápide; na lápide: "Pasta excluída: o registro fica só para leitura." `bi-lock` | anot:117-144; show:2927-2990 |
| PZ-DAD-03 | Lista | Pílula do dia ("26 ago 2026"), linha vertical, ponto, seta | | 4 primeiros visíveis (`visiveis = 4`) | | | anot:149-187 |
| PZ-DAD-04 | Cartão | Avatar 24px (foto ou iniciais), autor, hora (`dd/mm · HH:MM` se resposta noutro dia), megafone `bi-megaphone-fill`, corpo rico (`registro_texto`) | | | | | anot:248-327 |
| PZ-DAD-05 | Cartão | Presença do autor | ausente | | | | |
| PZ-DAD-06 | Cartão | Etiqueta (Combinado, Ligação…) | ausente | | | | |
| PZ-DAD-07 | Cartão | "editado" com dica "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM" | | Atualizada no salvar | | | anot:264-267 |
| PZ-DAD-08 | Cartão | Lápis "Editável por mais N minutos" | clique | Abre edição inline com editor rico (menções ligadas); Salvar: `fetch POST pasta_editar_mensagem` (`_token` `pasta_mensagem_editar_<msgId>`); Cancelar | Não | autor e dentro da janela (`comentario_editavel`), fora da lápide | anot:269-293; show:2715-2830 |
| PZ-DAD-S01 | Cartão | Lixeira oculta `btn-excluir-msg-pasta` (`hidden`; acionada pelo menu) | menu Excluir | `fetch POST pasta_excluir_mensagem` (`_token` `pasta_mensagem_excluir_<msgId>`); remove; respostas ficam e viram "Resposta a uma mensagem excluída" | `cfPs` vermelho: "Excluir o comentário de NOME?" + "data · \"trecho (140)\". Ele some para toda a equipe e a exclusão fica registrada no histórico." | idem lápis | anot:284-291; show:2396-2407, 2836-2880 |
| PZ-DAD-09 | Cartão | Responder `bi-reply-fill` | clique | Abre caixa sob o cartão raiz (um nível) | | não lápide | anot:294-306 |
| PZ-DAD-10 | Cartão | Encaminhar via Chat I.A | ausente | | | | |
| PZ-DAD-11 | Cartão | Caixa de resposta: faixa "Respondendo a NOME", campo "Escreva a resposta…", Cancelar, Responder (desabilitado vazio); Ctrl+Enter envia, Esc fecha | | `POST pasta_enviar_mensagem` com `resposta_a` = raiz | | | show:2409-2560 |
| PZ-DAD-12 | Cartão | Resposta recuada com "Resposta a NOME" `bi-reply-fill` | | | | | anot:252-258 |
| PZ-DAD-13 | Cartão | Destaque de cor | ausente | | | | |
| PZ-DAD-14 | Registro | Cartões de sistema (andamento) | ausente (P3) | Os eventos do sistema vão para o drawer Histórico | | | |
| PZ-DAD-15 | Registro | "Ver anotações anteriores (N)" | clique | Mostra `.ps-anotacao--extra` e remove o botão; `#pasta-msg-N` na URL abre e rola até o cartão | | com mais de 4 | anot:189-193; pasta-show.js:409-419, 572-602 |
| PZ-DAD-16 / PZ-CTX-05 | Menu `#psRegMenu` (250px) | ⋮ "Mais ações do registro" ou botão direito no cartão (salvo em link/botão/edição/seleção): título (autor), Responder, Copiar texto ("Texto copiado" 1,2 s), Editar · N min / Editar · bloqueado (`bi-lock` + motivo), Excluir comentário · N min / Excluir · bloqueado | clique / contextmenu | Itens delegam aos botões do cartão; fecha em clique fora, Esc (devolve foco), scroll, resize | ver PZ-DAD-S01 | Responder/Editar/Excluir somem na lápide | anot:198-217; show:2600-2705 |
| PZ-DAD-17 | Registro | @menção com notificação (`pasta-mencoes.js`: "@" abre lista, ↑/↓, Enter/Tab, Esc; `GET pasta_mencionaveis?q=`; envia `@[Nome](user:ID)`) | | Servidor notifica | | | pasta-mencoes.js |
| PZ-EDT | Editor rico (todas as áreas) | Barra: Desfazer, Refazer, Negrito, Itálico, Sublinhado, Tachado, Citação, Inserir símbolo, Inserir data de hoje, Revisar texto, Localizar e substituir (Ctrl+H), Limpar formatação, Cor da fonte (9), Realce (9), Estilo do parágrafo, Alinhamento, Numeração, Marcadores, recuo (Tab/Shift+Tab); botão direito no editor: Negrito, Itálico, Sublinhado, Tachado, Realçar com a última cor, Cor da fonte, Marcadores, Numeração, Título, Limpar formatação, Sugestões de correção, Recortar, Copiar, Colar, Colar somente texto (Ctrl+Shift+V), Parágrafo; correção com Corrigir/Ignorar/Desfazer; Ctrl+Enter envia; Esc fecha janelinhas | | | | | editor-rico.js (2.377 linhas, compartilhado) |

#### A3.2 Trilho (`_dados_trilho.html.twig`)

Ordem real: Próximos prazos, Clientes, Documentos. Os cartões "Ação" e "Processo vinculado" (PZ-TRI-01/02) **não existem no trilho**: a informação mora na faixa do cabeçalho (P1).

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-TRI-01, 02 | Trilho | Cartões Ação e Processo vinculado | ausentes | Ver faixa (P1) | | | |
| PZ-TRI-03 | Prazos | "Próximos prazos" + contagem + chevron "Abrir as metas" | clique | Vai à aba Metas | | sempre | trilho:22-31 |
| PZ-TRI-N01 | Prazos | "+" Adicionar prazo ou audiência | ausente | | | | |
| PZ-TRI-N02 | Prazos | Vazio "Nenhum prazo aberto / Metas com prazo aparecem aqui, da mais próxima para a mais distante." | | Texto diferente do desenho | | | trilho:32-37 |
| PZ-TRI-04 | Prazos | Linha: pip por tom, título (link `tarefa_show`), meta (`prazo.meta`), selo (`prazo.selo`), ⋮ | clique no título | Abre a meta em `tarefa_show` | | | trilho:57-72 |
| PZ-TRI-N03 | Prazos | Selos e tons | | Calculados no servidor (`proximosPrazos`: `tom`, `selo`, `meta`) | | | |
| PZ-TRI-N04 a N06 | Prazos | Origens manual/Push, leitor de datas, publicação | ausentes | Só metas da pasta com prazo | | | |
| PZ-TRI-N07 a N09 | ⋮ prazo | Cumprido, Editar, Excluir (manual) | ausentes | | | | |
| PZ-TRI-05 | ⋮ prazo | Título do prazo (seção) + "Ver nas metas" `bi-check2-square` | clique | Vai à aba Metas | | | trilho:77-81 |
| PZ-TRI-06 | ⋮ prazo | Encaminhar via Chat I.A | ausente | | | | |
| PZ-TRI-07 | ⋮ prazo | "Alertar PRIMEIRONOME" `bi-bell` (um por responsável da meta, exceto o próprio usuário) | submit | Form POST `tarefa_alertar` (`_token` `alertar_tarefa_<id>`, `usuario`), recarga | Não | `can_access_module('tarefas') and podeEditarPasta and not lápide` | trilho:82-90 |
| PZ-TRI-08 | ⋮ prazo | Adicionar à agenda (.ics) `bi-calendar-plus` | clique | Gera `.ics` no navegador (`prazo-<pasta>.ics`) com uid, dia, título, responsável | | com prazo datado | trilho:92-101; pasta-clientes.js:95-143 |
| PZ-TRI-09 | ⋮ prazo | Copiar `bi-clipboard` ("Prazo copiado") | clique | Copia "título · selo · meta · Pasta N" | | | trilho:102-106 |
| PZ-CTX-03 | Prazos | Botão direito na linha do prazo | contextmenu | Abre o mesmo ⋮ | | | pasta-clientes.js:415-422 |
| PZ-TRI-N10 a N14 | Modal prazo | "Novo prazo ou audiência" | ausente | | | | |
| PZ-TRI-N15 | Clientes | "Clientes" + contagem (servidor) | | | | | trilho:142-149 |
| PZ-TRI-10 | Clientes | "+" Vincular cliente → painel inline `#psVincCliente`: busca "Nome ou CPF do cliente", resultados (nome, documento mascarado, selo Completo/Incompleto/Já vinculado), vazio "Cliente ainda não cadastrado." + "Cadastrar cliente" (abre `#modalAdicionarCliente` com o termo preenchido), erro | clique, Esc, Enter | `GET pasta_clientes_buscar`; clicar: `POST pasta_cliente_vincular`; a linha volta pronta (`html`) e entra sem recarregar (evento `pasta:cliente-vinculado`) | Não | `podeEditarPasta and not lápide` | trilho:151-176; pasta-clientes-busca.js |
| PZ-TRI-11 | Clientes | Linha: iniciais (`.cliente-avatar`), nome, ícone de cadastro `bi-person-vcard` (completo/pendente, dica com a lista de pendências) que abre Detalhes, selo "Principal", documento | clique no ícone / botão direito na linha | Abre a janela Detalhes do cliente | | | `_cliente_linha`; pasta-clientes.js:381-412 |
| PZ-TRI-12 | Clientes | Selo "CPF duplicado" | ausente | | | | |
| PZ-TRI-13 | Clientes | Estrela: principal = `bi-star-fill` fixa; demais = form "Tornar cliente principal (a Média por CPF passa a ser dele)" | submit | `fetch POST pasta_cliente_principal`; troca estrelas e a Média por CPF do Financeiro sem recarregar | Não. Erro: `alert()` nativo | troca só com edit fora da lápide | `_cliente_linha`; show:1370-1475, 1525-1553 |
| PZ-TRI-13 | Clientes | Lixeira "Desvincular cliente da pasta" `bi-trash` | submit | `fetch POST pasta_cliente_desvincular`; remove a linha | **Nenhuma confirmação** (o form não tem `data-ps-cf` e o handler não pergunta). Erro: `alert()` | edit fora da lápide | `_cliente_linha`; show:1483-1524 |
| PZ-TRI-S01 | Clientes | Pasta "Abrir ficha do cliente (documentos e anexos ficam lá)" `bi-folder-fill`/`bi-folder2-open` [SÓ-BLUEJUS] | clique | GET `cliente_show` | | sempre | `_cliente_linha` |
| PZ-TRI-S02 | Clientes | Placeholder: nome livre com selo "não vinculado"; vazio "Nenhum cliente vinculado / Vincule o cliente para calcular a média por CPF." | | | | | trilho:206-221 |
| PZ-TRI-14 | Documentos | "Documentos" + contagem real + chevron "Abrir todos os documentos"; 3 mais recentes (ícone por tipo, título, tamanho · data) | clique | Abre `#previewDocModal` (visualizador PDF/imagem/áudio/vídeo/DOCX/planilha/texto) com Baixar; sem JS, `pasta_documento_view` em nova aba | | | trilho:229-268; show:1907-1930 |

---

### A4. Aba Metas (`_metas.html.twig`, `pasta-metas.js`, modal `#modalCriarTarefa` em `show:3989-4340`, `pasta-dialogos.js`)

Variáveis: `podeConcluir` = `can_access_module('tarefas')`; `metaEditavel` = `podeConcluir and not lápide`;
`podeAlertar` = `metaEditavel and podeEditarPasta`.

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-MET-01 | Metas | Título "Metas da pasta" | | | | | metas:101 |
| PZ-MET-02 | Metas | Filtros Abertas · Atrasadas · Concluídas · Todas com contagem (servidor) | clique | Filtra no navegador por `data-meta-estado`; vazio do filtro "Nenhuma meta neste filtro / Troque o filtro acima ou crie uma nova meta." | | sempre | metas:102-114, 288-292; pasta-metas.js:63-100 |
| PZ-MET-03 | Metas | "Nova meta" `bi-plus-lg` (primário) | clique | Abre `#modalCriarTarefa` | | não lápide | metas:118-123 |
| PZ-MET-04 | Linha | Cartão: ✓ `bi-check-circle-fill` se concluída, nº local "N.", título, autor `bi-person`, responsáveis `bi-people`, prazo ("N dias em atraso", "vence hoje", "vence dd/mm/aaaa", ou prazo da concluída), à direita status (Concluída/Atrasada/Pendente/Para Revisão) e datas ("dd/mm/aaaa · mod. dd/mm/aaaa") | clique | A linha é `<a href=tarefa_show>`; com JS abre o drawer "Relatório da meta" | | | metas:136-181 |
| PZ-MET-05 | Linha | Nº global "Nº 0042" | ausente | Só o número local com `title` "Meta N desta pasta" | | | metas:147 |
| PZ-MET-06 | Linha | Editar nome inline (form `tarefa_renomear`, campo com `maxlength` = `RenomearMetaUseCase::TAMANHO_MAXIMO`, Salvar, Cancelar; Enter salva, Esc cancela) | ⋮ Editar nome | Form POST `tarefa_renomear` com recarga (espaço puro barrado no navegador) | Não | `metaEditavel` | metas:275-283; pasta-metas.js:282-363 |
| PZ-MET-07 | Linha | Sino "Alertar para verificar" (`bi-bell`; alertado: `bi-bell-fill` + `is-alertado`, recente `is-recente`; `title` com o último alerta) | clique | Abre popover `#psMetaSino<id>` | | `podeAlertar`, não concluída, há responsável diferente do usuário | metas:187-212 |
| PZ-MET-08 | Menu do sino | Seção "Alertar para verificar" + "NOME · responsável" | submit | Form POST `tarefa_alertar` (`usuario`), recarga | Não | idem | metas:200-209 |
| PZ-MET-09 | Menu do sino | Demais usuários | ausente | Só os responsáveis | | | |
| PZ-MET-10 | ⋮ meta | Abrir relatório `bi-file-earmark-text` | clique | Abre o drawer | | `podeConcluir` | metas:219-221 |
| PZ-MET-11 | ⋮ meta | Marcar como concluída `bi-check2-circle` / Reabrir meta `bi-arrow-counterclockwise` | submit | XHR POST `tarefa_concluir`/`tarefa_reabrir` (Accept JSON); o servidor devolve a aba renderizada e os pedaços são trocados; aviso "Meta concluída"/"Meta reaberta" + "Desfazer" por 4,2 s (desfazer do reabrir manda `desfazer=1`) | Não | `metaEditavel` | metas:222-238; pasta-metas.js:540-790 |
| PZ-MET-12 | ⋮ meta | Editar nome `bi-pencil` | clique | PZ-MET-06 | | `metaEditavel` | metas:239-243 |
| PZ-MET-13 | ⋮ meta | Duplicar | ausente | | | | |
| PZ-MET-14 | ⋮ meta | Encaminhar via Chat I.A | ausente | | | | |
| PZ-MET-15 | ⋮ meta | Copiar link `bi-link-45deg` ("Link da meta copiado") | clique | Copia `url('tarefa_show')` | | | metas:244-248 |
| PZ-MET-16 | ⋮ meta | Abrir em Metas `bi-box-arrow-up-right` | clique | GET `tarefa_show` | | | metas:253-255 |
| PZ-MET-17 | ⋮ meta | Excluir `bi-trash3` (perigo) | submit | Form POST `tarefa_excluir`, recarga | `data-ps-cf` + `data-ps-cf-perigo`: "Excluir esta meta? Esta ação não pode ser desfeita." (fallback `confirm()`) | não lápide e `meta_pode_excluir(tarefa)` | metas:256-268 |
| PZ-CTX-06 | Linha | Botão direito na linha abre o mesmo ⋮ no cursor | contextmenu | | | `podeConcluir` | pasta-metas.js:791-845 |
| PZ-MET-18 | Metas | Aviso com "Desfazer" | | Só concluir/reabrir (não duplicar/excluir) | | | pasta-metas.js:556-600 |
| PZ-MET-19 | Drawer | "Relatório da meta" `bi-clipboard2-check`: posição, ▲ ▼ (anterior/próxima), × (Esc, fundo); situação, título, prazo (atraso em vermelho), campos (`rel.campos`), "Histórico" (eventos com tom), "Outras metas desta pasta" (montado das linhas), rodapé Marcar como concluída/Reabrir (XHR) e "Abrir em Metas" | clique na linha / ⋮ | Conteúdo vem pronto do servidor | | com metas | metas:361-432; pasta-metas.js:365-538 |
| PZ-MET-20 | Metas | Vazio geral "Nenhuma meta criada para esta pasta / Crie a primeira pelo botão acima." | | | | | metas:293-299 |
| PZ-MET-21 | Trilho | Andamento das metas: "C/T concluídas", barra (`role=progressbar`), legenda concluídas/pendentes/atrasadas | | Servidor (`metasResumo`) | | | metas:304-318 |
| PZ-MET-22 | Trilho | Precisa de atenção (metas atrasadas: título com link, "prazo X · N dias em atraso · responsáveis") + nota "Metas atrasadas continuam contando dias até serem concluídas." / "Nenhuma meta atrasada nesta pasta." | | | | | metas:320-338 |
| PZ-MET-23 | Trilho | Responsáveis nas metas (avatar 27, nome, contagem) **reais** (o desenho tem fixos) | | | | | metas:340-353 |
| PZ-MET-24 | Trilho | Botão de chat por pessoa | ausente | | | | |
| PZ-MET-25 | Metas | Meta nova realçada (`?meta=<id>` na URL; `&acao=renomear` abre a edição do nome) | carga | Abre a aba, filtro Abertas, realça | | | pasta-metas.js:846-882 |
| PZ-MET-S01 | Metas | Bloco `<style>` próprio do aviso `.ps-metas-aviso` (tema escuro com `--ps-metas-aviso-acao: #0a7aad`) | | | | | metas:46-80 |
| PZ-MOD-01 | Nova meta | Janela não modal (`data-bs-backdrop=false`, `data-bs-focus=false`, página continua rolando), cabeçalho com alça `bi-grip-vertical`, `bi-list-check`, "Nova meta", "Pasta N", Recolher `bi-dash-lg` (também duplo clique na faixa), Fechar (Esc) | arraste, duplo clique | Arrasta mantendo 48px visíveis; fechar desfaz recolher e posição | | | show:4142-4160; pasta-metas.js:890-980 |
| PZ-MOD-02 | Nova meta | Título * (255), Descrição * (5000, contador "N/5000"), Prazo com calendário próprio (D S T Q Q S S, fim de semana em cinza, dias passados desabilitados, Hoje, Limpar, setas de mês, Esc), atalhos Hoje · Amanhã · +5 úteis · +15 úteis, texto "Escolha a data limite." / dia escolhido | | Só preenchem campos | | | show:4168-4227; pasta-dialogos.js:152-261; pasta-metas.js:105-185 |
| PZ-MOD-S05 | Nova meta | Aviso de título parecido "…" + "Abrir meta existente" [SÓ-BLUEJUS] | input | Alerta, não bloqueia; o botão fecha e realça a meta na lista | | | show:4228-4232; pasta-metas.js:188-280 |
| PZ-MOD-03 | Nova meta | Análise da I.A. do prazo | ausente | | | | |
| PZ-MOD-04 | Nova meta | Obrigatórios: só título e descrição; "Criar meta" `bi-send` desabilitado com "Preencha: título, descrição" na dica | | Prazo e responsáveis são opcionais (D-META pendente) | | | pasta-metas.js:883-916 |
| PZ-MOD-S06 | Nova meta | Responsáveis: campo com chips (avatar 22px, remover), lista com busca "Filtrar…", opções com avatar 30 e nome em CAIXA ALTA, ✓, "Ninguém encontrado", "Concluir"; Enter escolhe o primeiro; Esc fecha | | Espelha um `<select multiple name=responsaveis[]>` oculto | | | show:4244-4282; pasta-dialogos.js:24-150 |
| PZ-MOD-05 | Nova meta | Envio e notificação | submit | `fetch POST tarefa_criar_para_pasta` (`_token` `tarefa_criar_<id>`); sucesso: `location.href = destino` (volta à pasta, aba Metas, meta realçada); erro em `#tarefaErroMsg`; botão "Enviando…" | Não | | show:4297-4340 |

---

### A5. Aba Processo (`_processos_vinculados.html.twig`, `pasta-processo.js`, `nota-tecnica.js`, `processo/_notas_tecnicas`, `processo/_nota_tecnica_item`)

`podeMexerNoVinculo` = `podeEditarPasta and not lápide`.

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-PRO-01 | Cabeçalho | "Processos vinculados" + contagem | | | | | proc:68-70 |
| PZ-PRO-02 | Cabeçalho | Interruptor "Administrativo sem processo" (`role=switch`) | submit | XHR POST `pasta_administrativa_definir` (`administrativa` 0/1); otimista; resposta troca a aba (`mpSwapProcessos`); falha de rede desfaz com `alert()` | Com processo vinculado e ligando: `cfUI` azul "Marcar como administrativo / A Pasta N tem o processo X vinculado. Marcar mesmo assim como "Administrativo sem processo"?" | `podeMexerNoVinculo`; senão interruptor desabilitado só leitura | proc:73-100; pasta-processo.js:280-370 |
| PZ-PRO-03 | Cabeçalho | ⋮ do cabeçalho (também botão direito sobre o ⋮) | clique / contextmenu | Abre `#psProcHdMenu<id>` (título "Processos · Pasta N") | | `podeMexerNoVinculo` | proc:107-130; pasta-processo.js:235-250 |
| PZ-PRO-04 | ⋮ cabeçalho | Relatório geral de processos | ausente | | | | |
| PZ-PRO-05 | ⋮ cabeçalho | Enviar à Controladoria agora | ausente | | | | |
| PZ-PRO-06 | ⋮ cabeçalho | Marcar / Desmarcar como administrativo sem processo `bi-briefcase` | clique | Submete o form do interruptor (`form=psAdmForm<id>`) | idem PZ-PRO-02 | | proc:126-128 |
| PZ-PRO-07 | Cabeçalho | Vincular processo `bi-link-45deg` (suave) | clique | Abre `#modalVincularProcesso` | | `podeMexerNoVinculo` | proc:112-115 |
| PZ-PRO-08 | Cabeçalho | Vincular apensado | ausente | | | | |
| PZ-PRO-09 | Cabeçalho | Peticionar na pasta `bi-file-earmark-text` (primário, caixa alta) | clique | GET `pasta_peticionar` em nova aba | | sempre (sem condição) | proc:117-121 |
| PZ-PRO-10 | Cartão | Pílula "Vinculado em dd/mm/aaaa" / "Processo vinculado"; nº CNJ (link `processo_show`); copiar `bi-copy` ("copy-btn"); selo "Principal" `bi-star-fill`; `title` do cartão "Clique com o botão direito para mais opções" | clique | | | | proc:168-194 |
| PZ-PRO-11 | Cartão | Selo do apenso | ausente | | | | |
| PZ-PRO-12 | Cartão | Classe, Assunto (se houver), Tribunal, Situação (ponto verde se contém "ANDAMENTO"; filtro `situacao_processo`) | | "Não informada/o" em `.ps-dado--vazio` | | | proc:281-305 |
| PZ-PRO-13 | Cartão | Órgão julgador, Valor da causa (**da pasta**, `pasta.valorCausa`), Distribuição, Instância (se houver) | | | | | proc:310-329 |
| PZ-PRO-14, 15 | Cartão | Marcas (justiça gratuita etc.) e linha de fonte | ausentes | O detalhe mostra "Última atualização no tribunal" | | | |
| PZ-PRO-16 | Cartão | Edição inline | ausente | "Editar dados do processo" é link para `processo_edit` | | | |
| PZ-PRO-17 | Cartão | "Ver todas as informações" / chevron (`js-proc-alternar`) | clique | Alterna `#psProcDetalhes<id>`: Classe judicial (com código), Assunto, Órgão, Distribuição, Valor, Instância, Tribunal, Situação, Data da baixa, Nível de sigilo (selo), Sistema, Formato, Processo de origem, Partes (nome · papel), Última atualização no tribunal | | `temDetalhe` | proc:330-437 |
| PZ-PRO-18 | ⋮ processo | Adicionar nota técnica `bi-journal-plus` | clique | Abre o compositor do bloco de notas do processo | | sempre (o bloco decide se escreve) | proc:205-208 |
| PZ-PRO-19 | ⋮ processo | Abrir no PJe | ausente | No lugar: "Ver processo completo" `bi-box-arrow-up-right` (GET `processo_show`) [SÓ-BLUEJUS] | | | proc:210-212 |
| PZ-PRO-20 | ⋮ processo | Ver todas as informações `bi-card-list` | clique | Aciona o botão do cartão | | `temDetalhe` | proc:213-218 |
| PZ-PRO-21 | ⋮ processo | Editar dados do processo `bi-pencil` | clique | GET `processo_edit` (sai da pasta) | | não lápide e `can_access_resource('processo', id, 'edit')` | proc:223-227 |
| PZ-PRO-22 | ⋮ processo | Atualizar dados do PJe | ausente | | | | |
| PZ-PRO-23 | ⋮ processo | Ver movimentações (Push) `bi-broadcast` | clique | Troca para a aba Push | | | proc:228-231 |
| PZ-PRO-24 | ⋮ processo | Copiar número `bi-copy` ("Número do processo copiado") | clique | clipboard | | | proc:233-236 |
| PZ-PRO-25 | ⋮ processo | Copiar resumo do processo `bi-clipboard` | clique | Linhas: Processo, Ação, Classe, Tribunal, Situação, Órgão julgador, Distribuição, Valor da causa, Pasta · cliente | | | proc:155-163, 237-240 |
| PZ-PRO-26 | ⋮ processo | Compartilhar `bi-share` | clique | `navigator.share` com o resumo; sem suporte, copia ("Resumo copiado para compartilhar") | | | proc:243-248; pasta-processo.js:140-150 |
| PZ-PRO-27 | ⋮ processo | Encaminhar via Chat I.A | ausente | | | | |
| PZ-PRO-28 | ⋮ processo | Relatório geral de processos | ausente | | | | |
| PZ-PRO-29 | ⋮ processo | Imprimir resumo `bi-printer` | clique | Janela de impressão com tabela (rótulo/valor); PDF `secao=processo` | | | proc:252-256; pasta-processo.js:156-181 |
| PZ-PRO-S01 | ⋮ processo | Tornar processo principal `bi-star` [SÓ-BLUEJUS no menu] | submit | XHR POST `pasta_definir_processo_principal`; troca a aba in-place | Não. Erro: `alert()` | `podeMexerNoVinculo` e não principal | proc:259-267; show:1601-1630 |
| PZ-PRO-30 | ⋮ processo | Desvincular da pasta `bi-x-lg` | submit | XHR POST `pasta_desvincular_processo`; troca a aba in-place | `cfUI` vermelho: "Desvincular o processo X da Pasta N? / Os dados do processo continuam salvos e você pode vincular de novo." | `podeMexerNoVinculo` | proc:269-276; show:1559-1595 |
| PZ-CTX-07 | Cartão | Botão direito no cartão abre o ⋮ no cursor (salvo em link/botão/seleção); botão direito fora fecha | contextmenu | | | | pasta-processo.js:235-266 |
| PZ-PRO-31 | Notas | Nota técnica: selo "Nota técnica" `bi-journal-text`, iniciais, autor, quando, "editada", marca de movimentação `bi-megaphone-fill` | | | | | `_nota_tecnica_item` |
| PZ-PRO-39 | Notas | Faixa "Notas técnicas N" sob todo cartão + "A nota é do processo: aparece em toda pasta que o vincula." + botão "Adicionar nota técnica" | | | | botão: `podeEscreverNota` (edit e não somente leitura) | `_notas_tecnicas` |
| PZ-PRO-37 | Notas | Editor "Nova nota técnica"/"Editar nota técnica": placeholder "Explique o processo ou o despacho: o que significa, o que precisa ser feito, prazo… Use @ para mencionar alguém.", Cancelar, Salvar nota (Ctrl+Enter), Esc cancela; vazio: "A nota técnica não pode ser vazia." | | POST `processo_nota_tecnica_criar` / `processo_nota_tecnica_editar` | | | nota-tecnica.js:111-290 |
| PZ-PRO-32 | ⋮ nota `bi-three-dots` | Responder com outra nota `bi-reply` | clique | Abre o compositor | | quem escreve | `_nota_tecnica_item` |
| PZ-PRO-33 | ⋮ nota | Copiar texto `bi-clipboard` | clique | | | | |
| PZ-PRO-34 | ⋮ nota | Encaminhar via Chat I.A | ausente | | | | |
| PZ-PRO-35 | ⋮ nota | Editar · N min / Editar · bloqueado `bi-lock` (aviso "Só o autor edita a nota, em até 15 minutos" por `avisarPs`) | clique | | | autor na janela | |
| PZ-PRO-36 | ⋮ nota | Excluir · N min / Excluir · bloqueado | clique | POST `processo_nota_tecnica_excluir` | `cfUI` vermelho "Excluir esta nota técnica?". Erro: `alert()` | | nota-tecnica.js:339-373 |
| PZ-PRO-38 | Notas | Primário edita e exclui sem limite | ausente | Regra de 15 min para todos | | | |
| PZ-CTX-09 | Notas | Botão direito na nota abre o ⋮ | contextmenu | | | | nota-tecnica.js:466-482 |
| PZ-PRO-40 | Aba | Nota de rodapé "O processo principal é o que aparece no cabeçalho da pasta e nas listagens. Uma pasta pode ter vários processos vinculados." | | | | | proc:470-473 |
| PZ-PRO-S02 | Aba | Vazios: "Administrativo sem processo" `bi-briefcase` (com ação registrada) e "Nenhum processo judicial vinculado" (texto muda com permissão) [SÓ-BLUEJUS no texto] | | | | | proc:448-469 |
| PZ-PRO-N01 a N04 | Motor `bj-processo.js` | ausente | | | | | |

---

### A6. Aba Financeiro (`_financeiro.html.twig`, `_financeiro_obs_script`, `_financeiro_pagamentos(_script)`, modal `#modalNovoPagamento` em `show:353-460`)

`podeEscreverFin` = `podeEditarPasta and not lápide`.

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-FIN-01 | Faixa | Contrato: selo Pendente `bi-hourglass-split` (perigo) ⇄ Assinado `bi-patch-check-fill` (ok); o selo é o botão | clique | `fetch POST pasta_alternar_situacao_contrato`; troca o selo | Não. Erro: `alert()` | botão com `podeEscreverFin`; senão selo estático | fin:60-92, 605-635 |
| PZ-FIN-02 | Faixa | Pró-bono: "Pró-bono ativo" `bi-check2-circle` / "Não é pró-bono" `bi-dash-circle` | clique | `fetch POST pasta_atualizar_pro_bono` | Não. Erro: `alert()` | idem | fin:94-128, 640-665 |
| PZ-FIN-03 | Faixa | **"Honorários contratuais"** (não "Valor da causa"), número editável (clique ou lápis `bi-pencil`; Enter/Espaço abre, Enter grava, Esc cancela), sub "valor cadastrado na pasta" | clique | `fetch POST pasta_atualizar_valor_causa`; valor manual (o desenho soma os lançamentos) | Não | edição com `podeEscreverFin` | fin:130-172, 690-760 |
| PZ-FIN-04 | Faixa | Média por CPF: rótulo `financeiro.mediaRotulo`, valor, nota com o nome do cliente (`title` "Média das pastas deste cliente"); atualizada ao trocar o cliente principal | | Servidor; JS do show atualiza | | | fin:174-186; show:1460-1475 |
| PZ-FIN-05 | Relatório | "Relatório financeiro" + contador **real** (raízes) + compositor rico "Registrar combinado, honorário ou movimento financeiro do caso…" + "Visível para a equipe" + Enviar (Ctrl+Enter), @menções (`origem=financeiro`) | submit | `fetch POST pasta_financeiro_observacao_enviar` | | compositor com `podeEscreverFin`; lápide: "Pasta excluída: o relatório financeiro fica só para leitura." | fin:196-235; obs_script:84-140 |
| PZ-FIN-40 | Relatório | Lista com classe `.ps-fin-obs-rolagem`; vazio "Nada registrado ainda / Anote acima o que foi combinado de honorário, custas ou reembolso." | | | | | fin:237-279 |
| PZ-FIN-06 | Cartão | "editado", lápis, lixeira oculta, "· N min" (janela de edição), Responder, caixa de resposta (Ctrl+Enter, Esc) | | Editar: POST `pasta_financeiro_observacao_editar` (vazio: `alert('O registro não pode ser vazio.')`); Excluir: POST `pasta_financeiro_observacao_excluir` | Excluir: `cfPs` vermelho com título/texto do desenho (`textoDeConfirmacaoExcluir`) | | fin:1560-1636; obs_script |
| PZ-FIN-07, 08 | Cartão | Presença do autor, Encaminhar | ausentes | | | | |
| PZ-FIN-09 | Relatório | Cartão de sistema "Andamento" | ausente (P3) | | | | |
| PZ-FIN-10 a 15 / PZ-CTX-10 | Menu `#financeiroObsMenu` | Botão direito no registro (ou ⋮): título, Responder, Copiar texto, Editar / Editar · bloqueado, Excluir comentário / Excluir · bloqueado (aviso `avisarPs` com o motivo) | contextmenu / clique | Itens clicam os botões do cartão | idem PZ-FIN-06 | escrita com `podeEscreverFin` | fin:293-314, 1400-1500 |
| PZ-FIN-16 | Arquivos | "Arquivos" + contagem + "Adicionar" (`<label>` com input file `.pdf,.docx,.doc,.jpg,.jpeg,.png`, `title` "… máx. 10 MB") | change | `fetch POST pasta_financeiro_upload` (com "Reduzir tamanho" opcional); a linha nova é montada no JS; spinner "Enviando…"; erro no rodapé | | `podeEscreverFin` | fin:326-351, 960-1010 |
| PZ-FIN-17 | Arquivos | Selo "Assinado" | ausente | | | | |
| PZ-FIN-S01 | Arquivos | Só os 3 primeiros visíveis + "ver os outros N no gerenciador" (vai à aba Documentos) [SÓ-BLUEJUS] | clique | | | | fin:462-468 |
| PZ-FIN-18 / PZ-CTX-11 | ⋮ arquivo (e botão direito na linha, e duplo clique) | Abrir `bi-box-arrow-up-right` | clique / dblclick | Abre `#previewDocModal` (`pasta_financeiro_documento_view`) | | | fin:389-400, 1485-1525 |
| PZ-FIN-19 | ⋮ arquivo | Baixar `bi-download` | clique | GET `pasta_financeiro_documento_download` | | | fin:401-405 |
| PZ-FIN-20 | ⋮ arquivo | Copiar nome do arquivo `bi-clipboard` | clique | | | | fin:406-410 |
| PZ-FIN-S02 | ⋮ arquivo | Renomear `bi-pencil` [SÓ-BLUEJUS] | clique | Campo inline no nome (Enter salva, Esc restaura); POST `pasta_financeiro_documento_renomear` | Não. Erro: `alert()` | `podeEscreverFin` | fin:412-423, 1060-1108 |
| PZ-FIN-21 | ⋮ arquivo | Encaminhar via Chat I.A | ausente | | | | |
| PZ-FIN-22 | ⋮ arquivo | Enviar por e-mail ao cliente `bi-envelope` | clique | `mailto:` com assunto "Pasta N · arquivo" e corpo; sem e-mail válido do cliente principal: botão que leva à aba Dados com aviso | | | fin:424-436 |
| PZ-FIN-23 | ⋮ arquivo | Ver em Documentos `bi-folder2-open` | clique | Vai à aba Documentos | | | fin:437-441 |
| PZ-FIN-24 | ⋮ arquivo | Excluir `bi-trash3` (perigo) | clique | POST `pasta_financeiro_documento_excluir` | `cfUI` vermelho "Excluir o arquivo "NOME" do Financeiro?" | `podeEscreverFin` | fin:442-453, 1110-1150 |
| PZ-FIN-25 | Arquivos | Interruptor "Reduzir tamanho ao enviar" + nota "Envios mantêm o arquivo original, recomendado para contratos assinados." | clique | Liga a compressão no próximo envio | | `podeEscreverFin` | fin:471-487 |
| PZ-FIN-26 | Pagamentos | "Pagamentos" + total de lançamentos; "R$ X" (botão que entra no modo edição) "recebidos de R$ Y"; barra; "Próximos vencimentos" + nota; linhas (descrição, quando, valor, selo de estado) | | Servidor (`pagamentos`) | | botão com escrita | pag (parcial) |
| PZ-FIN-27 | ⋮ Pagamentos | Adicionar pagamento `bi-plus-lg` | clique | Abre `#modalNovoPagamento` | | `podeEscreverFin` | fin:538-542 |
| PZ-FIN-28 | ⋮ Pagamentos | Editar ou corrigir valores `bi-pencil-square` / botão "Concluir edição" `bi-check2` | clique | Alterna o modo edição (valores clicáveis e lixeiras visíveis) | | `podeEscreverFin` | fin:517-545 |
| PZ-FIN-29 | ⋮ Pagamentos | Ver todos os lançamentos `bi-list-ul` | clique | Mostra as linhas além dos próximos | | | fin:547-549 |
| PZ-FIN-30 | ⋮ Pagamentos | Copiar resumo `bi-clipboard` | clique | | | só com extrato | fin:551-553 |
| PZ-FIN-31 | ⋮ Pagamentos | Imprimir extrato `bi-printer` | clique | `PastaImpressao.extrato()` (janela de impressão; recuo para a folha oculta `#psExtratoFolha`) | | só com extrato | fin:554-556, 1337 |
| PZ-FIN-32 | ⋮ Pagamentos | Botão direito sobre o próprio ⋮ | contextmenu | Abre o menu | | | fin:1485-1515 |
| PZ-FIN-34 | Pagamentos | Correção inline do valor (Enter salva, Esc cancela); "corrigido · era R$ X" com histórico na dica | clique no valor | POST `pasta_pagamento_corrigir_valor`; conflito: `alert` "O valor foi alterado por outra pessoa…" | `cfUI` azul "Corrigir "DESC" de R$ A para R$ B? A alteração fica registrada no histórico com seu nome, data e hora." | escrita | pag_script:150-220 |
| PZ-FIN-35 | Botão direito na linha | Corrigir valor `bi-pencil` | contextmenu | Menu `#psPagMenu-<id>` (título "DESC · VALOR") | | escrita | pag |
| PZ-FIN-36 | Botão direito na linha (e selo de estado) | Marcar como recebido `bi-check2-circle` / Desfazer recebimento `bi-arrow-counterclockwise` | clique | POST `pasta_pagamento_alternar_quitacao`; aplica o resumo | **Nenhuma confirmação**. Erro: `alert()` | escrita | pag_script:238-252 |
| PZ-FIN-37 | Botão direito na linha (e lixeira no modo edição) | Excluir lançamento `bi-trash3` | clique | POST `pasta_pagamento_excluir` | `cfUI` vermelho "Excluir lançamento / Excluir "DESC" (R$ X)?" | escrita | pag_script:254-276 |
| PZ-FIN-38 | Pagamentos | Sufixo " · juros N% a.m." na linha da parcela com juros | | Montado no servidor (`PastaPagamentoLinhaOutput::sufixoJuros`) | | | src/Pasta/DTO/PastaPagamentoLinhaOutput.php:97-114 |
| PZ-FIN-39 | Pagamentos | Vazio "Nenhum lançamento. Use Adicionar pagamento." + rodapé "Adicionar pagamento"; quitado "Tudo pago. Nada a vencer." | | | | | pag; fin:577-584 |
| PZ-MOD-23 | Adicionar pagamento | Modal "Adicionar pagamento" `bi-cash-coin` · "Valor da causa R$ X · cálculo automático": Tipo (Contratuais, Êxito desabilitado, Sucumbência desabilitado, Custas) com nota; Base Valor fixo / % da causa; Valor total ou %; Entrada; Parcelas (1 a 60); 1º vencimento; Juros Sem/Com + taxa a.m.; Descrição (opcional) [desvio consciente P16a]; Prévia (resumo, linhas, "mais", erro, vazio "Preencha o valor para ver as parcelas calculadas."); Cancelar; Adicionar (desabilitado até válido) | submit | `fetch POST pasta_pagamento_parcelar` (o servidor refaz a conta); entrada e parcelas nascem pendentes ("Entrada já recebida hoje" removido por decisão do dono, 07/10) | Não | escrita | show:353-460; pag_script:300-566 |
| PZ-MOD-24 | Adicionar pagamento | Êxito e Sucumbência | desabilitados ("Ainda não disponível.") | | | | show:398-401 |
| PZ-FIN-N02 | Impressão | Financeiro na impressão | | Opção "Financeiro" aparece na janela quando a folha traz a parte | | | pasta-impressao.js:231 |

---

### A7. Aba Detalhes (`_detalhes_obs.html.twig` + trilho em `show:279-317`)

`podeEscreverObs` = `podeEditarPasta and not lápide`; `obsPodeCriarMeta` = `podeEscreverObs`.

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-DET-01 | Aba | "Relatório inicial de Atendimento" + contador (dica "Observações principais (respostas não contam)") | | | | | det:69-74 |
| PZ-DET-02 | Aba | Compositor rico "Adicionar observação…" + Enviar (Ctrl+Enter), @menções | submit | `fetch POST pasta_detalhes_observacao_enviar`; cartão montado no JS | | `podeEscreverObs`; lápide só leitura | det:79-104, 215-260 |
| PZ-DET-S01 | Aba | Vazio "Nenhuma observação registrada / Registre acima o relato do cliente, o resumo da demanda, a tese e os resultados esperados." | | | | | det:109-116 |
| PZ-DET-03 | Cartão | "editado" (dica publicado/última edição), lápis "Editável por mais N minutos", lixeira oculta, Responder, caixa de resposta ("Escreva a resposta…", Ctrl+Enter, Esc), ⋮ "Mais ações da observação" | | Editar: POST `pasta_detalhes_observacao_editar`; Excluir: POST `pasta_detalhes_observacao_excluir` | Excluir: `cfPs` vermelho "Excluir o comentário de NOME?" + texto | | det:1050-1198 |
| PZ-DET-04, 05 | Cartão | Presença do autor, Encaminhar no hover | ausentes | | | | |
| PZ-DET-06 | ⋮ / botão direito | Editar · N min / Editar · bloqueado (`bi-lock`, motivo "O prazo de 15 minutos para editar ou excluir terminou." ou "Só o autor pode editar ou excluir, nos 15 minutos após publicar.") | | | | | det:1153-1185 |
| PZ-DET-07 | ⋮ | Copiar texto `bi-clipboard` | | | | | det:1128-1130 |
| PZ-DET-08 | ⋮ | Encaminhar via Chat I.A | ausente | | | | |
| PZ-DET-09 | ⋮ | Citar no novo registro `bi-quote` | clique | Escreve a citação no compositor de Detalhes | | `podeEscreverObs` | det:1132-1136, 710-718 |
| PZ-DET-10 | ⋮ | Criar meta a partir deste registro `bi-check2-square` | clique | Abre `#modalCriarTarefa` com descrição = texto (600) e prazo em 7 dias | | `obsPodeCriarMeta` | det:1137-1141, 719-740 |
| PZ-DET-11 | ⋮ | Enviar no Chat I.A | ausente | | | | |
| PZ-DET-12 | ⋮ | Imprimir `bi-printer` | clique | Janela de impressão "Registro do expediente" (autor · data · tipo + texto clonado); PDF `secao=observacao` | | | det:1145-1150; pasta-impressao.js:388-416 |
| PZ-DET-13 | ⋮ | Excluir comentário · N min / Excluir · bloqueado | | ver PZ-DET-03 | | | |
| PZ-DET-14 | ⋮ | Destacar | ausente | | | | |
| PZ-CTX-13 | Lista | Botão direito na observação abre o ⋮ no cursor | contextmenu | | | | det:778-800 |
| PZ-DET-15 | Cartão | "continuar lendo (N parágrafos)" / "mostrar menos" (`pasta-ler-mais.js`, corta em 3 parágrafos) | clique | | | | pasta-ler-mais.js |
| PZ-DET-16 | Trilho | "Registro da pasta": Criada em, Modificada em ("hoje, dd/mm/aaaa HH:MM" no dia), Criada por (avatar 24 com iniciais + nome) | | | | | show:279-305 |
| PZ-DET-17 | Trilho | "Ver histórico do sistema" `bi-clock-history` (`data-ps-historico`) | clique | Abre o drawer Histórico | | | show:306-311 |
| PZ-DET-18 | Trilho | "Pontos do relatório inicial" | ausente | | | | |
| PZ-DET-19 | Trilho (P2) | "Como usar esta aba" (texto explicativo com Financeiro em negrito) | | Preservado (P2) | | | show:313-316 |

---

### A8. Aba Documentos (`_documentos_explorador.html.twig`, `pasta-explorador.js` 3.769 linhas, `pasta-explorador-visor.js`, `_documentos_checklist` + `pasta-checklist.js`, `_documentos_sugeridos` + `pasta-documentos-sugeridos.js`, modais `#pexDestinoModal` e `#pexEditarModal` em `show:485-551`)

Escrita condicionada a `explorador.podeEditar` (calculado no servidor; some na lápide e para quem só lê, DOC-118).

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-DOC-01 | Aba | Cabeçalho "Documentos" e rodapé | | | | | expl:68 |
| PZ-DOC-02 | Aba | Busca "Buscar arquivos e pastas…" + limpar (Esc limpa) | input | Filtro no navegador | | sempre | expl:72-76; pasta-explorador.js:3058 |
| PZ-DOC-03 | Aba | "Nova pasta" `bi-folder-plus` (inline, nome livre "Nova pasta") e "Anexar" `bi-paperclip` (input múltiplo, ~30 extensões) | clique | Nova pasta: POST `pasta_secao_criar`; Anexar: upload com barra de progresso por arquivo, POST `pasta_peticionar_upload` (`_token` `peticionar_upload_<id>`); aviso de duplicado "Arquivo idêntico já existe no escritório." (sessionStorage, sobrevive ao reload) | | `podeEditar` | expl:79-82, 174-188; show:2137-2323 |
| PZ-DOC-04 | Aba | "Organizar" `bi-sliders` (selo "1 filtro ativo"): Ordem das colunas (setas, ou arrastar o título), Classificar por Manual/Nome/Tipo/Tamanho/Modificado (Categoria oculta), Colunas (Categoria), Tipo de documento (Todos, Pastas, PDF, Word, Excel, Imagens, Compactados, Outros), Restaurar padrão, **Lixeira (N)** | clique | Preferências por navegador; Lixeira abre a lista de excluídos (`GET pasta_documentos_lixeira`, restaurar `POST pasta_documentos_restaurar`) | | | expl:86-140 |
| PZ-DOC-05 | Aba | "Visualizar": Ícones extra grandes, grandes, médios, pequenos, Lista, Detalhes, Blocos, Conteúdo + "Painel de detalhes" | clique | | | | expl:142-165 |
| PZ-DOC-06 | Checklist | "Checklist de documentação": Documentos sugeridos (se houver sugestão), Modelos, Editar (remover, renomear, reordenar com Sortable), Adicionar ("Nome do documento, ex.: Certidão de casamento"), interruptor Desativar/Reativar com painel "Motivo da desativação" (motivos, "Atualizar o checklist", "Manter ativo", "Desativar"); item clicável marca concluído/pendente; aviso quando marcado sem arquivo de nome correspondente; estado desativado "Checklist desativado / Conferência de anexos e alerta da aba Documentos pausados." | clique | Rotas `pasta_checklist_adicionar/toggle/editar/excluir/reordenar/estado`; modelos `pasta_checklist_modelo_listar/salvar/aplicar/renomear/excluir` | Excluir item: `cfUI` vermelho "Excluir este item do checklist?"; Substituir modelo: `cfUI` vermelho "Substituir modelo / Já existe o modelo "X"…"; Excluir modelo: `cfUI` vermelho "Excluir o modelo "X"? Os checklists já aplicados nas pastas não mudam." | escrita com `podeEditar`; estado com `podeAlterarEstado` | `_documentos_checklist`; pasta-checklist.js |
| PZ-DOC-07 | Checklist | Avisar a Controladoria, cobrança automática | ausentes | | | | |
| PZ-DOC-08 | Sugeridos | Painel "Documentos sugeridos": Atualizar (recarrega a aba), Fechar, conflito, "Contexto do processo" com Confiança média/baixa, prazos em curso, chips por status, grupos, "Localizado em", origem (link `#push`), "Adicionar ao checklist" por item e "faltantes" em lote, "Possíveis duplicados", organização por fase, notas (catálogo fixo, regras) | clique | `fetch` para adicionar ao checklist | | escrita com `sugPodeEscrever` | `_documentos_sugeridos`; pasta-documentos-sugeridos.js |
| PZ-DOC-09 | Sugeridos | Camada de IA e leitura da capa do PDF | ausente | Regras fixas; o texto declara "o conteúdo do processo não é lido" | | | |
| PZ-DOC-10 | Aba | "Mostrando somente" + chip com X | clique | Remove o filtro | | | expl:193 |
| PZ-DOC-11 | Aba | Barra de seleção: Limpar (Esc), contagem, Baixar/Baixar (.zip)/Baixar N itens (.zip), Copiar (Ctrl+C), Recortar (Ctrl+X), Renomear (F2), Selecionar tudo (Ctrl+A), Excluir (Del) | clique | ZIP: POST `pasta_documentos_zip` | ver PZ-DOC-15 | | expl:202-211; pasta-explorador.js:1420-1455 |
| PZ-DOC-12 | Aba | Trilha com ↑ "Voltar à pasta anterior (Backspace)" | clique / Backspace / Alt+← / Alt+↑ | | | | expl:214 |
| PZ-DOC-S01 | Aba | Faixa "Selecionar todos" e cabeçalho de colunas clicável (Nome, Tipo, Tamanho, Modificado; duplo clique no cabeçalho) | | | | | expl:225-236; pasta-explorador.js:3202 |
| PZ-DOC-13 | Aba | Faixa de limpeza "por regras" (cópia idêntica, vazio, cópia do processo > 100 páginas ou 50 MB, > 100 MB): Revisar, dispensar, "Nada é excluído sem você confirmar. A exclusão pode ser desfeita.", "Excluir N selecionado(s) · tamanho" | clique | Exclusão em lote | `cfUI` vermelho "Excluir N arquivo(s) sugerido(s)? Você pode desfazer logo em seguida." | | expl:246-262; pasta-explorador.js:1280-1320 |
| PZ-DOC-14 | Lista | 8 modos, estrela de favorito (`POST pasta_documentos_favorito`), seleção por laço, arrastar para mover (`POST pasta_documentos_mover_lote` / `pasta_documento_mover_secao`), reordenar manual (`pasta_documentos_reordenar`, `pasta_secoes_reordenar`), soltar arquivos do sistema para enviar | arraste / drop | | | escrita | pasta-explorador.js:3465-3760 |
| PZ-DOC-15 | Botão direito no item (arquivo) | Abrir (Enter), Visualizar (Espaço), Baixar, Copiar link, Recortar (Ctrl+X), Copiar (Ctrl+C), Mover para…, Copiar caminho, Favoritar/Desfavoritar, Renomear (F2), Editar…, Excluir (Del), Propriedades | contextmenu | Renomear inline (Enter/Esc); Editar… abre `#pexEditarModal` (nome-base + extensão preservada, Tipo de documento, Descrição, Número; POST `pasta_documento_edit`); Mover para… abre `#pexDestinoModal` (árvore, origem e descendentes desabilitados) | Excluir: `cfUI` vermelho "Excluir "NOME"?" + aviso da lixeira | escrita | pasta-explorador.js:1970-2002 |
| PZ-DOC-15 | Botão direito no item (pasta) | Abrir, Baixar como .zip, Copiar link (`?pex=`), Recortar, Copiar (desabilitado só pastas), Colar, Mover para…, Copiar caminho, Favoritar, Renomear, Excluir, Propriedades | contextmenu | | Excluir pasta: `cfUI` vermelho "Excluir a pasta "X" e todo o conteúdo dela? Ao todo: … " + aviso da lixeira | | pasta-explorador.js:2545-2590 |
| PZ-DOC-16 | Botão direito | "Compartilhar link(s)" | ≈ "Copiar link(s)" | | | | |
| PZ-DOC-17 | Botão direito | Encaminhar via Chat I.A | ausente | | | | |
| PZ-DOC-18 | Botão direito com vários | Baixar como .zip (N), Copiar links, Recortar, Copiar, Mover para…, Copiar caminhos, Favoritar, Excluir N itens, Propriedades | contextmenu | | "Excluir N itens? Ao todo: …" | | pasta-explorador.js:1955-1969 |
| PZ-DOC-19 | Botão direito no fundo | Nova pasta, Colar (Ctrl+V), Selecionar tudo (Ctrl+A), Mostrar/Ocultar painel de detalhes | contextmenu | | | | pasta-explorador.js:1938-1950 |
| PZ-DOC-20 | Aba | Toast `#pexToast` com "Desfazer" | | Erro sem toast cai em `alert()` | | | expl:300-306; pasta-explorador.js:2120 |
| PZ-DOC-21 | Aba | Painel de propriedades: Tipo, Categoria, Tamanho, Modificado, Adicionado em, Enviado por, Descrição, Nome parecido, Local; seleção múltipla: Pastas, Arquivos, Tamanho dos arquivos | | | | | pasta-explorador.js:1070-1105 |
| PZ-DOC-22 | Aba | Atalhos: Enter, Espaço, Del, F2, Ctrl+A/C/X/V, Backspace, Alt+←/↑, setas, Home, End; menu de contexto com ↑/↓/Home/End/Esc/Tab | tecla | Só leitura: Del, F2, Ctrl+X/C/V desligados | | | pasta-explorador.js:1647-1720, 2070-2080 |
| PZ-DOC-S02 | Visor | Visor próprio `#pexVisor`: anterior (←), próximo (→), zoom −/100%/+, Baixar, Imprimir, Abrir em nova aba, Fechar (Esc) | dblclick / Espaço / Visualizar | | | | expl:317-345; pasta-explorador-visor.js |
| PZ-DOC-23 | Aba | Lixeira (N) | ver PZ-DOC-04 | | | | |
| PZ-DOC-24 | Aba | Escrita escondida de quem só lê e na lápide | | | | | |
| PZ-DOC-25 | Aba | Vazio "Pasta vazia… Colar (Ctrl+V)"; `<noscript>` com a lista de arquivos (link `viewUrl`) | | | | | expl:350-358 |

---

### A9. Aba Push Processual (`_push_processual.html.twig`, `_push_teor.html.twig`, `pasta-push.js`, acordeão em `pasta-show.js:510-550`)

`pushPastaViva` = não lápide; `pushPodeEscrever` = viva e `podeEditarPasta`.

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-PUSH-01 | Faixa | "Movimentações recebidas" + contagem (`push.total`) | | | | | push:29-32 |
| PZ-PUSH-S01 | Faixa | Link "ver no módulo" (`push_processual_index?busca=`) [SÓ-BLUEJUS] | clique | | | com número único e `can_access_module('djen')` | push:38-41 |
| PZ-PUSH-02 | Faixa | "✦ Resumir com IA" / "Gerar nova análise" / "Analisando…" (dica com movimentações não analisadas); indisponível: botão desabilitado com a mensagem | clique | `POST inteligencia_push_solicitar`; polling de status | Não | `analisesIa` com movimentações ou análise | push:47-89; pasta-ia-push.js:255-300 |
| PZ-PUSH-03 | Faixa | Filtros Todas · Novas · Geram prazo; vazio "Nenhuma movimentação neste filtro." | clique | No navegador (`data-push-gera-prazo` do servidor) | | | push:92-96, 296; pasta-push.js |
| PZ-PUSH-04 | Faixa | Filtros por processo | ausente | | | | |
| PZ-PUSH-05 | Análise IA | Cartão "✦ Análise IA", "Gerado por IA · não é movimentação oficial · hoje/data", "lido", cadeado "Interna do escritório" (alterna `inteligencia_analise_interna`), recolher com prévia (`inteligencia_analise_recolher`), "Quem deve agir", erro "Não foi possível gerar a análise agora."/"IA não configurada nesta instalação."; vazio "Nenhuma análise por IA ainda para esta pasta." | clique / submit | XHR | | | `inteligencia/_analises_push`; pasta-ia-push.js |
| PZ-PUSH-06 | ⋮ análise | Editar análise | ausente | | | | |
| PZ-PUSH-07 | ⋮ análise | Marcar como lido / Marcar como não lido `bi-check2-all` | submit | POST `inteligencia_analise_lida` / `inteligencia_analise_nao_lida` | | | `_analises_push` |
| PZ-PUSH-08 | ⋮ análise | Criar tarefa da providência `bi-check2-square` | clique | Abre `#modalCriarTarefa` pré-preenchido | | concluída e `iaPodeCriarTarefa` | `_analises_push:105-117` |
| PZ-PUSH-09 | ⋮ análise | Excluir análise `bi-trash3` | submit | POST `inteligencia_analise_excluir` | `cfUI` vermelho "Excluir esta análise da IA? As movimentações oficiais não são alteradas." | | `_analises_push:118-123`; pasta-ia-push.js:340-360 |
| PZ-PUSH-10 | Análise | Enviar ao cliente | ausente | | | | |
| PZ-PUSH-11 | Cartão | Pílula do dia ("3 out 2026"; repetida esmaecida), ponto colorido juízo/cartório com dica | | | | | push:166-205 |
| PZ-PUSH-12 | Cartão | Assunto (resumo do teor/órgão/tipo) com `bi-megaphone-fill`, "Novo", tipo (intimação/decisão/citação/outro), meta "TRIBUNAL · DJEN", chevron | clique | Acordeão: `GET pasta_push_teor` (XHR) uma vez; marca a linha como lida na tela | | | push:206-230; pasta-show.js:510-550 |
| PZ-PUSH-13 | Teor | ID do documento com "Copiar ID" e "Abrir no PJe" (link externo) | clique | | | dentro do teor | teor |
| PZ-PUSH-14 | Cartão | Encaminhar | ausente | | | | |
| PZ-PUSH-15 / 16 | Teor | "Criar tarefa" (abre o modal Nova meta pré-preenchido com tipo, data, órgão, processo, documento, link) | clique | | | `teorPodeEscrever` | teor; pasta-push.js:250-280 |
| PZ-PUSH-17 | Teor | Marcar como lida / não lida | clique | `POST pasta_push_lida`; o estado vem da resposta | | não lápide | teor; pasta-push.js:175-245 |
| PZ-PUSH-S02 | Teor | "Esta publicação não trouxe teor textual."; "Abrir no módulo" (`push_processual_show`) [SÓ-BLUEJUS] | | | | djen | teor |
| PZ-PUSH-18 | Notas | Notas técnicas penduradas sob o cartão (some vazio, `ocultoSeVazio`) | | | | | push:244-255 |
| PZ-PUSH-19, 20 | Notas | Editor e ⋮ da nota (ver PZ-PRO-31 a 37) | | | | | |
| PZ-PUSH-21 a 24 / PZ-CTX-15 | Menu `#pushMenuContexto` | Botão direito no cartão: título, Adicionar nota técnica, Criar tarefa, Marcar como lida/não lida, Abrir documento, Copiar texto (`GET pasta_push_texto`) | contextmenu | Esc, clique fora, scroll, resize fecham | | nota e tarefa com `pushPodeEscrever`; lida com viva | push:269-295; pasta-push.js:490-620 |
| PZ-PUSH-25 | Aba | Vazios: sem processo ("Esta pasta não tem processo vinculado… Vincule um processo …") e sem publicação ("Nenhuma publicação captada para este caso…") | | | | | push:107-120 |
| PZ-PUSH-26 | Aba | Limite atingido + "Ver todas no módulo" | | | | | push:297-305 |
| PZ-PUSH-S03 | Aba | Selo e linha de pendência da aba Push acompanham as linhas novas (MutationObserver na classe) [SÓ-BLUEJUS na mecânica] | | | | | pasta-push.js |
| PZ-PUSH-27 a 33 | Trilho | Monitoramento, Buscar agora, Previsão, Avisar quando chegar, Avisar cliente, Enviar ao cliente | ausentes | A aba Push não tem trilho | | | |

---

### A10. BlueJus Intelligence (drawer `#psIaDrawer`, `inteligencia/_agentes_drawer`, `_agentes_painel`, `_analises_agente`, `_auditoria`, `pasta-ia-agentes.js`)

| ID | Área | Elemento | Gatilho | Comportamento real | Confirmação | Visibilidade | Referência |
|---|---|---|---|---|---|---|---|
| PZ-IA-01 | Drawer | "BLUEJUS IA / Assistente jurídica do escritório", X, fundo, Esc | botão do cabeçalho | Na 1ª abertura `GET inteligencia_agentes_painel` | | `can_access_module('inteligencia')` | `_agentes_drawer`; pasta-ia-agentes.js |
| PZ-IA-02 | Drawer | Chips de contexto ("Contexto carregado automaticamente desta tela") | | | | | `_agentes_drawer` |
| PZ-IA-03 | Drawer | "O que a IA sabe desta pasta" (itens com corte) | | | | | `_agentes_painel` |
| PZ-IA-10 | Drawer | Lista de agentes (`ia_agentes()`), cada um com nome e "Gerar análise" (dica por estado); indisponível desabilitado | clique | `POST inteligencia_agente_solicitar` (202/200/409/429); polling `GET inteligencia_analise_status` (2 s, 5 s após 30 s, 10 s após 2 min, pausa oculto); recarga `GET inteligencia_agente_listar` | | | `_agentes_painel`; pasta-ia-agentes.js:290-360 |
| PZ-IA-11 | Resultado | Copiar, Baixar .txt (`inteligencia_analise_txt`), Imprimir; baixar e imprimir registram (`POST inteligencia_analise_registro`) | clique | | | | `_analises_agente`; pasta-ia-agentes.js:453-530 |
| PZ-IA-S01 | Resultado | ⋮ do cartão: lida/não lida, interna, Criar tarefa da providência, Excluir | | XHR + recarga do agente | Excluir: `cfUI` vermelho | | pasta-ia-agentes.js:400-450 |
| PZ-IA-15 | Drawer | "Registro de auditoria (N)" (`GET inteligencia_auditoria_da_pasta`), lista com download/impressão, "Nenhuma ação da IA nesta pasta ainda." | clique | | | | `_auditoria` |
| PZ-IA-04 a 09, 12 a 14 | Drawer | Abas Análise/Documentos/Relatórios/Perguntar, fase, providências, situação, achados, memória, Perguntar, Ler documentos | ausentes | | | | |
| PZ-IA-16 a 32 | Chat / Central de Comunicação | ausentes na pasta_show | | | | | |

---

### A11. Tema escuro e responsividade

#### Tema escuro
- Mecanismo: `[data-bs-theme="dark"]` (Bootstrap 5.3), não `prefers-color-scheme`. Tokens `--ps-*` redefinidos em `pasta-show.css:209` e 42 regras escuras no arquivo; também em `pasta-explorador.css` (4), `nota-tecnica.css` (4), `pasta-ia-agentes.css` (3), `pasta-documentos-sugeridos.css` (3), `pasta-checklist.css` (2), `pasta-impressao.css` (2), `pasta-ia.css` (1), `_janela_confirmacao` (2), `_metas` (1), aviso `#psAviso` (`--ps-aviso-bg: #0b161d`).
- Fundo da página: claro `--ps-bg #eaeef1`; escuro `var(--bs-body-bg)` (`pasta-show.css:368-373`).
- Pontos com cor fixa que não seguem o tema (literais): `.ps-vazio i #c3d2dd` (tem par escuro), `.ps-aba-badge` fundo `#e2e8ed`, `.ps-abas` borda `#e0e7ec`, `.ps-avatar` `#e7eef4/#2a4353`, cabeçalho da janela de confirmação `#0b6a96`, `_janela_confirmacao` tem par escuro; os estilos inline legados `.doc-folder-*`/`.pasta-secao-card*` (gradiente `#5b9bd5→#3f7bbf`) não têm par escuro, mas estão mortos.

#### Responsividade (media queries da página)
| Arquivo:linha | Corte | O que muda |
|---|---|---|
| pasta-show.css:1290 | max 980px | `.ps-grade` vira uma coluna (trilho abaixo do conteúdo) |
| pasta-show.css:2086 | max 767.98px | `.ps-page` padding 14px 14px 40px; cabeçalho 16px; nome do cliente 22px; linha 1 quebra; ações e setas sem margem automática |
| pasta-show.css:4386 | max 767.98px | Push: filtros em linha própria; botão IA até 100% |
| pasta-show.css:4627 | max 420px | modal Editar dados: campo duplo vira simples |
| pasta-show.css:4696 | max 767.98px | botão Cadastro e pílula de situação sem margem automática |
| pasta-show.css (17×) | prefers-reduced-motion | desliga animações (selo, indicador, pontos) |
| pasta-show.css (2×) | print | regras de impressão |
| pasta-show.css (1×) | hover: none | dica do nome do cliente some |
| show:2079 | max 991.98px | estilos inline antigos de clientes/anexos |
| show:112, 189 | max 575.98px | estilos inline mortos `.doc-folder-*`/`.pasta-secao-card` |
| pasta-explorador.css:1195, 1456 | max 767.98px ou hover: none | caixas de seleção 32px; ações sempre visíveis no toque |
| pasta-explorador.css:1616, 1624, 1875 | max 575.98px / 767.98px | lixeira empilhada; cabeçalho e busca em linha inteira; visor compacto |
| pasta-ia-agentes.css:591 | max 520px | drawer da IA 100vw; rótulo "BLUEJUS Intelligence" do botão some |
| pasta-checklist.css:320 | max 480px | itens do checklist em linha inteira |
| JS | largura do trilho | Abas em modo compacto (só ícone e selo) quando não cabem (`pasta-show.js:121-126`) |

---

### A12. Valores visuais declarados (resumo por bloco, para a comparação)

Tokens (claro): fundo `#eaeef1`; cartão `#fff`; borda `#dde5eb`; borda de controle `#cdd7de`; divisória forte `#eef2f5`, fraca `#f4f7f9`; texto `#12242f`, corpo `#243845`, secundário `#455c6b`, mudo `#6b8494`, rótulo `#7b93a2`, apagado `#8496a3`; acento `#0a7aad` (hover `#086a96`, fundo `#e5f1f7`); ok `#186c47/#1f9d61/#e2f4ea`; aviso `#8a5a12/#c8952a/#fdf1dc`; perigo `#a3232b/#c0392f/#fdeceb`; tarja de menu `linear-gradient(135deg,#0d80a3,#0b6688)`; cabeçalho de painel `#f5f6f7` com borda `#dfe4e8`; pílula do dia `#dde3e8`/`#3c4a54`. Fonte `var(--jp-font-sans)`.

| Bloco | Valores (pasta-show.css salvo indicação) |
|---|---|
| Página | `max-width 1500px`, padding `20px 26px 60px` (l.381) |
| Cartão `.ps-card` | fundo `--ps-card`, borda 1px `--ps-border`, raio 4px, sombra `--ps-shadow-card` (l.407) |
| Botão `.ps-btn` | altura 34px, padding 0 13px, borda 1px `--ps-border-ctl`, texto `--ps-text-2`, gap 7px (l.419); primário fundo `#0a7aad`, texto branco, 600 (l.447) |
| Cabeçalho | padding `20px 24px 0`; linha 1 gap 12px, margem 10px (l.463-465) |
| "PASTA nnnn" | 15px/700, letter-spacing .06em, tabular (l.691) |
| Prioridade `.ps-prio` | padding 3px 9px, raio 3px, 11px/600, gap 6px (l.707) |
| Etiqueta | 11px/600, caixa alta, .04em, gap 5px (l.727); "+" 24×24 (l.758) |
| Nome do cliente `h1` | 28px/600, line-height 1.25, caixa alta, `max-width min(50vw,640px)` (l.798); 22px no celular |
| Movimentado | 12.5px/500 `--ps-muted`, gap 7px (l.820) |
| Documento | 12px/400, .02em, `--ps-faint` (l.809) |
| Dica do nome | 12.5px/700, padding 7px 11px, raio 4px, fundo `#e3f0f8`, texto `#0b5f86`, aparece em 350 ms (cab:658-673) |
| Faixa de dados | grade `auto-fit minmax(min(100%,220px),1fr)`, gap 4px, padding 4px, margem 20px; célula padding 12px 16px (l.852-861) |
| Rótulo `.ps-rotulo` | 10.5px/700, .1em, caixa alta, `--ps-label` (l.1031); dado 14.5px, forte 600, sub 12.5px `--ps-muted` |
| Selo ARQUIVADO | cor `#5d7383`, girado −14°, 120×83, aro 78, faixa 27, fonte 12 (l.863-900) |
| Abas | trilho fundo `#eef2f5`, borda `#e0e7ec`, raio 4px, gap 3px (l.1128); aba ativa 600 `#0a7aad` (l.1198); selo 10.5px/600 fundo `#e2e8ed`, padding 1px 6px, raio 3px (l.1236) |
| Situação | pílula altura 44px, padding 0 14px, raio 4px, gap 8px (l.1068); Cadastro altura 44px, padding 0 15px (l.4658) |
| Popover `.ps-pop` | raio 4px, padding 5px, sombra `--ps-shadow-pop`, z 30 (l.501); item 13.5px, padding 8px 10px, gap 10px (l.644); tarja 10.5px/700, padding 10px 16px (l.523); interruptor 30×17, bola 13 (cab:649-652) |
| Grade de aba | `minmax(0,1fr) 356px`, gap 18px; trilho gap 14px (l.1283) |
| Cabeçalho de cartão | padding 14px 17px, h2 13.5px/600 (l.1295); de painel: fundo `#f5f6f7`, padding 15px 20px, h2 14px (l.1302) |
| Contagem | 11px `--ps-label`, tabular (l.1366) |
| Registro | cartão padding 16px 20px, gap 13px (l.1629); autor 13.5px/600; data 12px; texto 14.5px/1.6 `--ps-body` (l.1638-1640); pílula do dia 13px/700, padding 5px 16px, raio 4px (l.1439); compositor padding 16px 20px fundo `--ps-soft` (l.1376); menu do registro 250px (anot:69-73) |
| Linha do trilho | padding 12px 17px, gap 11px (l.1705); pip 7px (l.1722); selo 11px/600, padding 3px 9px, raio 4px (l.1730) |
| Vazio | padding 40px 24px; ícone 30px `#c3d2dd`; título 14.5px/600; nota 13px (l.1670) |
| Avatar 24 | 9.5px/700 branco, gradiente `#1597bd→#0c7a9c` (l.1061) |
| Drawer Histórico | 430px, fixo à direita (l.1975) |
| Financeiro | faixa `auto-fit minmax(200px,1fr)`, gap 12px (l.2297); rótulo 10.5px/700 .1em (l.2319); número 24px/600 (l.2348); selo altura 30px, padding 0 12px, raio 4px (l.2365); recebido 22px/600; barra 6px (l.2524-2526) |
| Push | item padding 0 14px 0 44px, linha 2px em 21px (l.2855); pílula do dia 12.5px/700, padding 5px 15px (l.2858) |
| Processo | número 17px/500 (l.3103) |
| Metas | cartão padding 17px 22px, margem 16px, gap 13px (l.3321); título 14.5px/600 (l.3344); filtro 12.5px/500, padding 5px 11px (l.3381); barra 7px verde `--ps-ok-dot` (l.3823) |
| Relatório da meta | `min(460px,100vw)`, z 1056 (l.3540) |
| Timeline | `min(560px, 100vw − 32px)`, 16px das bordas, z 1058 (l.5064) |
| Modal Editar dados | cabeçalho padding 12px 16px, gradiente `--ps-modal-cab-grad`, texto branco (l.4517) |
| Nova meta | campo 40px, raio 4px, 14px; calendário 280px, cabeçalho `--ps-accent`, dia 34px (show:4003-4140) |
| Confirmação | 420px, raio 4px, cabeçalho `#0b6a96`, título 14px/700, texto 13.5px/1.55, botões 34px 13px/700, OK `#b3262e` / azul `#0a6fa0`, Cancelar fundo `--cf-div` texto `#34505f` |
| Aviso (toast) | fundo `#12242f`, 13px, padding 10px 14px, raio 4px, rodapé 24px, 2,6 s |

---


### [SÓ-BLUEJUS] Funcionalidade real que o protótipo não tem (preservar)

1. Lápide: aviso "Pasta excluída…", botão "Restaurar" (confirmação azul), "Histórico" rotulado no lugar do ⋮, escrita escondida em todas as abas, textos "fica só para leitura" (cab:156-176, 302-315).
2. Excluir pasta com digitação do número (`confirmar_nup`) (cab:285-295).
3. Bloco "Cobrança" na faixa com link para a unidade cobrada (cab:501-512) e nome do cliente derivado da cobrança, só leitura no Editar dados (show:3633-3647).
4. Chip "N vinculado(s)" com a lista dos demais processos na faixa (cab:437-462).
5. Seção "Preferências" no ⋮ com "Fixar nos favoritos" ligado ao servidor.
6. Timeline inteligente real (visita, "Enquanto você estava fora", resumo, atenção, antes/depois) (`_timeline_inteligente`, pasta-timeline.js).
7. Janela de impressão com PDF do servidor para resumo, observação, processo e extrato (pasta-impressao.js).
8. Modal "Adicionar cliente" PF/PJ completo, vínculo múltiplo, busca de existentes (show:552-1694).
9. Janela "Detalhes do cliente" com pastas do mesmo CPF/CNPJ, contatos editáveis, Copiar qualificação, Editar cadastro (`cliente/_resumo`).
10. Ícone "Abrir ficha do cliente" na linha do cliente (`_cliente_linha`).
11. Situação no modal Editar dados (select) e campo Ação editável.
12. Modal Vincular processo com cadastro de novo CNJ, partes, movimentações e consulta DataJud.
13. Processo: "Ver processo completo", "Tornar processo principal" no ⋮, detalhe com sigilo, sistema, formato, origem, partes, última atualização; vazio "Administrativo sem processo".
14. Notas técnicas: aviso "A nota é do processo: aparece em toda pasta que o vincula."; notas penduradas nas movimentações do Push.
15. Metas: drawer "Relatório da meta" com histórico real e "Outras metas"; aviso de título parecido na Nova meta; atalhos +5/+15 úteis; `?meta=&acao=renomear`; Responsáveis nas metas com dados reais.
16. Financeiro: "Honorários contratuais" editável; Média por CPF ligada ao cliente principal; arquivos com Renomear; limite de 3 + "ver os outros N no gerenciador"; correção de valor com histórico e conflito; descrição opcional no Adicionar pagamento; recusa de entrada já paga.
17. Detalhes: "Citar no novo registro", "Criar meta a partir deste registro", "Imprimir" no ⋮; "Registro da pasta" e "Como usar esta aba" (P2).
18. Documentos: explorador completo (lixeira, favoritos, .zip, copiar/colar, mover, Editar… com categoria e número, visor próprio, limpeza por regras, aviso de arquivo idêntico no escritório, checklist com modelos e desativação com motivo, documentos sugeridos por catálogo e regras).
19. Push: "ver no módulo", "Abrir no módulo", limite com link, análise IA com cadeado "Interna do escritório", recolher, "Quem deve agir".
20. BlueJus IA: agentes por pasta, polling honesto, auditoria com downloads/impressões.
21. Aviso de arquivo duplicado no upload que sobrevive à recarga (sessionStorage) (show:2150-2320).
22. `#aba` e `#pasta-msg-N` na URL abrem a aba e o registro certos (pasta-show.js:558-602).

---


### Limitações

- Levantamento estático: nada foi executado, renderizado ou clicado. Ordem visual, medidas finais e estados dinâmicos (posições de menus, modo compacto das abas) foram inferidos do código.
- `pasta-explorador.js` (3.769 linhas) e `editor-rico.js` (2.377 linhas) foram lidos por amostragem (menus, atalhos, rotas, confirmações); detalhes internos de arraste, laço, colunas e revisor ortográfico não foram enumerados item a item.
- `_financeiro_pagamentos_script` (modal de parcelamento, prévia) e `_financeiro_obs_script` foram lidos nos pontos de ação e confirmação, não linha a linha.
- `inteligencia/_analises_agente`, `_agentes_painel` e `pasta-ia-push.js`/`pasta-ia-agentes.js` foram lidos pelo cabeçalho de comentário e pelos gatilhos; textos de cada estado de análise não foram todos transcritos.
- `cliente/_resumo.html.twig`, `processo/_nota_tecnica_item.html.twig` e `_partials/modal_mover_marcador.html.twig` foram lidos por extração de elementos, não integralmente.
- Os dados que o servidor calcula (`proximosPrazos`, `metasResumo`, `pendencias.porAba`, `financeiro`, `pagamentos`, `push`, `explorador`) foram tratados como caixa preta: o inventário registra como a tela os mostra, não a regra de cálculo.
- Não rodei `debug:router` (as rotas foram cruzadas com `rotas-pasta.txt` e com `path()` nos templates); `api_datajud_search` e `app_profile_foto_serve` não estão nessa lista e não foram conferidos no roteador.
- O inventário B do protótipo não foi consultado (não esperado); a correspondência de IDs para Detalhes, Documentos, Push e IA usou o checklist de 13/10.
- Os textos de usuário do legado ainda trazem travessão (ex.: aviso da lápide em cab:311, `title` "Pago … clique para desfazer o recebimento" nos pagamentos, comentários e `title` da faixa de dados); foram citados aqui sem o caractere.


# Parte 4. BlueJus, backend (leitura, domínio, testes)



Worktree: `/home/prime/projetos/jusprime/.claude/worktrees/fidelidade-designer` (HEAD 9c081725). Somente leitura; nenhum teste rodado, nenhum banco tocado.
Caminhos abaixo relativos a `app/` salvo indicação. C = CONFIRMADO (código lido, arquivo:linha). H = HIPÓTESE (inferência, com motivo).

Legenda de autorização usada nas tabelas:
- **R:view / R:edit / R:delete** = `PermissionChecker::canAccessResource(user, tenant, 'pasta', id, ação)` (src/Service/PermissionChecker.php:64-90). Ordem: ROLE_SUPER_ADMIN passa; sem tenant nega; TenantRole `isSystem` passa; `ResourceAccess` do item; senão fallback `resources.pasta.<ação>` do TenantRole.
- **M:x** = `canAccessModule(..., 'x')`.
- **TF** = TenantFilter Doctrine (src/Shared/Doctrine/Filter/TenantFilter.php), ligado por request em src/Shared/EventListener/TenantFilterListener.php. Aplica-se também a `find()` (o persister chama `generateFilterConditionSQL` no `getSelectSQL`, vendor/doctrine/orm/src/Persisters/Entity/BasicEntityPersister.php:1146; provado por tests/Pasta/Functional/PastaExpedienteTenantFilterTest.php:37 `testFiltroFechaIdorDaPasta`). Vários comentários no código afirmam o contrário ("o TenantFilter não se aplica a find()"); são defesa redundante, não falha.
- **Tenant explícito** = `$pasta->getTenant() !== $tenant` → 404 antes de tudo.
- **Lápide** = `PastaSomenteLeituraListener` (src/Pasta/EventListener/PastaSomenteLeituraListener.php) recusa POST/PUT/PATCH/DELETE em pasta com `excluida_em`; exceções em `ROTAS_LIBERADAS` (linha 72: `pasta_restaurar`, `pasta_documentos_zip`, `inteligencia_analise_registro`).
- **Audit** = `AuditLogSubscriber` (src/Service/Audit/AuditLogSubscriber.php:45-104) grava insert/update/delete e coleções de toda entidade `Auditavel`. São Auditavel: Pasta, PastaDocumento, PastaSecao, PastaMensagem, PastaObservacaoDetalhes, PastaObservacaoFinanceira, PastaPagamento, PastaChecklistItem, PastaChecklistModelo(+Item), Tarefa, NotaTecnica, AnaliseDeInteligencia. NÃO são: Cliente, Processo, PastaProcesso, PastaFavorita, PastaDocumentoFavorito, PastaTimelineVisita.

---

### 1. Leitura da página (`pasta_show`)

| Item | Achado | Evidência |
|---|---|---|
| Rota | `pasta_show` GET `/pasta/{id}` | C src/Controller/PastaController.php:301 (controller legado, 2.608 linhas, prefixo `#[Route('/pasta')]` :133) |
| Resolução da pasta | `Pasta $pasta` por resolver de entidade (find por PK) com TF ligado. Pasta de outro escritório: `find()` devolve null, resolver responde **404** | C PastaController.php:303; TF + teste citado acima |
| Tenant explícito no show | **Não há** comparação explícita `pasta->getTenant() !== tenant` no show (só TF) | C PastaController.php:311-318 |
| Permissão exigida | `denyResourceAccessUnlessGranted(..., 'pasta', id, 'view', 'pasta_index')`. Sem permissão: redirect para `pasta_index?requestAccess=1...` (modal de pedido de acesso), não 403 | C PastaController.php:315; src/Controller/Trait/ResourceAccessTrait.php:12-34 |
| Módulo | Show NÃO exige `modules.pastas.view` (F1, aceito por design) | C docs/AUTORIZACAO.md §8 F1 |
| O que carrega | timeline (assembler), anotações (MENSAGEM) e eventosHistorico (EVENTO, vindos do audit_log), próximos prazos (`PastaPrazoOutput::proximas($pasta->getTarefas())`), marcadores do tenant, colaboradores ativos e fotos, docs de contrato, observações financeiras, pagamentos + correções de valor, observações de detalhes, checklist + conferência de anexos, seções + explorador (JSON com tokens CSRF), média de valor por CPF, push DJEN (por número CNJ, teto 100) + notas técnicas, análises de IA (só com M:inteligencia), vizinhas/posição no acervo, pendências, resumo de metas, unidade cobrada (cobrança) | C PastaController.php:320-499 |
| Repositórios com tenant explícito | `findByPasta($pasta, $tenant)` em observações, pagamentos, checklist, seções, docs; push por `listarItensPorNumerosDoTenant($tenant, ...)` | C PastaController.php:356-409 |
| Variáveis de permissão no Twig | `podeEditarPasta = can_access_resource('pasta', pasta.id, 'edit')` calculada no template (show.html.twig:44) e repassada aos parciais; cada parcial tem fallback igual. `podeExcluirPasta = can_access_resource('pasta', id, 'delete')` (_cabecalho.html.twig:41). `explorador.podeEditar = !estaExcluida && podeEditarPasta(pasta)` e `podeVerLixeira` vêm do controller (PastaController.php:389-390, helper :1354-1360). Metas: `podeConcluir = can_access_module('tarefas')` (_metas.html.twig:86), `podeAlertar = metaEditavel and podeEditarPasta` (:96). Push: `pushPodeEscrever = pastaViva and podeEditarPasta` (_push_processual.html.twig:28); `can_access_module('djen')` para link do push. IA: `can_access_module('inteligencia')` (_cabecalho.html.twig:369). Cobrança: `can_access_module('cobrancas')` (:501). Duplicar: `can_access_module('pastas')` (:213) | C (arquivos citados) |
| Financeiro | Não há permissão própria: quem tem R:view vê o financeiro (`VisibilidadeDoFinanceiroDaPasta::podeVer` = R:view). `modules.financeiro.view` é "futuro" | C src/Inteligencia/Service/VisibilidadeDoFinanceiroDaPasta.php:31-34 |
| Lápide no show | Pasta excluída abre normalmente (GET liberado); escrita bloqueada pelo listener; template esconde ações com `pasta.estaExcluida` | C docs/AUTORIZACAO.md §7 "Pasta excluída"; templates citados |

---


### 3. Prazos, audiências, réu e situação

#### 3.1 Prazo / audiência / agenda / evento / feriado

| O que existe | Onde | Serve ao painel "Prazos e audiências"? |
|---|---|---|
| **Prazo da meta** (`Tarefa.prazo`), editável só pelo criador | src/Tarefa/UseCase/AtualizarPrazoTarefaUseCase.php; rota `tarefa_atualizar_prazo` | Sim, é a única fonte de "prazo" persistida ligada à pasta |
| Cartão "Próximos prazos" | src/Pasta/DTO/PastaPrazoOutput.php (metas abertas ordenadas por prazo; meta sem prazo não entra) | Já é a agregação "Metas" do painel, só leitura |
| `StatusDoPrazoDaMeta`, `TextoDoPrazoDaMeta` | src/Tarefa/DTO/ | Formatação de selo |
| **Prazo extraído do Push** por regra (não IA) | src/Pasta/Service/DeterminacoesDoJuizo.php (só prazo explícito "prazo de N dias"; audiência detectada por regex :223 mas "registrada sem prazo" :242) | Fonte para "prazos do Push", hoje usada só nos documentos exigidos; não grava nada; não calcula data final (não há contagem de dias úteis) |
| **Agenda**: entidade `Evento` (titulo, dataInicio/Fim, local, status, cor, recorrência, visibilidade, participantes, tenant) | src/Entity/Agenda/Evento.php:16-89; src/Controller/AgendaController.php (M:agenda) | Poderia hospedar audiência, mas **não tem vínculo com Pasta nem Processo** (C: nenhum campo pasta/processo no arquivo). `EventoType` tem a opção "Audiência" como cor/legenda (src/Form/EventoType.php:41) |
| **Feriado** (nome, data, recorrente, tenant) | src/Ponto/Entity/Feriado.php; FeriadoController no Ponto; `SeedFeriadosNacionaisCommand` | Existe base para dias úteis, mas pertence ao Ponto |
| `EventoHistorico` | src/Cobranca/Entity/ | É histórico da Cobrança, não agenda |

Conclusão C: **não existe** entidade, tabela ou endpoint de "prazo manual da pasta" nem de "audiência" ligada à pasta. Criar/editar/cumprir/excluir prazo manual e publicar o agregado para outras telas é funcionalidade nova (entidade TenantAware + Auditavel, rotas com R:edit, CSRF, lápide). H: "datas escritas nos registros" exigiria parser novo sobre PastaMensagem/PastaObservacaoDetalhes; nada disso existe.

#### 3.2 Réu / parte adversa

| O que existe | Onde |
|---|---|
| `ParteProcesso` (tipo em MAIÚSCULAS, nome, documento, papel, processo, tenant) | src/Processo/Entity/ParteProcesso.php:15-36; preenchida pelo Datajud (`DatajudProcessoMapper.php:129`, tipo default `PARTE`) e no vincular processo (PastaController.php:2562) |
| Pasta | só `nomeCliente` e `nomeAcao` (texto livre, maiúsculas) (Pasta.php:66, :317-319); não há campo réu/parte adversa |

Conclusão C: réu só existe derivado das partes do **processo** (sem enum de polo; o polo vem em `tipo`/`papel` como string da fonte). Pasta sem processo não tem réu. Não há `ParteAdversa`/`Reu`/`Polo` em src.

#### 3.3 Situação da pasta

| Campo | Valores aceitos | Evidência |
|---|---|---|
| `situacao` (string) | `ativo`, `arquivado` | Pasta.php:30-31; EditarPastaUseCase.php:30; toggle PastaController.php:1504-1506. `setSituacao` não valida (Pasta.php:257), quem valida é o chamador |
| Exclusão | **lápide** por `excluida_em` (não é valor de situação); remoção real quando não há pastas posteriores | Pasta.php:89, :340; ExcluirPastaUseCase.php:78-101 |
| `situacaoContrato` | `PENDENTE`, `REGULAR` | AlterarSituacaoContratoUseCase.php:12 |
| `prioridade` (enum) | `normal`, `prioridade`, `urgente` | src/Pasta/Entity/PrioridadePasta.php:9-11 |
| `administrativa`, `proBono` | booleanos | Pasta.php:150, :160 |

Não existem **Suspenso** nem **Cancelado**. Se o Designer pedir, é mudança de domínio (e o toggle binário de `pasta_alternar_situacao` quebra).

---

### 4. Testes existentes da pasta_show

`app/tests/Pasta/` tem **307 arquivos** (Functional + Unit). Por área (amostra representativa, nomes completos em `ls app/tests/Pasta/*`):

| Área | Arquivos |
|---|---|
| Isolamento entre escritórios (dedicados) | PastaRepositoryIsolamentoTest, PastaExpedienteTenantFilterTest (find filtrado, marcador, listagem), PastaMensagemPosseDoEscritorioTest, PastaObservacaoPosseDoEscritorioTest, PastaSecaoPosseDoEscritorioTest, PeticionarIsolamentoControllerTest; fora da pasta: Tarefa/Functional/TarefaIsolamentoControllerTest, TarefaPosseDoEscritorioTest, MetaNaListaPosseDoEscritorioTest, Inteligencia/Functional/InteligenciaTenantFilterTest, Processo/Functional/NotaTecnicaRepositoryIsolamentoTest, Shared/Functional/TenantAwareCoberturaTest, Permission/Functional/ResourceAccessIsolamentoControllerTest |
| Lápide / somente leitura | PastaSomenteLeituraListenerTest, PastaSomenteLeituraPelaFilhaTest, PastaSomenteLeituraPorIdTest, PastaSomenteLeituraPelaMetaListenerTest, PastaSomenteLeituraMensagemDaMetaTest, PastaLapideTest, ExcluirPastaLapideTest, MetaNaLapideSemEscritaTelaTest, PastaExploradorSomenteLeituraTelaTest/JsTest, PastaDocumentosSugeridosSomenteLeituraTest; arquitetura: tests/Arquitetura/PastaSomenteLeituraRotasArquiteturaTest.php (percorre o router) |
| Permissão na tela | PastaCabecalhoPermissaoTelaTest, PastaClientesAcoesPermissaoTelaTest, PastaProcessoAcoesPermissaoTelaTest, PastaFinanceiroEscritaTelaTest, PastaDetalhesObsEscritaTelaTest |
| Controllers de escrita | EditarPastaControllerTest, AlterarPrioridadeControllerTest, PastaValorCausaControllerTest, PastaFinanceiroControllerTest, PastaPagamento*ControllerTest (3), PastaObservacao*ControllerTest (2), PastaMensagemControllerTest, PastaCliente*ControllerTest (4), VincularProcessoControllerTest, PastaDocumento*ControllerTest (5), PastaSecaoControllerTest, PastaChecklist*ControllerTest (2), PastaAdministrativaControllerTest, PastaFavoritoControllerTest, PastaDuplicarControllerTest, PastaPushLida/Teor/TextoControllerTest, PastaTimelineControllerTest, PastaResumo*ControllerTest |
| UseCases (Unit) | um por UseCase de src/Pasta/UseCase (Adicionar/Editar/Excluir/Toggle/Reordenar checklist, Enviar/Editar/Excluir mensagem e observações, Registrar/Parcelar/Corrigir pagamento, Excluir/Restaurar/Mover/Copiar itens, Duplicar, DefinirCliente/ProcessoPrincipal etc.) |
| Arranjo/tela (HTML) | ~100 arquivos `*TelaTest`, `*JsTest`, `*CssTest`, `*FolhaTest` (não testam aparência) |

Lacunas de teste (C pela busca, H quanto a existir com outro nome):
- Não achei teste funcional de **GET /pasta/{id} de outro escritório → 404** (o isolamento é provado só no nível do filtro, PastaExpedienteTenantFilterTest:37).
- Sem teste de outro escritório em: `pasta_alternar_situacao`, `pasta_alterar_prioridade`, `pasta_atualizar_responsavel`, `pasta_alternar_situacao_contrato`, `pasta_atualizar_pro_bono`, `pasta_financeiro_upload` (contagem por grep de path: 0 arquivos com "outro escritório/404").
- Sem teste que prove que `tarefa_concluir` / `tarefa_update_responsaveis` / `tarefa_show` recusam quem tem o módulo Tarefas mas não tem acesso à pasta (o contrário do que MetaNaListaControllerTest cobre).

---

### 5. Falhas conhecidas do docs/AUTORIZACAO.md que tocam a pasta_show

| Falha | Efeito na pasta_show | Estado |
|---|---|---|
| F1: show/edit/delete não checam módulo | `/pasta/{id}` abre com R:view sem `modules.pastas.view` | Aceito por design (§7, modelo paralelo) |
| F2: `canActOnResource` morto | `resources.pasta.*` no perfil vale como fallback genérico para TODAS as pastas do escritório | Vigente: quem tem `resources.pasta.edit` no TenantRole edita qualquer pasta |
| Bypass 2a/2b | ROLE_SUPER_ADMIN passa em tudo (inclusive sem tenant: `canAccessResource` devolve true antes de olhar tenant, PermissionChecker.php:66); TenantRole isSystem passa em tudo no escritório | Vigente. H: super admin sem tenant ativo tem TF desligado (TenantFilterListener: sem tenant, não liga), então rotas que confiam só no TF ficam sem filtro para ele; as que têm tenant explícito respondem 404/403 por `tenant === null` |
| Pasta sem guarda de módulo na sidebar (`pastas` sem item) | Expediente e Demandas são as portas; Demandas sem guarda | Vigente, fora da pasta_show |
| Doc §7 desatualizado | `ROTAS_LIBERADAS` tem 3 rotas no código (inclui `inteligencia_analise_registro`), o doc lista 2 | Drift de documentação (PastaSomenteLeituraListener.php:72) |
| "TenantFilter não cobre find()" | Afirmado em comentários de vários controllers (PastaDuplicarController:25, PastaPushProcessualController:67, NotaTecnicaController:39/:222 etc.) mas contradito pelo código do Doctrine e pelo teste PastaExpedienteTenantFilterTest:37 | Drift de comentário; inofensivo (gera checagem redundante) |

---


### Limitações

- Não rodei testes nem `debug:router`; as rotas foram lidas do código e cruzadas com `rotas-pasta.txt`. Não li os corpos de todos os UseCases (marcados H onde a regra está no UseCase e não foi aberta: posse da análise de IA, restauração).
- Não consultei banco: não sei quantos perfis têm `resources.pasta.*` como fallback, o que define o impacto de R1 e de F2.
- Não li `PastaController::novoCliente` inteiro (~200 linhas) nem `ExcluirPastaUseCase` inteiro; o que está na tabela vem dos trechos citados.
- A camada de tela (`inv-bluejus-tela.md`) não foi consultada; a lista de rotas acionáveis veio dos `path('...')` dos templates `pasta/show.html.twig` e `pasta/_*.html.twig` e das URLs do explorador. Rotas montadas em JS fora desses arquivos podem ter ficado de fora.
