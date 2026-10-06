# Investigação — Pasta (`pasta_show`) × `02 - EXPEDIENTES 1.2.3.dc.html` (pós-Trilha A)

Data: 05/10/2026 · Read-only · Base: master `beff02fd` (Trilha A integrada).
Desenho: `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html` — abaixo, **"dc L.n"** =
linha do arquivo (template ≈ L.60–2786; script/estado a partir de L.2787).
BlueJus: caminhos relativos a `app/`.

Legenda de classe: **A** já existe · **B** parcial · **C** implementável com infra atual (sem migration) ·
**D** exige infra interna nova (entidade/coluna/migration), sem decisão externa · **E** depende de decisão
do dono, credencial ou integração externa (IA, PJe, e-mail ao cliente, link público).
Esforço: **P** ≤ meio dia · **M** 1–2 dias · **G** > 2 dias.

> **Achado transversal importante:** o desenho é um protótipo React que grava quase tudo em `localStorage`
> (`bj-pasta-prefs-v1`, `bj-com-destaque-v1`, `bj-pastas-duplicadas-v1`…). "Existe no desenho" ≠ "tem regra
> de negócio definida": vários itens abaixo trazem a regra embutida no script (citada), e isso é o que vale
> como especificação.

---

## 0. Medições feitas na PRODUÇÃO (MCP somente-leitura) que mudam a classificação

| Pergunta | Resultado | Consequência |
|---|---|---|
| Metas concluídas têm `data_conclusao`? | **296/296** (272 com prazo; 125 concluídas até o prazo) — coluna nasce no schema original (`Version20260223120000`), sem backfill | "concluída no prazo" **é afirmável** → a decisão do §5 ("o sistema não sabe afirmar") pode ser revista. **C/P** |
| CPF repetido entre clientes do mesmo escritório? | **0 grupos** (`UniqueEntity cpf+tenant` em `src/Cliente/Entity/ClientePF.php:9`) | Selo "CPF duplicado" do trilho **não se aplica** (nunca acenderia) |
| Publicações DJEN com `link` do documento? | **379/379** com `https://pje…/ConsultaDocumento`; 379/379 com `numero_comunicacao` | "Abrir documento no PJe" e "Copiar ID" são **dado real** → C, não E |
| Processo: dados do DataJud preenchidos? | órgão julgador 162/162, data de distribuição 155/162, instância 162/162; `sistema`/`formato`/`nivel_sigilo`/`processo_pai` = 0 | "Ver todas as informações" tem lastro para Órgão · Distribuição · Instância; **apensados não** (processo_pai vazio) |

---

## 1. CABEÇALHO

| Item | Desenho (o que faz) | Hoje no BlueJus | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|---|
| **"3 de 7" entre as setas** | `pastaPos: (i+1)+' de '+L.length` (dc L.5792), renderizado em dc L.1085 entre ‹ › | Setas sem posição: `templates/pasta/_cabecalho.html.twig:96-128`; `PastaRepository::vizinhasNoAcervo()` `src/Pasta/Repository/PastaRepository.php:771-840` | **C** | **P** — `vizinhasNoAcervo` devolve também `posicao` (COUNT de chaves maiores +1) e `total` (COUNT no tenant) com a MESMA chave composta; `PastaVizinhasOutput` ganha os 2 campos; `<span>` em `.ps-cab-nav`. Testes: `PastaRepositoryVizinhasNoAcervoTest`, `PastaNavegacaoSetasTelaTest` | Multi-tenant: o COUNT precisa do mesmo `andWhere(tenant)`+`orX` agrupado (o comentário l.820 já registra a armadilha do OR solto). Semântica: no desenho a posição é da LISTA filtrada; aqui seria do **acervo inteiro** (mesma régua das setas) — dizer isso no `title` |
| **Selo carimbado "ARQUIVADO"** (e "ATIVO" transitório ao reativar) | `seloEl()` dc L.5846-5856: carimbo rotacionado −14°, cor `#5d7383`, ícone `bi-archive-fill`, animação `seloBate`; ATIVO aparece 3,3 s e some (`ativoSeloMostrar`, L.5857); renderizado em dc L.1170 | Não existe; só a pílula `.ps-situacao-pill` (`_cabecalho.html.twig:413-455`) | **C** (só para Arquivado/Ativo — Suspenso/Cancelado são D+E, ver abaixo) | **P** — markup em `_cabecalho` (bloco do cliente l.224-258) + CSS da seção "Linha 2" (`public/css/pasta-show.css:586-752`) + o script de `show.html.twig` ~l.3845 que troca a situação passa a ligar/desligar o selo. `prefers-reduced-motion` desliga | Nenhum (visual sobre dado existente). Atenção ao `zoom:.8` do desenho (não usar `zoom`, usar `transform:scale`) |
| **Excluir pasta: digitar o número para confirmar** | `menuPasta('excluir')` dc L.4336-4340: `prompt('…Para confirmar, digite o número da pasta:')`; se não confere: "Número não confere. A pasta não foi excluída." | `confirm()` simples: `_cabecalho.html.twig:191-197` | **C** — **divergência sanável que o §5/§6 não listam** | **P** — trocar o `onsubmit`; opcional validar no servidor (`pasta_delete` recebe `confirmar_nup`). NÃO copiar o texto "lixeira por 30 dias" (aqui é lápide, `ExcluirPastaLapideTest`) | Baixo. Se validar no servidor, ajustar `ExcluirPastaLapideTest`/`ExclusaoLapideNaTelaTest` |
| **Imprimir resumo** (menu ⋮) | `imprimirResumo()` dc L.4580-4600: folha com Dados da pasta (cliente, documento, responsável, situação, processo, ação, órgão), Pendências, Metas abertas (até 12, com atraso), Financeiro, últimas 5 do Push; modal de impressão dc L.197-212 com opções (cabeçalho do escritório, dados, autor/data, financeiro), tamanho da fonte, Imprimir/Baixar PDF/Copiar texto (`impVals` L.4621) | Não existe | **C** (a parte "Baixar PDF" = usar o "Salvar como PDF" do navegador; nada de gerador novo) | **M** — rota nova GET `pasta_resumo_impressao` em controller NOVO (`src/Pasta/Controller/PastaResumoController.php`) + template novo `pasta/resumo_impressao.html.twig` com `@media print`; reaproveita `PastaPendenciasOutput`, `PastaPagamentosOutput`, `PastaPushOutput`; item no ⋮ de `_cabecalho` | **Permissão**: mesmo `denyResourceAccessUnlessGranted(RESOURCE_PASTA, VIEW)` do `show` (`src/Controller/PastaController.php:296`); bloco Financeiro só se o usuário vê o financeiro hoje; teste cross-tenant (404/403) |
| **Detalhes do cliente pelo botão direito** | dc L.779-836 (janela arrastável): nome e CPF com copiar, KPIs, "Pastas com o mesmo CPF" (nº, ação, Atual/Ativa/Arquivada), "cliente desde", telefone e e-mail editáveis inline | Não existe | **C** (leitura) / edição inline = já existe em `cliente_edit` | **M** — endpoint de fragmento novo (ex. `src/Cliente/Controller/ClienteResumoController.php`) usando `PastaRepository::findByCliente()` (já existe, l.316) + `Cliente` (email, telefones, `criadoAt`); gatilho `contextmenu` + botão no `.ps-cab-cliente-icone`. Só quando há cliente vinculado | IDOR: cliente do tenant + pasta visível; lista de "pastas do mesmo CPF" tem de respeitar a permissão por pasta (não só o tenant) |
| **Botão "Cadastro" com selo de pendências** | dc L.1252-1254; `badgeDica` L.3290 "Cadastro incompleto: N pendências"; regra `cliPend` L.3249-3255: `CLI_REQ` (nome, estado civil, profissão, RG, CPF, logradouro, número, bairro, cidade, UF, CEP) + "nenhum contato" + doc. de identificação e comprovante de residência anexados | Não existe; o cadastro abre por `cliente_show`/`cliente_edit` | **C** (regra sobre campos que existem: `ClientePF` rg/estadoCivil/profissao, `Cliente` endereco/cidade/estado/cep/email/telefones; anexos via `ClienteDocumento.categoria`) | **M** — serviço puro novo `src/Cliente/Service/PendenciasDoCadastro.php` (+ unit test) reutilizado pelo trilho (ícone completo/incompleto) e pelo cabeçalho; botão leva a `cliente_edit`. Modal próprio de cadastro (dc L.602-743) = G e fora | Baixo. **Conferir** se "Número" e "Bairro" existem como campos separados (hoje `endereco` é um campo só → a regra precisa ser adaptada; decidir com o dono) |
| Foto do cliente (botão + "Ajustar foto") | dc L.1127, L.401-427 (carregar, arrastar, zoom, girar) | Ícone de pessoa inerte (`_cabecalho.html.twig:225`) | **D** | **M/G** — coluna/chave de arquivo no `Cliente` + upload pelo storage (E2) | Upload: tenant na chave `t/{tenant}/…`, validação de MIME |
| BlueJus Intelligence | dc L.1139-1143, painel L.843-1040 | — | **E** (IA) | — | — |
| Chip de apensados (tipo de apenso) | dc L.1178-1186 (`ap.rotulo`, `a.tipo`) | Chip "N vinculado(s)" (`_cabecalho.html.twig:282-310`) | **B** → fica como está (`processo_pai` = 0 em prod) | — | — |
| Situações Suspenso / Cancelado | `situacaoVals` dc L.5859-5866 (4 cores) | Só `ativo`/`arquivado` (`Pasta::$situacao`, `src/Pasta/Entity/Pasta.php:53`) | **D+E** (valor novo + o que cada uma faz nas listas/relatórios = decisão) | M | Filtros do Expediente e relatórios que leem `situacao` |
| Cadeado (acesso por pessoa) | dc L.434-471, L.1150-1171 | — | **D+E** (é uma 5ª camada de autorização; ver `docs/AUTORIZACAO.md`) | G | **ALTO** (permissão) |
| ⋮ Timeline inteligente (atalho T) | dc L.1096 → `BJCentral.timeline` | — | **E** (IA) | — | — |
| ⋮ Duplicar pasta | dc L.4321-4327: copia cliente, ação, responsável e checklist; NÃO copia documentos/metas/financeiro; "aguardando número" | — | **C+E** — tecnicamente C (`CriarPastaUseCase`, `GerarNumeroDePasta`, `AplicarChecklistModeloUseCase` existem), mas **"aguardando número" e numeração** são decisão do dono | M | Numeração única por tenant |
| ⋮ Mover para outra carteira | dc L.4328-4333 (lista fixa de carteiras) | Pasta não tem "carteira" (o conceito só existe na Cobrança) | **E** | — | — |
| ⋮ Link externo do Push | dc L.504-554 (link público, código de acesso, validade 30 dias, painel de acessos) | — | **E** (rota pública sem login = decisão de segurança) + D | G | **ALTO** |
| ⋮ Acompanhar alterações | `prefPasta('seguir')` dc L.4311-4318 — "Você vai receber no Chat I.A as alterações da pasta" | `tarefa_acompanhar` existe só para META (`src/Controller/TarefaController.php:103`) | **D** (assinatura por usuário×pasta + disparo de notificação nas alterações) | M/G | Fan-out de notificação respeitando permissão por pasta |
| ⋮ Fixar nos favoritos | `prefPasta('fav')` (idem) | — | **D+E** (onde o favorito aparece? lista do Expediente/menu = decisão) | M | — |
| Editar dados: abas (Dados · Cliente · Processo · Histórico), CPF/CNPJ validado, confirmação ao trocar o número | `peVals` dc L.4276-4310; modal dc L.561-596 | `#modalEditarPasta` (`templates/pasta/show.html.twig:3835-4040`) com NUP, nome do cliente, **nome da ação editável**, situação | **B** | M — o desenho põe "Ação · vem do menu Processo" **somente leitura**; aqui `nome_acao` é dado real editável → **perguntar ao dono antes** (desvio de função) | — |

## 2. REGISTRO DOS EXPEDIENTES / OBSERVAÇÕES

Hoje: `templates/pasta/_dados_anotacoes.html.twig` (cartão Twig) + espelho JS `inserirMensagem` em
`templates/pasta/show.html.twig:3338`; entidade `src/Pasta/Entity/PastaMensagem.php` (conteúdo, autor,
criadaEm, editadaEm — **sem** pai, etiqueta ou cor).

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **"continuar lendo (N parágrafos)" / "mostrar menos"** | `toggleLabel` dc L.6479 (texto com > 3 parágrafos) — em Detalhes (dc L.2348) | **C** | **P** — CSS `line-clamp`/altura máx. + botão; em Detalhes o cartão é Twig + JS dentro do próprio parcial (`_detalhes_obs.html.twig:118` e espelho l.255). No Registro também exige o espelho de `show.html.twig:3338` | Nenhum |
| Janela de edição de 15 min | `regsItens` dc L.5972-5975 ("Editável por mais N minutos"); nota técnica L.5392 (primário sem limite) | **C técnico, mas E** (encurta uma regra de negócio vigente: `EditarMensagemPastaUseCase.php:23` e `ExcluirMensagemPastaUseCase.php:22` = `PT24H`; `PastaJanelaDeEdicaoTelaTest`) | P | Decisão do dono: reduzir 24h→15min muda o que o usuário pode fazer hoje |
| Responder (thread com recuo e "Resposta a X") | dc L.1311, L.1320-1322; `respRot` L.5954 | **D** — `pai_id` em `pasta_mensagem` + UseCase + Twig + espelho JS | M | Pai do mesmo tenant/pasta (IDOR) |
| Destacar com cor (1 destaque por aba; o último prevalece) | `destVals` dc L.4347-4352 (comentário: "Sistema real: PATCH …/destaque { cor } com validação de acesso e tenant") | **D** — coluna `destaque_cor` | M | idem |
| Etiqueta (Combinado / Ligação / Atendimento / Observação) | cores dc L.5928; seeds L.6161-6163 | **D** — coluna enum `etiqueta` + seletor no compositor | M | — |
| Presença do autor (ponto de status) | `presDica` dc L.5955 ("definido no Chat I.A") | **E** (Chat I.A) | — | — |
| Encaminhar via Chat I.A | dc L.1312 | **E** | — | — |
| Menu do botão direito | itens = editar/responder/destacar/copiar | **C** só para "Copiar texto" + editar/excluir existentes; resto depende de D | P | — |

## 3. TRILHO DA ABA DADOS

Hoje: `templates/pasta/_dados_trilho.html.twig` (prazos l.22-60, clientes l.63-130, documentos l.133-170),
linha de cliente `_cliente_linha.html.twig` **com espelho JS em `show.html.twig` ~l.1516**.

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **⋮ por prazo: "Adicionar à agenda (.ics)" e "Copiar"** | dc L.6150-6151: `.ics` com `DTSTART;VALUE=DATE`, `SUMMARY: título (Pasta N)`, `DESCRIPTION: Responsável`; Copiar = "título · badge · meta · Pasta N" | **C** | **P** — gerar o `.ics` no navegador (Blob) a partir de `data-*` no item (`PastaPrazoOutput` já tem título, prazo, responsável) ou rota GET. "Ver nas metas" já existe (chevron) | Nenhum (dado já visível na tela) |
| ⋮ "Alertar <responsável>" | mesma linha: notifica o responsável ("Alerta de prazo · Pasta N") | **C** | M — **mesmo endpoint do sino das Metas** (ver §4); `NotificacaoService::criarNotificacao()` (`src/Service/NotificacaoService.php:64`), `tipo` é string livre | Só para quem pode ver a meta; limitar repetição |
| Encaminhar via Chat I.A | idem | **E** | — | — |
| **Busca inline para vincular cliente** | dc L.1357-1372: campo "Nome ou CPF", resultados com selo Completo/Incompleto/Já vinculado, "Cliente ainda não cadastrado. Cadastrar cliente"; CPF mascarado `***.xxx.xxx-**` até digitar o CPF inteiro (L.3304) | **B** — a busca existe no modal `#modalAdicionarCliente` (`show.html.twig:1173`, rota `pasta_clientes_buscar`) | M — levar a busca para o próprio cartão (reusa `pasta_clientes_buscar` + `pasta_cliente_vincular`) | A máscara de CPF do desenho é proteção de PII: **adotar** |
| Selo "CPF duplicado" | dc L.1382 (`dup: cpfCont>1`, L.6123) | **não se aplica** (0 em prod; validação impede) | — | — |
| **Ícone cadastro completo/incompleto por cliente** | `cadDica`/`cadIcSt` dc L.6125-6126 (verde `#1f9d61` / âmbar `#d39222`) | **C** | **P/M** — depende do serviço `PendenciasDoCadastro` (§1); mexe em `_cliente_linha` **e** no espelho JS de `show.html.twig` | — |

## 4. METAS

Hoje: aba em `templates/pasta/show.html.twig:253-383`; trilho via `PastaMetasResumoOutput`;
endpoints em `src/Controller/TarefaController.php` (`tarefa_concluir` l.416 — POST+CSRF, **redireciona**
para `pasta_show#tarefas`; `tarefa_excluir` l.446; `tarefa_acompanhar` l.103 JSON). **Não há** rota de
renomear nem de reabrir.

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **Filtros Abertas · Atrasadas · Concluídas · Todas (com contagem)** | dc L.1421; lista L.6230; vazio "Nenhuma meta neste filtro" L.1516-1517 | **C** | **P** — filtro no navegador sobre a lista já renderizada (`ps-meta--{tom}` já existe) | Nenhum |
| **Numeração local "1." e global "Nº 0042"** | `numLocal/numGlobal/numTit` dc L.6272 ("Meta N desta pasta · nº X na sequência geral do sistema") | **C** (local = ordem de criação na pasta; global = `tarefa.id`) | **P** | `tarefa.id` é sequência de TODO o sistema → expõe volume entre escritórios. Sugerir ao dono só a numeração local (ou o id, ciente disso) |
| **"concluída no prazo dd/mm/aaaa"** | `prazoLabel` dc L.6265 | **C** — §5 decidiu contra, mas `data_conclusao` existe em 296/296 | **P** — "concluída no prazo" quando `dataConclusao ≤ prazo`; senão "concluída com N dia(s) de atraso" (o desenho é omisso nesse caso → convenção) | Revisar a linha do §5 com o dono (é ele quem aprovou o texto atual) |
| **Concluir / reabrir na lista** | menu `metaVals` dc L.3555-3566 ("Marcar como concluída"/"Reabrir meta") + toast "Desfazer" | **B/C** — concluir existe (form POST); reabrir **não** | **M** — reabrir = UseCase novo (status→pendente, limpa `dataConclusao`); concluir na lista = form POST ao endpoint atual | `verificarAcessoTarefa` (TarefaController) precisa valer no endpoint novo |
| **Renomear na lista** | dc L.1435-1437; "Editar nome" no menu | **C** (sem migration) | **M** — endpoint + `RenomearMetaUseCase` | Quem pode renomear? (criador? responsável?) → **perguntar** (há `MetaNaoExcluivelException` com regra de janela para excluir) |
| **Sino "Alertar para verificar"** | `sinoTit` dc L.4433 ("Alertado: X às HH:MM. Clique para alertar de novo") + animação `sinoBalanca` | **C** | **M** — endpoint que notifica os responsáveis via `NotificacaoService::criarNotificacao`; "último alerta" exige guardar quem/quando → sem coluna nova dá para ler da própria `notificacao` (tipo novo + url da meta) | Spam: limitar 1 alerta/min por meta |
| ⋮ da meta (Abrir relatório, concluir/reabrir, editar nome, Duplicar, Encaminhar, Copiar link, Abrir em Metas, Excluir) | dc L.3555-3566 | **B** — compõe-se dos itens acima; Copiar link e Abrir em Metas = **C/P**; Duplicar = C/P; Encaminhar = E | P (montagem) | — |
| Drawer "Relatório da meta" (nav ‹ ›, status, prazo, campos, histórico, outras metas, "Abrir em Metas") | dc L.1466-1510; `hist` L.3574+ | **C** | **G** — fragmento novo servido a partir de `tarefa_show`/`TarefaTimelineAssembler` | Permissão da meta |
| "Precisa de atenção" com conversar no chat | dc L.1541-1552 | **A** (lista) + **E** (chat) | — | — |
| **Nova meta: prazo e responsáveis obrigatórios** | dc L.78-80, L.169 ("Prazo \*", "Responsáveis \*"); `faltaTxt` L.3896 "Preencha: …" | **B** — hoje prazo é opcional (`show.html.twig` modal l.4041+, campo `tarefaPrazo`) | **P** — mas muda regra de criação → **perguntar ao dono** (desvio de comportamento) | Testes de criação de meta |
| **Nova meta: atalhos de prazo (Hoje · Amanhã · +5 úteis · +15 úteis) e "segunda-feira, 12 de outubro, em 7 dias. Cai em fim de semana."** | dc L.3900-3902, `prazoInfo` L.3975 | **C** | **P** — JS no modal (dias úteis = seg–sex, como o desenho; feriado é omisso) | Nenhum |
| **Nova meta: aviso de meta aberta com o mesmo título** | `dup` dc L.3887 ("Já existe a meta aberta "X" (prazo …). Atualize a existente…" + "Abrir meta existente") | **C** | **P** — comparação no navegador contra as metas da pasta já renderizadas | — |
| Nova meta: análise/prazo jurídico por IA | dc L.117-160 | **E** | — | — |

## 5. PROCESSO

Hoje: `templates/pasta/_processos_vinculados.html.twig` (re-renderizado por XHR — tudo mora no parcial);
cartão com Classe/Assunto/Tribunal/Situação (l.86-110); "Vinculado em" (l.49).

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **Resumo Órgão julgador · Valor da causa · Última distribuição + "Ver todas as informações"** | `resumo` dc L.2909; `expTxt` L.2894; campos `PJE_CAMPOS` | **C** para o que o DataJud já trouxe: `Processo::orgaoJulgador`, `dataDistribuicao`, `instancia` (`src/Processo/Entity/Processo.php:28,53,62`) + `Pasta.valorCausa`; **E** para o resto (marcas gratuita/tutela/prioridade, editar/atualizar no PJe) | **P** — só Twig no parcial; "Não informado" onde vazio (convenção já usada) | Nenhum |
| **⋮ do processo / botão direito** | `procMenu` dc L.2925-2937: nota técnica, Abrir no PJe, Ver todas, Editar, Atualizar, Ver movimentações (Push), Copiar número, Copiar resumo, Compartilhar, Encaminhar, Relatório geral, Imprimir resumo, Desvincular | **B/C** — reagrupar o que existe (tornar principal, abrir, desvincular `.js-ajax-*`, copiar `data-copy`) + **C/P** novos: "Copiar resumo do processo" (texto do L.2927), "Ver movimentações" (`data-ps-ir-aba="push-tab"`), "Compartilhar" (`navigator.share` com fallback copiar) | **P/M** — JS por delegação (o parcial é trocado por XHR) | Preservar `.js-ajax-processo-principal`/`.js-ajax-desvincular-processo` |
| "Administrativo sem processo" | dc L.1580 (switch "Marque quando a pasta é administrativa…"); categoria `admin` em L.4564-4568 | **D** — booleano novo em `Pasta` + migration + regra em `PastaPendenciasOutput` (sem linha vermelha de Processo quando administrativa) + vazio do Push | **M** | Muda a pendência da aba (A2) e o texto do vazio do Push |
| Abrir no PJe / editar e atualizar dados do PJe | dc L.1623-1684 | **E** | — | — |
| Nota técnica | dc L.1699-1716 (+ Push L.2540) | **D** (entidade nova) — §4 da spec a deixou fora | M | — |
| "Ação" por processo | dc L.1603-1604 | decidido no §5 (fica) | — | — |

## 6. FINANCEIRO

Hoje: `templates/pasta/_financeiro.html.twig` (selo do contrato l.25-53; arquivos l.271-320 com botões
`btn-renomear-doc`/`btn-excluir-doc`; "Reduzir tamanho" l.335-342) e `_financeiro_pagamentos.html.twig`.

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **⋮ dos arquivos** | dc L.1847 ("Mais ações do arquivo") | **B** — ações existem soltas | **P** — agrupar Abrir/Baixar/Renomear/Excluir num menu; manter classes/`data-*` que o JS usa | `PastaFinanceiroArranjoTelaTest` |
| **Imprimir extrato** (menu ⋮ de Pagamentos) | dc L.3515: "R$ X recebidos de R$ Y" + lista com "pago"/"vence dd/mm" | **C** | **P** — rota de impressão (pode ser a mesma do Imprimir resumo, com seção só de financeiro) ou `window.print()` com folha `@media print` | Permissão do financeiro |
| Modal "Adicionar pagamento" (tipo contrato/êxito/sucumbência/custas, base valor ou % da causa, entrada, parcelas, 1º vencimento, juros Price, prévia) | dc L.1930-2008; cálculo L.3470-3493 | **C+E** — gerar N lançamentos com o `RegistrarPagamentoDaPastaUseCase` existente é C; **êxito/sucumbência sem vencimento e juros compostos** mudam o significado do "previsto" → decisão do dono | M/G | Cobrança/contabilidade: não misturar com a régua da Cobrança |
| "Editar ou corrigir valores" com "corrigido · era R$ X" | dc L.1869-1913; `ajusteDe/ajusteHist` L.3462 | **D** — histórico da correção (coluna/tabela) + endpoint de edição; hoje "corrigir é excluir e lançar de novo" (`_financeiro_pagamentos.html.twig` comentário) | M | — |
| Selo "Assinado" por arquivo | dc L.1845 (`a.assinado`, mock sempre `false` em L.6323-6328) | **D/E** — marcar manualmente (coluna) ou detectar assinatura digital (E) | M | — |
| Média por CPF, Reduzir tamanho, Pró-bono, Contrato Assinado/Pendente | dc L.1737-1771, L.1858 | **A** | — | — |

## 7. PUSH PROCESSUAL

Hoje: `templates/pasta/_push_processual.html.twig`, teor em `_push_teor.html.twig` (já tem "Inteiro teor na
origem" = `publicacao.link`), `src/Pasta/Controller/PastaPushProcessualController.php` (GET do teor
marca como lida, l.32/79). `PublicacaoDjen` tem `link`, `numeroComunicacao`, `lida` (bool **por tenant**).

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **Filtros Todas · Novas** | dc L.5427 (`pushFiltro`), L.2420 | **C** ("Novas" = não lidas) | **P** — filtro no navegador (`.ps-push-item--nova` já existe) | — |
| Filtro "Geram prazo" | idem | **E** (classificação; o dado não diz) | — | — |
| **Abrir documento / Copiar ID no cartão** | dc L.2518-2520 ("Abrir documento", "Copiar ID do documento", "Abrir no PJe") | **B/C** — o link já aparece dentro do teor; ID = `numeroComunicacao` (379/379) | **P** — no teor (`_push_teor.html.twig`, que já recebe `PublicacaoDjenOutput`) sem mexer no DTO de lista compartilhado com o módulo | `link` é URL externa: manter `rel="noopener noreferrer"` e o escape já feito |
| **Marcar como lida / não lida** | dc L.2529 (`m.lidaTxt`), análise L.2446 | **C** | **P/M** — POST novo no `PastaPushProcessualController` (`setLida(false)`) + CSRF; JS atualiza o selo da aba e a linha vermelha | `lida` é do escritório (não do usuário) — mesma semântica do módulo; IDOR: reusar a checagem do teor (publicação casada com processo da pasta) |
| **Criar meta a partir da publicação** | dc L.2527 (`m.tarefaTxt`), análise L.2447 "Criar tarefa da providência" | **C** | **P** — abre o `#modalCriarTarefa` existente pré-preenchido (título "Tipo · Órgão", descrição com data e link) por JS, usando os ids já contratados (`#tarefaTitulo`, `#tarefaDescricao`) | Nenhum |
| Análise por IA, Encaminhar, Monitoramento/previsão, "Avisar quando chegar", Avisar cliente por e-mail (com CC da Controladoria) | dc L.2413-2503, L.2571-2736 | **E** | — | — |
| Nota técnica na movimentação | dc L.2513, L.2540-2558 | **D** | M | — |

## 8. DOCUMENTOS

Hoje: gerenciador `fm*` (`show.html.twig:434-640`, `public/js/pasta-arquivos.js`, 871 linhas) — **compartilhado
com a Cobrança**; já tem busca (`#fmBusca`), nova pasta, upload, ordenar/colunas, alternar visualização
(`.fm-view-btn`), mover, renomear, checklist e modelos de checklist. Visualizador `#previewDocModal` (l.2461).

| Item | Desenho | Classe | Esforço | Risco |
|---|---|---|---|---|
| Filtro por tipo de documento ("Mostrando somente …") | dc L.2054-2059, L.2192 | **C** | P/M | `fm` compartilhado com a Cobrança |
| Painel de detalhes | dc L.2072, L.2282-2290 | **C** | M | idem |
| Visualizador com anterior/próximo (← →) e zoom | dc L.300-327 | **C** | M (`#previewDocModal` também é usado pelo trilho) | idem |
| Seleção em lote / laço, Organizar colunas móveis, oito modos | dc L.2039-2069, L.2197-2214 | **C** | G | idem |
| Possíveis duplicados ("Idêntico") | dc L.2158, L.2251 | **C** por nome+tamanho / **D** por hash (não há hash em `PastaDocumento`) | M | — |
| Favoritos de arquivo | `expFavs` dc L.4441-4442 (localStorage) | **D** | M | — |
| Sugestões de limpeza, "Sugerir documentos", análise documental | dc L.2089-2164, L.2221-2236 | **E** (IA) | — | — |
| Desativar checklist com motivo | dc L.2093-2116 | **D+E** | M | — |
| Pendência da aba Documentos ("documento cobrado e ainda não anexado") | `tabPendencias` dc L.5236-5242 | **D** (precisa do registro de cobrança do item) | M | — |

## 9. DETALHES (fora do §6, achado novo)

| Item | Desenho | Classe | Esforço / arquivos | Risco |
|---|---|---|---|---|
| **"Pontos do relatório inicial" (Completo / N pendentes) + "Completar no relatório"** | dc L.2390-2396; `ri` L.6519; regra `relInicial()` L.5230-5235 — **não é IA**: três regex sobre o texto das observações (objetivos: `objetivo|pretens|pretende|busca(r) |deseja|finalidade|o cliente quer|ajuizamento de`; acordos: `acord|combinad|ajustad|autoriz|ficou (definido|acertado)|definiu-se|alinhad|orientad`; resultado: `resultado|expectativa|espera(-se| que| obter)|almeja|exito pretendido|pedido final`); "Completar" insere "Objetivos do cliente: / Acordos iniciais: / Resultado esperado pelo cliente:" no editor | **C** — o §5 a classificou como "(IA) fora"; o código do desenho mostra que é regra determinística | **P/M** — DTO puro novo (ex. `src/Pasta/DTO/RelatorioInicialOutput.php`, unit test com as 3 regex) a partir de `Pasta::getObservacoesDetalhes()` (`src/Pasta/Entity/Pasta.php:746`); cartão no trilho de Detalhes (`show.html.twig:384-433`) | Nenhum. Propor ao dono (é heurística de palavra-chave — pode dar "Completo" falso) |
| **Linha vermelha na aba Detalhes** | `tabPendencias` dc L.5248: "relatório inicial de atendimento ainda não preenchido" / "relatório inicial sem objetivos do cliente e …" | **C** | **P** — `PastaPendenciasOutput::montar` (`src/Pasta/DTO/PastaPendenciasOutput.php:42-92`) ganha `detalhes`, lendo o DTO acima | Muda a aba em TODAS as pastas sem relatório (≈ quase todas) → confirmar com o dono antes |

## 10. Outras funções do desenho fora do §6

| Item | Desenho | Classe | Observação |
|---|---|---|---|
| Busca rápida Ctrl+K (pasta, cliente, CPF, processo) | dc L.332-344 | **C+E** | Vive na barra global (`base.html.twig`) = decisão do dono (§5 "Barra global") |
| Ocultar aba por pessoa | dc L.265-276 | **D+E** | Camada de permissão nova |
| Marcadores: busca, submarcadores, criar | dc L.349-392 | **A/B** | `Marcador::$pai` existe (`src/Expediente/Entity/Marcador.php:30`); modal é o compartilhado com o Expediente |
| Atalhos de teclado E/H | dc L.1094-1095 | **A** | `data-ps-atalho` |
| Histórico do sistema (drawer) | dc L.2759-2775 | **A** | `_historico_drawer.html.twig` |

## 11. Divergências do §5 que ainda são sanáveis (visual/texto, sem função nova)

1. **Metas — "concluída no prazo"** (§5 "Prazo das metas"): o dado existe (296/296). Sanável **P**.
2. **Detalhes — "Pontos do relatório inicial"** (§5 "Aba Detalhes"): não é IA, é regex. Sanável **P/M**.
3. **"3 de 7"** (§5 última linha): já registrado como C de custo baixo. **P**.
4. **Excluir pasta digitando o número**: desvio não listado em lugar nenhum. **P**.
5. **Selo carimbado ARQUIVADO**: o §6 o juntou a Suspenso/Cancelado, mas Arquivado tem dado. **P**.
6. **Push — documento e ID no cartão**: o §5 diz "documento do PJe = função nova"; o `link` está em 379/379. **P**.
7. **Rótulo das setas "Cliente anterior" × "Pasta anterior"**: decisão consciente do dono (01/09) — **não** mexer.
8. **Barra global** (cores/alturas medidas no §5): sanável tecnicamente, mas é `base.html.twig` de todas as telas → dono.
9. **Nova meta com prazo/responsáveis obrigatórios**: desvio de regra → perguntar antes.
10. **Editar dados com "Ação" só leitura**: desvio de função (perderia edição real) → perguntar antes; recomendo manter.

---

## IMPLEMENTAR PRIMEIRO — lotes paralelos com arquivos disjuntos

Regras para não haver sobreposição:
- **Ninguém edita `public/js/pasta-show.js` nem `src/Controller/PastaController.php`.** JS novo fica no
  parcial do lote (padrão já usado em `_financeiro_obs_script.html.twig`) ou em arquivo novo; se um lote
  precisar de variável nova no contexto do `show`, entrega o DTO + teste e o **orquestrador** adiciona a linha
  no array de `PastaController::show` (~l.400-430) na integração.
- `public/css/pasta-show.css`: cada lote escreve **só na sua seção** (cabeçalho l.308-937 · Processo
  l.2085-2149 · Metas l.2150-2231 · Detalhes l.2232-2250 · Push l.1941-2056/2251-2264 · Financeiro
  l.1679-1940). Hunks distantes → o cherry-pick em série não conflita.
- `templates/pasta/show.html.twig` tem **um dono por onda**.

### Onda 1 (paralela)

| Lote | Itens (todos C, P) | Arquivos exclusivos | Testes a tocar/criar |
|---|---|---|---|
| **L1 Cabeçalho** | "3 de 7" · selo carimbado ARQUIVADO/ATIVO · excluir digitando o número · (opcional, M) Imprimir resumo | `templates/pasta/_cabecalho.html.twig`, `src/Pasta/Repository/PastaRepository.php` (`vizinhasNoAcervo`), `src/Pasta/DTO/PastaVizinhasOutput.php`; se Imprimir: `src/Pasta/Controller/PastaResumoController.php` (novo) + `templates/pasta/resumo_impressao.html.twig` (novo); CSS seção cabeçalho | `PastaRepositoryVizinhasNoAcervoTest` (posição + cross-tenant), `PastaNavegacaoSetasTelaTest`, `PastaCabecalhoPjeTelaTest`, `ExclusaoLapideNaTelaTest`; teste de permissão/tenant da rota de impressão |
| **L2 Processo** | Órgão julgador · Distribuição · Instância · Valor da causa + "Ver todas as informações" · ⋮ com Copiar resumo / Ver movimentações / Compartilhar | `templates/pasta/_processos_vinculados.html.twig`; CSS seção Processo | `PastaAbasEmCartaoTelaTest`, `VincularProcessoControllerTest` (XHR re-render mantém o markup) |
| **L3 Push** | Filtro Todas/Novas · Copiar ID e "Abrir documento" no teor · marcar lida/não lida · Criar meta da publicação | `templates/pasta/_push_processual.html.twig`, `templates/pasta/_push_teor.html.twig`, `src/Pasta/Controller/PastaPushProcessualController.php`; CSS seção Push | `PastaPushProcessualTest`, `PastaPushTeorControllerTest` (+ IDOR do POST de não-lida, cross-tenant) |
| **L4 Financeiro** | ⋮ dos arquivos · Imprimir extrato (folha `@media print` no próprio parcial) | `templates/pasta/_financeiro.html.twig`, `templates/pasta/_financeiro_pagamentos.html.twig`; CSS seção Financeiro | `PastaFinanceiroArranjoTelaTest`, `PastaFinanceiroControllerTest` |
| **L5 Metas** (dono do `show.html.twig` nesta onda) | Filtros · numeração local · "concluída no prazo" · concluir na lista (endpoint atual) · Nova meta: atalhos de prazo + texto do dia + aviso de título repetido | `templates/pasta/show.html.twig` (só aba Metas l.253-383 e modal `#modalCriarTarefa` l.4041+); CSS seção Metas | `PastaAbasEmCartaoTelaTest`, `PastaAbasPendenciaTelaTest` |
| **L6 Detalhes — "continuar lendo"** | "continuar lendo (N parágrafos)" / "mostrar menos" nas observações de Detalhes | `templates/pasta/_detalhes_obs.html.twig` (Twig + espelho JS estão no próprio parcial, l.118 e l.255); CSS seção Detalhes | `PastaObservacaoDetalhesControllerTest` |

### Onda 2 (depois da onda 1; em série onde indicado)

| Lote | Itens | Arquivos | Classe |
|---|---|---|---|
| **L7 Clientes** | serviço `PendenciasDoCadastro` + ícone completo/incompleto + "Copiar qualificação" + popover "Detalhes do cliente" + botão "Cadastro" no cabeçalho + ⋮ do prazo (.ics, Copiar) | `src/Cliente/Service/PendenciasDoCadastro.php` (novo), controller de fragmento novo, `_cliente_linha.html.twig`, `_dados_trilho.html.twig`, `show.html.twig` (espelho JS ~l.1516), `_cabecalho.html.twig` (botão) | C, M |
| **L8 Relatório inicial** | `RelatorioInicialOutput` (3 regex) + cartão "Pontos do relatório inicial" + pendência da aba Detalhes + "continuar lendo" no Registro (espelho `inserirMensagem` l.3338) | DTO novo, `PastaPendenciasOutput.php`, `show.html.twig` (l.384-433 e ~l.3338), `_dados_anotacoes.html.twig` | C, P/M — **perguntar ao dono antes** (acende a linha vermelha em quase todas as pastas) |
| **L9 Metas, parte 2** | Reabrir · Renomear · Sino/Alertar (o mesmo endpoint serve o "Alertar" do prazo) · ⋮ da meta | controller novo `src/Tarefa/Controller/…` + 3 UseCases novos; `show.html.twig` (aba Metas) | C, M — quem pode renomear/reabrir = pergunta ao dono |
| **L10 Administrativo sem processo** (depois de L2 e L8) | coluna booleana + migration + switch + pendência + vazio do Push | `Pasta.php`, migration, `PastaPendenciasOutput.php`, `_processos_vinculados.html.twig`, `_push_processual.html.twig` | D, M |

### Fila de D (sem decisão externa) para depois
Responder registro (pai_id) · Destacar (cor) · Etiqueta do registro · Correção de pagamento com histórico ·
Acompanhar pasta · Foto do cliente · Nota técnica · Favoritos de arquivo · Duplicados por hash.

### Dependem do dono (E)
IA (Intelligence, Timeline inteligente, análise do Push, sugestões de documentos, prazo jurídico) · PJe
(abrir/atualizar/editar) · Link público do Push · Cadeado / Ocultar aba · Suspenso/Cancelado · Mover
carteira · Favoritos de pasta (onde aparecem) · Duplicar (numeração) · janela de 15 min · prazo e
responsáveis obrigatórios na meta · parcelamento com juros · e-mail ao cliente · barra global / Ctrl+K.
