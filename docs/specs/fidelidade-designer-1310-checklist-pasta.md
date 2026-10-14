# Checklist acumulado da Pasta — Claude Designer (13/10) × BlueJus

> **Para quem:** o dono e quem continuar a frente `fidelidade-designer`. Plano e evidências em
> `fidelidade-designer-1310.md`. Este arquivo é **acumulado**: cada entrega atualiza a marca do elemento
> (✓/≈/∅) em vez de abrir lista nova. Levantado em 14/10/2026 sobre a branch em `b97719d4`. Atualizações por entrega na §20
> (E2: raios, PZ-GLB-16; E3: nota técnica e marca IA; E4: Financeiro, responsável, situação).
>
> **∅ não quer dizer "fora".** Elemento sem backend continua na lista. A coluna *Backend?* diz o que
> falta para ele funcionar de verdade; a §19 diz quais dá para criar já como casca visual que não
> simula operação concluída.

## 0. Três achados antes da tabela

1. **O protótipo oficial mudou depois da auditoria do ledger.**
   - O ledger e os inventários (`docs/specs/engineering-loop/inventario-*.md`) auditaram `/home/prime/projetos/jusprime/docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html`, com 6.637 linhas.
   - O protótipo deste pedido é `/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo/02 - EXPEDIENTES 1.2.3.dc.html`, de 13/10, com 6.927 linhas.
   - O diff do template mostra elementos novos, sem ID no ledger. Na tabela eles aparecem como **NOVO**:
     - **"Andamento da pasta"**: uma trilha de fases na linha 2 do cabeçalho (dc:1276-1298, lógica dc:5990-6006 e 6162), com a faixa "Avançar para X? · Avançar · Ignorar" e cartões de sistema "Andamento da pasta · fase" no Registro e no Relatório financeiro.
     - **A faixa Processo · Ação · Responsável saiu do cabeçalho.** O Responsável foi para a linha 1 (dc:1155-1183). Ação e Processo vinculado viraram cartões no topo do trilho da aba Dados (dc:1398-1430). O link "trocar" sumiu e o vazio passou a dizer "abrir menu Processo".
     - **Botão "Vincular apensado"**, selo de apenso com X no cartão do processo e modal Vincular processo/apensado com "Tipo de apenso" (dc:285-349).
     - **No Push:** filtros por processo (principal e apensos) e interruptor de monitoramento por apenso. A "Situação atual" ficou oculta.
     - **Em Detalhes:** a caixa "Como usar esta aba" foi retirada.
     - **Novos scripts carregados:** `bj-dica.js` (dicas padronizadas) e `bj-contas.js` (multiconta).
2. **A "fonte pendente" do ledger (§0, o relatório do Farlei) chegou.**
   - Está em `/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/especificacao/ESPECIFICACAO_ORIGINAL_FARLEI_BLUEJUS.md`.
   - Ela decide itens que o ledger deixou em C ou D:
     - §3.7: destaque de comentários.
     - §3.9: o Valor da causa do Financeiro é o espelho dos Pagamentos.
     - §3.12: Master, primário e cadeado.
     - §3.3: primário sem limite de tempo.
   - Ela também conflita com o protótipo novo:
     - §3.2 pede os painéis na ordem Processo → Ação → Responsável.
     - §3.3 pede a caixa "Como usar esta aba".
   - A trilha de fases não aparece em nenhum pedido do Farlei. O §9 dessa especificação diz para não implementar como regra o que não tem pedido sem confirmar com ele.
3. **O catálogo do Chat I.A cresceu.** O ledger contou 197 comandos em 29 grupos (IA-037). `especificacao/COMANDOS_CHAT_IA.md` tem **321 comandos em 51 grupos**.

**Legenda e caminhos**

- Marcas: ✓ existe e está conforme · ≈ existe e diverge · ∅ ausente.
- Base do BlueJus: `/home/prime/projetos/jusprime/.claude/worktrees/fidelidade-designer/app/`. Abreviações:
  - Templates da pasta (`templates/pasta/`): `cab` = `_cabecalho.html.twig`, `show` = `show.html.twig`, `ano` = `_dados_anotacoes.html.twig`, `tri` = `_dados_trilho.html.twig`, `met` = `_metas.html.twig`, `proc` = `_processos_vinculados.html.twig`, `fin` = `_financeiro.html.twig`, `pag` = `_financeiro_pagamentos.html.twig`, `det` = `_detalhes_obs.html.twig`, `push` = `_push_processual.html.twig`, `teor` = `_push_teor.html.twig`.
  - Outros templates: `nt` = `templates/processo/_nota_tecnica_item.html.twig`, `base` = `templates/base.html.twig`.
  - JS: em `public/js/`.
- A coluna "Ledger" traz o ID e a classe (C = decisão do Samuel, D = especificação do Farlei, E = fora do escopo, F = feito).
- Acrescentei os prefixos PZ-TRI (trilho), PZ-EDT (editor) e PZ-VIS (visualizador). Dicas, toasts e atalhos ficaram em PZ-GLB.

## 1. Cabeçalho (PZ-CAB)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-CAB-01 | Linha 1 | Voltar `bi-arrow-left` | botão | ✓ | cab:48 | não | F |
| PZ-CAB-02 | Linha 1 | "PASTA nnnn" | texto | ✓ | cab:54 | não | F |
| PZ-CAB-03 | Linha 1 | Pílula de prioridade `bi-flag-fill` + menu (Normal/Prioridade/Urgente, ✓) | botão+menu | ✓ | cab:63-80 | não | F |
| PZ-CAB-04 | Linha 1 | Etiquetas `bi-tag-fill` que abrem Marcadores | chip | ✓ | cab:94-98 | não | F |
| PZ-CAB-05 | Linha 1 | "+" Marcadores da pasta | botão | ✓ | cab:105 | não | F |
| PZ-CAB-06 | Linha 1 | Chip do Responsável na linha 1 (avatar, nome 14.5px/700 cortado em 170px com reticências, chevron; dica "Responsável pela pasta: X · clique para alterar") | chip | ≈ corte em 170px ✓ (E4); continua na faixa (P1, aberta); a dica do BlueJus é só o nome | cab:481-488, `_resp_chip`; pasta-show.css `.ps-cab-dados … .pasta-resp-nome` | não | NOVO |
| PZ-CAB-07 | Linha 1 | Menu do responsável ("Filtrar responsável", `bi-check2`, "Nenhum colaborador encontrado") | listbox | ✓ | `_resp_menu` | não | PAS-013/013b F |
| PZ-CAB-08 | Linha 1 | ‹ `bi-chevron-left` · "N de M" · › | botão | ✓ | cab:127-150 | não | F |
| PZ-CAB-09 | ⋮ | Botão "Mais ações" `bi-three-dots-vertical` | menu ⋮ | ✓ | cab:181 | não | F |
| PZ-CAB-10 | ⋮ | Tarja "Pasta nnnn" `bi-folder2-open` | menu ⋮ | ✓ | cab:184 | não | F |
| PZ-CAB-11 | ⋮ | Editar dados (E) `bi-pencil` | item | ✓ | cab:192 | não | F |
| PZ-CAB-12 | ⋮ | Histórico (H) `bi-clock-history` | item | ✓ | cab:200 | não | F |
| PZ-CAB-13 | ⋮ | Timeline inteligente (T) `bi-activity` | item | ✓ | cab:207 | não | F |
| PZ-CAB-14 | ⋮ | Duplicar pasta `bi-files` | item | ✓ | cab:219 | não | F |
| PZ-CAB-15 | ⋮ | Vincular processo `bi-link-45deg` | item | ✓ | cab:226 | não | F |
| PZ-CAB-16 | ⋮ | Trocar responsável `bi-person-gear` | item | ✓ | cab:229 | não | F |
| PZ-CAB-17 | ⋮ | Mover para outra carteira `bi-folder-symlink` | item | ∅ | — | sim: carteira e regra de mover | PAS-007 C |
| PZ-CAB-18 | ⋮ | Copiar link (ícone vira `bi-check2`, "Link copiado") | item | ✓ | cab:236; pasta-show.js:435 | não | EDT-007 F |
| PZ-CAB-19 | ⋮ | Link externo do Push `bi-broadcast` + contagem de links ativos | item | ∅ | — | sim: link público, código, acessos | PAS-008/FPN-015 C |
| PZ-CAB-20 | ⋮ | Imprimir resumo `bi-printer` | item | ✓ | cab:246; `_impressao_modal` | não | EDT-001 F |
| PZ-CAB-21 | ⋮ | Seção "Acesso · Primário" + Cadeado (com selo) | item | ∅ | — | sim: perfil primário, cadeado | PAS-010 C |
| PZ-CAB-22 | ⋮ | Acompanhar alterações `bi-bell` + interruptor | item | ∅ | — | sim: seguir a pasta | PAS-009 C |
| PZ-CAB-23 | ⋮ | Fixar nos favoritos `bi-star` + interruptor | item | ✓ | cab:259 | não | F |
| PZ-CAB-24 | ⋮ | Arquivar/Desarquivar pasta | item | ✓ | cab:268 | não | F |
| PZ-CAB-25 | ⋮ | Excluir pasta `bi-trash3` (digitando o número) | item | ✓ | cab:289 | não | F |
| PZ-CAB-26 | Linha 2 | Foto do cliente: botão de 38px, menu Carregar/Ajustar/Remover e modal "Ajustar foto do cliente" (arrastar, zoom, girar 90°, Trocar imagem, Salvar foto) | botão+menu+modal | ≈ o ícone `bi-person-fill` não faz nada | cab:319 | sim: foto no cadastro | M5 PAS-005/PMC-052 C |
| PZ-CAB-27 | Linha 2 | Nome do cliente (h1); botão direito abre Detalhes | menu contexto | ✓ | cab:325; pasta-clientes.js:394 | não | PAS-001 F |
| PZ-CAB-28 | Linha 2 | Dica "Detalhes: clique com o botão direito" `bi-mouse2` | tooltip | ✓ | cab:357 | não | PAS-001 F |
| PZ-CAB-29 | Linha 2 | "Movimentado hoje, HH:MM" com ponto pulsante | texto | ✓ | cab:338 | não | F |
| PZ-CAB-30 | Linha 2 | Documento "CPF …" | texto | ✓ | cab:350 | não | F |
| PZ-CAB-31 | Linha 2 | BLUEJUS Intelligence (estrela SVG); tipografia do dc de 13/10 (BLUEJUS 10.5px/700/.12em #0a6fa0, Intelligence 14.5px) | botão | ✓ E3 (title trocado de propósito) | cab:366; pasta-ia-agentes.css:138 | não (a análise usa IA) | F |
| PZ-CAB-50 | Linha 2 | Foco do botão BLUEJUS Intelligence: o dc declara anel `0 0 0 3px rgba(10,122,173,.14)` (`style-focus`), mas o protótipo renderiza só o contorno do navegador; o BlueJus mostra o contorno + fundo `#e5f1f7` | estado | ≈ divergência registrada e **aceita** (dono, 14/10: preservar indicador visível e acessível); travada em teste | pasta-ia-agentes.css:134 | não | NOVO (E3) |
| PZ-CAB-32 | Linha 2 | Faixa "Pasta trancada" (motivo, "Pedir acesso ao primário", aguardando, recusado) | painel | ∅ | — | sim | PAS-010 C |
| PZ-CAB-33 | Linha 2 | Faixa "só visualizar" `bi-lock-fill` + "Pedir para editar" | painel | ∅ | — | sim | PAS-010 C |
| PZ-CAB-34 | Linha 2 | Selo carimbado ARQUIVADO (ondas) | selo | ✓ | cab:387 | não | F |
| PZ-CAB-35 | Linha 2 | Selo ATIVO por 3,3 s | selo | ✓ | cab:399 | não | PAS-002 F |
| PZ-CAB-36 | Linha 2 | Selos SUSPENSO e CANCELADO | selo | ∅ | — | sim: situações novas | PAS-006 C |
| PZ-CAB-37 | Linha 2 | Selo do cadeado | selo | ∅ | — | sim | PAS-010 C |
| PZ-CAB-38 | Linha 2 | "Andamento da pasta": trilha de fases (ícone por fase, data, nº de registros, "N dias", "(atual)", duração total, fase pulada em vermelho, finalizada em verde, rolagem) | painel | ∅ | — | sim: fase, datas, regra das fases | NOVO |
| PZ-CAB-39 | Linha 2 | Clique na fase com confirmação ("Avançar/Voltar fase", "Finalizar pasta", reativar) | modal | ∅ | — | sim | NOVO |
| PZ-CAB-40 | Linha 2 | Faixa "Avançar para X?" + motivo + Avançar/Ignorar | painel | ∅ | — | sim | NOVO |
| PZ-CAB-41 | Faixa | Faixa Processo vinculado · Ação · Responsável (· Cobrança) no cabeçalho | painel | ≈ o desenho novo tirou a faixa daqui (ver PZ-CAB-06 e PZ-TRI-01/02) | cab:407-506 | não | NOVO (o F do ledger era do desenho antigo) |
| PZ-CAB-42 | Faixa | Link "trocar" `bi-arrow-left-right` | link | ≈ extra; o desenho novo tirou | cab:427 | não | NOVO |
| PZ-CAB-43 | Linha 3 | Abas com indicador deslizante e modo compacto | aba | ✓ | cab:521-553 | não | F |
| PZ-CAB-44 | Linha 3 | Selo "0" nas abas | chip | ≈ o 0 some | cab:551 | não | PAS-003 C |
| PZ-CAB-45 | Linha 3 | Linha vermelha animada de pendência | ícone | ≈ some na aba ativa (no desenho fica a 45%) | cab:547; pasta-show.css:1219-1232 | não | — |
| PZ-CAB-46 | Linha 3 | Botão direito na aba: Ocultar para toda a equipe / Ocultar para… / Mostrar para todos; indicador `bi-eye-slash`; aviso para quem não é primário; itens do link externo na aba Push | menu contexto | ∅ | — | sim: visibilidade por usuário | PAS-011/PAS-008 C |
| PZ-CAB-47 | Linha 3 | Cadastro `bi-person-vcard` com ponto verde/âmbar | botão | ≈ abre Detalhes do cliente, não o modal de cadastro | cab:572 | sim | PAS-012 C |
| PZ-CAB-48 | Linha 3 | Pílula e menu de Situação (4 opções com descrição e ✓) | botão+menu | ≈ só 2 opções | cab:590 | sim | PAS-006 C |
| PZ-CAB-49 | Linha 3 | Faixa "Pasta excluída" (extra do sistema) | painel | ✓ | cab:~300 | não | F |

## 2. Trilho direito da aba Dados (PZ-TRI)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-TRI-01 | Trilho | Cartão "Ação" `bi-bank2` ("Extraído do processo vinculado") | painel | ≈ fica na faixa do cabeçalho | cab:468 | não | NOVO |
| PZ-TRI-02 | Trilho | Cartão "Processo vinculado": chip de apensos com tipo, nº e ação; vazio "Nenhum processo. abrir menu Processo" | painel | ≈ fica no cabeçalho; o chip é "N vinculados", sem tipo; o vazio diz "vincular" | cab:410-466 | sim: tipo de apenso | NOVO |
| PZ-TRI-03 | Trilho | Próximos prazos: contagem sempre visível + chevron | painel | ≈ o 0 some (também em Clientes e Documentos) | tri:24-30 | não | PAS-004 C |
| PZ-TRI-04 | Trilho | Linha de prazo; botão direito abre o ⋮ | menu contexto | ✓ | tri:76; pasta-clientes.js:415 | não | F |
| PZ-TRI-05 | ⋮ prazo | Ver nas metas `bi-check2-square` | item | ✓ | tri:80 | não | F |
| PZ-TRI-06 | ⋮ prazo | Encaminhar via Chat I.A `bi-send` | item | ∅ | — | sim: Chat | PAS-027 C |
| PZ-TRI-07 | ⋮ prazo | Alertar <nome> `bi-bell` | item | ✓ (um por responsável) | tri:87 | não | PAS-038 F |
| PZ-TRI-08 | ⋮ prazo | Adicionar à agenda (.ics) | item | ✓ | tri:99 | não | F |
| PZ-TRI-09 | ⋮ prazo | Copiar | item | ✓ | tri:105 | não | F |
| PZ-TRI-10 | Trilho | Clientes: "+", busca "Nome ou CPF do cliente", "Cliente ainda não cadastrado" e Cadastrar cliente | painel | ✓ | tri:146-165 | não | F |
| PZ-TRI-11 | Trilho | Linha do cliente: iniciais, ponto do cadastro, Principal, CPF, "Abrir"; botão direito abre Detalhes | painel | ✓ | `_cliente_linha` | não | F |
| PZ-TRI-12 | Trilho | Selo "CPF duplicado" | chip | ∅ | — | — | PMC-053 E |
| PZ-TRI-13 | Trilho | Estrela e lixeira visíveis na linha do cliente (extra) | botão | ≈ | `_cliente_linha` | — | PMC-051 C |
| PZ-TRI-14 | Trilho | Documentos: ícone de 30px por tipo, abre o visualizador | painel | ✓ | tri:232-262 | não | F |

## 3. Aba Dados da pasta: Registro (PZ-DAD)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-DAD-01 | Registro | "Registro dos expedientes" + contagem com dica | painel | ✓ | ano:108-109 | não | F |
| PZ-DAD-02 | Registro | Compositor "Adicionar…", "Visível para a equipe", Enviar desabilitado se vazio | painel | ✓ | ano:134-138 | não | EDT-021 F |
| PZ-DAD-03 | Registro | Linha do tempo (pílula do dia, ponto, seta) | painel | ✓ | ano | não | F |
| PZ-DAD-04 | Cartão | Avatar, autor, hora, megafone | painel | ✓ | ano:308,319 | não | F (iniciais: PAS-036 C) |
| PZ-DAD-05 | Cartão | Presença do autor (ponto + status) | ícone | ∅ | — | sim: presença | PAS-028 C |
| PZ-DAD-06 | Cartão | Etiqueta do registro | chip | ∅ | — | sim: coluna | PAS-029 D |
| PZ-DAD-07 | Cartão | "editado" com dica de publicação e última edição | tooltip | ✓ | ano:266 | não | PAS-033 F |
| PZ-DAD-08 | Cartão | Lápis com dica "Editável por mais N min" | botão | ✓ | ano:275 | não | PAS-037 F |
| PZ-DAD-09 | Cartão | Responder `bi-reply-fill` (no hover) | botão | ✓ | ano:298 | não | F |
| PZ-DAD-10 | Cartão | Encaminhar via Chat I.A (no hover) | botão | ∅ | — | sim: Chat | PAS-027 C |
| PZ-DAD-11 | Cartão | Caixa de resposta sob o cartão (Ctrl+Enter, Esc) | painel | ✓ | ano/show | não | PAS-030 F |
| PZ-DAD-12 | Cartão | Resposta recuada "Resposta a NOME" | painel | ✓ | ano:255 | não | F |
| PZ-DAD-13 | Cartão | Destaque de cor (15 cores) | painel | ∅ | sim: destaque persistido | PAS-026 C (decidido no Farlei §3.7) |
| PZ-DAD-14 | Registro | Cartão de sistema "Andamento da pasta · fase" `bi-signpost-split` | painel | ∅ | — | sim: evento de fase | NOVO |
| PZ-DAD-15 | Registro | "Ver anotações anteriores (N)" | botão | ✓ | ano:191 | não | F |
| PZ-DAD-16 | Cartão | ⋮ no cartão (caminho de toque; extra) | menu ⋮ | ✓ | ano:311 | não | PAS-025 F |
| PZ-DAD-17 | Registro | @menção com notificação | painel | ✓ | pasta-mencoes.js | não | F |

## 4. Aba Processo (PZ-PRO)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-PRO-01 | Cabeçalho | "Processos vinculados" + contagem | painel | ✓ | proc:69 | não | F |
| PZ-PRO-02 | Cabeçalho | Interruptor "Administrativo sem processo" | botão | ✓ | proc:85 | não | F |
| PZ-PRO-03 | Cabeçalho | ⋮ do cabeçalho; também abre pelo botão direito sobre o próprio ⋮ | menu ⋮ | ≈ abre só pelo clique | proc:107 | não | — |
| PZ-PRO-04 | ⋮ cabeçalho | Relatório geral de processos `bi-file-earmark-bar-graph` | item | ∅ | — | sim: varre o escritório | PMC-015 D |
| PZ-PRO-05 | ⋮ cabeçalho | Enviar à Controladoria agora | item | ∅ | — | sim | PMC-016 C |
| PZ-PRO-06 | ⋮ cabeçalho | Marcar/Desmarcar administrativo | item | ✓ | proc:107 | não | F |
| PZ-PRO-07 | Cabeçalho | Vincular processo | botão | ✓ | proc:114 | não | F |
| PZ-PRO-08 | Cabeçalho | Vincular apensado `bi-paperclip` | botão | ∅ | — | sim: tipo de apenso | NOVO |
| PZ-PRO-09 | Cabeçalho | Peticionar na pasta | botão | ✓ | proc:120 | não | F |
| PZ-PRO-10 | Cartão | Pílula "Vinculado em", nº CNJ + copiar, Principal, ⋮; botão direito no cartão | painel | ✓ | proc; pasta-processo.js:235 | não | PMC-001 F |
| PZ-PRO-11 | Cartão | Selo do apenso + X "Desvincular este apensado" | chip | ∅ | — | sim | NOVO |
| PZ-PRO-12 | Cartão | "Ação" junto de Classe, Tribunal e Situação | texto | ≈ sem Ação | proc | não | PMC-006 C |
| PZ-PRO-13 | Cartão | Órgão, Valor e Distribuição com "Não informado" | texto | ✓ (rótulo: PMC-008 C) | proc | não | PMC-007 F |
| PZ-PRO-14 | Cartão | Marcas (Justiça gratuita, Tutela, Prioridade, Segredo, 100% digital) | chip | ∅ | — | sim: PJe | PMC-010 B |
| PZ-PRO-15 | Cartão | Linha de fonte do PJe, "Atualizar", "Buscando…". Sem integração o protótipo mostra o aviso honesto "Integração com o PJe não configurada. Preencha manualmente." `bi-cloud-slash` — esse estado é só texto | painel | ∅ | — | sim (o aviso estático: não) | PMC-012 B |
| PZ-PRO-16 | Cartão | Edição inline com selo "manual" | painel | ∅ | — | sim | PMC-005 C |
| PZ-PRO-17 | Cartão | Painel "Ver todas as informações" | painel | ✓ | proc:333 | não | F |
| PZ-PRO-18 | ⋮ processo | Adicionar nota técnica | item | ✓ | proc:207 | não | F |
| PZ-PRO-19 | ⋮ processo | Abrir no PJe | item | ≈ virou "Ver processo completo" | proc:211 | sim: URL do PJe | PMC-011 B |
| PZ-PRO-20 | ⋮ processo | Ver todas as informações / Mostrar menos | item | ✓ | proc:216 | não | F |
| PZ-PRO-21 | ⋮ processo | Editar dados do processo | item | ≈ vai à página processo_edit | proc:225 | sim (inline) | PMC-004 F / PMC-005 C |
| PZ-PRO-22 | ⋮ processo | Atualizar dados do PJe | item | ∅ | — | sim | PMC-013 B |
| PZ-PRO-23 | ⋮ processo | Ver movimentações (Push) | item | ✓ | proc:230 | não | F |
| PZ-PRO-24 | ⋮ processo | Copiar número | item | ✓ | proc:235 | não | F |
| PZ-PRO-25 | ⋮ processo | Copiar resumo do processo | item | ✓ | proc:239 | não | F |
| PZ-PRO-26 | ⋮ processo | Compartilhar | item | ✓ | proc:247 | não | EDT-006 F |
| PZ-PRO-27 | ⋮ processo | Encaminhar via Chat I.A | item | ∅ | — | sim | PMC-014 C |
| PZ-PRO-28 | ⋮ processo | Relatório geral de processos | item | ∅ | — | sim | PMC-015 D |
| PZ-PRO-29 | ⋮ processo | Imprimir resumo | item | ✓ | proc:255 | não | EDT-005 F |
| PZ-PRO-30 | ⋮ processo | Desvincular da pasta (o BlueJus tem a mais "Tornar processo principal", proc:264) | item | ✓ | proc:274 | não | F |
| PZ-PRO-31 | Notas | Nota técnica sob o cartão (autor, "hoje, HH:MM · editada"); caixa e editor #f6fafd, borda #b9d6e8, 4px; conector 1.5px #c9dfec; selo segue #0a5f86/3px | painel | ✓ E3 | nt; nota-tecnica.css | não | F |
| PZ-PRO-41 | Cartão | Situação do processo legível ("Em andamento") no cartão, no painel "Ver todas", no Copiar/Compartilhar/Imprimir resumo e no PDF; mapeamento explícito dos 4 valores do cadastro, desconhecido aparece como veio; dado gravado intacto | texto | ✓ E4 | proc:159/301/384; `SituacaoProcessoRotulo`; PastaResumoController | não | NOVO (E3) |
| PZ-PRO-32 | ⋮ nota | Responder com outra nota | item | ✓ | nt | não | PMC-057 F |
| PZ-PRO-33 | ⋮ nota | Copiar texto | item | ✓ | nt | não | PMC-017 F |
| PZ-PRO-34 | ⋮ nota | Encaminhar via Chat I.A | item | ∅ | — | sim | PMC-018 C |
| PZ-PRO-35 | ⋮ nota | Editar / Editar · bloqueado | item | ✓ | nt | não | PMC-017 F |
| PZ-PRO-36 | ⋮ nota | Excluir / Excluir · bloqueado | item | ✓ | nt | não | PMC-017 F |
| PZ-PRO-37 | Notas | Editor da nota "…Use @ para mencionar alguém." com @menção | painel | ✓ | `processo/_notas_tecnicas`:92 | não | PMC-019 F |
| PZ-PRO-38 | Notas | Primário edita e exclui sem limite | regra | ∅ | — | sim | PMC-021 C (decidido no Farlei §3.12) |
| PZ-PRO-39 | Notas | Faixa "Notas técnicas 0" sob todo cartão | painel | ≈ | `_notas_tecnicas` | não | PMC-009 C |
| PZ-PRO-40 | Aba | Vazios e nota do rodapé | painel | ✓ | proc:453,471 | não | F |

## 5. Aba Metas (PZ-MET)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-MET-01 | Aba | "Metas da pasta" | painel | ✓ | met:101 | não | F |
| PZ-MET-02 | Aba | Filtros Abertas · Atrasadas · Concluídas · Todas | chip | ✓ | met | não | F |
| PZ-MET-03 | Aba | Nova meta `bi-plus-lg` | botão | ✓ | met:119 | não | F |
| PZ-MET-04 | Linha | Estado, número local, título, autor, todos os responsáveis, prazo, pílula | painel | ✓ | met | não | PMC-036 F |
| PZ-MET-05 | Linha | Nº global "Nº 0042" | texto | ∅ | — | — | PMC-035 E |
| PZ-MET-06 | Linha | Editar nome inline (Salvar/Cancelar) | painel | ✓ | met | não | F |
| PZ-MET-07 | Linha | Sino "Alertar para verificar" (fica laranja, "Alertado: X às HH:MM") | botão | ✓ | met:197 | não | F |
| PZ-MET-08 | Menu do sino | Responsáveis "· responsável" | item | ✓ | met:206 | não | F |
| PZ-MET-09 | Menu do sino | Separador + demais usuários | item | ∅ | — | sim: regra | PMC-034 C |
| PZ-MET-10 | ⋮ meta / botão direito | Abrir relatório | item | ✓ | met:220; pasta-metas.js:818 | não | PMC-027 F |
| PZ-MET-11 | ⋮ meta | Marcar como concluída / Reabrir meta | item | ✓ | met:228-235 | não | F |
| PZ-MET-12 | ⋮ meta | Editar nome | item | ✓ | met:241 | não | F |
| PZ-MET-13 | ⋮ meta | Duplicar `bi-copy` | item | ∅ | — | sim | PMC-032 C |
| PZ-MET-14 | ⋮ meta | Encaminhar via Chat I.A | item | ∅ | — | sim | PMC-033 C |
| PZ-MET-15 | ⋮ meta | Copiar link | item | ✓ | met:247 | não | PMC-028 F |
| PZ-MET-16 | ⋮ meta | Abrir em Metas | item | ≈ destino é tarefa_show | met:254 | não | PMC-037 C |
| PZ-MET-17 | ⋮ meta | Excluir | item | ✓ | met:262 | não | PMC-030 F |
| PZ-MET-18 | Aba | Toast com "Desfazer" ao concluir ou reabrir | toast | ✓ | pasta-metas.js | não | PMC-031 F |
| PZ-MET-19 | Aba | Drawer "Relatório da meta" (▲▼, Esc, Histórico, Outras metas) | painel | ✓ | met:360 | não | F |
| PZ-MET-20 | Aba | Vazios | painel | ✓ | met | não | F |
| PZ-MET-21 | Trilho | Andamento das metas | painel | ✓ | met:302 | não | F |
| PZ-MET-22 | Trilho | Precisa de atenção | painel | ✓ | met:318 | não | F |
| PZ-MET-23 | Trilho | Responsáveis nas metas | painel | ✓ | met:338 | não | F |
| PZ-MET-24 | Trilho | Botão de chat por pessoa `bi-chat-dots` | botão | ∅ | — | sim | PMC-046 C |
| PZ-MET-25 | Aba | Meta nova realçada, fica na aba | painel | ✓ | pasta-metas.js | não | PMC-044 F |

## 6. Aba Financeiro (PZ-FIN)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-FIN-01 | Faixa | Contrato (selo Pendente ⇄ Assinado) | botão | ✓ | fin:63 | não | F |
| PZ-FIN-02 | Faixa | Pró-bono | botão | ✓ | fin:96 | não | F |
| PZ-FIN-03 | Faixa | "Valor da causa" = soma dos pagamentos, só leitura ("total dos pagamentos · sincronizado") | painel | ≈ é "Honorários contratuais", editável | fin:136-164 | sim: regra | FPN-001 C (decidido no Farlei §3.9) |
| PZ-FIN-04 | Faixa | Média por CPF | painel | ✓ | fin:169 | não | F |
| PZ-FIN-05 | Relatório | Título, contador, compositor "Adicionar observação…" | painel | ≈ placeholder "Registrar combinado…" | fin:198,225 | não | FPN-004 C |
| PZ-FIN-40 | Relatório | Lista de observações sem rolagem interna (cresce com a página) | painel | ✓ E4 | fin:237; pasta-show.css `.ps-fin-obs-rolagem` | não | NOVO (dc 13/10 L1890) |
| PZ-FIN-06 | Cartão | editado, lápis, Responder e caixa de resposta | painel | ✓ | fin | não | M1 FPN-005 F |
| PZ-FIN-07 | Cartão | Presença do autor | ícone | ∅ | — | sim | FPN-007 C |
| PZ-FIN-08 | Cartão | Encaminhar (no hover) | botão | ∅ | — | sim | FPN-007 C |
| PZ-FIN-09 | Relatório | Cartão de sistema "Andamento da pasta" | painel | ∅ | — | sim | NOVO |
| PZ-FIN-10 | Botão direito no registro | Responder | item | ✓ | fin:297 | não | FPN-006 F |
| PZ-FIN-11 | Botão direito no registro | Copiar texto | item | ✓ | fin:301 | não | F |
| PZ-FIN-12 | Botão direito no registro | Encaminhar via Chat I.A | item | ∅ | — | sim | FPN-007 C |
| PZ-FIN-13 | Botão direito no registro | Destacar / Remover destaque | item | ∅ | — | sim | D-DESTAQUE C |
| PZ-FIN-14 | Botão direito no registro | Editar · N min / bloqueado | item | ✓ | fin:306 | não | FPN-006b/C7-FPN-02 F |
| PZ-FIN-15 | Botão direito no registro | Excluir comentário · N min / bloqueado | item | ✓ | fin:309 | não | F |
| PZ-FIN-16 | Arquivos | Contagem, Adicionar, até 3 + "ver os outros N" | painel | ✓ | fin:328 | não | F |
| PZ-FIN-17 | Arquivos | Selo "Assinado" no arquivo | chip | ∅ | — | sim | FPN-003 D |
| PZ-FIN-18 | ⋮ arquivo (e botão direito) | Abrir | item | ✓ | fin:399 | não | F |
| PZ-FIN-19 | ⋮ arquivo | Baixar | item | ✓ | fin:404 | não | F |
| PZ-FIN-20 | ⋮ arquivo | Copiar nome do arquivo | item | ✓ | fin:409 | não | F |
| PZ-FIN-21 | ⋮ arquivo | Encaminhar via Chat I.A | item | ∅ | — | sim | FPN-007 C |
| PZ-FIN-22 | ⋮ arquivo | Enviar por e-mail ao cliente (mailto) | item | ✓ | fin:428 | não | F |
| PZ-FIN-23 | ⋮ arquivo | Ver em Documentos (o BlueJus tem a mais Renomear, fin:421) | item | ✓ | fin:440 | não | F |
| PZ-FIN-24 | ⋮ arquivo | Excluir | item | ✓ | fin:451 | não | F |
| PZ-FIN-25 | Arquivos | Interruptor "Reduzir tamanho ao enviar" | botão | ✓ | fin:479 | não | F |
| PZ-FIN-26 | Pagamentos | Contagem, progresso, Próximos vencimentos, estados | painel | ✓ | fin:501 | não | F |
| PZ-FIN-27 | ⋮ Pagamentos | Adicionar pagamento | item | ✓ | fin:541 | não | F |
| PZ-FIN-28 | ⋮ Pagamentos | Editar ou corrigir valores / Concluir edição | item | ✓ | fin:544 | não | F |
| PZ-FIN-29 | ⋮ Pagamentos | Ver todos os lançamentos / Mostrar só os próximos | item | ✓ | fin:548 | não | F |
| PZ-FIN-30 | ⋮ Pagamentos | Copiar resumo | item | ✓ | fin:552 | não | F |
| PZ-FIN-31 | ⋮ Pagamentos | Imprimir extrato | item | ✓ | fin:555 | não | EDT-004 F |
| PZ-FIN-32 | ⋮ Pagamentos | Botão direito sobre o próprio ⋮ | menu contexto | ≈ só o clique abre | fin:541 | não | — |
| PZ-FIN-33 | Pagamentos | Botão "Concluir edição" | botão | ✓ | fin:522 | não | F |
| PZ-FIN-34 | Pagamentos | Correção inline (Enter/Esc), "corrigido · era R$ X" | painel | ✓ | `_financeiro_pagamentos_script` | não | F |
| PZ-FIN-35 | Botão direito na linha | Corrigir valor | item | ✓ | pag:139 | não | F |
| PZ-FIN-36 | Botão direito na linha | Marcar como recebido / Desfazer recebimento | item | ✓ | pag | não | F |
| PZ-FIN-37 | Botão direito na linha | Excluir lançamento | item | ✓ | pag:151 | não | FPN-008 F |
| PZ-FIN-38 | Pagamentos | Sufixo "· juros N% a.m." | texto | ✓ | `PastaPagamentoLinhaOutput` | não | M7 F |
| PZ-FIN-39 | Pagamentos | Vazio "Nenhum lançamento…" | painel | ✓ | pag | não | F |

## 7. Aba Documentos e explorador (PZ-DOC)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-DOC-01 | Aba | Cabeçalho e rodapé | painel | ✓ | `_documentos_explorador` | não | F |
| PZ-DOC-02 | Aba | Busca "Buscar arquivos e pastas…" + limpar | campo | ≈ busca global, não só no nível aberto | pasta-explorador.js | não | DOC-125 C |
| PZ-DOC-03 | Aba | Nova pasta, ANEXAR `bi-paperclip` | botão | ✓ | explorador | não | DOC-115 F |
| PZ-DOC-04 | Aba | Organizar (ordem, classificar, colunas, tipo, restaurar) | menu | ✓ | explorador | não | F |
| PZ-DOC-05 | Aba | Visualizar (8 modos + painel) | menu | ✓ | explorador | não | F |
| PZ-DOC-06 | Checklist | Faixa, selo, barra, desativar com motivos, +/✎, remover | painel | ✓ | `_documentos_checklist` | não | F |
| PZ-DOC-07 | Checklist | Avisar a Controladoria, cobrança automática, selo "cobrado Nx" | painel | ∅ | — | sim | DOC-126/127/128 C |
| PZ-DOC-08 | Sugeridos | Painel, Atualizar, chips, grupos, Origem, "Possíveis duplicados" | painel | ✓ | `_documentos_sugeridos` | não | DOC-106/107 F |
| PZ-DOC-09 | Sugeridos | Camada de IA e leitura da capa do PDF | painel | ∅ | — | sim | DOC-131/138 B |
| PZ-DOC-10 | Aba | Filtro "Mostrando somente" | chip | ✓ | explorador | não | F |
| PZ-DOC-11 | Aba | Barra de seleção (6 botões, Limpar seleção) | painel | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-12 | Aba | Trilha com ↑ (Backspace) | botão | ✓ | explorador | não | F |
| PZ-DOC-13 | Aba | Faixa de limpeza (`bi-stars` "Ganhe espaço", Revisar, Agora não) | painel | ≈ `bi-hdd` + "por regras" | explorador | não | DOC-104 C |
| PZ-DOC-14 | Lista | 8 modos, favoritos, ⋮ no toque, laço, arraste, colunas | painel | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-15 | Botão direito no item | Abrir, Visualizar, Baixar ou Baixar como .zip, Recortar, Copiar, Colar, Copiar caminho, Renomear, Excluir, Propriedades (o BlueJus tem a mais Mover para…, Editar…) | menu contexto | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-16 | Botão direito | "Compartilhar link(s)" | item | ≈ "Copiar link(s)" | pasta-explorador.js | não | S-12 F |
| PZ-DOC-17 | Botão direito | Encaminhar via Chat I.A | item | ∅ | — | sim | DOC-130 C |
| PZ-DOC-18 | Botão direito com vários itens | Baixar .zip (N), Recortar, Copiar, Copiar caminhos, Excluir N itens, Propriedades | menu contexto | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-19 | Botão direito no fundo | Nova pasta, Colar, Selecionar tudo, Mostrar/Ocultar painel | menu contexto | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-20 | Aba | Toast de 4,2 s com Desfazer | toast | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-21 | Aba | Painel de propriedades | painel | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-22 | Aba | Atalhos (Enter, Espaço, Del, F2, Ctrl+A/C/X/V, Backspace, setas) | atalho | ✓ | pasta-explorador.js | não | F |
| PZ-DOC-23 | Aba | Lixeira (N) (extra) | botão | ✓ | explorador | não | DOC-101 F |
| PZ-DOC-24 | Aba | Escrita escondida de quem só lê e na lápide | regra | ✓ | explorador | não | DOC-118 F |
| PZ-DOC-25 | Aba | Vazio "Pasta vazia… Colar (Ctrl+V)" | painel | ✓ | pasta-explorador.js | não | DOC-105 F |

## 8. Aba Detalhes (PZ-DET)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-DET-01 | Aba | "Relatório inicial de Atendimento" | painel | ✓ | det:71 | não | F |
| PZ-DET-02 | Aba | Compositor "Adicionar observação…" | painel | ✓ | det:96 | não | F |
| PZ-DET-03 | Cartão | editado, lápis, Responder, caixa de resposta, ⋮ "Mais ações da observação" | painel | ✓ | det | não | M2 F |
| PZ-DET-04 | Cartão | Presença do autor | ícone | ∅ | — | sim | PAS-028 C |
| PZ-DET-05 | Cartão | Encaminhar (no hover) | botão | ∅ | — | sim | PAS-027 C |
| PZ-DET-06 | ⋮ / botão direito | Editar · N min | item | ✓ | det:375 | não | PAS-041 F |
| PZ-DET-07 | ⋮ | Copiar texto | item | ✓ | det:357 | não | F |
| PZ-DET-08 | ⋮ | Encaminhar via Chat I.A | item | ∅ | — | sim | C |
| PZ-DET-09 | ⋮ | Citar no novo registro `bi-quote` | item | ✓ | det:360 | não | EDT-009 F |
| PZ-DET-10 | ⋮ | Criar meta a partir deste registro | item | ✓ | det:363 | não | PAS-041 F |
| PZ-DET-11 | ⋮ | Enviar no Chat I.A `bi-chat-dots` | item | ∅ | — | sim | D-CONNECT C |
| PZ-DET-12 | ⋮ | Imprimir | item | ✓ | det:369 | não | EDT-003 F |
| PZ-DET-13 | ⋮ | Excluir comentário · N min / bloqueado | item | ✓ | det:380 | não | F |
| PZ-DET-14 | ⋮ | Destacar | item | ∅ | — | sim | D-DESTAQUE C |
| PZ-DET-15 | Cartão | "continuar lendo" | botão | ✓ | pasta-ler-mais.js | não | F |
| PZ-DET-16 | Trilho | Registro da pasta (Criada em, Modificada em, Criada por) | painel | ✓ | show:282 | não | F |
| PZ-DET-17 | Trilho | Ver histórico do sistema | botão | ✓ | show:309 | não | F |
| PZ-DET-18 | Trilho | "Pontos do relatório inicial" + "Completar no relatório" | painel | ∅ | — | sim | PAS-042 C |
| PZ-DET-19 | Trilho | "Como usar esta aba" | painel | ≈ o BlueJus tem; o desenho novo tirou (o Farlei §3.3 pede) | show:314 | não | NOVO |

## 9. Aba Push (PZ-PUSH)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-PUSH-01 | Faixa | "Movimentações recebidas" + contagem | painel | ✓ | push:31 | não | F |
| PZ-PUSH-02 | Faixa | Resumir com IA / Gerar nova análise | botão | ✓ | push:54 | sim (IA) | F |
| PZ-PUSH-03 | Faixa | Filtros Todas · Novas · Geram prazo | chip | ✓ | push:93-95 | não | FPN-032 F |
| PZ-PUSH-04 | Faixa | Filtros por processo ("rótulo · N", principal e apensos) | chip | ∅ | — | não para os processos já vinculados; sim para o tipo de apenso | NOVO |
| PZ-PUSH-05 | Análise IA | Cartão: cadeado, recolher com prévia, "lido" | painel | ✓ | `inteligencia/_analises_push` | não | IA-010 F |
| PZ-PUSH-06 | ⋮ análise | Editar, com o editor (resumo, pontos, "Conteúdo originalmente gerado por IA…") | item | ∅ | — | sim | IA-009/FPN-030 B |
| PZ-PUSH-07 | ⋮ análise | Marcar como lido / não lido | item | ✓ | idem | não | IA-011 F |
| PZ-PUSH-08 | ⋮ análise | Criar tarefa da providência | item | ✓ | idem | não | F |
| PZ-PUSH-09 | ⋮ análise | Excluir análise | item | ✓ | idem | não | F |
| PZ-PUSH-10 | Análise IA | Enviar ao cliente + histórico de envios | botão | ∅ | — | sim | IA-028 C |
| PZ-PUSH-11 | Cartão | Pílula do dia, ponto colorido com dica | ícone | ✓ (polo ≈ D) | push | não | FPN-019 F |
| PZ-PUSH-12 | Cartão | Título = teor em caixa alta, NOVO, megafone | painel | ✓ | push | não | FPN-012 F |
| PZ-PUSH-13 | Cartão | "ID - Tipo" com Copiar ID e Abrir no PJe à vista | painel | ≈ só dentro do teor | teor:52-65 | não | FPN-013 C |
| PZ-PUSH-14 | Cartão | Botão Encaminhar `bi-send` | botão | ∅ | — | sim | FPN-016 C |
| PZ-PUSH-15 | Cartão | Ações à vista (Criar tarefa, Nota técnica, Lida, hora) | botão | ≈ dentro do teor | teor:75-90 | não | FPN-013 C |
| PZ-PUSH-16 | Cartão | Criar tarefa em 1 clique / "Tarefa já criada" | botão | ≈ abre o modal preenchido | teor:82 | sim | FPN-023 D |
| PZ-PUSH-17 | Cartão | "Marcar como lida" / "Lida" `bi-check2-all` | botão | ≈ "Marcar como não lida" `bi-envelope` | teor:85-91 | não | FPN-017 C |
| PZ-PUSH-18 | Notas | Notas penduradas sob o cartão | painel | ✓ | push | não | FPN-020 F |
| PZ-PUSH-19 | Notas | Editor "Explique o despacho… Use @…" + Salvar nota | painel | ✓ | `_notas_tecnicas` | não | F |
| PZ-PUSH-20 | Notas | ⋮ da nota (ver PZ-PRO-32 a 36) | menu ⋮ | ✓ | nt | não | F |
| PZ-PUSH-21 | Botão direito no cartão | Adicionar nota técnica | item | ✓ | push:274; pasta-push.js:490 | não | FPN-014 F |
| PZ-PUSH-22 | Botão direito no cartão | Criar tarefa (o BlueJus tem a mais Marcar como lida e Abrir documento, push:288) | item | ✓ | push:278 | não | FPN-014b F |
| PZ-PUSH-23 | Botão direito no cartão | Encaminhar via Chat I.A | item | ∅ | — | sim | FPN-016 C |
| PZ-PUSH-24 | Botão direito no cartão | Copiar texto | item | ✓ | push:292 | não | F |
| PZ-PUSH-25 | Aba | Textos dos vazios | painel | ≈ | push:98-110 | não | FPN-018 C |
| PZ-PUSH-26 | Aba | "Exibindo as N mais recentes" | texto | ✓ | push | não | F |
| PZ-PUSH-27 | Trilho | Monitoramento: interruptor, PRINCIPAL, apensos com interruptor e status | painel | ∅ | — | sim | FPN-024 C + NOVO |
| PZ-PUSH-28 | Trilho | Buscar agora, Cancelar a leitura, Atualizar com arquivos, soltar arquivos | botão | ∅ | — | sim | FPN-025 B |
| PZ-PUSH-29 | Trilho | Previsão (próximo ato, data provável, confiança, "Ver análise completa") | painel | ∅ | — | sim | FPN-026 B/C |
| PZ-PUSH-30 | Trilho | Modal "Análise do processo" (Fato/Inferência/Previsão) | modal | ∅ | — | sim | PMC-022 C |
| PZ-PUSH-31 | Trilho | Avisar quando chegar | painel | ∅ | — | sim | FPN-027 C |
| PZ-PUSH-32 | Trilho | Avisar cliente (e-mails, principal, pausar, cópia para a Controladoria "Sempre", automático, "Só manual", histórico) | painel | ∅ | — | sim | FPN-028 C |
| PZ-PUSH-33 | Trilho | Modal "Enviar ao cliente" (Para, cópia, Assunto, Mensagem, "Revisei e autorizo") | modal | ∅ | — | sim | FPN-028/IA-028 C |

## 10. Modais, janelas e drawers (PZ-MOD)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-MOD-01 | Nova meta | Moldura, grip, recolher, X, arrastável, não modal | modal | ✓ | show:4122 | não | PMC-038/059 F |
| PZ-MOD-02 | Nova meta | Contador, atalhos Hoje/Amanhã/+5/+15 úteis, calendário próprio, chips de responsáveis, aviso de repetida, "Criar meta" | modal | ✓ | show:4190 | não | PMC-039/040/041 F |
| PZ-MOD-03 | Nova meta | Análise da I.A. do prazo jurídico | painel | ∅ | — | sim | PMC-043 C |
| PZ-MOD-04 | Nova meta | Prazo e responsáveis obrigatórios, "Preencha: …" | regra | ≈ são opcionais | show | não | PMC-042 C |
| PZ-MOD-05 | Nova meta | Nota "Os responsáveis recebem a notificação no Chat I.A" | texto | ∅ | — | não (o texto seria falso hoje) | PMC-045 C |
| PZ-MOD-06 | Imprimir | Prévia, Incluir na folha (4), tamanho −/+, Imprimir, Baixar PDF, Copiar texto | modal | ✓ | `_impressao_modal`; pasta-impressao.js | não | EDT-001/002 F |
| PZ-MOD-07 | Destaque | "Destacar comentário": 15 cores + Sem cor | modal | ∅ | — | sim | D-DESTAQUE C |
| PZ-MOD-08 | Vincular processo/apensado | Buscar existente, Cadastrar novo (CNJ), Informações/Partes/Movimentações, Buscar no CNJ, Tipo de apenso*, flags Sim/Não, Jurisdição | modal | ≈ existe em Bootstrap; faltam Tipo de apenso, flags e Jurisdição | show:1694 | sim (apenso, flags) | NOVO |
| PZ-MOD-09 | Confirmação | Janela própria (título, texto, Cancelar, botão com ícone) | modal | ✓ | show:4376; pasta-dialogos.js:364 | não | PAS-034 F |
| PZ-MOD-10 | Busca rápida | Ctrl+K "Buscar pasta, cliente, CPF ou processo" | modal | ∅ | — | sim: busca global | EDT-034 C |
| PZ-MOD-11 | Marcadores | Busca, submarcadores, Cancelar/Salvar | modal | ✓ | show:3962; `_partials/modal_mover_marcador` | não | F |
| PZ-MOD-12 | Cadeado | Por colaborador (Ocultar/Visualizar/Editar), Aplicar a todos | modal | ∅ | — | sim | PAS-010 C |
| PZ-MOD-13 | Link externo | Acessos ao link | modal | ∅ | — | sim | D-LINK C |
| PZ-MOD-14 | Link externo | Gerar, código, validade, lista, Ver como terceiro | modal | ∅ | — | sim | D-LINK C |
| PZ-MOD-15 | Editar dados | Abas, pílula de estado, Salvar travado, Pendências, Responsável com "Trocar", confirmar troca de número | modal | ✓ | show:3583 | não | PAS-019/020/021 F |
| PZ-MOD-16 | Editar dados | Ação só leitura, CPF/CNPJ, número único | regra | ≈ | show | sim | PAS-016/017 C, PAS-018 D |
| PZ-MOD-17 | Cadastro | Cadastro e qualificação (abas, OCR, Histórico, Copiar qualificação, Salvar) | modal | ∅ | — | sim | PMC-047/048/049 C |
| PZ-MOD-18 | Detalhes do cliente | Arrastável, copiar, pastas do mesmo CPF, contatos, Copiar qualificação | modal | ✓ | `cliente/_resumo`; pasta-clientes.js | não | F |
| PZ-MOD-19 | Detalhes do cliente | Contatos em lista livre, foto no avatar | modal | ≈ | `cliente/_resumo` | sim | PMC-050 C / PMC-058 |
| PZ-MOD-20 | Histórico | Drawer "Histórico do sistema" | painel | ✓ | show:465 | não | F |
| PZ-MOD-21 | Timeline | Painel (busca, chips, período, pessoa, "Enquanto você estava fora", Ver alteração) | painel | ✓ | show:471; pasta-timeline.js | não | PAS-043/045 F |
| PZ-MOD-22 | Timeline | Detecção por regras (tarefa, cadastro, CNJ), conversas do Chat | painel | ∅ | — | sim | PAS-044 D/C, PAS-048 C |
| PZ-MOD-23 | Adicionar pagamento | Tipos, base, campos, juros, prévia, "Gerar N lançamentos" | modal | ✓ | show:355 | não | F (Entrada hoje: D-FIN-ENTRADA fechada) |
| PZ-MOD-24 | Adicionar pagamento | Êxito e Sucumbência | botão | ≈ desabilitados | show | sim | FPN-002 D |

## 11. Menus de botão direito (PZ-CTX)

Os itens de cada menu estão nas linhas das abas indicadas.

| ID | Região | Superfície (itens) | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-CTX-01 | Cabeçalho | Nome do cliente → janela Detalhes | menu contexto | ✓ | pasta-clientes.js:394 | não | PAS-001 F |
| PZ-CTX-02 | Trilho | Linha do cliente → Detalhes | menu contexto | ✓ | pasta-clientes.js:407 | não | F |
| PZ-CTX-03 | Trilho | Linha do prazo → ⋮ (PZ-TRI-05 a 09) | menu contexto | ✓ | pasta-clientes.js:415 | não | F |
| PZ-CTX-04 | Abas | Ocultar… (PZ-CAB-46) | menu contexto | ∅ | — | sim | PAS-011 C |
| PZ-CTX-05 | Registro | Responder ✓ · Copiar texto ✓ · Encaminhar ∅ · Destacar ∅ · Remover destaque ∅ · Editar · N min/bloqueado ✓ · Excluir comentário · N min / "Excluir · permanente" ✓ | menu contexto | ≈ faltam 3 itens (C) | ano:202-214; show:2658 | sim (os 3 que faltam) | PAS-025 F, PAS-026/027 C |
| PZ-CTX-06 | Metas | Linha da meta → ⋮ (PZ-MET-10 a 17) | menu contexto | ✓ | pasta-metas.js:818 | não | PMC-027 F |
| PZ-CTX-07 | Processo | Cartão → ⋮ (PZ-PRO-18 a 30) | menu contexto | ✓ | pasta-processo.js:235 | não | PMC-001 F |
| PZ-CTX-08 | Processo/Financeiro | Botão direito sobre o ⋮ dos Processos e dos Pagamentos | menu contexto | ≈ não abre | — | não | — |
| PZ-CTX-09 | Processo/Push | Nota técnica (PZ-PRO-32 a 36) | menu contexto | ✓ | nota-tecnica.js:458 | não | PMC-017 F |
| PZ-CTX-10 | Financeiro | Registro (PZ-FIN-10 a 15) | menu contexto | ≈ sem Encaminhar e Destacar | fin:1477 | sim (os que faltam) | FPN-006 F |
| PZ-CTX-11 | Financeiro | Arquivo (+ duplo clique) | menu contexto | ✓ | fin:1477 | não | F |
| PZ-CTX-12 | Financeiro | Linha de pagamento (PZ-FIN-35 a 37) | menu contexto | ✓ | fin:1477 | não | F |
| PZ-CTX-13 | Detalhes | Observação (PZ-DET-06 a 14) | menu contexto | ≈ sem Encaminhar, Enviar no Chat e Destacar | det:778 | sim | PAS-040 F |
| PZ-CTX-14 | Documentos | Item, vários itens e fundo (PZ-DOC-15 a 19) | menu contexto | ≈ sem Encaminhar; Compartilhar virou Copiar | pasta-explorador.js | não | F |
| PZ-CTX-15 | Push | Cartão (PZ-PUSH-21 a 24) | menu contexto | ≈ sem Encaminhar; itens a mais | pasta-push.js:490 | sim | FPN-014 F |
| PZ-CTX-16 | Editor | PZ-EDT-15 | menu contexto | ≈ | editor-rico.js:1440 | — | EDT-011 F |
| PZ-CTX-17 | Chat | Conversa, mensagem, nome do usuário (promover cargo) | menu contexto | ∅ | — | sim | D-CONNECT C |
| PZ-CTX-18 | Global | Botão direito fora fecha o menu aberto | menu contexto | ✓ | pasta-processo.js:239 | não | F |

## 12. Editor (PZ-EDT)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-EDT-01 | Barra | Desfazer, estilos (+Citação), B/I/U/S, cor, realce, listas, alinhar, símbolo, data, revisão, localizar, limpar | painel | ✓ | editor-rico.js | não | EDT-018 F |
| PZ-EDT-02 | Barra | Link (Ctrl+K) | botão | ∅ | — | não (pede mudar o sanitizador) | EDT-025 C |
| PZ-EDT-03 | Barra | Imagem (7 layouts, redimensionar) | botão | ∅ | — | sim (upload) | EDT-026 C |
| PZ-EDT-04 | Barra | Tabela | botão | ∅ | — | não (sanitizador) | EDT-027 C |
| PZ-EDT-05 | Barra | Linha horizontal, quebra de página | botão | ∅ | — | não (sanitizador) | EDT-028/029 C |
| PZ-EDT-06 | Barra | Fonte e tamanho (o Farlei §3.6 pede) | botão | ∅ | — | não (classes `ql-*` já aceitas) | EDT-030 C |
| PZ-EDT-07 | Barra | Espaçamento de linha e parágrafo | botão | ∅ | — | não (classes novas) | EDT-031 C |
| PZ-EDT-08 | Barra | Modo documento (A4, margens, régua, zoom) | painel | ∅ | — | não | EDT-032 C |
| PZ-EDT-09 | Barra | Ditado por voz | botão | ∅ | — | não (LGPD) | EDT-033 C |
| PZ-EDT-10 | Digitação | Autocorreção com "Desfazer", "Você quis dizer" | toast | ✓ | editor-rico.js | não | F |
| PZ-EDT-11 | Digitação | Sugestão de palavras (Tab, ↑↓) | painel | ✓ | editor-rico.js | não | EDT-015 F |
| PZ-EDT-12 | Digitação | "> " e "1)" + chip "Formatação automática" | toast | ✓ | editor-rico.js | não | EDT-016 F |
| PZ-EDT-13 | Colar | Manter, Mesclar, Somente texto | toast | ✓ | editor-rico.js | não | EDT-017 F |
| PZ-EDT-14 | Rodapé | Rascunho automático ("Rascunho recuperado/salvo") | texto | ∅ | — | não (localStorage) ou sim (servidor) | EDT-024 C |
| PZ-EDT-15 | Botão direito | Mini barra ✓, listas/título/limpar ✓, correção ✓, Sinônimos ✓, Recortar/Copiar/Colar/Colar somente texto ✓, Parágrafo ✓, Localizar ✓, Revisar ✓, Selecionar tudo ✓, Inserir qualificação ✓, Definir/Traduzir/Pesquisar na web ∅ (C EDT-013), Hiperlink ∅ (C EDT-025), Shift+botão direito nativo ✓ | menu contexto | ≈ | editor-rico.js:1440 | não | EDT-011/012/014 F |
| PZ-EDT-16 | Teclado | Ctrl+Enter, Esc, Ctrl+Y, Tab com 4 espaços, botões 34×32 no toque, Enviar desabilitado se vazio | atalho | ✓ | editor-rico.js | não | EDT-019 a 023 F |
| PZ-EDT-17 | Global | @menção em todos os editores | painel | ✓ | pasta-mencoes.js | não | M3/PMC-019 F |
| PZ-EDT-18 | Global | Citar → `<blockquote>` | painel | ✓ | editor-rico.js | não | EDT-009 F |

## 13. Visualizador (PZ-VIS)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-VIS-01 | Visor | Tela cheia, cabeçalho azul, "i de N", ←/→, Esc | modal | ✓ | pasta-explorador-visor.js | não | F |
| PZ-VIS-02 | Visor | Zoom de 50 a 200% (ODP incluído) | botão | ✓ | idem | não | DOC-109 F |
| PZ-VIS-03 | Visor | Imprimir com tarja, Baixar | botão | ✓ | idem | não | DOC-108 F |
| PZ-VIS-04 | Visor | Formatos no navegador (PDF, imagem, DOCX, planilha, ODT, RTF, PPTX, EML, ZIP, texto do .doc, TIFF, P7S com tamanho) | painel | ✓ | visualizador-documento.js | não | DOC-111/112/114/141 F |
| PZ-VIS-05 | Visor | DOC formatado, PPT, MSG, RAR/7Z | painel | ∅ | — | sim (LibreOffice) | DOC-113 C |
| PZ-VIS-06 | Visor | Soltar arquivo no visor | interação | ∅ | — | não (o upload já existe) | DOC-129 C |
| PZ-VIS-07 | Visor | Aviso (toast) do visor | toast | ✓ | idem | não | F |

## 14. BlueJus Intelligence e Chat I.A / Central (PZ-IA)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-IA-01 | Drawer da pasta | 460px, "BLUEJUS IA / Assistente jurídica", X, Esc | painel | ✓ | `inteligencia/_agentes_drawer`; pasta-ia-agentes.js | não | F |
| PZ-IA-02 | Drawer | Chips Pasta · cliente · processo · responsável | chip | ✓ | idem | não | IA-005 F |
| PZ-IA-03 | Drawer | Grade "O que a IA sabe desta pasta" (5 contadores) | painel | ≈ 4 contadores, sem "fontes" | idem | sim | IA-006 F / IA-021 D |
| PZ-IA-04 | Drawer | Abas Análise / Documentos / Relatórios / Perguntar | aba | ≈ só a lista de agentes | idem | sim | IA-016 B |
| PZ-IA-05 | Drawer | Fase identificada, Corrigir fase, provas, Inconsistência, Sugerir atualização | painel | ∅ | — | sim | IA-020 D |
| PZ-IA-06 | Drawer | Providências marcáveis + "Criar N tarefas" | painel | ∅ | — | sim | IA-022 D |
| PZ-IA-07 | Drawer | Situação da pasta + "Como cheguei…" | painel | ∅ | — | sim | IA-023 D |
| PZ-IA-08 | Drawer | Achados e fontes | painel | ∅ | — | sim | IA-021 D |
| PZ-IA-09 | Drawer | Memória de aprendizado | painel | ∅ | — | sim | IA-025 D |
| PZ-IA-10 | Drawer | 10 comandos da pasta | botão | ≈ são 7 agentes | idem | sim | IA-024 C |
| PZ-IA-11 | Resultado | Copiar, Baixar .txt, Imprimir | botão | ✓ | `_analises_agente` | não | IA-007 F |
| PZ-IA-12 | Resultado | Criar prazo, Enviar para revisão | botão | ∅ | — | sim | IA-026 C / IA-027 D |
| PZ-IA-13 | Drawer | "Pergunte sobre esta pasta…" + Enviar | painel | ∅ | — | sim | IA-017 B |
| PZ-IA-14 | Drawer | Ler documentos, Índice inteligente | painel | ∅ | — | sim (OCR) | IA-019 B |
| PZ-IA-15 | Drawer | Registro de auditoria (N) | painel | ✓ | `_agentes_drawer`:59 | não | IA-008/C6-IA-01 F |
| PZ-IA-16 | Chat | Botão flutuante 66×54 (balões, bolinhas animadas, ✓ quando em dia), arrastável, posição lembrada | botão | ∅ | — | sim: Central | IA-036 C |
| PZ-IA-17 | Chat | Selo de não lidas (laranja; vermelho pulsante se urgente; 99+) | chip | ∅ | — | sim | C |
| PZ-IA-18 | Chat | Selo "@N" de menções | chip | ∅ | — | sim | C |
| PZ-IA-19 | Chat | Aviso acima do botão | toast | ∅ | — | sim | C |
| PZ-IA-20 | Chat | Janela "Central de Comunicação": arrastar, redimensionar, ímã, meia tela, maximizar, minimizar, janela separada, Fechar, setas | modal | ∅ | — | sim | C |
| PZ-IA-21 | Chat | Cabeçalho: avatar com status, "Chat I.A", pílula on-line, subtítulo | painel | ∅ | — | sim | C |
| PZ-IA-22 | Chat | Abas Entrada / Conversas / Notificações | aba | ∅ | — | sim | FPN-038 C |
| PZ-IA-23 | Chat | ⋮ da Central, 26 itens, todos ∅: Central de Atenção (N) · Nova conversa · Novo grupo · Ver conversas arquivadas · Ver resolvidas antigas · Resolvidas ficam por · Marcar todas como lidas · Governança e acessos · Ocorrências do controller · Ver todas as notificações · Status · Não perturbe por 1 hora · Som · Volume · Receber toques · Alertas visuais · Avisar Todas/Importantes · Enter envia · Receber ligações · Tamanho do texto · Limpar notificações lidas · Excluir todas as conversas · Restaurar posição · Restaurar tamanho · Abrir em janela separada · Ajuda | menu ⋮ | ∅ | — | sim | FPN-037/038, IA-036 C |
| PZ-IA-24 | Chat | Conversa: voltar, info, pesquisar, anexos, emojis, responder, editar, não lida, renomear grupo, "digitando…" | painel | ∅ | — | sim | C |
| PZ-IA-25 | Chat | Comandos "/" (321 em 51 grupos), linguagem natural, @IA | comando | ∅ | — | sim | IA-037 D (contagem desatualizada) |
| PZ-IA-26 | Chat | Central de Atenção (painel) | painel | ∅ | — | sim | R38 C |
| PZ-IA-27 | Chat | Som e toque por tipo | regra | ∅ | — | sim | FPN-037 C |
| PZ-IA-28 | Chat | Baixas aceleradas (níveis 1 a 3, suspensão de 10 min) | regra | ∅ | — | sim | IA-042 D |
| PZ-IA-29 | Chat | Destino de todo "Encaminhar via Chat I.A" / "Enviar no Chat I.A" | painel | ∅ | — | sim | PAS-027 C |
| PZ-IA-30 | Chat | Grupo automático da pasta | painel | ∅ | — | sim | C |
| PZ-IA-31 | Chat | Alertas de meta e prazo (BJCentral.alertar) | regra | ≈ chegam pelo sino do topo; funciona | `Notificacao` | não | F |
| PZ-IA-32 | Chat | Ligações (BlueJus Connect) | botão | ∅ | — | sim | R33 C/B |

## 15. Elementos globais (PZ-GLB)

| ID | Região | Elemento | Tipo | BlueJus | Onde | Backend? | Ledger |
|---|---|---|---|---|---|---|---|
| PZ-GLB-01 | Topo | `bi-list` (menu lateral) | botão | ✓ | base:125 | não | — |
| PZ-GLB-02 | Topo | Logo BlueJus branco | ícone | ✓ | base:128 | não | — |
| PZ-GLB-03 | Topo | Título "Pastas" | texto | ✓ | base:137-160 | não | — |
| PZ-GLB-04 | Topo | `bi-building` + escritório | botão | ✓ | base:174 | não | — |
| PZ-GLB-05 | Topo | Sino `bi-bell` | botão | ✓ | base:218 | não | FPN-035 F |
| PZ-GLB-06 | Topo | Selo "9+" | chip | ✓ | base:219-221 | não | F |
| PZ-GLB-07 | Topo | Tela cheia `bi-arrows-fullscreen` | botão | ✓ | base:304 | não | — |
| PZ-GLB-08 | Topo | Nome + avatar com iniciais + caret (o BlueJus tem a mais "Instalar App", base:294) | botão | ✓ | base:309-314 | não | — |
| PZ-GLB-09 | Navegação | EXPEDIENTE · DEMANDAS · PROCESSOS, com EXPEDIENTE ativo na Pasta | aba | ≈ na pasta_show nenhuma fica ativa (o teste é `expediente_*`) | base:401-413 | não | NOVO |
| PZ-GLB-10 | Dicas | Todo `title` vira dica azul-clara com letra azul-escura, sem cobrir o botão (`bj-dica.js`) | tooltip | ∅ o BlueJus usa o title nativo (Bootstrap só em célula truncada) | base:566-590 | não | NOVO (README; Farlei R106/R232) |
| PZ-GLB-11 | Dicas | Notas de ajuda que somem com o uso | tooltip | ∅ | — | não (por navegador) ou sim (por usuário) | R31 E / NOVO |
| PZ-GLB-12 | Toast | Toast padrão (fundo escuro, ✓ verde, 2,6 s) | toast | ✓ | pasta-dialogos.js:470 | não | PAS-034 F |
| PZ-GLB-13 | Janelas | X em toda janelinha | botão | ✓ | pasta-show.js:260 | não | PAS-022 F |
| PZ-GLB-14 | Janelas | Janelas arrastáveis | interação | ✓ | pasta-show.js:27 | não | PAS-023 F |
| PZ-GLB-15 | Atalhos | E/H/T com o ⋮ aberto, Ctrl+Enter, Esc (Ctrl+K em PZ-MOD-10) | atalho | ✓ | pasta-show.js:480; pasta-timeline.js:122 | não | F |
| PZ-GLB-16 | Visual | Quinas ≤ 4px, Arial, azul | regra | ✓ | pasta-show.css | não | PAS-024 F |
| PZ-GLB-17 | Contas | Multiconta: primário e secundários (`bj-contas.js`) | regra | ∅ | — | sim | D-MASTER C (decidido no Farlei §3.12) |

## 16. Contagens por região

Cada linha da tabela conta uma vez; os itens de uma sub-lista não contam separado.

| Região | ✓ | ≈ | ∅ | Total |
|---|---|---|---|---|
| Cabeçalho (CAB) | 29 | 8 | 12 | 49 |
| Trilho de Dados (TRI) | 8 | 4 | 2 | 14 |
| Dados / Registro (DAD) | 12 | 0 | 5 | 17 |
| Processo (PRO) | 23 | 5 | 12 | 40 |
| Metas (MET) | 19 | 1 | 5 | 25 |
| Financeiro (FIN) | 29 | 3 | 7 | 39 |
| Documentos (DOC) | 19 | 3 | 3 | 25 |
| Detalhes (DET) | 12 | 1 | 6 | 19 |
| Push (PUSH) | 16 | 5 | 12 | 33 |
| Modais (MOD) | 10 | 5 | 9 | 24 |
| Menus de botão direito (CTX) | 9 | 7 | 2 | 18 |
| Editor (EDT) | 8 | 1 | 9 | 18 |
| Visualizador (VIS) | 5 | 0 | 2 | 7 |
| IA e Chat I.A (IA) | 4 | 4 | 24 | 32 |
| Globais (GLB) | 13 | 1 | 3 | 17 |
| **Total** | **216** | **48** | **113** | **377** |

Itens NOVOS em relação ao ledger: 17 linhas. São CAB-06, 38, 39, 40, 41, 42; TRI-01, 02; DAD-14; PRO-08, 11; FIN-09; DET-19; PUSH-04, 27 (parte dos apensos); MOD-08; GLB-09, 10, 11.

## 17. ∅ que dá para fazer sem dado novo

São interações visuais que não fingem uma operação concluída.

**Sem dado novo e sem decisão pendente**
- **PZ-GLB-10 (dicas padronizadas):** um script global que troca a dica nativa do `title` pela dica do sistema. É o único ∅ puramente visual e livre.
- **PZ-PUSH-04 (filtro por processo):** a publicação já tem processo e a pasta já tem vários processos vinculados. Fica sem o rótulo do tipo de apenso, que é dado novo.

**Sem dado novo, mas com classe C ou D a resolver antes**
- **PZ-GLB-11 (notas de ajuda que somem):** a preferência pode ficar no navegador. A regra de quando somem é limiar inventado (Farlei §9.4).
- **PZ-VIS-06 (soltar arquivo no visor):** usaria o Anexar que já existe. É D-DOC-S7, classe C.
- **PZ-EDT-05, 06 e 07 (linha horizontal, quebra de página, fonte e tamanho, espaçamento):** não pedem coluna nova, só mexer no sanitizador ou em classes. Estão em D-EDITOR, classe C; o Farlei §3.6 pede fonte e tamanho.
- **PZ-EDT-02 (link):** o mesmo caso, ainda com o dono do Ctrl+K em aberto.

**Fora desta lista de propósito:** o Chat I.A (PZ-IA-16 a 32), "Encaminhar", destaque, cadeado, link externo, trilha de fases, apensos, Suspenso e Cancelado. Sem servidor, qualquer um deles seria uma tela que finge funcionar.

**Divergências ≈ que também são só visuais (bom candidato a lote rápido)**
- Seguir o desenho novo no cabeçalho:
  - Responsável na linha 1 (CAB-06).
  - Ação e Processo vinculado no trilho de Dados (CAB-41, TRI-01, TRI-02).
  - Tirar "trocar" (CAB-42) e trocar o vazio por "abrir menu Processo".
  - **Antes disso**, ratificar: o Farlei §3.2 (R087) pede a faixa Processo → Ação → Responsável.
- Pendência visível a 45% na aba ativa (CAB-45).
- "Expediente" ativa na sub-navegação das rotas `pasta_*` (GLB-09).
- Botão direito sobre os ⋮ de Processos e Pagamentos (CTX-08, PRO-03, FIN-32).
- Selo "0" e contagens zeradas (CAB-44, TRI-03; C D-PASTA6).
- Placeholder do Financeiro (FIN-05; C).
- "Como usar esta aba": o desenho novo tirou e o Farlei pede (DET-19; precisa de decisão).

## 18. Itens do ledger que a especificação do Farlei pode ter resolvido

Convém reabrir só para trocar a classe:
- FPN-001: §3.9.
- D-DESTAQUE (PAS-026): §3.7, com a regra de 1 destaque por aba.
- PAS-035 e PMC-021 (primário sem limite): §3.3 e §3.12.
- D-MASTER e cadeado (PAS-010/011): §3.12, que exige validação no servidor.
- IA-037: o catálogo agora tem 321 comandos.
- Nenhum item da trilha "Andamento da pasta" tem pedido do Farlei: não implementar sem confirmar com ele (§9).

## 19. Ausentes a criar — leitura do orquestrador

Pedido do dono (14/10): não descartar elemento visual por falta de backend; casca visual pode existir
desde que não finja operação concluída. Separação por natureza, a partir das tabelas acima:

| Natureza | Elementos ∅ | Casca visual possível agora? |
|---|---|---|
| **Chat I.A / Central** | PZ-IA-16 a 30, 32 (botão flutuante, selos, janela, abas, ⋮ de 26 itens, conversa, comandos "/") | **Sim, em parte:** botão flutuante, janela com arrastar/redimensionar/minimizar e abas vazias com estado "sem conexão com a Central". **Não:** respostas, conversas, contagens, comandos — sem servidor seriam dado inventado |
| **"Encaminhar via Chat I.A"** em todo menu | PZ-TRI-06, DAD-10, PRO-27/34, MET-14, FIN-08/12/21, DET-05/08/11, DOC-17, PUSH-14/23 | Item visível e desabilitado com dica "Disponível quando o Chat I.A estiver ligado" — mesmo padrão dos itens bloqueados que o sistema já usa |
| **Menus de contexto** | PZ-CAB-46/CTX-04 (ocultar aba), CTX-08 (botão direito sobre ⋮ de Processos e Pagamentos), CTX-17 (chat) | CTX-08: **sim, já** (só abre o ⋮ que existe). CAB-46: não (visibilidade por usuário é permissão) |
| **Botões e ícones** | PRO-08 Vincular apensado, PRO-11 X do apenso, MET-24 chat por pessoa, PUSH-28 Buscar agora, EDT-02..09 barra do editor | Editor (EDT-05/06/07): sim, com decisão C. Os outros dependem de dado |
| **Modais** | MOD-07 Destaque, MOD-10 Ctrl+K, MOD-12 Cadeado, MOD-13/14 Link externo, MOD-17 Cadastro, PUSH-30/33 | MOD-07: decidido pelo Farlei §3.7, falta backend (persistir). Demais: dependem de dado/permissão |
| **Painéis do cabeçalho** | CAB-38/39/40 Andamento em fases, CAB-32/33/37 cadeado, CAB-36 Suspenso/Cancelado | Não: fase é regra nova **sem pedido do Farlei** (§9 da especificação dele) |
| **Dicas** | GLB-10 `bj-dica.js`, GLB-11 notas que somem | GLB-10: sim, mas toca `base.html.twig` (decisão do dono, ver spec §3.1) |

### Conflitos desenho 13/10 × especificação do Farlei (decidir antes de implementar)

O README do pacote manda: em conflito, vale a decisão mais recente do Farlei em
`ESPECIFICACAO_ORIGINAL_FARLEI_BLUEJUS.md`. Conferido no arquivo:

1. **Cabeçalho** — o desenho tirou a faixa Processo · Ação · Responsável (PZ-CAB-06/41/42, TRI-01/02); a
   especificação §3.2 (R087) pede essa faixa, nessa ordem.
2. **"Como usar esta aba"** — o desenho tirou (PZ-DET-19); a especificação §3.3 (R215) pede a caixa.
3. **Andamento da pasta** — está no desenho, não está em nenhum pedido (§9).

## 20. Atualizações por entrega

| Entrega | Commit | Itens que mudaram de marca | Itens novos |
|---|---|---|---|
| E2 — cantos de 4px | `83d6724d` | PZ-GLB-16 continua ✓ (agora também nos 26 seletores do delta de 13/10) | — |
| E5 — Minhas Metas | `e227183b` | nenhuma (a E5 não toca a Pasta) | — (Minhas Metas tem checklist próprio: `fidelidade-designer-1310-checklist-minhas-metas.md`) |
| E4 — Financeiro, responsável, situação | `47662002` | PZ-PRO-41 ≈→✓; PZ-CAB-06 continua ≈ (corte feito, posição depende de P1); PZ-CAB-50 continua ≈, agora aceita | PZ-FIN-40 ✓ |
| E3 — nota técnica e marca IA | `53aa6163` | PZ-PRO-31 ✓ (cores, raio, conector do dc de 13/10), PZ-CAB-31 ✓ (tipografia), PZ-PUSH-18/19 ✓ (mesmo CSS no teor do Push) | PZ-CAB-50 (foco da marca, decidir), PZ-PRO-41 (situação crua), aviso estático em PZ-PRO-15 |

Contagem depois da E3: **216 ✓ · 50 ≈ · 113 ∅ = 379** (2 linhas novas ≈; as marcas ✓ das linhas que a E3
tocou já eram ✓ — a E3 trouxe o valor novo do desenho para elas).

Contagem depois da E4 (inalterada na E5): **218 ✓ · 49 ≈ · 113 ∅ = 380**. Os 113 ausentes seguem na §19, por natureza;
nenhum foi descartado por falta de backend.

### Pendências de especificação

| # | Conflito | Desenho de 13/10 | Especificação do Farlei | Estado |
|---|---|---|---|---|
| P1 | Faixa Processo · Ação · Responsável no cabeçalho (PZ-CAB-06/41/42, TRI-01/02) | tira a faixa | §3.2, R087: pede a faixa nessa ordem | **ABERTA** — E6 travada |
| P2 | Caixa "Como usar esta aba" (PZ-DET-19) | tira | §3.3, R215: pede | **ABERTA** — caixa mantida |
| P3 | Andamento da pasta em fases (PZ-CAB-38/39/40, DAD-14, FIN-09) | tem | sem pedido (§9: confirmar antes) | **ABERTA** — e depende de backend |
| P5 | Numeração da meta em Minhas Metas (MM-LIS-12) | 1..n por grupo — implementado na E5 a pedido do dono | §3.5 R239: `{nº na pasta} Título {nº global}` | **ABERTA** |
| P6 | Traço sob o título de Minhas Metas (MM-CAB-02) | azul | §3.5 R129: vermelho | **ABERTA** — não implementado |
| P4 | Foco do botão BLUEJUS Intelligence (PZ-CAB-50) | declara anel, renderiza contorno | omissa | **DECIDIDA (14/10)** — preservar o indicador atual (contorno do navegador + fundo); divergência registrada |

### Fora da Pasta, mesmo defeito da PZ-PRO-41 (para a entrega de cada tela)

- Peticionar (`layout_peticionar.html.twig:768`) imprime a situação crua → E9.
- Processo show e lista (`processo/show.html.twig:56`, `processo/_resultado.html.twig:41`) trocam `_` por espaço
  ("EM ANDAMENTO"). O filtro `situacao_processo` já serve; ficou fora da E4 por ser outra tela.
