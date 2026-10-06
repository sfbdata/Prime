# Trilha B — pendências A/B do §9.4 depois de Documentos (investigação read-only, 06/10/2026)

Base: `master` em `dae06cf0` (a frente Documentos seguiu commitando durante a investigação:
`4d804529`, `dae06cf0` — nenhum dos dois toca os itens abaixo). Desenho: `docs/design/claude-design-2026-10-05 (1)/`
(`dc` = `02 - EXPEDIENTES 1.2.3.dc.html`; `dash` = `01 - Dashboard 1.2.2.dc.html`; `README-dash` =
`design_handoff_dashboard/README.md`). Fora deste relatório: aba Documentos (§9.3), IA/provedor (D-IA), itens C do §4.

Legenda da classe: **PRONTO** = implementável agora, lastro existe · **BACKEND** = precisa endpoint/UseCase/migration
novo (dito qual) · **SAMUEL** = depende de decisão do §4 (dita qual) · **FEITO** = já está no código ·
**SEM LASTRO** = o dado não existe (D/E).
Tamanho: P (≤ ½ dia) · M (1 dia) · G (> 1 dia).

---

## 1. Tabela-resumo

| # | Item | Estado no código | Classe | Tam. | Risco |
|---|---|---|---|---|---|
| 1 | Metas: sino "Alertado: Nome às HH:MM" | pendente (`_metas.html.twig:15` diz que não tem) | PRONTO | P | baixo |
| 2 | Metas: drawer "Relatório da meta" | pendente (`_metas.html.twig:15`) | PRONTO | M | baixo |
| 3 | Pasta: botão "Cadastro" com selo | pendente (`_cabecalho.html.twig:17` o lista como não renderizado) | PRONTO | P | baixo |
| 4 | Pasta: abas do modal Editar dados como atalhos (+ botão "Cadastro do cliente") | pendente (`show.html.twig:3495-3500`) | PRONTO | P | baixo |
| 5 | Pasta: interruptor Administrativo sem recarregar | pendente no JS; **backend já pronto** (XHR devolve `html`) | PRONTO | P | baixo |
| 6 | Pasta: vincular cliente sem recarregar (linha nova com ícone) | pendente (`pasta-clientes-busca.js:164` `location.reload()`) | BACKEND leve (devolver o HTML da linha) | M | baixo-médio |
| 7 | Expediente: estrela de favorito no cartão/celular | pendente (`_card.html.twig` não tem; `_tabela.html.twig:92` tem) | PRONTO | P | baixo |
| 8 | Extrair CASE duplicado `vizinha()`/`posicaoNoAcervo` | pendente (`PastaRepository.php:830-831` e `:896-897`) | PRONTO (refactor) | P | baixo |
| 9 | Push: filtro "Geram prazo" | pendente (só Todas/Novas, `_push_processual.html.twig:75-82`) | PRONTO (regra do desenho) | P | baixo |
| 10 | Financeiro: "Enviar por e-mail ao cliente" (arquivo) | pendente (`_financeiro.html.twig:294-296`) | PRONTO (`mailto:`, sem mailer) | P | baixo |
| 11 | Financeiro: modal "Adicionar pagamento" com parcelas/juros | pendente (modal simples, 1 lançamento) | BACKEND (UseCase de parcelamento; migration para tipo/percentual) | G | MÉDIO |
| 12 | Financeiro ⋮: "Editar ou corrigir valores" / "Corrigir valor" | pendente (`_financeiro_pagamentos.html.twig:72`) | BACKEND (UseCase + rota; histórico pelo `audit_log`) | M | MÉDIO |
| 13 | Checklist por regras / cobrança do checklist | auditoria "marcado sem anexo" FEITA (L11 de Documentos); cobrança pendente | SAMUEL (D-DOC-S6) | — | — |
| 14 | Timeline inteligente | pendente (`_cabecalho.html.twig:17`) | BACKEND (endpoint de eventos; motor no navegador) | G | médio |
| 15 | Pontuação da inteligência cadastral | pendente | SEM LASTRO (precisa OCR/extração — D) | — | — |
| 16 | Dashboard: sons (calendário; campeão) | pendente | calendário PRONTO (P); campeão SAMUEL (D-DASH: troféu) | P | baixo |
| 17 | Dashboard: "Adicionar coluna" do ⋮ | pendente | BACKEND (7 métricas no UseCase + preferência nova) — 1 métrica sem lastro | M/G | médio |
| 18 | Visualizador: ODP | pendente (`visualizador-documento.js:101-110`) | PRONTO | P | baixo |
| 19 | Visualizador: ZIP64 | pendente (`visualizador-documento.js:309,324` recusa) | PRONTO | P | baixo |
| 20 | Registro: notificação ao autor da resposta | pendente (`EnviarMensagemPastaUseCase` não notifica) | PRONTO | P | baixo |
| 20b | Registro/nota: @menção que notifica | pendente | BACKEND (parser + autocomplete de usuários do tenant) | M | médio |
| 21 | Clientes: edição inline de contatos na janela de detalhes | pendente (`cliente/_resumo.html.twig:6-7` "SOMENTE LEITURA") | BACKEND (rota + UseCase; modelo tem 3 slots fixos) | M | médio |
| 22 | D-AUDIT-UNDO | **confirmado** (`DesfazerAlteracaoAuditLogUseCase.php:113-128`) | PRONTO (correção) | M | MÉDIO |
| 23 | D-DOC-RO (escrita em pasta excluída) | listener cobre 61 de 66 rotas de escrita; **5 escapam** | PRONTO (correção) | P | MÉDIO |
| 24 | 3ª passada de fidelidade (Pasta e Dashboard) | ver §3 | A (CSS/markup) na maioria | M | baixo |

Itens do §9.4 que eu **não** reabri: Documentos sugeridos/duplicados (Documentos), "Perguntar à IA" (D-IA).
Nenhum item A/B estava inteiramente feito; os parcialmente feitos estão na linha de cada um (5, 13).

---

## 2. Detalhe por item

### 1. Metas — sino "Alertado: Nome às HH:MM"
- **Estado:** pendente. O comentário `app/templates/pasta/_metas.html.twig:15` lista o estado como "sem lastro"; o sino
  (`_metas.html.twig:97-120`) usa sempre `bi-bell` e o título fixo "Alertar para verificar".
- **Desenho:** dc L.4429-4438 (`sinoMeta`): depois de alertar, ícone `bi bi-bell-fill`, cor `#e8590c` (sem alerta: `#8496a3`),
  título `"Alertado: " + nome + " às " + HH:MM + ". Clique para alertar de novo"`; nos primeiros 8 s após o clique, animação
  `sinoBalanca 1s ease-in-out 2`. Botão 30×30, raio 4. O desenho guarda isso em memória (só a sessão de quem alertou).
- **Lastro:** EXISTE e é melhor que o do desenho: `Notificacao` (`src/Entity/Notificacao.php`) com `tipo =
  'tarefa_alerta_verificar'` (`AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO`), `tarefa`, `usuario` (destinatário) e
  `criadaEm`. Não há coluna de remetente — então o estado é "último alerta enviado para esta meta (por qualquer pessoa)".
  O próprio UseCase já formata `H:i` na recusa anti-spam (`AlertarResponsavelDaMetaUseCase.php:86-91`).
- **Decisão técnica a registrar:** alerta de outro dia mostra `dd/mm às HH:MM` (o desenho só conhece o mesmo dia).
  Animação só quando a página volta do POST (flash) — o form é POST comum.
- **Arquivos:** `src/Pasta/DTO/PastaMetasResumoOutput.php` (ou um repositório: 1 consulta agregada
  `MAX(criadaEm)` por tarefa da pasta, com o nome do destinatário — sem N+1), `templates/pasta/_metas.html.twig`,
  `public/css/pasta-show.css`; teste funcional da aba (título e classe do ícone) + unit do DTO.
- **Classe/tamanho:** PRONTO · P · risco baixo (só leitura; consulta tem de filtrar tenant — `Notificacao.tenant`).

### 2. Metas — drawer "Relatório da meta"
- **Estado:** pendente (`_metas.html.twig:15`; hoje o título é link para `tarefa_show`, `_metas.html.twig:59`).
- **Desenho:** markup dc L.1464-1508; dados dc L.3557-3600. Abre ao clicar na linha (`abrir`, dc L.6259) e pelo ⋮
  "Abrir relatório" (`bi-file-earmark-text`, dc L.3557). Aside fixo à direita, `width:min(460px,100vw)`, sombra
  `-20px 0 50px -20px rgba(10,40,60,.45)`, animação `drawerIn .32s cubic-bezier(.22,.8,.3,1)`; fundo `rgba(10,30,45,.28)`.
  Cabeçalho gradiente `135deg #0d80a3→#0b5f86`, ícone `bi-clipboard2-check` 16px, título 13.5/700, "N de M" (Arial 12px,
  opacidade .85), setas ▲▼ (28×28, `rgba(255,255,255,.14)`, opacidade .4 desabilitada), fechar (Esc).
  Corpo: pílula de situação (Concluída `#186c47/#e2f4ea` · Atrasada `#a3232b/#fdeceb` · Pendente `#8a5a12/#fdf1dc`, 11px/700
  caixa-alta), título 18/700 `#12242f`, prazo ("Concluída no prazo X" / "N dias em atraso · prazo X" em `#c0392f` /
  "Vence X"); grade 2 colunas de 6 campos (Criada por, Responsáveis, Prazo, Última modificação | "Sem alterações", Pasta,
  Situação) em caixas `#f6f9fb` raio 4; **Histórico** (pontos 10px: criada `#0a7aad`, prazo `#8496a3`, concluída/atualizada
  `#1f9d61`/`#c8952a`, atraso `#c0392f`); **Outras metas desta pasta** (barra 3×22 + pílula). Rodapé: "Marcar como
  concluída" (`#1f9d61`) / "Reabrir meta" (`#5f7684`) + "Abrir em Metas" (`#eef4f8`/`#0b5f86`).
- **Lastro:** completo em `Tarefa` (`src/Entity/Tarefa/Tarefa.php:35-69`): `criadoPor`, `dataCriacao`, `dataAlteracao`,
  `dataConclusao`, `prazo`, `status`, `responsaveis`. Concluir/reabrir já existem (`tarefa_concluir`, `tarefa_reabrir`,
  com CSRF); "Abrir em Metas" = `tarefa_show`. A frase "Concluída no prazo" já é calculada em `PastaMetasResumoOutput`
  (B13). **Fora** (sem lastro/decisão): "Duplicar", "Encaminhar via Chat", "Copiar link" do mesmo ⋮ (ledger B13).
- **Arquivos:** `PastaMetasResumoOutput.php` (expor criador/datas/responsáveis por linha), `_metas.html.twig` (um
  `<template>`/`data-*` por linha, sem rota nova), `public/js/pasta-metas.js` (abrir, navegar, Esc, foco), `pasta-show.css`.
- **Classe/tamanho:** PRONTO · M · baixo. **Mesmos arquivos do item 1 → mesmo lote.**

### 3. Pasta — botão "Cadastro" com o selo de pendências
- **Estado:** pendente (`_cabecalho.html.twig:16-17` diz que "Cadastro" não é renderizado). O serviço existe:
  `src/Cliente/Service/PendenciasDoCadastro.php`, exposto como Twig `cliente_pendencias()`
  (`src/Cliente/Twig/CadastroDoClienteExtension.php:27`), já usado no trilho (`_cliente_linha.html.twig`).
- **Desenho:** dc L.1251-1255 (na faixa das abas, à direita, antes da pílula de Situação); estilos dc L.3284-3285, 3290:
  botão `height:44px; padding:0 15px; raio 4; 13px/700; letter-spacing .06em; caixa-alta; fundo #eef2f5; cor #34505f`
  (hover `#e3eff6`/`#0a7aad`), ícone `bi-person-vcard` 15px com ponto de 8px no canto (`right:-4px;top:-3px`, anel
  `0 0 0 2px #eef2f5`) **verde `#1f9d61` completo / âmbar `#d39222` incompleto**. Título/aria: "Cadastro completo · abrir
  qualificação do cliente" ou "Cadastro incompleto: N pendência(s) · clique para completar".
- **Lastro:** `pasta.clientePrincipal` + `cliente_pendencias()`. Destino do clique (o desenho abre o modal próprio de
  cadastro, dc L.602-743, que é G e fora): **`cliente_edit`** quando incompleto e a janela "Detalhes do cliente"
  (`cliente_resumo`, B17) quando completo — ou `cliente_edit` sempre (decisão técnica simples; registrar).
  Pasta sem cliente cadastrado (1.152/1.201 em prod, `_cabecalho.html.twig:29-30`): não desenhar.
- **Arquivos:** `_cabecalho.html.twig` (bloco `ps-abas-faixa`, L.470), `pasta-show.css`; teste funcional (completo × incompleto ×
  sem cliente, + permissão de editar cliente para o link).
- **Classe/tamanho:** PRONTO · P · baixo.

### 4. Pasta — abas do modal "Editar dados" como atalhos
- **Estado:** pendente; o comentário `show.html.twig:3495-3500` lista "as abas de atalho (Cliente/Processo/Histórico)" e
  o botão "Cadastro do cliente" como não feitos.
- **Desenho:** dc L.570-572 (faixa `#eef2f5`, borda inferior `#e0e7ec`, `padding:0 10px`, gap 2) e dc L.4299-4301:
  4 abas `height:40px; padding:0 12px; 13px/700; ícone 12px`; ativa com `border-bottom:2px solid #0a7aad`, cor `#0a6a96`;
  inativas `#4f6878`. "Dados da pasta" (`bi-folder2`, ativa, sem ação) · "Cliente" (`bi-person`: fecha o modal e abre o
  cadastro do cliente) · "Processo" (`bi-bank`: fecha e vai à aba Processo) · "Histórico" (`bi-clock-history`: fecha e abre o
  drawer de histórico). Rodapé ganha "Cadastro do cliente" (`bi-person-vcard`, `#e3eff6`/`#0a6a96`, dc L.587).
- **Lastro:** tudo existe — aba Processo (`#processo`), drawer `_historico_drawer.html.twig` (B35), cliente_edit/resumo.
  "Sem perder campo" = nenhum campo do form atual sai (o contrato `pasta_edit` fica intacto). O desenho descarta o que foi
  digitado ao trocar de aba (`peForm: null`); proposta segura dentro do desenho: se o form estiver alterado, `confirm()`
  antes de sair (o desenho tem a pílula "Alterações não salvas", que é D-META — não entra).
- **Arquivos:** `show.html.twig` (só `#modalEditarPasta`, L.3504-3600) + JS curto no mesmo bloco, `pasta-show.css`.
- **Classe/tamanho:** PRONTO · P · baixo. **Junto com o item 3** (mesmo destino "Cadastro").

### 5. Pasta — "Administrativo sem processo" sem recarregar
- **Estado:** backend PRONTO — `PastaAdministrativaController.php:74-81` já devolve
  `{sucesso, administrativa, html: _processos_vinculados}` em XHR; o swap existe (`window.mpSwapProcessos`,
  `show.html.twig:2487-2494`). Falta só o JS: nada intercepta `.js-pasta-administrativa` (grep vazio); hoje é POST +
  redirect. A confirmação está em `onsubmit` inline (`_processos_vinculados.html.twig:73-74`).
- **Desenho:** dc L.6588 (`admSPtoggle`): troca na hora, `confirm()` com o texto já usado quando há processo principal.
- **Arquivos:** `public/js/pasta-processo.js` (delegação no `document`, como o resto do arquivo — `_processos_vinculados:40`),
  `_processos_vinculados.html.twig` (trocar `onsubmit` por `data-confirmar`). Teste: o controller XHR já tem teste (B36);
  acrescentar asserção do atributo; o comportamento JS é smoke do dono.
- **Classe/tamanho:** PRONTO · P · baixo.

### 6. Pasta — vincular cliente sem recarregar
- **Estado:** pendente. `public/js/pasta-clientes-busca.js:164` faz `window.location.reload()`; o modal antigo monta a
  linha por um **espelho JS** (`show.html.twig:1155-1175`, `criarClienteRow`) que não tem o ícone completo/incompleto
  (B17) — "a linha nova sem ícone".
- **Lastro:** `PastaController::vincularCliente` (`src/Controller/PastaController.php:923-965`) já responde JSON com
  tokens. **Proposta:** acrescentar `html` = `renderView('pasta/_cliente_linha.html.twig', …)` à resposta (e à de
  `pasta_cliente_novo`, L.475) e fazer o JS inserir esse HTML; o espelho JS passa a ser removível (fim da divergência
  servidor × JS que o próprio comentário L.1155-1158 teme). Atualizar o principal com o `payloadClientePrincipal` já devolvido.
- **Arquivos:** `PastaController.php` (2 ações), `pasta-clientes-busca.js`, `show.html.twig` (bloco do modal, ~L.1140-1300),
  talvez `_dados_trilho.html.twig`. Testes funcionais: `html` presente, contém o ícone, cross-tenant inalterado.
- **Classe/tamanho:** BACKEND leve · M · baixo-médio (mexe no controller grande e no JS do modal).

### 7. Expediente — estrela de favorito no modo cartão/celular
- **Estado:** pendente. `_tabela.html.twig:12` monta `favoritasIds` e `:92` desenha `bi-star-fill
  pasta-favorita-estrela`; `_card.html.twig` (incluído em `_tabela.html.twig:209`, com contexto) não desenha.
- **Desenho:** omisso para o cartão (a estrela do dc L.4743 é do explorador de documentos) → convenção do sistema: a
  mesma estrela da tabela, antes do NUP (`pasta-card-nup`, `_card.html.twig:16`).
- **Arquivos:** `_card.html.twig`, eventualmente `_tabela.html.twig:302+` (CSS do cartão). Teste funcional do Expediente
  com `view=lista`.
- **Classe/tamanho:** PRONTO · P · baixo.

### 8. Extrair a expressão CASE duplicada
- **Estado:** pendente. `src/Pasta/Repository/PastaRepository.php:830-831` (`vizinha()`) e `:895-897`
  (`posicaoNoAcervo()`, "As MESMAS expressões de `vizinha()`") repetem `$prefixo`/`$nupCru`; `aplicarOrdenacao`
  (`:1000`, `:1055`) usa uma variante `THEN 1 ELSE 0` (não é a mesma — não unificar).
- **Proposta:** duas constantes privadas (`EXPR_PREFIXO_NUP`, `EXPR_NUP_CRU`) ou um método privado; zero mudança de SQL.
- **Testes que já cobrem:** `tests/Pasta/Functional/PastaRepositoryVizinhasNoAcervoTest.php`,
  `PastaRepositoryPosicaoNoAcervoTest.php` (rodar os dois; prova de reintrodução não se aplica a refactor).
- **Classe/tamanho:** PRONTO · P · baixo. Escopo exclusivo: só `PastaRepository.php`.

### 9. Push — filtro "Geram prazo"
- **Estado:** pendente (só Todas · Novas: `_push_processual.html.twig:75-82`, `pasta-push.js`).
- **Desenho:** dc L.5414 e L.5427: terceiro filtro `['prazo', 'Geram prazo']`, regra **`/Intima|Decis/.test(m.tipo)`**
  (o tipo da comunicação começa com Intimação ou Decisão); botão 28px, 12/600, ativo fundo `#fff` cor `#0a7aad` sombra
  `0 1px 3px rgba(16,42,58,.1)`.
- **Lastro:** `PublicacaoDjen.tipoComunicacao` (`src/Djen/Entity/PublicacaoDjen.php:57`); o template já normaliza o tipo
  para a cor da pílula (`_push_processual.html.twig:138-146`). **Medido em prod (MCP, 06/10):** 363 Intimação, 20 Edital,
  4 Lista de distribuição — o filtro mostraria ≈94% das publicações. Não há "Decisão" no DJEN real.
- **Nota de honestidade:** o inventário anterior (`pasta.md:145`) classificou como E ("o dado não diz"). Com a regra
  explícita do desenho, é implementável literalmente; o rótulo é do desenho. Se o orquestrador achar que "Geram prazo"
  promete demais (D-PRAZOS), é a única pergunta a fazer — recomendo seguir o desenho (regra fixa, sem prazo calculado).
- **Arquivos:** `_push_processual.html.twig` (botão + `data-push-gera-prazo` por item), `public/js/pasta-push.js`.
  Teste funcional: Intimação marcada, Edital não.
- **Classe/tamanho:** PRONTO · P · baixo.

### 10. Financeiro — "Enviar por e-mail ao cliente"
- **Estado:** pendente; `_financeiro.html.twig:294-296` diz que depende de função nova.
- **Desenho (o escopo era a dúvida):** dc L.6546, item do ⋮ **do arquivo** (`bi bi-envelope`): **não usa mailer** — abre
  `mailto:<e-mail do cliente>?subject="Pasta N · <nome do arquivo>"&body="Segue o arquivo "<nome>" referente à pasta N."`.
  Sem e-mail cadastrado: vai à aba Dados e avisa "Cadastre um e-mail do cliente para enviar". Não anexa nada.
- **Lastro:** `Cliente.email` (`src/Cliente/Entity/Cliente.php:26`, um só) do `clientePrincipal`.
- **Arquivos:** `_financeiro.html.twig` (item do menu, L.~320-345) + o script do mesmo arquivo (L.~1175). Teste: o `href`
  `mailto:` com assunto codificado; pasta sem cliente → item leva à aba Dados.
- **Classe/tamanho:** PRONTO · P · baixo (PII só no `href` da própria tela, que já mostra o e-mail).

### 11. Financeiro — modal "Adicionar pagamento" com parcelas/juros
- **Estado:** pendente. Hoje: `#modalNovoPagamento` → `pasta_pagamento_registrar` →
  `RegistrarPagamentoDaPastaUseCase::executar(pasta, autor, tenant, descricao, valor, vencimento)` — 1 lançamento.
- **Desenho:** markup dc L.1928-2012; regra dc L.3470-3500. Cabeçalho "Adicionar pagamento" + "Valor da causa X · cálculo
  automático". **Tipo** (4: contrato, custas, êxito, sucumbência). Para contrato/custas: **Base** (valor | % do valor da
  causa), Valor total, **Entrada** (opcional; "Entrada já recebida hoje" marca paga hoje), **Parcelas** 1–60,
  **1º vencimento** (padrão hoje + 1 mês), **Juros** (sem | com, taxa % a.m., padrão 1). Cálculo: com juros, **Price**
  `pmt = P·i/(1−(1+i)^−n)`, arredonda a centavo e a última parcela absorve a diferença; sem juros `P/n`. Descrições:
  "Entrada · honorários|custas", "kª parcela · …", ou "Honorários contratuais"/"Custas e despesas" com n=1. Para
  êxito/sucumbência: só o **percentual** sobre a causa, sem vencimento e sem valor fixo ("Honorários de êxito (20%)").
  **Prévia** das parcelas antes de gravar; botão "Gerar N lançamento(s)".
- **Lastro:** `PastaPagamento` (`src/Pasta/Entity/PastaPagamento.php:36-66`) só tem `descricao`, `valor`, `vencimento`
  (obrigatório), `pagoEm`. `Pasta.valorCausa` existe (`Pasta.php:191`). Contrato/custas parcelados **cabem** no modelo atual
  (N linhas). **Não cabem:** êxito/sucumbência (valor variável por %, sem vencimento) e a marca de juros/tipo por linha.
- **Backend novo:** `RegistrarParcelamentoDaPastaUseCase` (cálculo NO SERVIDOR, transação única, teto 60, centavos em
  string/bcmath — nunca float), rota nova ou a mesma com `modo=parcelado`; migration para `pasta_pagamento.tipo` (enum) e
  `percentual` (nullable) + `vencimento` nullable se êxito/sucumbência entrarem. O resumo "recebido de contratado" e a régua
  de vencidos (B33 L5) precisam ignorar linha percentual sem valor.
- **Classe/tamanho:** BACKEND · G · **MÉDIO** (dinheiro, migration). Revisão Fable recomendada. Pode ser fatiado:
  11a contrato/custas (sem migration) · 11b êxito/sucumbência (migration).

### 12. Financeiro ⋮ — "Editar ou corrigir valores" / "Corrigir valor"
- **Estado:** pendente (`_financeiro_pagamentos.html.twig:72`: "corrigir é excluir e lançar de novo").
- **Desenho:** dc L.3505-3512 (⋮ do card: "Adicionar pagamento", "Editar ou corrigir valores" ↔ "Concluir edição"
  `bi-pencil-square`, "Ver todos/Mostrar só os próximos", Copiar resumo, Imprimir extrato) e dc L.3440-3460 (por linha):
  modo edição mostra lápis; clique no valor vira input (Enter salva, Esc cancela); `confirm('Corrigir "X" de R$ a para
  R$ b?\n\nA alteração fica registrada no histórico com seu nome, data e hora.')`; a linha passa a mostrar o valor original
  riscado (`ajusteDe`) e o título "já corrigido Nx" com o histórico "dd/mm/aaaa hh:mm · Nome: R$ a → R$ b". O ⋮ da linha
  ganha "Corrigir valor".
- **Lastro:** `PastaPagamento implements Auditavel` (`PastaPagamento.php:31`) → o `AuditLogSubscriber` já grava de/para,
  usuário e data de cada `update` de `valor`. **Não precisa tabela nova**: o histórico sai do `audit_log` (consulta por
  `entity_class`/`entity_id` do tenant). Falta: `CorrigirValorDoPagamentoUseCase` (valor > 0, mesma pasta/tenant,
  lançamento existente) + rota POST com CSRF e guarda de pasta (a mesma do quitar).
- **Arquivos:** `src/Pasta/UseCase/` (novo), `PastaPagamentoController.php` (rota nova), `PastaPagamentosOutput`/
  `PastaPagamentoLinhaOutput` (ajustes da linha), `_financeiro_pagamentos.html.twig`, `_financeiro_pagamentos_script.html.twig`,
  `pasta-show.css`. Testes: unit do UseCase, funcional (CSRF, cross-tenant, pasta excluída → listener), audit gravado.
- **Classe/tamanho:** BACKEND · M · MÉDIO. **Mesmos arquivos do item 11 → lote sequencial (12 antes de 11).**

### 13. Checklist por regras / cobrança do checklist (`inteligencia.md` §1.9)
- **Feito:** auditoria "item marcado sem anexo" e estado do checklist entraram na frente Documentos (`791d5f11`,
  `52ea12b6` — L11). Sugestão por regras: B37.
- **Pendente:** cobrança espalhada na semana (dc L.3996-4070, `CK_PESO`, tom que sobe, aviso à Controladoria, desativar com
  motivo + lembrete 30 dias). Isso **é** a decisão **D-DOC-S6** do §4 ("quem é cobrado, quando, por qual canal").
- **Classe:** SAMUEL (D-DOC-S6). Não implementar.

### 14. Timeline inteligente (`inteligencia.md` §1.11)
- **Estado:** pendente; item "Timeline inteligente (T)" do menu da pasta não é renderizado (`_cabecalho.html.twig:16-17`;
  desenho dc L.1096, `mTimeline` dc L.6629).
- **Desenho:** painel em `bluejus-central.js` L.3345-3452 (`#bjtl`, cabeçalho gradiente `180deg #0d80a3→#0a6c9…`, busca,
  filtros por tipo/período, agrupado por dia, "Enquanto você estava fora" pela última visita, detecção por regex de
  tarefa/telefone/e-mail/CNJ **sem executar**); fontes em dc L.4232 (`__bjTimelineFontes`: registros, documentos, metas, push,
  situação, abas ocultas).
- **Lastro:** `src/Pasta/Service/PastaTimelineAssembler.php` já funde `PastaMensagem` + `audit_log`; faltam push
  (`PublicacaoDjen` do processo da pasta), metas e um endpoint JSON da pasta; "desde a última visita" precisa guardar a
  última visita (preferência por usuário — `preferencia_usuario` de B38 serve, mas é por chave fixa do Dashboard:
  conferir o catálogo, `CatalogoDePreferenciasDoDashboard.php`). "Abas ocultas" = D-MASTER (fora).
- **Classe/tamanho:** BACKEND · G · médio (agrega fontes com permissão por pasta; o `audit_log` não cobre Processo/Cliente).
  Rótulo honesto (regras, sem "IA").

### 15. Pontuação da inteligência cadastral (`inteligencia.md` §1.14)
- `iiScore` pontua dados **extraídos dos documentos por OCR**; sem OCR/extração no servidor não há entrada. A parte
  "o cadastro está completo?" já existe (`PendenciasDoCadastro`). **SEM LASTRO (D: OCR)**. Não implementar.

### 16. Dashboard — sons (F11)
- **Desenho:** README-dash L.215-230 (som do **calendário**: seno 1.3 kHz subindo por dia da semana, 80 ms, volume 0.045,
  passa-baixa, no máximo 1 a cada 28 ms, fim de semana mais grave, setas de mês com som próprio; dash L.2105-2115) e
  L.236-248 + L.726-728 (fanfarra do **campeão**, só no hover do troféu, máx. 2 por página). Opção pessoal "Sons" no
  "Meu estilo" do ⋮ (README-dash L.583-588); padrão ligado.
- **Lastro:** preferências existem (B38: `CatalogoDePreferenciasDoDashboard.php:25-71` tem densidade, animações, setas,
  colunas ocultas — **não tem `dashboard.sons`**); calendário próprio existe (B26, `public/js/dashboard-filtros.js`).
- **Classe:** som do calendário + chave `dashboard.sons` = PRONTO (P, baixo; respeitar `prefers-reduced-motion`? o README
  não liga as duas coisas — seguir só a opção). Som do campeão = **SAMUEL (D-DASH: critério do troféu)** — sem troféu
  não há onde tocar.
- **Arquivos:** `CatalogoDePreferenciasDoDashboard.php`, `PreferenciasDoDashboardOutput.php`, `dashboard/index.html.twig`
  (item no ⋮), `dashboard-preferencias.js`, `dashboard-filtros.js`. Teste: allowlist aceita/recusa `dashboard.sons`.

### 17. Dashboard — "Adicionar coluna" do ⋮
- **Desenho:** README-dash L.565-582; dash L.691. Catálogo de 7 métricas: Metas concluídas · Taxa de conclusão (%) · Em
  revisão · Tempo médio (dias criar→concluir) · Pastas urgentes · Pastas concluídas · Eventos na agenda. "+" adiciona no
  fim (ordenável, número clicável, seta de tendência, Total = soma ou média para % e dias, bloco no card do celular);
  aparece em Colunas como "(extra)"; > 8 colunas → mínimo 52px, depois rolagem com coluna fixa. Cores: Tempo médio e
  Urgentes subindo = vermelho; Em revisão e Eventos neutros.
- **Lastro:** Metas concluídas/Taxa (`Tarefa.status`), Em revisão (`Tarefa::STATUS_EM_REVISAO`), Pastas urgentes
  (`Pasta.prioridade`), Eventos (`Evento.participantes`, `src/Entity/Agenda/Evento.php:83` — atenção à `visibilidade`)
  existem. **Tempo médio:** `dataConclusao` é nula nas metas concluídas antes da coluna existir (`dashboard.md` F17) —
  média só sobre as que têm data (dizer isso no balão). **Pastas concluídas: SEM LASTRO** (`Pasta.situacao` só tem
  ativo/arquivado) → omitir do catálogo ou perguntar se "arquivada" vale — desvio do desenho = perguntar ao Samuel.
- **Backend novo:** mapas `userId => valor` no `ObterDadosDashboardUseCase` (só para as extras ligadas), chave nova
  `dashboard.colunas_extras` na allowlist, tendência (período anterior) para as que têm data. Números clicáveis só onde a
  lista de destino bate (regra B12).
- **Classe/tamanho:** BACKEND · M/G · médio (UseCase grande do Dashboard; 306 testes). Recomendo fatiar: 17a casca +
  4 métricas de contagem; 17b tempo médio/taxa/eventos.

### 18–19. Visualizador — ODP e ZIP64
- **Estado:** `public/js/visualizador-documento.js` trata ODT (`:101,:109,:146`) e PPTX/PPSX (`:102-110,:147`) mas não ODP;
  ZIP64 é recusado com mensagem (`:309`, `:324`).
- **Desenho:** `bj-visualizar.js` L.28 (`pptx: ['PPTX','PPSX','ODP']`), L.75-76 (ODP: `content.xml`, texto por
  `draw:page`). ZIP64: o desenho usa JSZip, que lê ZIP64; aqui o leitor é próprio (`DecompressionStream`).
- **Lastro:** nada de servidor. ODP = mime `application/vnd.oasis.opendocument.presentation`, mesmo caminho do ODT
  (ler `content.xml`) agrupando por `draw:page` → "Slide N". ZIP64 = ler o EOCD64 locator/record e o extra field `0x0001`,
  **mantendo o teto anti zip-bomb** (`:309-324`).
- **Arquivos:** só `visualizador-documento.js` (+ `.css` se o ODP reusar o estilo do PPTX). Testes: os 36 do B29 + um ODP
  e um ZIP64 de fixture.
- **Classe/tamanho:** PRONTO · P cada · baixo. **Atenção:** o visor de tela cheia de Documentos (L10, `2fc2e6e8`) usa o
  mesmo arquivo — rodar depois da frente Documentos fechar, ou combinar com ela.

### 20. Registro — notificação ao autor da resposta
- **Estado:** pendente. `src/Pasta/UseCase/EnviarMensagemPastaUseCase.php:26-54` liga a resposta à raiz e não notifica.
- **Desenho:** `bluejus-central.js` L.6627: notificação "direcionada" ao autor do comentário respondido, se não for quem
  responde: título `"<Primeiro nome> respondeu seu comentário"`, texto `"\"<resposta, 140 chars>\" · em <aba> da Pasta N ·
  dd/mm/aaaa hh:mm."`.
- **Lastro:** `PastaMensagem.respostaA` → `getAutor()`; `NotificacaoService` (`src/Service/NotificacaoService.php`):
  `criar(usuario, tenant, tipo, titulo, mensagem, tarefa)` não aceita url e `criarNotificacao(usuario, tenant, tipo,
  mensagem, url)` usa a mensagem como título — para título + texto + url (`pasta_show#dados`) é preciso um `setUrl()` depois
  do `criar()` ou um parâmetro opcional novo (mudança pequena e compatível). Guardas: autor ainda com
  vínculo ativo no tenant (`UserTenantRepository::existeVinculoAtivo`), autor ≠ quem responde, mensagem excluída → não
  notifica; texto da resposta sem HTML (o conteúdo é rico — `strip_tags` antes de cortar).
- **Arquivos:** `EnviarMensagemPastaUseCase.php` + teste unit/funcional (notifica o autor; não notifica a si mesmo; não
  notifica ex-colaborador; cross-tenant).
- **Classe/tamanho:** PRONTO · P · baixo.

### 20b. @menção que notifica
- **Desenho:** placeholder "Use @ para mencionar alguém" nas notas (dc L.1713, L.2554); `bluejus-central.js` L.384 e
  L.5914 (`mencoes(texto, membros)`, notificação "X mencionou você" com chave anti-duplicata e contexto da pasta).
- **Backend novo:** autocomplete dos usuários **ativos do tenant** (endpoint GET), marcação no editor que passe pelo
  sanitizador `textoRico` (hoje não aceita atributos novos — ver D-EDITOR; usar texto `@Nome` + ids enviados à parte),
  parser no servidor que só aceita ids do tenant, notificação idempotente.
- **Classe/tamanho:** BACKEND · M · médio (sanitizador + permissão). Depende parcialmente de D-EDITOR se a menção tiver
  de virar elemento marcado no HTML; com `@Nome` em texto puro, não depende.

### 21. Clientes — edição inline de contatos na janela "Detalhes do cliente"
- **Estado:** pendente; `templates/cliente/_resumo.html.twig:6-7` "SOMENTE LEITURA… essa edição continua na ficha".
  `ClienteResumoController.php:88` já calcula `podeEditar` (`ACTION_EDIT`).
- **Desenho:** markup dc ~L.810-836 ("+ Telefone", "+ E-mail": 28px, `#f1f6f9`/`#0a7aad`, 12/600); lógica dc L.5748-5786:
  lista de contatos tel/e-mail, lápis abre input (Enter salva, Esc cancela; novo vazio é removido), telefone formatado
  `(DD) 9XXXX-XXXX`, e-mail validado e minúsculo, remover.
- **Lastro:** `Cliente` tem **3 slots fixos**: `email` (string **não nula**), `telefoneCelular`, `telefoneFixo`
  (`Cliente.php:26-32`). A lista livre do desenho não cabe: "+ Telefone" só enquanto houver slot vazio, "+ E-mail" só se o
  e-mail estiver vazio, e o e-mail não se remove (só se troca). Outra lista do desenho (vários e-mails com principal e
  "pausar avisos", dc L.4995-5010) é SEM LASTRO.
- **Backend novo:** `AtualizarContatosDoClienteUseCase` + POST `/clientes/{id}/contatos` (CSRF, `ACTION_EDIT`,
  tenant), resposta com o parcial `_resumo` re-renderizado. **Atenção:** `audit_log` não cobre Cliente
  (memória `reference_audit_log_cobertura`) — a troca de contato fica sem histórico. Os setters de contato
  (`Cliente.php:89-103`) gravam cru (sem maiúsculas) — normalizar no UseCase (e-mail minúsculo, telefone só dígitos ou
  com máscara, conforme o que a ficha grava hoje).
- **Arquivos:** `src/Cliente/UseCase/` (novo), `src/Cliente/Controller/ClienteResumoController.php` (ou controller novo),
  `templates/cliente/_resumo.html.twig`, JS da janela (onde B17 a abre — `_dados_trilho.html.twig`), CSS.
- **Classe/tamanho:** BACKEND · M · médio.

### 22. D-AUDIT-UNDO — desfazer da auditoria em enum e em campo sem setter
- **Confirmado no código atual:** `src/Auditoria/UseCase/DesfazerAlteracaoAuditLogUseCase.php:113-128`:
  - valor escalar vai cru ao setter (`$entity->$setter($from)`). O subscriber grava enum pelo **valor string**
    (`AuditLogSubscriber.php:464-466`) e data como **string ATOM** (`:424-426`) → setter tipado (`setPrioridade(PrioridadePasta)`,
    `setPrazo(\DateTimeImmutable)`) dá **TypeError** (500);
  - campo sem setter (`method_exists` falso) é pulado em silêncio e o fim devolve `sucesso: true` (`:131-133`) — "no-op com
    sucesso" (ex.: checklist);
  - string truncada (`…`) é aplicada truncada (só sinaliza `truncado`).
- **Correção proposta:** antes de mudar qualquer coisa, para cada campo do diff consultar `ClassMetadata`
  (`$em->getClassMetadata($classe)`): `enumType` → `EnumClass::tryFrom($from)` (recusa se nulo); tipos de data →
  `new \DateTimeImmutable($from)`; decimal/int/bool → cast pelo tipo do campo; sem setter ou associação a coleção → **recusar
  a operação inteira** com mensagem ("O campo X não se desfaz automaticamente"); string truncada → recusar o campo (hoje
  grava o texto cortado). Só então aplicar e `flush`.
- **Testes:** unit com Pasta (enum `prioridade`, data), entidade com campo sem setter (resultado `sucesso=false` e nada
  muda), string truncada; funcional da rota de desfazer (tenant). Prova por reintrodução.
- **Classe/tamanho:** PRONTO · M · **MÉDIO** (mexe em reversão de dado de várias entidades). Revisão Fable recomendada.
  Escopo exclusivo: só `src/Auditoria/`.

### 23. D-DOC-RO — escrita em pasta excluída (lápide)
- **O listener cobre?** Quase tudo. `src/Pasta/EventListener/PastaSomenteLeituraListener.php` barra toda request não-GET cujo
  controller recebe uma `Pasta` ou uma filha com `getPasta()`. Varredura das 66 rotas de escrita que tocam pasta
  (`src/Controller/*.php`, `src/Pasta/Controller/*.php`, `src/Processo/Controller`, `src/Tarefa/Controller`): **5 recebem
  `int`** e resolvem a pasta à mão, então **escapam**:
  - `pasta_documento_edit` — `PastaDocumentoController::editar(int $id)` (L.97-98);
  - `pasta_secao_renomear`, `pasta_secao_excluir`, `pasta_secao_mover` — `PastaSecaoController` (`int $secaoId`);
  - `pasta_documento_mover_secao` — `PastaSecaoController` (`int $docId`).
  Nenhum deles chama `estaExcluida()` (grep: só `DuplicarPastaUseCase`, `Restaurar`, `Excluir`, o listener e `NotaTecnicaController`).
- **Efeito colateral a decidir:** `pasta_documentos_zip` é **POST** (`PastaDocumentoController.php:364`) e recebe `Pasta`
  → em pasta excluída o download do zip é recusado, embora seja leitura. Sugestão: liberar em `ROTAS_LIBERADAS` (é leitura).
- **Correção proposta:** nas 5 ações, recusar quando a pasta resolvida `estaExcluida()` (mesma mensagem/forma do listener —
  extrair a resposta para um serviço reutilizável) **ou** trocar a assinatura para receber a entidade (`PastaSecao $secao`,
  `PastaDocumento $documento`), o que faz o listener valer automaticamente (preferível: rota nova nasce coberta). Cuidado:
  `PastaDocumento` tem `LixeiraFilter` — documento na lixeira passa a dar 404 no `ParamConverter`.
- **"Ações de escrita para quem só lê"** (outra metade do D-DOC-RO, esconder botões na UI) é Documentos — não investigado.
- **Testes:** um funcional por rota (pasta excluída → 403 JSON / redirect com flash), + o zip liberado.
- **Classe/tamanho:** PRONTO · P · **MÉDIO** (segurança de escrita). A recomendação do §4 já é "bloquear"; o Samuel não
  precisa decidir o bloqueio, só o zip se quiser. **Toca `PastaSecaoController`/`PastaDocumentoController`, que a frente
  Documentos está mexendo → fazer depois dela ou dentro dela.**

---

## 3. 3ª passada de fidelidade (amostragem dirigida, 2 subagentes read-only)

Nenhum item repete `pasta.md`, `auditoria-pasta-2.md`, `dashboard.md`, `auditoria-dashboard-2.md` nem D-PASTA6.
"dc" = o arquivo do desenho da área; "css" = `app/public/css/pasta-show.css`.

### 3.1 Pasta (~60 elementos conferidos; 11 divergências novas)

| # | Área · elemento | Desenho | Atual | Classe |
|---|---|---|---|---|
| N1 | Push · data da pílula do dia | "5 out 2026" (`dataTxt`, dc L.5372) | "05/10/2026" pronto de `PublicacaoDjenListaItem::formatarData` (`_push_processual.html.twig:136`) | A (formatar no Twig com a lista `meses` de Dados) |
| N2 | Push · canto direito do cartão | hora `HH:MM` (dc L.2531) | repete a data da pílula (`_push_processual.html.twig:165`) | A para tirar a duplicata; a hora não vem do DJEN (sem lastro) |
| N3 | Push · título do cartão | teor curto em caixa-alta (`titulo: m.texto`, dc L.5378, 2514) | `item.nomeOrgao` (`_push_processual.html.twig:156`) | B pequeno (trecho do `texto` no DTO da lista) |
| N4 | Processo · ações do cartão | só copiar, selo Principal e ⋮ 32px numa coluna (dc L.1594-1599, 1689-1690) | estrela, abrir e lixeira sempre visíveis + ⋮ (`_processos_vinculados.html.twig:145-164`) | A (mover as três para o ⋮ `.ps-processo-menu`) |
| N5 | Processo · ⋮ do cabeçalho do cartão | "Mais opções de processos" entre o interruptor e Vincular (dc L.1581; `procHdMenu` dc L.6588: Relatório geral, Enviar à Controladoria, Marcar administrativo) | não existe (`_processos_vinculados.html.twig:72-92`) | B (só o item administrativo tem lastro, e repete o interruptor) |
| N6 | Detalhes · Responder na observação | Responder `#7fb3cf` 15px (dc L.2332) | ausente (`_detalhes_obs.html.twig:85-118`) | B (observação não tem `resposta_a`: migration) |
| N7 | Detalhes · ⋮ da observação | 28×28 `#8496a3` 14px após a hora (dc L.2335) | só lápis/lixeira nos 15 min (`_detalhes_obs.html.twig:93-118`) | A se o menu tiver só "Copiar texto"; resto B |
| N8 | Metas · rótulo do prazo | "vence 24/06/2026" (`prazoLabel`, dc L.6265) | "vence 24/06" (`_metas.html.twig:82`, `d/m`) | A |
| N9 | Detalhes · "Modificada em" | "hoje, 26/08/2026 14:58" no dia (dc L.6431) | sempre `dd/mm/aaaa HH:MM` (`show.html.twig:279`) | A |
| N10 | Dados · documento no trilho | "CPF 006…" peso normal 11.5px `#7b93a2` (dc L.1385, 6115) | rótulo em 600 (css:2077, `.cliente-doc-rotulo`; `_cliente_linha.html.twig:49`) | A |
| N11 | Dados · hover do trilho | cliente sem hover (dc L.1377, 6132); documento só fundo `#f1f7fb` (dc L.1400) | cliente com `--ps-hover` (css:2055); documento pinta o nome de azul (css:1812) | A |

Bateram (amostra): cabeçalho (título 28/600, posição "3 de 7", ⋮ e menu, abas, Situação 44px e menu); Dados (registro,
linha do tempo, trilho de prazos/clientes); Metas (filtros, cartão, trilho); Processo (interruptor, número, rótulos,
"Ver todas"); Financeiro (trilho, pagamentos, vazio); Detalhes ("continuar lendo", registro da pasta); Push (faixa,
filtros, linha vertical, cartão, selo NOVO). Fora de propósito: "Pontos do relatório inicial" (D-RELINI), Nº global da
meta, fonte Arial do `body` (sistema inteiro — D-BARRA).

### 3.2 Dashboard (9 divergências novas, todas A)

| # | Elemento | Desenho | Atual | Classe |
|---|---|---|---|---|
| D1 | **Setas de tendência de Vencidas e Prazos por linha** (tabela e celular) — a mais visível | dash L.806-809, script L.2995/1930: toda célula numérica tem seta | `_resultado.html.twig:337-350` e `_desempenho_cards.html.twig:116-135` não chamam `tend.tendencia(...)` para elas (só Metas/Demandas/Pastas, L.328/357/373). **Dado pronto:** `LinhaAdvogadoDashboardOutput.php:34-35` (`metasVencidasAnterior`, `prazosProximosAnterior`), preenchido no UseCase (B39). Conferido. | A (`'seta', 'baixa'`) |
| D2 | Sombra da linha no hover | `…0 10px 20px -16px…; z-index:2` (dash L.793) | `dashboard.css:1461-1462` sem `z-index` no hover (a sombra de baixo provavelmente fica sob a linha seguinte — inferido da ordem de pintura, conferir) | A |
| D3 | Densidade Normal na linha de Total | `rowPad` 9px também no Total (L.2947, 3345) | `dashboard.css:2430-2432` só em `tbody td`; `tfoot` fica 7px | A |
| D4 | Botão ⋮ fechado | `#4f6878` (L.3391) | `dashboard.css:2233` `--db-label-card` = `#34505f` | A |
| D5 | Caixa do menu ⋮ | `min-height:220px; resize:both; border #e3eaef` (L.3392) | `dashboard.css:2241-2262` sem os dois, borda `#dde5eb` | A |
| D6 | Borda do painel do calendário | `#e3eaef` (L.2174) | `--db-fp-painel-borda` = `#dde5eb` (`dashboard.css:795-805`) | A (mínimo) |
| D7 | KPIs do Intelligence no celular | `repeat(4,…)` sempre (L.1313) | 2×2 < 720px (`dashboard-inteligencia.css:524`) | A (baixo) |
| D8 | "dia X de Y" no Intelligence | topo do bloco de ritmo, à direita (L.1285) | no subcabeçalho (`_inteligencia.html.twig:50`) | A (baixo) |
| D9 | Legendas de hover no PDF | `data-print="hide"` (L.526, 568) | só `opacity:0` (`dashboard.css:1293-1297`); `@media print` não esconde → linha reservada | A (baixo) |

Bateram: título e cabeçalho, segmentado de período, calendário próprio, selects com avatar, Limpar filtros, cards,
cabeçalho/colunas/linha da tabela, pílulas, linha de Total, seta/balão, menu ⋮ com a faixa "Ajustes salvos só para",
painel Intelligence, celular, impressão. Janela flutuante do colaborador/histórico continua inexistente — já é F27–F29 (D).

---

## 4. Lotes propostos (1 commit testável cada)

Restrições de paralelismo: **`pasta-show.css`** é o arquivo quente (L1, L2, L3, L4, L10, L13 mexem nele) — cada lote
escreve só no bloco da sua área (comentário-âncora próprio) e o orquestrador integra por cherry-pick em série; **`show.html.twig`**
é tocado por L2 (só `#modalEditarPasta`, L.3504-3600), L11 (bloco do modal de clientes, L.~1140-1300) e L13 (L.279) —
regiões disjuntas, mas integrar em série. A frente **Documentos** está ativa em `PastaDocumentoController`,
`PastaSecaoController` e no visor → L14 e L15 só depois dela.

### Onda 1 — paralela (escopos disjuntos, todos P/M, baixo risco)
| Lote | Itens | Arquivos (escopo exclusivo) | Tam. | Risco | Revisão |
|---|---|---|---|---|---|
| L1 Metas | 1, 2, N8 | `PastaMetasResumoOutput.php`, `_metas.html.twig`, `pasta-metas.js`, css (bloco Metas) | M | baixo | Opus |
| L2 Cabeçalho + modal | 3, 4 | `_cabecalho.html.twig` (faixa das abas), `show.html.twig` (só `#modalEditarPasta`), css (bloco cabeçalho/modal) | P | baixo | Opus |
| L3 Processo | 5, N4 | `pasta-processo.js`, `_processos_vinculados.html.twig`, css (bloco processo) | P | baixo | Opus |
| L4 Push | 9, N1, N2 | `_push_processual.html.twig`, `pasta-push.js`, css (bloco push) | P | baixo | Opus |
| L5 Notificar autor da resposta | 20 | `EnviarMensagemPastaUseCase.php`, `NotificacaoService.php` (parâmetro url opcional), testes | P | baixo | Opus |
| L6 Refactor CASE | 8 | `PastaRepository.php` | P | baixo | Opus (ou sem revisão: refactor coberto) |
| L7 Estrela no cartão | 7 | `_card.html.twig` (+ CSS do cartão em `_tabela.html.twig`) | P | baixo | Opus |
| L8 Dashboard fidelidade + som do calendário | D1–D9, 16 (calendário) | `templates/dashboard/*`, `dashboard*.css`, `dashboard-filtros.js`, `dashboard-preferencias.js`, `CatalogoDePreferenciasDoDashboard.php`, `PreferenciasDoDashboardOutput.php` | M | baixo | Opus |
| L9 D-AUDIT-UNDO | 22 | `src/Auditoria/UseCase/*` + testes | M | **MÉDIO** | **Fable** |

### Onda 2 — depois da onda 1 integrada
| Lote | Itens | Arquivos | Tam. | Risco | Revisão |
|---|---|---|---|---|---|
| L10 Financeiro: e-mail + corrigir valor | 10, 12 | `_financeiro.html.twig`, `_financeiro_pagamentos*.twig`, `PastaPagamentoController.php`, UseCase novo, `PastaPagamento*Output`, css (bloco financeiro) | M | **MÉDIO** (dinheiro) | **Fable** |
| L11 Vincular cliente sem recarregar | 6 | `PastaController.php` (vincular + cliente/novo), `pasta-clientes-busca.js`, `show.html.twig` (bloco do modal de clientes) | M | baixo-médio | Opus |
| L12 Contatos inline do cliente | 21 | `src/Cliente/UseCase` (novo), `ClienteResumoController.php`, `cliente/_resumo.html.twig`, JS da janela | M | médio | Opus (Fable se quiser: escrita de PII) |
| L13 Fidelidade restante da Pasta | N7 (só Copiar), N9, N10, N11 | `_detalhes_obs.html.twig`, `show.html.twig:279`, `_cliente_linha.html.twig`, css (blocos Detalhes/trilho) | P | baixo | Opus |

L10, L11, L12 e L13 são disjuntos entre si (L11 e L13 tocam regiões diferentes de `show.html.twig`).

### Onda 3 — quando a frente Documentos fechar
| Lote | Itens | Arquivos | Tam. | Risco | Revisão |
|---|---|---|---|---|---|
| L14 D-DOC-RO | 23 | `PastaSecaoController.php`, `PastaDocumentoController.php` (só `editar`), `PastaSomenteLeituraListener.php` (zip liberado) | P | **MÉDIO** (segurança) | **Fable** |
| L15 Visualizador ODP + ZIP64 | 18, 19 | `visualizador-documento.js` (+ css) | P | baixo | Opus |

### Onda 4 — backend maior (um por vez ou em paralelo, escopos disjuntos)
| Lote | Itens | Arquivos | Tam. | Risco | Revisão |
|---|---|---|---|---|---|
| L16a Parcelamento contrato/custas | 11 (sem migration) | UseCase novo, `PastaPagamentoController.php`, modal em `show.html.twig` (`#modalNovoPagamento`), script | M | **MÉDIO** | **Fable** |
| L16b Êxito/sucumbência | 11 (migration `tipo`/`percentual`, vencimento nulo) | `PastaPagamento.php`, migration, DTOs do resumo | M | **MÉDIO** (migration + dinheiro) | **Fable** |
| L17a/b Adicionar coluna | 17 | `ObterDadosDashboardUseCase.php`, DTOs, catálogo, `_resultado`/`_desempenho_cards`, JS | M + M | médio | Opus |
| L18 Timeline inteligente | 14 | `PastaTimelineAssembler.php`, controller JSON novo, JS/CSS novos, item no ⋮ do `_cabecalho` | G | médio (permissão por pasta) | Fable (arquitetura) |
| L19 @menção | 20b | endpoint de usuários do tenant, parser no envio, editor (JS) | M | médio | Fable (sanitizador) |

L16a/b depois de L10 (mesmos arquivos). L17 depois de L8.

### Não implementar (decisão do Samuel ou sem lastro)
- Cobrança do checklist (13) → **D-DOC-S6**.
- Som do campeão (16) → **D-DASH** (troféu).
- Coluna "Pastas concluídas" (17) → sem lastro; perguntar se "arquivada" vale (desvio do desenho).
- Pontuação cadastral (15) → OCR (D).
- N3 (trecho do teor no cartão do Push) é B pequeno e pode entrar em L4 se o orquestrador aceitar mexer no DTO de lista
  compartilhado com o módulo Push; N5 e N6 (Responder em Detalhes = migration) ficam para frente própria.
