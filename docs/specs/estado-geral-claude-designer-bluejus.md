# Estado geral — Claude Designer → BlueJus (documento mestre)

> **Data:** 07/10/2026 · **Base:** `master` = `origin/master` = **`719de0e4`** = produção.
> **Para quem:** o dono (Samuel) e qualquer sessão futura que nunca viu a conversa.
> **O que é:** o apanhado de tudo o que foi feito, do que falta e de quem depende cada pendência, para levar
> o BlueJus ao que foi aprovado no Claude Designer. Os detalhes moram nos ledgers e nas specs; aqui ficam o
> estado, as contagens e a ordem de trabalho.
> **Autoridade:** git e código, depois os ledgers. Produção foi medida em 07/10 pelo MCP somente leitura
> (só agregados, sem PII). Onde um ledger diverge do código, ver §15.

**Documentos detalhados (leia o ledger antes de mexer na frente):**

| Documento | Cobre |
|---|---|
| `docs/specs/trilha-a-visual-pje.md` | Trilha A: visual padrão PJe (A1–A10) e auditoria de fidelidade da Pasta |
| `docs/specs/trilha-b-designer.md` | Trilha B: ledger das entregas B1–B39, decisões D-* (§4), homologações, publicações e P1–P19 (§10) |
| `docs/specs/trilha-b-documentos-arquitetura.md` | Aba Documentos: explorador `pex`, lotes L0–L11, decisões S-1…S-12 |
| `docs/specs/trilha-b-inventario/*.md` | Inventários por área (`pasta`, `dashboard`, `componentes`, `inteligencia`, `auditoria-pasta-2`, `pendencias-pos-documentos`, `publicacao-ei2-migrations-rollback`) |
| `app/src/Inteligencia/CLAUDE.md` | Regras da BlueJus IA e roteiro "Ligar um provedor" |

**Vocabulário deste documento:**
- **EI1:** 1ª entrega intermediária, `3f199dfd`, publicada em 06/10 (Trilha A + B1–B39).
- **EI2:** 2ª entrega intermediária, `3f199dfd..719de0e4` (117 commits: Documentos L1–L11, P1–P19, caixas de
  seleção), publicada em 07/10. **O smoke visual de produção dela está pendente.**
- **Samuel:** o dono do produto. "Decisão do Samuel" é só o que ele decidiu. Recomendação técnica de
  agente, revisor ou orquestrador **não é decisão**.

---

## 1. Contexto

### 1.1 Objetivo
Levar ao BlueJus real as telas que o Samuel aprovou no Claude Designer: primeiro o visual (padrão PJe, na
Trilha A), depois as funções novas que o desenho mostra (Trilha B), **sem perder nenhuma função
existente**. Nas palavras do dono: *"o Designer define o comportamento; a engenharia acha a implementação"*.

O pacote do Designer fica em `docs/design/claude-design-2026-10-05 (1)/` e **não é versionado** de
propósito, porque pode conter dado real.

### 1.2 Telas do Designer no escopo e fora dele

| Arquivo do pacote | Situação |
|---|---|
| `01 - Dashboard 1.2.2.dc.html` (+ `design_handoff_dashboard/`) | **No escopo.** Implementado em grande parte |
| `02 - EXPEDIENTES 1.2.3.dc.html` (a Pasta, com todas as abas) | **No escopo.** É a tela principal da rodada |
| `bj-editor.js`, `bj-visualizar.js`, `bj-docsug.js`, `bluejus-intelligence.js`, `bluejus-avancado.js` | **No escopo** (editor, visualizador, sugestor de documentos, painel por regras do Dashboard) |
| `Push Compartilhado.dc.html` (`bj-link.js`) | **No escopo, não implementado:** depende da decisão D-LINK (link público sem login, LGPD) |
| `Guia visual BlueJus.dc.html` | **Referência** do padrão (botões, etiquetas, painéis, 4px progressivo). Não é tela a implementar |
| `Relatorio de entregas BlueJus*.dc.html` | **Não é especificação** (relatório do próprio Designer) |
| `Agenda 1A.dc.html` | **Fora** (redesenho da Agenda é outra frente) |
| `Carteira 1B`, `Objeto 1A` e as "2 direções" | **Fora** (Cobrança). O defeito L0 do Objeto espera o OK do Samuel (D-DOC-S9) |
| `design_handoff_pasta_show/` (Pasta 1A) | **Fora** e desatualizado (só aproveitou "continuar lendo" e "Duplicar") |
| `bluejus-equipe.js` (rótulos de RH), `bj-processo.js` (motor de prazos), `bluejus-docs.js`/`bluejus-central.js` (Chat, Connect) | **Fora**, cada um preso a uma decisão (D-EQUIPE, D-PRAZOS, D-CONNECT) |

### 1.3 Protótipo × implementação real
O `.dc.html` é um protótipo React que roda no navegador: guarda tudo em `localStorage`, simula IA e monta
links só no cliente. A implementação troca tudo isso por **persistência no servidor, com tenant explícito,
permissões e auditoria** (ex.: `pasta_favorita`, `preferencia_usuario`, `dashboard_foto`, lixeira por
lápide). A aba Documentos não evoluiu o gerenciador antigo (`fm`): ganhou um **explorador próprio (`pex`)**,
e o `fm` ficou congelado para a Cobrança.

### 1.4 Regra de fidelidade
1. **O mais próximo possível do Claude Designer, sem perder função e sem função falsa.** Onde o desenho
   mostra algo diferente, o desenho manda. Onde é omisso, vale a convenção do sistema. Desviar do desenho
   exige perguntar antes ao Samuel.
2. **Nenhuma função some.** Ids, classes, `data-*`, XHRs e fragmentos existentes são contrato.
3. **Nada fake:** nenhum botão sem função, dado fictício, `localStorage` no lugar de persistência ou IA
   simulada. O que depende de função ausente **não é renderizado**. Regra é apresentada como regra
   ("por regras fixas", "Sugerir documentos" sem o ✦).
4. **Função nova do desenho é para ser feita de verdade.** Se o Designer mostra uma função nova e ela é
   tecnicamente possível, a intenção é implementá-la com backend, persistência, permissões, infraestrutura
   e integrações reais, não uma imitação de tela.
5. **IA real ou nenhuma.** Sem provedor, a tela diz "IA não configurada nesta instalação".
6. **Isolamento por escritório** em toda consulta nova (filtro + guarda IDOR + teste cross-tenant).

---

## 2. Linha do tempo

| Data | Marco | Evidência |
|---|---|---|
| 24/09 | Base anterior em produção: `a458e58e` | reflog `origin/master` |
| 30/09–05/10 | O Samuel produz o pacote do Designer. Em 05/10 fixa as versões finais e as diretrizes (4px progressivo, nota técnica no processo, 15 min, IA real) | memória `project_claude_design_2026_10_05` |
| 05/10 15:35 | **Trilha A começa** (A1–A6 Pasta, A7–A10 Dashboard) | `4d1d054a` |
| 05/10 20:19–20:28 | **Trilha A integrada e publicada** (17 commits de frente + registro `beff02fd`; suíte 5633/5633; zero migration) | `beff02fd`, reflog |
| 05/10 21:20 → 06/10 | **Trilha B, sessão 1:** ledger, inventários, spec da IA e entregas **B1–B39** | `c4772b88`; TB §3 |
| 06/10 manhã | Homologação local (8 correções, 3 revisões, smoke 52/52) | `4a919751` |
| 06/10 10:12 | **Homologação do Samuel:** aprovada como publicação intermediária. Documentos **reprovada** (não igual ao Designer) | `9615bc3c`; TB §8 |
| 06/10 10:23–10:28 | Prova de rollback e **publicação da EI1 `3f199dfd`** (99 commits sobre `beff02fd`, 9 migrations, IA desligada) | `3f199dfd`; TB §9.1 |
| 06/10 11:38 | Sessão 2: auditoria e arquitetura de Documentos (explorador `pex`) | `b679d0ba` |
| 06/10 | Documentos **L1–L11 + L2b** integrados; pendências **P1–P19** integradas | DOC §7; TB §10.2 |
| 06/10 | Decisão D-FIN-ENTRADA (entrada e parcelas nascem pendentes) | `8f5dbc5f` |
| 06/10 23:55 | Homologação local da EI2: smoke Playwright (Chromium + Firefox) acha 2 bloqueantes, corrigidos | `fc060f33`, `7a5d698f` |
| 07/10 06:15–06:22 | **Smoke visual do Samuel aprovado no dev** (`saas_ux`). Pedido: caixas de seleção em Documentos | `230a2c43`, `07b8eb65` |
| 07/10 | Análise de migrations, dry-run e rollback da EI2 | `719de0e4` |
| 07/10 06:58 | **Push de `719de0e4` e deploy da EI2**; migrations aplicadas em prod | reflog; MCP |

---

## 3. Estado exato de produção (07/10/2026)

| Item | Valor |
|---|---|
| Código em produção | **`719de0e4`** (= `origin/master` = HEAD local) |
| Última migration aplicada | **`Version20261007020000`** (206 registradas) |
| Schema | OK (as 4 migrations da EI2 aplicadas; as 2 antigas do Ponto seguem em skip, ver §11) |
| BlueJus IA | **Desligada.** Provedor = `nao_configurado` (`ProvedorNaoConfigurado`), `IA_HABILITADA` ausente (default `false`) |

### Classificação: **DEPLOY CONCLUÍDO · PÓS-DEPLOY TÉCNICO OK · SMOKE DE PRODUÇÃO APROVADO**

> **Atualização de 07/10 (início do Engineering Loop):** o Samuel fez o smoke visual e funcional da EI2 em
> produção e **aprovou**. `719de0e4` é o baseline de produção homologado. O texto abaixo, que trata o smoke
> como pendente, ficou como histórico. A continuação está em `docs/specs/engineering-loop-fidelidade-designer.md`.

### (histórico) Classificação anterior: DEPLOY CONCLUÍDO · PÓS-DEPLOY TÉCNICO OK · SMOKE VISUAL DE PRODUÇÃO PENDENTE

> A EI2 **não está homologada em produção.** A aprovação do Samuel em 07/10 foi feita no **dev**
> (`saas_ux`). A suíte verde e o smoke do dev não dizem nada sobre a tela de produção.

### Observações medidas em produção (MCP, 07/10)
- **Nenhum uso das funções novas desde o deploy:** lixeira com 0 documentos e 0 seções; favoritos de
  documento = 0; `inteligencia_analise` = 0; `inteligencia_configuracao` = 0.
- **Nenhum upload de documento desde 05/10 16:53**, ou seja, nenhum depois das duas publicações. **No smoke,
  conferir se é falta de uso ou defeito** (fazer um upload real pelo "Anexar").
- **`dashboard_foto` vazio:** o cron `app:dashboard:fotografar` não está configurado, então as tendências de
  Vencidas e Prazos não aparecem.
- **22.565 documentos, todos sem `sha256` e sem `paginas`:** o backfill `app:documentos:calcular-hash` nunca
  rodou. Selo Idêntico, limpeza por hash e regra "cópia do processo" por páginas ficam inertes até lá.
- **114 documentos com 0 byte** (artefato do DT-8, todos com `drive_file_id`) e Drive parado com 403 desde
  21/08 (130 pastas sem `drive_folder_id`).

---

## 4. Inventário por área

**Classes:** **PROD** = implementado e em produção (EI1 ou anterior) · **EI2** = publicado na EI2, smoke de
produção pendente · **PARCIAL** · **AUTÔNOMO** = pendente, implementável sem ninguém · **SAMUEL (D-x)** =
depende de decisão · **EXTERNO** = depende de serviço ou configuração externa · **FORA** = fora do escopo.

| Área | PROD | EI2 (smoke prod pendente) | PARCIAL / AUTÔNOMO | SAMUEL | EXTERNO / FORA |
|---|---|---|---|---|---|
| **Dashboard** | casca, KPIs 48px, A4, celular; filtros; Desempenho com links; tendências de metas, demandas e pastas; preferências por usuário; painel "BlueJus Intelligence" **por regras** | setas de Vencidas/Prazos por linha (P8); som do calendário; 6 colunas extras (P17a) | Tempo médio exclui legado sem `dataConclusao` (PARCIAL); links e setas das colunas extras (AUTÔNOMO) | troféu, zerar, período padrão, acesso restrito, tendência de ativas (D-DASH); "Pastas concluídas" (P17b, sem lastro) | **cron da foto não configurado** (tendências inertes); rótulos de RH FORA (D-EQUIPE) |
| **Pasta / cabeçalho** | linhas 1/2/3 PJe; "N de M"; selo ARQUIVADO; excluir digitando o número (servidor); ⋮ com Duplicar, Imprimir resumo, Copiar link, Favoritos | Timeline inteligente no ⋮ (T); botão Cadastro com pendências; atalhos do Editar dados; **pasta excluída somente leitura em todas as rotas** | foto do cliente (AUTÔNOMO, fila D) | selo "0" (D-PASTA6); Suspensa/Cancelada (D-SITUACAO); cadeado e abas ocultas (D-MASTER); acompanhar (D-ACOMP); mover carteira (D-CARTEIRA); modal de cadastro (D-CAD-MODAL); barra global (D-BARRA) | chip de apensados FORA (0 em prod) |
| **Dados / Registro** | linha do tempo; editor rico; janela de 15 min; Responder; trilho de prazos com .ics | **notificação ao autor respondido**; **@menção com autocomplete e notificação** (envio e edição); âncora `#pasta-msg-N` | @menção nas notas técnicas; "Alertar responsável" no ⋮ do prazo; etiqueta do registro (AUTÔNOMO) | destaque de comentário (D-DESTAQUE); bypass Master (D-MASTER); 15 min em Tarefa/Kanban (D-15MIN) | encaminhar via Chat, presença (D-CONNECT) |
| **Processo** | cartão, Órgão/Valor/Distribuição/Instância; ⋮ com Compartilhar; Administrativo sem processo; notas técnicas | estrela/abrir/lixeira no ⋮ (N4); ⋮ do cabeçalho (N5); Administrativo sem recarregar | versões da nota técnica (AUTÔNOMO) | faixa "Notas técnicas 0" e "Última distribuição" (D-PASTA6); motor de prazos (D-PRAZOS) | PJe/MNI, "Dados do PJe salvos em…", Ler PDF (EXTERNO, D-PJE) |
| **Metas** | filtros, numeração, concluir na lista, renomear, reabrir, sino "Alertar" (1/h) | "Alertado: Nome às HH:MM"; drawer "Relatório da meta"; "vence dd/mm/aaaa" | "Copiar link" da meta (AUTÔNOMO) | destino do "Abrir em Metas" (D-META-LINK); quem renomeia/reabre (D-PERM-META); prazo obrigatório (D-META) | Nº global FORA (expõe volume); análise por IA (D-IA) |
| **Clientes** | todos os clientes, ícone de completude, Copiar qualificação, janela "Detalhes do cliente", busca com máscara | vincular sem recarregar; novo principal ao desvincular; **editar contatos (3 slots) na janela** | — | lista livre de contatos (D-CLI-CONTATOS); auditar Cliente (D-CLI-AUDIT); PJ (D-PJ) | campos de endereço separados e selo "CPF duplicado" FORA (sem lastro) |
| **Financeiro** | selos, média por CPF, ⋮ por arquivo, extrato, Vencida, quitar/excluir | e-mail ao cliente por `mailto:`; **corrigir valor** com histórico e 409; **parcelamento Price no servidor**; entrada pendente (422); modal de pagamento corrigido | êxito/sucumbência P16b; `#[Version]` contra corrida; selo "Assinado" manual (AUTÔNOMO) | permissão própria (D-FIN); placeholder (D-PASTA6) | encaminhar via Chat (D-CONNECT) |
| **Push (aba)** | linha do tempo por dia, selo NOVO, filtros, Copiar ID, Abrir no PJe, lida/não lida, criar meta, teor + notas | filtro "Geram prazo"; data da pílula (N1/N2) | título do cartão = teor curto (N3, AUTÔNOMO) | hora no canto (D-PUSH-HORA, DJEN sem hora); rótulos (D-PASTA6); **Push Compartilhado (D-LINK)** | "Resumir com IA" pronto, desligado (D-IA); monitoramento (D-CONNECT) |
| **Documentos** | sugeridos pelo catálogo (B37), sha no upload, aviso de duplicado | **explorador `pex` inteiro** (L1–L11), favoritos, lixeira, zip, copiar/colar, limpeza por regras, visor em tela cheia, estado do checklist, exigido pelo juízo, **caixas de seleção** | páginas/Idêntico/hash sem dado em prod (PARCIAL, D-HASH); texto da lixeira; "Anexar" com menos formatos (AUTÔNOMO) | cobrança do checklist (D-DOC-S6); soltar no visor (D-DOC-S7); L0 da Cobrança (D-DOC-S9) | Encaminhar via Chat (D-CONNECT); DOC/PPT/MSG (D-LIBRE) |
| **Visualizador** | PDF, imagem, áudio, vídeo, DOCX, planilhas, texto, ODT, PPTX, RTF, EML, ZIP (tudo em sandbox ou `textContent`) | ODP; ZIP64; visor com zoom 50–200% e impressão de PDF/imagem | ODP sem botão de zoom; HTTP Range para arquivo grande (AUTÔNOMO) | — | DOC, PPT, MSG, HEIC, TIFF multipágina (EXTERNO: LibreOffice, D-LIBRE) |
| **Editor** | realce, desfazer, contagem 5000, localizar/substituir, 18 símbolos, revisão, autocorreção | autocomplete de @menção no Registro | — | link, imagem, tabela, `<hr>`, fonte, ditado (D-EDITOR: o sanitizador não aceita) | — |
| **Timeline** | drawer "Histórico do sistema" com moldura do desenho | **Timeline inteligente por regras** (4 fontes, busca com sinônimos, chips, período, pessoa, "Precisa de atenção") | "Enquanto você estava fora"; detecção de tarefa/telefone/CNJ; "Ver alteração" (AUTÔNOMO) | — | mensagens do Chat (D-CONNECT) |
| **BlueJus IA** | infraestrutura completa desligada (§7) | — | runbook de ativação; worker no compose de dev (AUTÔNOMO) | provedor, custo, LGPD (D-IA); papéis (D-IA2) | **adaptador e chave do provedor** (EXTERNO) |
| **Permissões** | 2 permissões de IA criadas sem papel; filtro por pasta na janela do cliente | lápide somente leitura em todas as rotas de escrita | esconder botões de escrita da lápide (D-DOC-RO UI) | Master/primário (D-MASTER); `modules.financeiro.view` (D-FIN); papéis da IA (D-IA2) | — |
| **Auditoria** | entidades novas `Auditavel`; histórico na timeline | enum grava `->value`; desfazer converte por tipo, tudo-ou-nada; corrigir valor lê o `audit_log` | desfazer recusa sempre `Cargo`/`Sede`/`Lotacao` (AUTÔNOMO) | Cliente sem auditoria (D-CLI-AUDIT) | favoritos não auditados (exceção consciente) |
| **Notificações** | "Alertar para verificar" de meta (1/h) | resposta no Registro; @menção no Registro | alerta de prazo pelo ⋮; menção em nota técnica (AUTÔNOMO) | acompanhar alterações (D-ACOMP) | "toque" e Chat (D-CONNECT) |
| **Integrações** | Datajud (capa); DJEN/Push | — | renomear documento não propaga ao Drive (AUTÔNOMO, depende do Drive) | rebaixar os 114 de 0 byte (D-DOC-VAZIO) | **Drive em 403**; PJe/MNI; OCR; LLM; servidor de chamadas |

---

## 5. Claude Designer × BlueJus: fidelidade por tela

### 5.1 Dashboard (`01 - Dashboard 1.2.2`)
Fiel na casca, nos KPIs, nos filtros, na tabela de Desempenho, nas tendências e nas preferências. O que
falta é de decisão (troféu, zerar, período padrão, tendência de ativas) ou sem lastro ("Pastas
concluídas"). Em produção, as setas de Vencidas e Prazos ficam vazias até o cron da foto rodar.

### 5.2 Pasta (`02 - EXPEDIENTES 1.2.3`), abas que não são Documentos
Fiel na estrutura, no cabeçalho, nas setas e na posição, no selo, no ⋮, no modal com moldura, no Registro,
nas metas, nos clientes, no financeiro e no Push. As diferenças visuais que restam estão quase todas
presas à decisão **D-PASTA6** (selo "0", faixa "Notas técnicas 0", "Última distribuição", placeholder do
financeiro, rótulo "Lida", texto do vazio do Push, iniciais). Conferir no smoke de produção o menu do
responsável (tarja em gradiente) e o compositor do Registro.

### 5.3 DOCUMENTOS (destaque)
A aba Documentos foi **reprovada** na homologação de 06/10 por não ser igual ao Designer. Foi refeita do
zero como explorador próprio (`pex`, ~6.800 linhas entre JS e CSS) em 11 lotes, e **aprovada pelo Samuel
no dev em 07/10**. Está na EI2: smoke de produção pendente.

**Medida contra os 90 itens DOC-01…DOC-90 da auditoria** (detalhe em `trilha-b-documentos-arquitetura.md`):
- **82 de 87** itens possíveis sem serviço externo estão implementados como no desenho ou com divergência
  intencional decidida (≈94%). Faltam DOC-51 (soltar no visor) e DOC-75 (cobrança do checklist), ambos do
  Samuel, e DOC-73/76 estão parciais (sem notificação nem lembrete). DOC-65 (páginas) está implementado,
  mas sem dado em produção.
- **Fiéis:** estrutura e ordem dos blocos, toolbar (busca em pílula, Nova pasta, Anexar, Organizar,
  Visualizar com 8 modos), navegação (trilha, Backspace, Alt+←), itens e ícones, colunas móveis e
  redimensionáveis, menus do item, de vários e do fundo, toast com Desfazer, upload com barra, zip,
  visor, nova pasta e renomear inline, mover, busca e filtro, checklist, sugeridos dentro do checklist,
  tema escuro, desempenho (pasta de 1.112 documentos abre em ~50–100 ms no dev).
- **Divergências intencionais aprovadas pelo Samuel em 07/10:** selo Idêntico por nível aberto; Copiar
  desabilitado quando só há pastas; confirmação antes de excluir; rótulo neutro "Sugerir documentos" (sem
  ✦); lixeira sem purga automática; 0 byte do Drive fora da limpeza.
- **Divergências em "registrar e seguir"** (default técnico, sem decisão item a item): S-1 (⋮ só no toque),
  S-2 (Categoria como 5ª coluna opcional), S-5 (pílula "Manual"), S-8, S-11, S-12 ("Copiar link" interno no
  lugar de "Compartilhar link").
- **Caixas de seleção (`230a2c43`, pedido do Samuel após o smoke de 07/10):**
  - uma caixa por item, ligada à **mesma seleção** de Ctrl, Shift, teclado, laço, menu e ações em lote
    (seleção unificada, não um segundo estado);
  - **"Selecionar todos" marca só os itens visíveis:** respeita o nível aberto, a busca e o filtro, e fica
    indeterminada quando a seleção é parcial;
  - alvo de toque de 32×32 em toque ou abaixo de 768px; duplo clique que começa na caixa não abre o item;
  - o desenho é omisso aqui; revisão Opus e smoke 9/9 em Chromium e Firefox, **no dev**.

### 5.4 Visualizador e editor
O visualizador abre todos os formatos do `bj-visualizar.js` que dispensam serviço externo. Os 5 restantes
(DOC, PPT, MSG, HEIC, TIFF multipágina) exigem LibreOffice. O editor está fiel no que o sanitizador aceita;
link, imagem e tabela esperam D-EDITOR.

### 5.5 Push Compartilhado
Não implementado. É link público com dado de cliente sem login: depende de D-LINK.

---

## 6. Funcionalidades novas reais (o que cada uma faz de verdade)

**Em produção desde a EI1:**
- **Posição "N de M"** da pasta no acervo inteiro, com setas.
- **Excluir pasta digitando o número**, conferido no servidor; a exclusão vira lápide.
- **Duplicar pasta**, **Imprimir resumo**, **Favoritos de pasta** por usuário.
- **Janela de 15 min** para o autor editar seu comentário, com "· N min".
- **Responder** no Registro, com recuo e "Resposta a X".
- **Notas técnicas do processo** com editor rico.
- **Administrativo sem processo** (dado e regra próprios).
- **Metas:** filtros, renomear, reabrir e sino "Alertar para verificar" que gera notificação real (1/h).
- **Busca inline de cliente** com máscara de CPF/CNPJ no servidor e janela "Detalhes do cliente".
- **Próximos prazos** com .ics e cópia.
- **Push:** filtro de novas, marcar lida/não lida, criar meta a partir da publicação.
- **Editor rico:** realce, localizar/substituir, revisão e autocorreção.
- **Dashboard:** preferências por usuário no servidor, foto diária do estoque (comando pronto), painel por
  regras.
- **Visualizador seguro** de DOCX, planilhas, ODT, PPTX, RTF, EML e ZIP sem executar nada do arquivo.

**Publicadas na EI2 (smoke de produção pendente):**
- **Timeline inteligente por regras:** junta mensagens, `audit_log`, Push e pagamentos (estes só para quem
  vê o financeiro), com busca, chips, período e pessoa.
- **@menção no Registro:** autocomplete só com quem vê a pasta, notificação sem duplicata; na edição, token
  forjado vira texto comum.
- **Notificação ao autor** quando alguém responde o comentário dele.
- **Relatório da meta** em drawer e "Alertado: Nome às HH:MM" lido do banco.
- **Botão Cadastro** com pendências do cliente e **edição dos 3 contatos** na janela do cliente.
- **Vincular cliente e marcar Administrativo sem recarregar**; desvincular devolve o novo principal.
- **Corrigir valor de lançamento** com histórico do `audit_log` e recusa 409 quando a tela está velha.
- **Parcelamento Price** calculado no servidor (1–60 parcelas, juros a.m., a última absorve a diferença);
  entrada e parcelas nascem pendentes.
- **E-mail do arquivo ao cliente por `mailto:`** (sem mailer, sem anexo).
- **Filtro "Geram prazo"** no Push.
- **Pasta excluída somente leitura** em todas as rotas de escrita, com teste de arquitetura.
- **Documentos:** explorador com 8 modos e colunas móveis; **favoritos** de arquivo e subpasta; **lixeira**
  (lápide, Desfazer, restaurar, purga só manual por comando); **zip** montado no servidor (500 arquivos,
  1 GB, `LEIA-ME.txt`, auditado); **copiar/colar** arquivos; **limpeza por regras** (Idêntico, nome
  parecido, 0 KB, cópia do processo, muito grande); **visor em tela cheia** com zoom e impressão;
  **estado do checklist** com motivo; **exigido pelo juízo** (regex sobre o teor do Push, com origem);
  metadados enviado por, modificado em e páginas; **caixas de seleção**.
- **Visualizador:** ODP e ZIP64.
- **Dashboard:** setas por linha, som do calendário, 6 colunas extras.

---

## 7. BlueJus IA

**Estado em produção:** **desligada.** Provedor `nao_configurado`, `IA_HABILITADA` ausente, 0 escritórios
configurados, 0 análises, 0 concessões das permissões. **Nada é simulado:** o único provedor com resposta
programada (`ProvedorFalso`) só existe em `when@test`. Sem provedor, as telas dizem "IA não configurada
nesta instalação". O painel "BlueJus Intelligence" do Dashboard e a Timeline inteligente **não são IA**:
são regras fixas, e o rótulo diz isso.

### 7.1 Já implementado
- Domínio `App\Inteligencia` (71 arquivos) com regras documentadas em `app/src/Inteligencia/CLAUDE.md`.
- Porta `ProvedorDeLinguagem` com exceções que separam falha transitória de definitiva.
- **Fatia 1:** "Resumir com IA" no Push (Controller → UseCase → linha `pendente` → fila → handler → fluxo →
  interpretador).
- **Fatia 2:** 7 agentes da pasta (gestor, processual, documental, prazos, relatórios, cliente, jurídico),
  cada um lendo só o que a sua spec permite; o financeiro só entra se quem pediu o vê.
- Assíncrono pelo Messenger (`async`), handler idempotente que revalida o tenant; worker de produção já
  existe.
- Tabelas `inteligencia_analise` e `inteligencia_configuracao` (1:1 por escritório, desligada por padrão,
  limites 50/dia e 500/mês, mascaramento ligado, registro de consentimento), incluídas na purga de
  escritório.
- Disponibilidade em camadas: plataforma → escritório → módulo → cota; rate limiter de 10/min por usuário.
- Segurança: tenant explícito no worker, máscara de CPF/CNPJ/telefone/e-mail, **segredo de justiça nunca
  enviado** (409 antes de ler), defesa contra injeção de prompt, resposta escapada, log sem o prompt.
- Telas: botão e lista no Push, drawer dos 7 agentes, admin `/admin/inteligencia`; comando
  `app:inteligencia:status`.

### 7.2 Ainda falta
| Item | Quem destrava |
|---|---|
| Adaptador do provedor real (sobre `HttpClientInterface`) e troca do alias | autônomo **depois** que D-IA escolher o provedor |
| Chave, modelo e URL (`IA_API_KEY`, `IA_MODELO`, `IA_BASE_URL`) e `IA_HABILITADA=1` no `.env.prod` | Samuel / serviço externo |
| LGPD: contrato zero-retention, fornecedor de LLM no Anexo I da Política, comunicação aos contratantes, transferência internacional | Samuel (D-IA) |
| Texto de aceite ou bloqueio antes de o escritório ligar | autônomo depois de D-IA |
| Papéis que recebem as 2 permissões | Samuel (D-IA2) |
| Custo, cota e quem paga | Samuel (D-IA) |
| Runbook "ligar a IA em prod" (env, rebuild, smoke, rollback) e worker no compose de dev | autônomo |
| "Perguntar à IA", chat e fatias seguintes | frentes futuras, depois do provedor |

---

## 8. Banco e infraestrutura

### 8.1 Migrations das trilhas (13, todas em produção; a Trilha A não criou nenhuma)
| Versão | Finalidade | Entrou em |
|---|---|---|
| `Version20261006002519` | `nota_tecnica` | EI1 |
| `Version20261006010231` | `pasta_mensagem.resposta_a_id`, `eh_resposta` | EI1 |
| `Version20261006120000` | `inteligencia_configuracao`, `inteligencia_analise` + 2 permissões | EI1 |
| `Version20261006121500` | `pasta_favorita` | EI1 |
| `Version20261006134500` | `pasta_documento.sha256` + índice `(tenant_id, sha256)` | EI1 |
| `Version20261006150000` | `inteligencia_analise.agente`, `texto_da_analise` | EI1 |
| `Version20261006160000` | `pasta.administrativa` | EI1 |
| `Version20261006161500` | `preferencia_usuario` | EI1 |
| `Version20261006171500` | `dashboard_foto` | EI1 |
| `Version20261006230000` | `pasta_documento.enviado_por_id`, `modificado_em`, `paginas` | EI2 |
| `Version20261007000000` | `pasta.checklist_desativado_em`, `_por_id`, `checklist_motivo` | EI2 |
| `Version20261007010000` | `pasta_documento_favorito` | EI2 |
| `Version20261007020000` | lixeira: `excluido_em`, `excluido_por_id` em documento e seção + índices parciais | EI2 |

Todas são só aditivas; o rollback por imagem foi provado duas vezes. Ressalva: depois de um rollback da
EI2, o código antigo mostraria como vivo o que estiver na lixeira (hoje 0 itens; contar antes).

### 8.2 Mudanças estruturais
- **Lixeira:** `LixeiraFilter` (Doctrine) aplica `excluido_em IS NULL` em toda entidade `Descartavel`; só é
  desligado ao restaurar, purgar ou listar a lixeira. O Sync do Drive ignora a lixeira.
- **Somente leitura em pasta excluída:** `PastaSomenteLeituraListener` + atributo `#[PastaPelaFilha]` +
  teste de arquitetura.
- **Auditoria:** enum gravado por `->value`; desfazer converte pelo tipo do campo, tudo-ou-nada.
- **Favoritos** (`pasta_favorita`, `pasta_documento_favorito`) são preferência pessoal e não auditados.

### 8.3 Storage
A abstração de armazenamento (E2, `ArmazenamentoDeArquivos`, anterior às trilhas) já estava em produção;
as trilhas só a consumiram (zip em área temporária privada, cópia transacional, remoção após a transação).
Registrado: uma URL assinada do R2 (E4) quebraria o fetch do visualizador.

### 8.4 Filas e worker
`jusprime_worker_prod` consome `async`. A IA tem a mensagem `ProcessarAnaliseDeInteligencia` roteada, sem
tráfego hoje. A EI2 não trouxe mensagem nova. O compose de **dev não tem worker**.

### 8.5 Crons e comandos (nenhum configurado)
| Comando | Para quê | Estado |
|---|---|---|
| `app:dashboard:fotografar` | foto diária: tendências de Vencidas e Prazos | **NÃO configurado** (0 linhas). Linha recomendada: `55 2 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:dashboard:fotografar` (crons da VPS rodam em UTC; sem `-w` não roda) |
| `app:documentos:purgar-lixeira --dias=30` | purga da lixeira | **NÃO configurar: decisão do Samuel de 07/10** (lixeira sem cron e sem purga automática). Só execução manual |
| `app:documentos:calcular-hash [--paginas]` | backfill de sha256 e páginas | **Nunca rodou** (22.565/22.565 NULL). Começar por `--dry-run --limite=500`, depois em fatias com `nice`/`ionice` |
| `app:inteligencia:status` | diagnóstico da IA | sob demanda |

### 8.6 Dependências externas (só onde alguma função exige)
| Dependência | Função que exige |
|---|---|
| Google Drive (403 desde 21/08) | rebaixar os 114 de 0 byte; renomear documento propagar ao Drive; purga × reimportação |
| LibreOffice na imagem (~300 MB) | visualizar DOC, PPT, MSG |
| OCR no servidor | "Ler PDF" e pontuação cadastral |
| PJe/MNI (credencial por tribunal) | "Dados do PJe salvos em…", abrir/atualizar no PJe, captura automática |
| Provedor de LLM + chave | toda a BlueJus IA |
| Servidor de chamadas | Chat, Central e Connect |

---

## 9. Pendências autônomas

Implementáveis sem decisão do Samuel, conferidas no código em `719de0e4`. Deduplicadas dos quatro
levantamentos. **Esforço:** P ≤ ½ dia · M ≈ 1 dia · G > 1 dia. Toda pendência que vira commit vai para a
próxima entrega e passa pelo ciclo normal (teste, revisão, smoke do dono).

| # | Pendência | Evidência | Depende de | Risco | Esf. |
|---|---|---|---|---|---|
| **Prioridade 1 — segurança, reversão e honestidade da tela** |||||
| 1 | `PastaSecaoController` usa `em->find()` sem tenant (l.105, 140, 181, 228, 246); só o TenantFilter protege. Pôr defesa em profundidade | `app/src/Pasta/Controller/PastaSecaoController.php` | — | MÉDIO (multi-tenant) | P |
| 2 | **Tela da lixeira promete o que não acontece:** "Itens ficam 30 dias na lixeira. Depois disso são apagados de vez." e o comentário "o cron roda", contra a decisão de não ter purga automática | `pasta-explorador.js:222, 2452, 2606` | — | baixo (função falsa em tela) | P |
| 3 | Desfazer auditoria com associação a `Cargo`/`Sede`/`Lotacao` recusa sempre (não são `TenantAware`) | `DesfazerAlteracaoAuditLogUseCase.php:398-416` | — | MÉDIO (reversão) | P |
| 4 | Atualizar o ledger `trilha-b-designer.md` com as divergências da §15 (publicação da EI2, decisões resolvidas, fila D) | §15 | — | baixo | P |
| 5 | `pm.max_children=5`: 5 zips simultâneos ocupam todos os workers PHP-FPM. Limiter no zip ou ajuste do pool | registrado no L8-backend | publicação do dono | MÉDIO (disponibilidade) | P |
| 6 | Linha legada com caminho inválido dá 500 antes de remover documento/seção (reconferir) | registrado no L4 | — | baixo | P |
| **Prioridade 2 — higiene e lacunas pequenas** |||||
| 7 | Upload legado `pasta_documento_upload` não grava `enviado_por`; nenhum template usa a rota: avaliar remover | `PastaController.php:1626+` | — | baixo | P |
| 8 | `modificado_em` só é gravado na edição de metadados (não no mover nem na peça de texto) | `EditarDocumentoDaPastaUseCase.php:79` | — | baixo | P |
| 9 | ODP abre sem o botão de zoom (`TIPOS_COM_ZOOM` sem `odp`) | `pasta-explorador-visor.js:72` | — | baixo | P |
| 10 | "Anexar" recusa ODT, ODP, RTF, PPTX e EML, que o visor abre. Ampliar `MIME_LIMITS` com validação de conteúdo | `UploadPecaUseCase::MIME_LIMITS` | — | baixo (validar MIME) | P |
| 11 | "Alertar <responsável>" no ⋮ do prazo: o backend existe (`tarefa_alertar`, 1/h), mas o comentário diz "não há back-end" e o item não é renderizado | `_dados_trilho.html.twig:41-43` | — | baixo | P |
| 12 | "Copiar link" no ⋮ da meta | `_metas.html.twig:25-26` | — | baixo | P |
| 13 | Ctrl+Espaço / Ctrl+setas no explorador (foco sem selecionar, padrão Windows). Conferir antes se o desenho pede | `pasta-explorador.js:1666, 1673` | conferir o dc | baixo | P |
| 14 | B10: `carregarPainel` apaga o `active` do menu (pré-existente); B21: setas ignoram favoritos (reconferir) | TB §3 | — | baixo | P |
| **Prioridade 3 — dinheiro (uma migration só, revisão rigorosa)** |||||
| 15 | **P16b** êxito/sucumbência no parcelamento: `pasta_pagamento.tipo`/`percentual`, vencimento nulo; resumo e régua ignoram a linha percentual | `ParcelamentoDaPastaInput.php:28`; `PastaPagamento` sem colunas | — | MÉDIO (dinheiro) | M |
| 16 | `#[ORM\Version]` (ou lock) em `PastaPagamento` contra dois POST simultâneos no corrigir valor. O ledger pôs "se o dono aprovar"; é técnico: **ratificar e fazer junto da 15** | `PastaPagamento.php` | ratificação | baixo | P |
| **Prioridade 4 — fidelidade funcional ao desenho** |||||
| 17 | @menção nas **notas técnicas** (o desenho diz "Use @ para mencionar alguém"); conferir também Detalhes e Financeiro | `nota-tecnica.js` sem menção | — | baixo | M |
| 18 | N3: título do cartão do Push = teor curto (DTO compartilhado com o módulo Push) | `_push_processual.html.twig:170` | — | baixo | P/M |
| 19 | N6: Responder na observação de Detalhes (migration `resposta_a`) | `_detalhes_obs.html.twig` | — | baixo | M |
| 20 | Links das colunas extras do Dashboard (Metas concluídas, Em revisão): `/tarefas/equipe` precisa filtrar por status | `dashboard/_extras.html.twig:10-14` | — | baixo | M |
| 21 | Setas de tendência nas colunas extras (as 4 com data têm lastro) | `_resultado.html.twig:392` | — | baixo | M |
| 22 | "Enquanto você estava fora" na timeline: guardar a última visita por usuário no servidor | `_timeline_inteligente.html.twig:13-15` | — | baixo | M |
| 23 | Detecção de tarefa/telefone/e-mail/CNJ na timeline com "Criar tarefa"/"Validar" e estado no servidor | `_timeline_inteligente.html.twig:8-11` | — | baixo | M/G |
| 24 | "Ver alteração" na timeline: guardar o texto anterior do registro | — | — | baixo | G |
| 25 | Etiqueta do registro (Combinado, Ligação, Atendimento, Observação) | `pasta.md` §2 (fila D) | — | baixo | M |
| 26 | Versões da nota técnica a cada edição | ledger B11 (fila D) | — | baixo | M |
| 27 | Selo "Assinado" por arquivo, marcado à mão (detectar assinatura digital seria externo) | `pasta.md` (fila D) | — | baixo | M |
| 28 | Foto do cliente (upload e ajuste; coluna + storage) | `pasta.md` §1 (fila D) | — | baixo (PII em storage) | M |
| **Prioridade 5 — infraestrutura e qualidade** |||||
| 29 | Runbook "ligar a IA em prod" e worker no compose de dev | §7.2 | — | baixo | P |
| 30 | Testes de comportamento de ODP e ZIP64 com fixture binária | só teste estático em `VisualizadorDocumentoArquiteturaTest` | harness de JS (§11, item 13) | baixo | M |
| 31 | HTTP Range no backend para o visualizador listar ZIP muito grande | TB §10.2 | — | baixo | M |
| 32 | Varredura de N+1 com o profiler (Pasta, Dashboard, Expediente; pasta 1025 no `saas_ux`) | o da timeline já foi corrigido | — | baixo | M |
| 33 | Renomear documento propagar ao Drive (só a pasta propaga) | spec Documentos D3 | Drive voltar | baixo | M |

**Já fechados no código** (não reabrir): N5 (⋮ do cabeçalho do Processo, `_processos_vinculados.html.twig:89`),
âncora `#pasta-msg`, novo principal ao desvincular, `tarefa_mensagem_editar` na lápide, total do tempo médio,
setas de Vencidas/Prazos por linha, ODP/ZIP64, as 5 rotas `int` da lápide, desfazer de enum e data.

---

## 10. Decisões do Samuel

### 10.1 FECHADAS (com evidência)
| Decisão | O que ficou decidido | Evidência | Data |
|---|---|---|---|
| D-FIN-ENTRADA | Entrada e parcelas nascem **pendentes**; o servidor recusa `entradaPaga` com 422 | TB §4; `8f5dbc5f` | 07/10 |
| D-DOC-IDENT | Selo Idêntico e limpeza **por nível aberto** | TB §10.6 | 07/10 |
| D-DOC-S3 | **Confirmação antes de excluir** mantida | TB §10.6 | 07/10 |
| D-DOC-S4 | Rótulo neutro "Sugerir documentos", sem ✦/IA sobre regra | TB §10.6 | 07/10 |
| Copiar (L8) | Copiar desabilitado quando a seleção só tem pastas | TB §10.6 | 07/10 |
| D-DOC-RET (cron) | **Lixeira sem cron e sem purga automática** | TB §10.6 | 07/10 |
| D-DOC-VAZIO (limpeza) | Os 114 arquivos de 0 byte ficam intocados e fora da limpeza | TB §10.6 | 07/10 |
| D-15MIN (Pasta) | Janela de 15 min do autor na Pasta | TB §3 B1 | 05/10 |
| B11 | Nota técnica do processo com editor rico e 15 min | TB §3 B11 | 05/10 |
| Publicação | EI1 como entrega intermediária; smoke visual da EI2 aprovado **no dev**; caixas de seleção pedidas | TB §8, §9.1, §10.6 | 06–07/10 |

### 10.2 Resolvidas por implementação, a ratificar (sem bloqueio)
Implementadas no default técnico, sem decisão dele registrada. Basta o Samuel confirmar ou pedir mudança.
| Item | O que foi feito |
|---|---|
| D-AUDIT-UNDO | O desfazer converte o tipo pela metadata e é tudo-ou-nada |
| D-DOC-RO (servidor) | Pasta excluída somente leitura em todas as rotas; zip liberado (a metade "esconder os botões" segue aberta) |
| D-DOC-SUB | "Nº · descrição" só no modo Conteúdo e no painel, **como o desenho** (a pergunta do ledger está desatualizada) |
| D-DOC-ORD | Ordem "Manual" = `ordem` e depois nome |
| D-DOC-PRAZO | Só "prazo de N dias" no teor vira prazo no "exigido pelo juízo" |
| D-DOC-EDITAR | "Editar…" no menu é o acesso à categoria |
| D-DOC-BARRA | A barra de seleção não tem "Mover para…" (segue o desenho; mover fica no menu) |
| D-PUSH-HORA | O canto da hora fica vazio porque o DJEN não fornece hora |
| S-1, S-2, S-5, S-8, S-11, S-12 | Divergências de Documentos marcadas "registrar e seguir" (§5.3), aprovadas só implicitamente no smoke de tela |

### 10.3 ABERTAS (35)
Formato: **decisão** — por que precisa dele · opções · **recomendação técnica (não é decisão)** · impacto de adiar.

**A. IA e dados a terceiros**
1. **D-IA** — provedor, custo, cota, LGPD e o que sai do sistema. *Por quê:* contrato com terceiro, gasto e
   responsabilidade de controlador. *Opções:* Anthropic, OpenAI, Gemini, Azure OpenAI (auto-hospedado é
   inviável sem GPU). *Rec.:* provedor comercial com zero-retention, só sob demanda, cota baixa, PII
   mascarada, sigilo nunca enviado. *Adiar:* IA segue desligada, sem dano.
2. **D-IA2** — papéis que recebem as permissões de IA. *Por quê:* enviar dado a terceiro é capacidade
   sensível. *Opções:* manual por escritório ou migration para o admin. *Rec.:* manual. *Adiar:* só o
   admin (bypass) usa.
3. **D-LINK** — Push Compartilhado (link público). *Por quê:* dado de cliente sem login. *Rec.:* snapshot no
   servidor, token + código de 6 dígitos com hash, limite, revogação e auditoria. *Adiar:* sem link.
4. **D-CONNECT** — Chat, Central e ligações. *Por quê:* escopo de produto e servidor de chamadas. *Rec.:*
   frente própria. *Adiar:* nada regride.
5. **D-PJE** — PJe/MNI e OCR. *Por quê:* credencial por tribunal. *Rec.:* frente própria; o Datajud já cobre
   a capa. *Adiar:* nada regride.

**B. Papéis e regras de negócio**
6. **D-MASTER** — o que é "Master" e "usuário primário" (edição sem limite, cadeado, abas ocultas, zerar).
   *Opções:* Master = super admin; primário = `Tenant.criadoPor`, campo novo ou todo admin. *Rec.:* Master
   = super admin; primário = campo novo explícito, com spec MÉDIO antes. *Adiar:* fica só "autor 15 min".
7. **D-15MIN (resto)** — a janela vale para Tarefa e Kanban (a Cobrança usa 48h)? *Rec.:* sim para Tarefa e
   Kanban, Cobrança à parte. *Adiar:* inconsistência entre módulos.
8. **D-SITUACAO** — Suspensa e Cancelada. *Rec.:* Suspensa nas listas sem alertas; Cancelada como arquivada.
   *Adiar:* sem as duas situações.
9. **D-FIN** — criar `modules.financeiro.view`? *Rec.:* só quando houver papel sem acesso ao financeiro.
   *Adiar:* o resumo impresso sempre leva o financeiro.
10. **D-PERM-META** — quem renomeia e reabre meta. *Rec.:* manter a guarda do "concluir".
11. **D-DESTAQUE** — destaque de comentário da equipe ou por pessoa? *Rec.:* por pessoa.
12. **D-ACOMP** — "Acompanhar alterações" ligado ou desligado por padrão? *Rec.:* desligado.
13. **D-CARTEIRA** — "Mover para outra carteira". *Rec.:* não fazer.
14. **D-EQUIPE** — rotular pessoas ("crítico" etc.). *Rec.:* não rotular; só números.
15. **D-PRAZOS** — motor de prazos e fases. *Rec.:* não fazer sem spec e feriados por tribunal.
16. **D-PJ** — aval do texto de qualificação e das pendências de pessoa jurídica.
17. **D-DOC-S6** — cobrança automática do checklist (quem, quando, canal). *Adiar:* sem cobrança; estado e
    motivo já existem.
18. **D-DOC-DRIVE** — o que a purga faz com o arquivo no Drive. *Opções:* mover no Drive, registro de
    `drive_file_id` purgado, aceitar. *Rec.:* registro mínimo. *Adiar:* hoje nulo (sem purga, Drive em 403).
19. **D-DOC-RET (prazo)** — quantos dias de retenção e quando purgar, mesmo à mão. *Adiar:* a lixeira só
    cresce em disco (hoje 0 itens).
20. **D-DOC-VAZIO (recuperação)** — rebaixar os 114 arquivos quando o Drive voltar? *Rec.:* sim. *Adiar:* 114
    ilegíveis na tela.
21. **D-DOC-RO (tela)** — esconder os botões de escrita para quem só lê a pasta excluída. *Rec.:* esconder,
    com teste. *Adiar:* o botão aparece e o servidor recusa.

**C. Desenho × sistema (desviar do desenho exige perguntar)**
22. **D-PASTA6** — os itens da 2ª auditoria (selo "0", faixa "Notas técnicas 0", "Última distribuição",
    placeholder do financeiro, "Lida", vazio do Push, iniciais). *Rec.:* seguir o desenho. *Adiar:*
    divergência visual, sem risco de dado.
23. **D-RELINI** — "Pontos do relatório inicial". *Rec.:* só o aviso, sem a linha vermelha.
24. **D-META** — prazo e responsáveis obrigatórios na nova meta; "Ação" só leitura; Duplicar meta.
25. **D-BARRA** — barra global e Ctrl+K. *Rec.:* frente própria.
26. **D-EDITOR** — link, imagem, tabela no editor. *Rec.:* link `https` com `noopener` e imagem por upload
    interno; o resto fora.
27. **D-DASH** — troféu, período padrão, zerar relatório, tendência de ativas, som do campeão, acesso restrito.
28. **D-DOC-S7** — soltar arquivo dentro do visor.
29. **D-CAD-MODAL** — o botão Cadastro abre a janela Detalhes (hoje) ou o modal próprio do desenho.
30. **D-META-LINK** — "Abrir em Metas" leva a `tarefa_show` ou a `/tarefas/equipe`.

**D. Clientes**
31. **D-CLI-AUDIT** — auditar o Cliente. *Opções:* `Auditavel` ou log próprio. *Rec.:* `Auditavel`, frente
    própria. *Adiar:* troca de contato e cadastro sem rastro (o P12 agrava: edita PII sem histórico).
32. **D-CLI-CONTATOS** — lista livre de contatos (vários e-mails, principal, pausar avisos). *Por quê:* modelo
    novo e migração de dados. *Adiar:* ficam 3 slots fixos.

**E. Infra e frentes vizinhas**
33. **D-LIBRE** — LibreOffice na imagem (~300 MB) para DOC/PPT/MSG. *Adiar:* esses formatos só baixam.
34. **D-DOC-S9** — aplicar o L0 da Cobrança (patch de 1 linha + 2 testes; a Cobrança está fora do escopo).
    *Rec.:* aplicar em commit isolado. *Adiar:* segue a faixa vazia de 356px no Objeto.
35. **P17b "Pastas concluídas"** — sem lastro: a pasta só tem ativo/arquivado. *Opções:* aceitar "arquivada"
    ou criar data de conclusão (ligada a D-SITUACAO).

---

## 11. Problemas conhecidos e dívida técnica (separada do Designer)

| # | Dívida | Medida | Ação | Quem |
|---|---|---|---|---|
| 1 | 2 migrations antigas do Ponto em skip (`Version20260401000000`, `Version20260408180237`) | prod 206 × repo 208; aparecem sempre como "New" | registrar como executadas (`migrations:version --add`) ou documentar; **nunca `migrate` sobre elas** | dono (escrita em prod) |
| 2 | **Drive parado com 403** | último sync 21/08 09:34; 130 pastas sem `drive_folder_id` (eram 44 em 02/09) | frente própria: credencial/OAuth no Google Console | dono |
| 3 | 114 documentos com 0 byte (DT-8) | MCP 07/10 | rebaixar do Drive quando o 403 cair | depende do 2 |
| 4 | Defeito L0 da Cobrança (faixa vazia no Objeto, em prod desde `90c7f773`) | patch descrito na spec Documentos §3 | commit isolado | precisa do OK (D-DOC-S9) |
| 5 | Disco da VPS: 19 GB livres, build cache de 6,8 GB | TB §9.5 | `docker builder prune` com filtro, junto da política de build | dono |
| 6 | 9 deprecations (5× `RateLimiterFactory` por autowiring, `UserChecker::checkPostAuth`, 2 opções do `doctrine.yaml`, precedência de `??` em `ponto/_folha_table.html.twig:147`) | `debug:container --deprecations` | corrigir antes do Symfony 8/DoctrineBundle 3; o do Ponto é risco ALTO (com teste) | autônomo (P cada) |
| 7 | Arquivos soltos na VPS com segredo (`.env.prod.backup-*` legíveis, `atualizar.sh`, diretório de 07/08) | TB §9.5; memória de segurança | mover para `/opt/backups` com 600 ou apagar | dono |
| 8 | 61 worktrees e 111 branches locais (83 `worktree-agent-*`); uma tem commit de cobrança fora do master | `git worktree list` | auditar com `git cherry` antes de remover; **não tocar** a `worktree-agent-a40e8d8ebf3d119ca` | dono |
| 9 | `audit_log` não cobre Cliente, Processo nem Kanban | `Cliente.php` sem `Auditavel` | D-CLI-AUDIT | Samuel decide |
| 10 | 84 testes unit mockam `EntityManager`, contra `app/tests/CLAUDE.md` (70 antigos, 14 da Trilha B) | grep em `tests/**/Unit/` | migrar por domínio, começando pelos 14 novos | autônomo (M/G) |
| 11 | Rollback da EI2 esconderia a lixeira | `publicacao-ei2…md` §3.3 | contar a lixeira antes de qualquer rollback | dono |
| 12 | Sem `lock_timeout` no migrate de produção | `publicacao-ei2…md` §1.2 | `SET lock_timeout` no entrypoint ou publicar fora do minuto múltiplo de 15 | autônomo (P) |
| 13 | Sem harness de teste de comportamento de JS | Cobrança sem teste de JS; Documentos e visor só com teste estático | introduzir runner (Vitest/jsdom) | autônomo (G) |
| 14 | Crons da VPS em UTC e comandos sem `-w /var/www/app` | memória `project_deploy_e2_pre_deploy` | conferir `crontab -l` ao configurar a foto | dono |

---

## 12. Testes e qualidade

### 12.1 Evolução da suíte (testes / asserções)
| Ponto | Commit | Suíte |
|---|---|---|
| Trilha A integrada | `beff02fd` | 5633 / 21.045 |
| Fim de B1–B39 | `e5b7aae5` | 6674 / 26.450 |
| Homologação B | `4a919751` | 6682 / 26.523; Playwright 52/52 |
| Fim da sessão 2 (Documentos + P1–P19) | `dbb6e8cc` | 7741 / 34.837 |
| Homologação EI2 | `7a5d698f` | 7755 / 34.956 |
| **Atual** | `230a2c43` | **7767 / 35.122**; smoke das caixas 9/9 |

Nas trilhas entraram 218 arquivos de teste e 230 de código novos.

### 12.2 Como foi revisado
- **Trilha A:** `feature-review-agent` por frente + auditoria de fidelidade item a item.
- **Homologação B:** três revisões read-only (ações destrutivas e migrations; autorização, tenant, IDOR e
  CSRF; regressão e IA desligada).
- **Documentos e pendências:** revisor por lote, com re-revisão quando havia bloqueante (L5 em 3 rodadas).
  Revisão de segurança própria da IA.
- **Prova por mutação:** o defeito foi reintroduzido para ver o teste cair (ex.: 21 testes caem com a
  janela trocada para 24h).
- **Rollback:** suíte das imagens antigas sobre o banco migrado e dry-run num clone do estado de produção.

### 12.3 Segurança e multi-tenant
- Testes de arquitetura: rotas de escrita da lápide, visualizador, polling da IA (e o de entrega de arquivo,
  anterior às trilhas).
- `LixeiraFilterTest` e `PurgaCoberturaSchemaTest` (toda tabela nova com `tenant_id` entra na purga).
- Padrão de todo endpoint novo: outro escritório → 404; pasta irmã do mesmo escritório → 404 sem efeito
  parcial; CSRF por pasta e por ação.

### 12.4 Defeitos importantes achados e corrigidos
Excluir a última pasta com documento na lixeira dava 500 · Sortable com busca gravava ordem parcial ·
@menção forjável na edição · 5 rotas de escrita escapavam da lápide · modal "Adicionar pagamento" não
inicializava (defeito antigo) · duplo clique abria o arquivo vizinho · avatar do autor quebrado com 500
(defeito da Trilha A já em produção) · Pasta consultava tabelas da IA sem plataforma · migration da IA
apagada por outra worktree e restaurada.

### 12.5 O que a suíte não prova
Aparência. A suíte verde não diz nada sobre posição, cor ou fonte. Não foram testáveis no dev: copiar/colar
e impressão (os arquivos não existem em `uploads/pastas`), selo Idêntico e faixa de limpeza (sem sha na
pasta 223), zoom e impressão de PDF no Firefox. **Tudo isso fica para o smoke de produção.**

---

## 13. Caminho para 100% do Claude Designer

### A. Trabalho autônomo (§9)
33 pendências, em 5 prioridades. As de prioridade 1 (segurança, reversão, texto falso da lixeira, ledger)
são todas P e devem vir primeiro.

### B. Decisões do Samuel (§10.3)
35 decisões abertas + 9 itens a ratificar. Sugestão de agrupar em uma sessão de decisão:
1. **Ratificar** os 9 itens da §10.2 e a pendência 16 (`#[Version]`): rápido, destrava sem risco.
2. **Desenho × sistema** (Bloco C, 9 itens): tiram a maior parte das diferenças visuais.
3. **Clientes e papéis** (D-CLI-AUDIT, D-MASTER, D-SITUACAO): destravam frentes inteiras.
4. **IA** (D-IA, D-IA2): só quando ele quiser ligar a IA.

### C. Serviços e credenciais externas (7)
1. Provedor de LLM + chave + adequação LGPD.
2. Credencial do Google Drive (403).
3. PJe/MNI por tribunal.
4. OCR no servidor.
5. LibreOffice na imagem.
6. Servidor de chamadas (Chat/Connect).
7. **Execução na VPS pelo dono:** cron da foto (com `-w` e em UTC), `calcular-hash` em fatias,
   `crontab -l`, registro das 2 migrations do Ponto.

### D. Validações finais
1. **Smoke visual de produção da EI2 — PRIMEIRO, antes de qualquer nova entrega.** Roteiro mínimo:
   - fazer **um upload real** pelo "Anexar" (confirma se a ausência de uploads desde 05/10 é falta de uso
     ou defeito) e conferir que o sha foi gravado;
   - Documentos: modos, colunas, caixas de seleção, laço, menu, lixeira (excluir, desfazer, restaurar),
     zip, copiar/colar, visor (zoom, imprimir PDF), em Chromium e Firefox e em 375px;
   - Financeiro: parcelamento, corrigir valor, modal de pagamento;
   - Registro: @menção, resposta e as notificações chegando;
   - Timeline inteligente; Metas (relatório, "Alertado às"); Clientes (vincular, contatos);
   - Dashboard: setas por linha, colunas extras;
   - pasta excluída: nada de escrita.
2. Depois do cron e do hash: conferir tendências do Dashboard e selo Idêntico em produção.
3. Ao fim de cada nova entrega: percorrer o desenho item a item (✓/✗) antes de entregar.

### E. Dívida técnica (§11, separada)
14 itens. Os mais urgentes: Drive (2), segredos soltos na VPS (7), `lock_timeout` (12) antes da próxima
publicação.

### Ordem recomendada de execução
1. **Smoke de produção da EI2** (Samuel). Se achar defeito, corrigir antes de tudo.
2. **Execução operacional do dono:** cron da foto e `calcular-hash` em fatias (dry-run primeiro).
3. **Pendências autônomas P1** (#1–#6) e **P2** (#7–#14): uma entrega pequena, de baixo risco.
4. **Sessão de decisão com o Samuel:** ratificações + Bloco C + Clientes/papéis.
5. **P3 (dinheiro):** P16b + `#[Version]` numa migration, com revisão rigorosa.
6. **P4 (fidelidade funcional)** junto com o que as decisões do passo 4 liberarem.
7. **P5 e dívida técnica**, em frentes próprias.
8. **IA e integrações externas** quando o Samuel decidir D-IA e houver credencial.

---

## 14. Percentual aproximado de conclusão por área (ESTIMATIVA)

> **São estimativas, não medições.** Critério geral: proporção das funções que o desenho mostra na área,
> com peso igual, contadas nos inventários.
> - **Código / autônomo:** implementado sobre o que não depende de decisão nem de serviço externo.
> - **Código / desenho todo:** implementado sobre tudo o que o desenho mostra.
> - **Validado em produção:** parte já homologada pelo Samuel **e** em produção (EI1). Tudo o que veio na
>   EI2 conta zero até o smoke de produção.

| Área | Código / autônomo | Código / desenho todo | Validado em produção | Critério específico |
|---|---|---|---|---|
| Dashboard | ~90% | ~75% | ~55% | F1–F36 do inventário; tendências da foto inertes sem cron |
| Pasta / cabeçalho | ~90% | ~65% | ~50% | itens da tabela do cabeçalho |
| Dados / Registro | ~85% | ~60% | ~40% | menção e notificação de resposta são EI2 |
| Processo | ~95% | ~60% | ~45% | o PJe é o bloco grande que falta |
| Metas | ~95% | ~80% | ~55% | relatório e "Alertado às" são EI2 |
| Clientes | ~95% | ~75% | ~45% | vincular sem recarregar e contatos são EI2 |
| Financeiro | ~90% | ~80% | ~45% | corrigir valor e parcelamento são EI2; falta P16b |
| Push (aba) | ~90% | ~55% | ~45% | IA, monitoramento e Push Compartilhado pesam |
| **Documentos** | **~94%** (82/87) | ~91% (82/90) | **0%** | DOC-01…90; a aba nova é toda EI2 (a da EI1 foi reprovada); Idêntico e limpeza inertes sem hash |
| Visualizador | ~95% | ~85% | ~75% | formatos do `bj-visualizar.js`; faltam 5 que exigem LibreOffice |
| Editor | ~100% | ~65% | ~60% | link, imagem e tabela esperam D-EDITOR |
| Timeline | ~75% | ~60% | ~20% | só o drawer do Histórico é EI1 |
| BlueJus IA | ~90% da infraestrutura | 0% funcional | 0% | infra pronta; sem provedor, nenhuma função de IA roda |
| Permissões | ~95% | ~60% | ~40% | lápide é EI2; D-MASTER, D-FIN, D-IA2 abertos |
| Auditoria | ~85% | ~70% | ~50% | Cliente sem auditoria; desfazer de Cargo/Sede/Lotação |
| Notificações | ~80% | ~60% | ~30% | resposta e menção são EI2 |
| Integrações | — | ~20% | ~20% | Datajud e DJEN funcionam; Drive parado; PJe, LLM, OCR e chamadas ausentes |

---

## 15. Divergências encontradas no ledger

Prevalece o git e o código. Corrigir o ledger é a pendência autônoma #4.

1. **A publicação da EI2 não está registrada.** O cabeçalho de `trilha-b-designer.md` ainda diz "`3f199dfd`
   PUBLICADO"; o §10.1 diz "Nada publicado… NENHUMA em produção"; o §10.5 diz "origin/master `3f199dfd`"; o
   §10.6 termina em "pronto para preparar a publicação"; a tabela de estado do §1 parou em `4a919751` e o
   §2 segue "(preenchido após as investigações)". Evidência: push de `719de0e4` em 07/10 06:58 e 206
   migrations em produção. Também falta dizer que o "APROVADO" de 07/10 foi **no dev** e que o smoke de
   produção está pendente.
2. **D-AUDIT-UNDO** aparece aberta no §4, mas está implementada (`5a65f0d2`, `b89a4919`, `3381f6c7`; o §10.5 já
   diz "feito").
3. **D-DOC-RO** ainda pergunta no §4 se a lápide bloqueia escrita; P14/P14b implementaram o bloqueio em todas
   as rotas. Só a metade "esconder os botões" segue aberta.
4. **D-DOC-SUB** diz que "Nº · descrição" foi mantido sob o nome em Detalhes; o código o mostra só no
   Conteúdo e no painel desde `b1c49582`, que é o desenho. A pergunta está desatualizada.
5. **Lixeira:** a tela promete "Depois disso são apagados de vez" em 30 dias e o comentário da l.222 cita o
   cron, mas o Samuel decidiu **sem purga automática** (§10.6). Nada é apagado sem execução manual.
6. **"Anexar" recusa ODT, ODP, RTF, PPTX e EML** (`UploadPecaUseCase::MIME_LIMITS`), formatos que o visor
   abre. Não está registrado no ledger.
7. **ODP sem zoom:** `TIPOS_COM_ZOOM` não inclui `odp`, que entrou depois do L10.
8. **"Alertar responsável" no ⋮ do prazo:** o comentário de `_dados_trilho.html.twig:41-43` diz "não há
   back-end", mas `tarefa_alertar` existe desde a EI1.
9. **@menção:** o ledger registra "P19 @menção" sem ressalva e o plano previa "Registro/nota"; o código só
   cobre o Registro da aba Dados, não as notas técnicas.
10. **D-PASTA6:** o §4 diz "6 itens" e lista 7 (com o selo "0"); o §8 fala em "7 itens do dono".
11. **Fila D de `pasta.md`** (etiqueta do registro, foto do cliente, selo "Assinado", versões da nota técnica)
    não aparece em nenhuma lista de pendências do ledger nem no §4.
12. **N5:** `pendencias-pos-documentos.md` (l.479) e um dos levantamentos o dão como pendente; o código o tem
    desde o P13 (`_processos_vinculados.html.twig:89`).
13. **Cron da foto e backfill do hash** constam como pós-produção desde 06/10, mas nunca rodaram
    (`dashboard_foto` = 0; sha NULL em 22.565/22.565).
14. **Detalhes menores:** a homologação local da EI2 está datada de 07/10, mas `fc060f33` é de 06/10 23:55;
    "85 commits sobre `beff02fd`" vale para `e5b7aae5` (a EI1 levou 99); a Trilha A são 17 commits de frente
    + o registro (18 no intervalo); `EntregaDeArquivoArquiteturaTest` é anterior às trilhas.
