# Engineering Loop de fidelidade — Claude Designer → BlueJus real

> **Para quem:** o Samuel e qualquer sessão futura que continue este loop sem ter visto a conversa.
> **O que é:** o ledger vivo do Engineering Loop. Cada divergência entre o Claude Designer e o BlueJus real
> tem um ID, uma classe, o estado das três dimensões (Visual, Funcional, Regressão) e a evidência.
> **Não substitui** os ledgers anteriores (`estado-geral-claude-designer-bluejus.md`, `trilha-b-designer.md`,
> `trilha-b-documentos-arquitetura.md`); eles ficam como histórico e mapa.

## 0. Estado e regras desta fase

| Item | Valor |
|---|---|
| Início do loop | 07/10/2026 |
| Baseline de produção homologado | **`719de0e4`** (EI2 publicada, migrations aplicadas, **smoke de produção APROVADO pelo Samuel**) |
| HEAD no início | `607f2c29` (1 commit documental local à frente de `origin/master`) |
| Modelo | Opus em tudo. **Fable suspenso** pelo Samuel até ordem contrária |
| Publicação | **Nada de push, deploy ou escrita em produção.** O Samuel publica quando houver entrega intermediária |

**Fontes de verdade (por ordem):** protótipos `.dc.html` do pacote `docs/design/claude-design-2026-10-05 (1)/`
(não versionado), os JS de comportamento do pacote, os READMEs de handoff, as capturas, as decisões
registradas do Samuel, o comportamento do BlueJus a preservar, o código atual. Ledgers são só mapa.
READMEs e protótipo são interpretação do Claude Designer: não atribuir as regras ao Farlei.

**Fonte pendente:** relatório do Farlei, sendo produzido no chat original do Claude Designer. Quando chegar,
provoca nova auditoria das áreas afetadas (todos os itens D primeiro).

**Classes:** **A** autônoma · **B** dependência externa · **C** decisão do Samuel · **D** aguardando
especificação do Farlei · **E** fora do escopo · **F** já concluída (com evidência).
**Prioridade:** P0 segurança/tenant/dados · P1 função do desenho que não funciona · P2 função faltante ·
P3 visual importante · P4 microfidelidade · P5 refinamento.

## 1. Agentes em andamento e próximo passo

**Ciclo 0 (07/10):** auditoria inicial por 8 subagentes Opus somente leitura, um por área. Cada um grava
o inventário completo fora do repositório (scratchpad da sessão) e o orquestrador consolida aqui.

| Área | Prefixo | Estado |
|---|---|---|
| Dashboard | `DASH-` | em andamento |
| Pasta: cabeçalho, Dados, Registro, Timeline | `PAS-` | em andamento |
| Processo, Metas, Clientes | `PMC-` | em andamento |
| Financeiro, Push, Notificações | `FPN-` | em andamento |
| Documentos e Visualizador | `DOC-` (novos a partir de `DOC-1xx`) | em andamento |
| Editor, atalhos, impressão | `EDT-` | em andamento |
| BlueJus Intelligence | `IA-` | em andamento |
| Segurança, tenant, autorização | `SEC-` | em andamento |

**Se a sessão cair aqui:** os inventários do scratchpad se perdem. Relance a auditoria da área que não
estiver consolidada na §3.

## 2. Matriz de convergência

**Medida em 07/10, ciclo 0** (auditoria inicial em `607f2c29`). O denominador é a lista real de requisitos
que cada auditor montou a partir do desenho, do JS e do README; ele **cresce** quando um requisito novo
aparece. "OK" = conferido no código como implementado (classe F). Contagem por leitura de código: o visual
(cor, raio, posição) foi conferido no CSS e no template, não na tela.

| Área | Visual | Interações | Funções | Responsivo | Dark | Total OK |
|---|---|---|---|---|---|---|
| Dashboard | 96/108 | 47/68 | 20/39 | 13/15 | 12/12 | **188/242** |
| Pasta: cabeçalho, Dados, Registro, Timeline | 53/71 | 50/58 | 24/51 | 10/10 | 12/12 | **149/202** |
| Processo, Metas, Clientes | 56/78 | 53/83 | 22/40 | 7/8 | 7/8 | **145/217** |
| Financeiro, Push, Notificações | 79/104 | 53/88 | 31/57 | 10/12 | 14/14 | **187/275** |
| Documentos e Visualizador | 72/80 | 89/96 | 44/65 | 15/15 | 6/6 | **226/262** |
| Editor, atalhos, impressão | 21/27 | 27/43 | 8/19 | 3/4 | 4/4 | **63/97** |
| BlueJus Intelligence (telas do código) | 21/34 | 13/37 | 27/33 | 4/4 | 5/5 | **70/113** |
| Segurança (checagens por rota, 57 rotas) | — | — | 371/392 | — | — | **371/392** |

**Divergências por classe (308 linhas):**

| Área | A | B | C | D | E |
|---|---|---|---|---|---|
| Dashboard | 12 | 2 | 15 | 3 | 2 |
| Pasta/Dados | 29 | 0 | 18 | 2 | 0 |
| Processo/Metas/Clientes | 24 | 6 | 23 | 1 | 2 |
| Financeiro/Push | 17 | 4 | 17 | 4 | 0 |
| Documentos | 21 | 4 | 11 | 0 | 3 |
| Editor | 22 | 0 | 12 | 0 | 0 |
| IA | 14 | 5 | 13 | 10 | 1 |
| Segurança | 9 | 0 | 2 | 0 | 0 |
| **Total** | **148** | **21** | **111** | **20** | **8** |

O README da Intelligence lista ainda **197 comandos de Chat I.A (29 grupos)** e 49 requisitos R1–R49, quase
todos D ou C; eles estão em `engineering-loop/inventario-ia.md` e **não** entram na tabela acima (a área IA
conta só as telas que existem no código).

**Duplicatas entre áreas** (a mesma função aparece em mais de um inventário; conta-se uma vez ao fechar):
@menção nas notas técnicas = EDT-010 = FPN-022 = PMC-019 · ⋮ da nota técnica = FPN-021 = PMC-017 · imprimir
extrato = EDT-004 = FPN-010 · foto do cliente = PAS-005 = PMC-052 · imprimir resumo com opções = EDT-001 =
PAS-015 · ⋮ da observação (Citar, Responder…) = EDT-009 ⊂ PAS-041 · zip sem limitador = DOC-117 = SEC-004 ·
`PastaSecaoController` sem tenant = DOC-119 = SEC-001 · Push marca lida no GET = FPN-013 (parte) = SEC-011 ·
Imprimir resumo do processo = EDT-005 = PMC-003 · Compartilhar sem Web Share = EDT-006 = PMC-002.

## 3. Divergências (ledger)

O detalhe de cada linha (fonte no desenho com linha, evidência no código, proposta e teste) está nos
inventários versionados em `docs/specs/engineering-loop/inventario-<área>.md`. Aqui fica o **estado** de
cada divergência A trabalhada e as filas B/C/D.

### 3.1 Divergências A em trabalho ou concluídas

| ID | Área | Elemento | Prior. | Estado | V | F | R | Commit | Revisão | Observação |
|---|---|---|---|---|---|---|---|---|---|---|
| DOC-101 | Documentos | Texto da lixeira prometia purga em 30 dias | P1 | **feito** | ✓ | ✓ | ✓ | `034f6ff1` | — (texto, teste de mutação) | Sem prazo nenhum: D-DOC-RET (prazo) segue aberta |
| DOC-102/103/105/109/110/114/121/123 | Documentos | Lote DOC-A1: textos da limpeza e do vazio, zoom ODP, data Modificado no visor, P7S, Espaço em pasta, `modificado_em` na peça | P4–P5 | **feito** | ✓ | ✓ | ✓ | `5038bbdf` | Opus: sem bloqueante | 8 mutações. Pendências menores: tamanho no aviso do P7S (desenho mostra) → DOC-141; testes de JS só leem o fonte (sem harness) |
| SEC-001 (=DOC-119) | Segurança | `PastaSecaoController` busca por id sem tenant | P0 | **feito** | — | ✓ | ✓ | `d9846179` | Opus: sem bloqueante | Teste com TenantFilter desligado (trait); mutação derruba 3 |
| SEC-002 | Segurança | Mensagem do Registro sem tenant explícito da pasta | P0 | **feito** | — | ✓ | ✓ | `d9846179` | Opus: sem bloqueante | Prod (MCP, agregado): 0 linhas com tenant nulo ou divergente em mensagem/seção/documento |
| SEC-003 | Segurança | XML/SVG/HTML do usuário servido inline sem CSP sandbox | P0 | **feito** | — | ✓ | ✓ | `3b6805f1` | Opus: sem bloqueante | `CSP: sandbox; default-src 'none'` fora de PDF, imagem raster, áudio e vídeo; nosniff sempre |
| SEC-004 (=DOC-117) | Segurança | Zip sem limitador (5 zips param o PHP-FPM) | P1 | **feito** com SEC-004b | — | ✓ | ✓ | `2fd0a672`, `688674e1`, `95819293` | Opus: sem bloqueante | + 2 vagas por escritório (flock; vale por container) | 3/min por usuário, 429. O teste original não provava nada (o ArrayAdapter zera entre pedidos); refeito com dublê + mutação. Ver DOC-140 |
| PMC-056 | Processo | `processo_new`/`processo_edit` sem CSRF | P0 | **feito** | — | ✓ | ✓ | `38df20f0` | Opus: sem bloqueante | Token por ação (`processo_edit_{id}`); mutação derruba 2 testes |
| IA-002 | IA | CPF/CNPJ sem pontuação não mascarados | P0 | **feito** | — | ✓ | ✓ | `a3f1352d`, `d808e5d0`, `727cec26` | Opus | + pontuação parcial, CNPJ alfanumérico (IN RFB 2.229/2024), NBSP | Só com DV válido; CNJ de 20 dígitos intacto |
| IA-001/003/004 | IA | Aceite incompleto; cota mensal dita diária; travessão | P1/P4 | **feito** | ✓ | ✓ | ✓ | `3f87fede`, `631fd4d2`, `78f1e716` | Opus | Aviso amarrado aos montadores por teste (campo novo derruba) | Lista do aceite gerada do enum `SecaoDoContexto` |

| PAS-038 | Pasta/Dados | "Alertar responsável" no ⋮ do prazo | P2 | feito (ver PAS-047) | ✓ | ✓ | ✓ | `586b7952` | Opus: sem bloqueante | Um item por responsável |
| PMC-028/030 | Metas | Copiar link e Excluir no ⋮ da meta | P2 | feito (ver PAS-048) | ✓ | ✓ | ✓ | `5da8b263` | Opus: sem bloqueante | Excluir com confirm, sem Desfazer (exclusão física) |
| PAS-046 | Timeline | Observações de Detalhes e Financeiro na Timeline | P2 | feito (ver PAS-048) | ✓ | ✓ | ✓ | `c5f520c5` | Opus: sem vazamento no código | Financeiro só sob `podeVer` |

| SEC-012 | Segurança | Anexo da Tarefa servido inline sem sandbox | P0 | **feito** | — | ✓ | ✓ | `d24cfd0b` | Opus: sem bloqueante | Usa a política do `EntregaDeArquivo` sem mudar o contrato de storage (a chave do anexo é da E2.7) |
| SEC-005 | Auditoria | Desfazer recusava Cargo/Sede/Lotação | P2 | **feito** | — | ✓ | ✓ | `0d611444` | Opus: sem achado | Latente: nenhuma entidade reversível tem esse vínculo hoje |
| SEC-006 | Financeiro | Corrigir valor sem trava | P2 | **feito** | — | ✓ | ✓ | `eb95ff04` | Opus: sem achado | `SELECT … FOR UPDATE` + releitura; resolve também o §9 #16 sem migration |
| SEC-007 | Documentos | Caminho legado inválido travava purga, zip e cópia | P2 | **feito** (ver SEC-007b) | — | ✓ | ✓ | `83da8343` | Opus: `--limite` pode prender a fila | Prod (MCP): 22.566/22.566 caminhos válidos; latente |
| SEC-008 | Registro | Menção e resposta sem limitador | P3 | **feito** | — | ✓ | ✓ | `4db61a5f` | Opus | 30 avisos/10 min por autor; a mensagem sempre grava. Teto a ratificar |
| IA-033 | IA | Cabeçalho do Push e análise anterior iam ao provedor sem máscara | P1 | **feito** | — | ✓ | ✓ | `d5d2383a`, `916d0849`, `46d14264` | (mutação: 3 caem) | Achado do implementador do IA-002b |

**Novas divergências achadas no ciclo 1:**
- **SEC-012 (A, P0):** `tarefa_mensagem_arquivo_view` serve anexo da Tarefa inline por `BinaryFileResponse` próprio,
  sem `EntregaDeArquivo` (sem CSP), e o upload aceita `.html`. Achado da revisão do SEC-003. Em implementação.
- **SEC-004b (A, P1):** trava por escritório (2 zips simultâneos) além do limitador por usuário. Em implementação.
- **IA-001b / IA-002b (A, P2):** aceite completo; máscara de CPF com pontuação parcial e CNPJ alfanumérico. Em implementação.
- **PAS-047 (A, P4):** "Alertar" no ⋮ do prazo aparece para quem só vê a pasta (o servidor exige edit). Mesmo
  comportamento do sino das Metas.
- **PAS-048 (A, P3, testes):** faltam testes pedidos na spec: observação financeira escondida também na contagem,
  pessoas, "atenção" e busca (com autor exclusivo); PMC-030 de outro escritório → 404; destino de outra pasta do
  mesmo escritório → 404 (era 422) no mover documento.
- **SEC-007b (A, P4):** com `--limite`, item pulado ocupa vaga e a purga deixa de avançar; o log grava trecho do
  caminho (pode ter nome); o resultado não conta os pulados. Correção: limite sobre os processados.
- **SEC-008b (C, ratificar):** teto de 30 avisos/10 min pode suprimir aviso legítimo (2 mensagens com 16 menções) sem
  o autor ver. Alternativa técnica: subir o teto ou avisar o autor.
- **Deprecations:** `debug:container --deprecations` mostra **5**, não 9 como diz o §11 #6 do mestre.
- **DOC-140 (A, P3):** o .zip sai por `<form target=_blank>`; qualquer recusa (429, 422, 403) abre uma aba com o
  JSON cru e o toast "Preparando o .zip" aparece mesmo assim. Proposta: a rota responder uma página simples
  em pt-BR quando o pedido não for XHR, ou o JS conferir a cota antes. Pré-existente para os 4xx; o 429 agrava.
- **DOC-141 (A, P5):** o aviso do P7S no visor não mostra o tamanho, que o desenho mostra (`bj-visualizar.js:87`).

### 3.2 Fila A priorizada (próximos lotes)

Ordem: P0 → P1 → P2 → P3 → P4/P5, agrupando por arquivo para não haver dois escritores no mesmo lugar.

1. **Segurança restante:** SEC-005 (desfazer Cargo/Sede/Lotação), SEC-006 (trava no corrigir valor, sem
   migration), SEC-007 (caminho inválido para a purga/zip/copiar), SEC-008 (limitador da @menção), SEC-011
   (Push marca lida no GET; ver C abaixo).
2. **P1/P2 da Pasta, sem migration:** PAS-038 (Alertar responsável no ⋮ do prazo), PAS-046 (Timeline lê as
   observações), PMC-030/028 (Excluir e Copiar link no ⋮ da meta), PMC-001/027 e PAS-001/025 (botão
   direito), PAS-041 (⋮ da observação), PMC-004 (Editar dados do processo, depois do PMC-056), PAS-014 e
   DOC-118 (esconder escrita de quem só vê).
3. **@menção nas notas técnicas** (PMC-019) e ⋮ da nota técnica (PMC-017).
4. **Documentos P2:** DOC-115 (Anexar mais formatos), DOC-108 (imprimir qualquer conteúdo do visor),
   DOC-106 (possíveis duplicados no Sugerir), DOC-116 (nome do zip).
5. **Dashboard:** DASH-025/026 (Modo avançado lembrado), DASH-008/010 (links e setas das extras), DASH-006.
6. **Push/Financeiro:** FPN-012 (título = teor), FPN-014 (botão direito), FPN-006, FPN-036, FPN-019, FPN-031.
7. **Editor:** EDT-021/022, EDT-009, EDT-011, EDT-017, EDT-015.
8. **Com migration** (uma por vez): PAS-039/FPN-005 (Responder em Detalhes e Financeiro), PAS-043
   (Enquanto você estava fora), PMC-020 (versões da nota), PAS-005 (foto do cliente), IA-009.

### 3.3 Itens D (aguardando especificação do Farlei)

DASH-002 (o que é "Este mês" e com o que compara) · DASH-034 (Urgentes com ou sem período) · DASH-021
(objetivo automático do ritmo) · PAS-018 (número de pasta repetido: o desenho recusa, o código aceita por
causa do Drive) · PAS-029 (etiqueta do registro: só aparece nos dados de exemplo) · PMC-015 (Relatório geral
de processos por palavras-chave) · FPN: êxito/sucumbência "Previsto" e a base do % (trava o P16b), o que dá o
selo "Assinado", "Criar tarefa" com prazo = data da publicação, "Lida" por escritório ou por pessoa · IA:
10 linhas (fase, situação, providências, memória e os 10 comandos do drawer como regra fixa; 197 comandos do
Chat I.A; ver `inventario-ia.md`).

### 3.4 Decisões novas para o Samuel (além das 35 do §10.3 do documento mestre)

- **SEC-009:** excluir análise da IA e mudar o cadeado "interna" exigem só VER a pasta. Editar? Só o autor?
- **SEC-010:** `/tarefas/equipe` é guardado só pelo módulo `bi` e mostra NUP e cliente sem checar o acesso
  por pasta. O painel de gestão vê tudo, exige também `pastas`, ou filtra pasta a pasta?
- **SEC-011:** abrir o teor no Push marca como lida (GET). Passar para o POST que já existe muda o momento
  em que a publicação conta como lida.
- **PAS (Editar dados):** o "Editar dados" pode alterar CPF e nome no cadastro do cliente? Conflita com a
  decisão de 01/09 (`nome_cliente` é o identificador da pasta).
- **PMC-005:** edição manual dos dados do processo com precedência sobre o Datajud.
- **PMC-034:** alertar pelo sino quem não é responsável da meta.
- **PMC-051:** onde ficam "Tornar principal" e "Desvincular" na linha do cliente.
- **FPN:** card "Valor da causa" = soma dos pagamentos ou campo da pasta; monitoramento e pausa por
  processo; quem recebe o aviso de publicação nova.
- **EDT:** Definir/Traduzir/Pesquisar na web (manda texto do cliente a terceiros); rascunho do editor em
  `localStorage`; dono do atalho Ctrl+K (link do editor × busca global), ligado a D-EDITOR e D-BARRA.
- **IA-024:** ratificar a troca dos 10 comandos do desenho pelos 7 agentes. **IA-028:** "Enviar análise ao
  cliente" com cópia para uma Controladoria que não existe. **IA-032:** o botão do Dashboard usa o nome e a
  estrela da IA real sobre um painel por regras.
- **DASH-028:** abertura animada de 2,65 s a cada carga do Dashboard (o auditor recomenda confirmar antes).

### 3.5 Dependências externas (B)

Cron `app:dashboard:fotografar` (DASH-005: tendências inertes em prod) · backfill `calcular-hash` · provedor
de LLM e chave (IA) · PJe/MNI e OCR ("Buscar agora", leitura do processo) · LibreOffice (DOC formatado,
PPT, MSG, HEIC) · Drive em 403 · servidor de chamadas (Chat/Connect) · pool do PHP-FPM (SEC-004, ajuste do
dono).

## 4. Ciclos executados

(um registro por ciclo: o que foi auditado, implementado, testado e revisado)

## 5. Handoff
