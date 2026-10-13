# Reauditoria C6: BlueJus Intelligence (IA)

Auditoria somente leitura em 12/10/2026, checkout principal, master em `4a98cc6b`. Prefixo: IA. IDs novos
começam em C6-IA-01. A suíte não foi rodada (proibido pelo briefing). O visual (cor, raio, posição, animação) foi
conferido lendo CSS e template, não na tela.

## Fontes lidas

- Inventário do ciclo 0: `docs/specs/engineering-loop/inventario-ia.md` (inteiro).
- Ledger: `docs/specs/engineering-loop-fidelidade-designer.md` §3.1 (linhas IA-001/003/004, IA-002, IA-033L,
  IA-005/006, IA-007, IA-008, IA-010/011, IA-012/013, IA-014, IA-034L, IA-006b/008b, IA-034L cota), ciclo 1
  (IA-001b/002b, IA-034L) e §3.4 (IA-024, IA-028, IA-032). Fila: `fila-a-restante.md` (IA-009 → B/C, colisão
  IA-033/IA-034).
- Desenho: `02 - EXPEDIENTES 1.2.3.dc.html` L838-1040 (fundo, drawer, chips, grade `sabe`, cartão do resultado,
  rodapé de auditoria), L1134-1142 (botão Intelligence), L2425-2500 (cartão da Análise IA no Push), L5128-5160
  (valores do Push IA: `lidoTxt`, `lidoIc`, recolher), L5593 e L5686-5745 (`iaAudit`, `contexto`, `sabe`,
  `baixar`, `imprimir`, `verAudit`), L38-44 (keyframes `iaEstrela`, `iaBrilho`, `iaIn`, `iaFade`, `iaPulse`).
- Código: `templates/inteligencia/` (os 5 parciais + `admin/configuracao.html.twig`), `pasta/_cabecalho.html.twig:354-373`,
  `pasta/_push_processual.html.twig:25-104`, `public/css/pasta-ia-agentes.css` e `pasta-ia.css`,
  `public/js/pasta-ia-agentes.js:425-560` e `pasta-ia-push.js:408-440`, `src/Inteligencia/` (Enum/Disponibilidade,
  Service/MascaradorDeDadosPessoais, MessageHandler, UseCase/ContarOQueAIaSabeDaPastaUseCase, DTO/OQueAIaSabeOutput,
  Controller/AnalisePushController), `docker-compose.yml:20-52`, `docs/runbooks/ligar-ia-em-producao.md`,
  `src/Controller/TarefaController.php:143-200`, `src/Pasta/Attribute/PastaPorId.php`.
- Git: `git show --stat` dos 23 commits declarados; `git branch --contains` confirma todos em `master`;
  `git log 607f2c29..HEAD` nos arquivos da IA (37 commits).

## Conferência dos feitos

| ID (ledger) | Commits | Veredito | Evidência |
|---|---|---|---|
| IA-002 / IA-002b (CPF/CNPJ sem pontuação, parcial, alfanumérico, NBSP) | `a3f1352d`, `d808e5d0`, `727cec26` | ✓ | `Service/MascaradorDeDadosPessoais.php:54-88` (CNJ protegido antes; variantes sem pontuação, parcial, com espaço/NBSP e alfanumérica), validação de DV em `:148-195` |
| IA-001 / IA-001b (aceite lista o que sai) | `3f87fede`, `78f1e716` | ✓ | `admin/configuracao.html.twig:79-114` (seções do enum `SecaoDoContexto`, blocos `data-ia-bloco`, análise anterior, financeiro condicional) |
| IA-003 (cota mensal) | `631fd4d2` | ✓ | `Enum/Disponibilidade.php:20,35-36` (`LimiteMensalAtingido` com mensagem própria) |
| IA-004 (travessão) | `631fd4d2` | ✓ | `Enum/Disponibilidade.php:33`; grep sem travessão em texto visível de `templates/inteligencia/`, enums, JS da IA |
| IA-033L (cabeçalho do Push e análise anterior mascarados) | `d5d2383a`, `916d0849`, `46d14264` | ✓ | `Contexto/MontadorDeContextoDoPush.php`, `MontadorDeContextoDaPasta.php`, `Fluxo/*` (diffs dos commits) |
| IA-005 (chips do drawer) | `39ca4566` | ✓ | `_agentes_drawer.html.twig:23-47` = dc L5686 (pasta, cliente, processo, responsável; "Sem processo"/"Sem responsável") |
| IA-006 / IA-006b (grade "O que a IA sabe") | `38ef7c6e`, `b615e3f9` | ✓ com ressalva já registrada | `_agentes_painel.html.twig:23-36`; `UseCase/ContarOQueAIaSabeDaPastaUseCase.php:50-65`; 4 colunas (`pasta-ia-agentes.css:265`) contra 5 do desenho, por decisão registrada ("fontes" fora); rótulo "prazos de metas abertas" contra "prazos" (ledger L185) |
| IA-007 (Copiar · Baixar .txt · Imprimir) | `ff9df25f` | ✓ | `_analises_agente.html.twig:49-62`; `pasta-ia-agentes.js:438-520` (impressão por `textContent`, sem HTML); rota `inteligencia_analise_txt` (`AnalisePushController.php:168`); feedback verde do copiar (`pasta-ia-agentes.css:413`) |
| IA-008 / IA-008b (Registro de auditoria) | `fff3f75f`, `4d45251b` | ◐ | `_auditoria.html.twig:20-45` fiel ao dc L1035-1040 (botão, chevron, lista 150px, hora 700 #0c7a9c). Falta o que o desenho também registra: download e impressão (dc L5742-5743 chamam `iaAudit`). Ver C6-IA-01 |
| IA-010 (recolher com prévia, preferência no servidor) | `99cbb19a` | ✓ | `_analises_push.html.twig:41-45,76-83,122-125,175-182`; `pasta-ia.css:323-345` esconde resumo, pontos, quem, base e ações, como o `sc-if aberto` do dc L2455-2496 |
| IA-011 (marcar como não lido) | `856e83c6` | ✓ (texto: ver C6-IA-04) | `_analises_push.html.twig:94-99,168-172`; `_analises_agente.html.twig:86-91,163-167` |
| IA-012 (runbook) | `6c68555a` | ✓ | `docs/runbooks/ligar-ia-em-producao.md` §0-§7 (decisões, env, rebuild, worker, status, smoke, rollback, Anexo I) |
| IA-013 (worker no dev) | `97a8a0cf`, `089a27db` | ✓ | `docker-compose.yml:30-52` (profile `worker`, `restart: "no"`, `messenger:consume async --time-limit=3600`) |
| IA-014 (corpus de injeção) | `9eccd415` | ✓ | `tests/Inteligencia/Support/CorpusDeInjecao.php` + testes do Neutralizador e dos 2 prompts (diff do commit); homóglifos em `Service/NeutralizadorDeConteudo.php` |
| IA-034L e IA-034L cota (worker reconfere a disponibilidade) | `77a013e0`, `45f0d2af`, `2caa5d7c` | ✓ | `MessageHandler/ProcessarAnaliseDeInteligenciaHandler.php:116-118,238-248` (`paraProcessamento`, cancelada antes do provedor não toma cota) |
| IA-009 (editar análise) | — | reclassificado B/C (`fila-a-restante.md:224`) | sem mudança; não conta como pendência A |

Total: **14 confirmados**, **1 parcial** (IA-008), **0 regredidos**.

## Regressões

Nenhuma. Os commits de outras frentes que tocaram arquivos da IA depois dos feitos (`8d30a425`, `72dc6e53`,
`3f99a456`, `ddcd5755`, `470d5d35`, `ddbcfe72`) não alteraram o bloco da IA: o "Resumir com IA" continua entre o
atalho do módulo e os filtros (`_push_processual.html.twig:42-86`), a lista das análises acima da linha do tempo
(`:99-105`), e o botão Intelligence com o drawer em `_cabecalho.html.twig:354-373`.

## Pendências A

Nenhuma do inventário: as 14 A foram feitas (13) ou reclassificadas (IA-009 → B/C). Continuam abertas, fora da
classe A: IA-015, IA-026, IA-028 a IA-036, IA-039, IA-041 (C), IA-016 a IA-019, IA-043 (B), IA-020 a IA-025,
IA-027, IA-037, IA-038, IA-042 (D) e IA-040 (E). A observação 2 do inventário (corrida no handler, sem trava entre
a leitura do status e o `iniciarProcessamento()`, `Handler:105-114`) segue igual. Não é divergência do desenho;
vale antes de haver mais de 1 worker.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-IA-01 | A | P4 | M | não | Registro de auditoria sem download e impressão | dc L5742 (`iaAudit('Download: ' + titulo)`), L5743 (`'Impressão: '`), L1038-1039 (lista hora + o quê) | `_auditoria.html.twig:32-36` e `AnaliseDeInteligenciaRepository.php:75-110` listam só as análises; `AnalisePushController.php:168` (`.txt`) não registra nada; o imprimir busca o mesmo `.txt` (`pasta-ia-agentes.js:440-505`) | Gravar `AuditLog` à mão no `ExportarAnaliseEmTextoUseCase` (precedente: `MontarZipDeDocumentosUseCase.php:337-358`), com ação `ia_baixar` ou `ia_imprimir` (cabe em `length: 10`) escolhida por `?para=impressao` que o JS de imprimir acrescenta. O copiar não entra, porque o desenho não registra cópia. O fetch do copiar passa `?para=copia` e não grava. `ListarAuditoriaDaPastaUseCase` junta essas linhas (tenant + `entityClass` AnaliseDeInteligencia + ids da pasta) às análises, por data. Arquivos: UseCase de exportar, controller (query), `pasta-ia-agentes.js`, `ListarAuditoriaDaPastaUseCase`, `_auditoria.html.twig`, DTO do registro | Functional: baixar grava 1 linha `ia_baixar` com tenant e autor, e o rodapé mostra "Download: …" e soma no N. `?para=copia` não grava. Linha de outro escritório não aparece (2 tenants) |
| C6-IA-02 | A | P5 | P | não | Animações do drawer e da estrela; cor do fundo | dc L38-44 (`iaEstrela` scale 1.12 + rotate 8deg + drop-shadow; `iaBrilho` .35/.7; `iaIn` translate 100%; `iaPulse` .55/.92 → 1/1.08; `iaFade` no fundo); L842 (fundo `rgba(10,30,45,.18)`, `iaFade .25s`) | `pasta-ia-agentes.css:106-109` (`iaDrawerEntra` 24px + opacidade; `iaPulsa` 1 → 1.12/.85; `iaEstrela` 12deg/1.08 sem sombra; `iaBrilho` .55/.85); `:38` (`--ia-fundo-escuro: rgba(10,36,56,.28)`); `.ia-drawer-fundo` sem animação (`:140-146`) | Copiar os 5 keyframes do dc com os mesmos valores e o fundo `rgba(10,30,45,.18)` + `iaFade .25s`, mantendo o `prefers-reduced-motion` (`:590-599`). Só `pasta-ia-agentes.css`, que não é compartilhado | Unit de CSS, lendo o arquivo: valores dos keyframes e do token. Visual: conferência do dono |
| C6-IA-03 | A | P4 | P | não | "Criar tarefa da providência" oferecido a quem só vê a pasta e na lápide | dc L2445 (menu do desenho, sem perfil de leitura); convenção do sistema (FPN-014c `470d5d35`: "nota técnica e Criar tarefa só com EDIT e pasta viva") | `_analises_push.html.twig:101-112` e `_analises_agente.html.twig:93-105` sem condição de edição; `pasta-ia-push.js:412-439` abre `#modalCriarTarefa`, que sempre existe (`show.html.twig:4092`). Pior: o servidor **não** recusa quem só vê. `TarefaController::criarParaPasta` (`:143-200`) confere módulo, tenant e lápide, mas não `canAccessResource('pasta', id, 'edit')` | Na IA: mostrar o item só quando `can_access_resource('pasta', pastaId, 'edit')` e a pasta está viva, como o `pushPodeEscrever` (`_push_processual.html.twig:26-28`), nos 2 parciais. O guarda do servidor é de outra área (ver Observação 1) | Functional (tela): usuário VIEW não vê `[data-ia-criar-meta]` no Push nem no drawer; EDIT vê; a lápide não vê |
| C6-IA-04 | A | P5 | P | não | Texto "Marcar como lida" × "lido" e ícone do item no ⋮ | dc L5157 (`lidoTxt: 'Marcar como lido'` / `'Marcar como não lido'`), L2446 (item do ⋮ sempre `bi-check2-all`) | `_analises_push.html.twig:92,166` e `_analises_agente.html.twig:84,161` dizem "Marcar como lida", e o par vizinho diz "não lido" (`:98,171` e `:90,166`): o mesmo menu mistura os dois gêneros. Item "não lido" do ⋮ com `bi-envelope` (`_analises_push:98`, `_analises_agente:90`) | "Marcar como lido" nos 4 lugares (o desenho manda); no ⋮, `bi-check2-all` nos dois estados. Na linha de ações, o `bi-envelope` fica (dc `lidoIc`). Só os 2 parciais da IA | Tela: o texto "Marcar como lido" e o ícone do item do menu. Atualizar as asserções existentes de "Marcar como lida" |

Não registrei como nova: a falta de `noopener` no `window.open('')` da impressão. A janela é `about:blank` da mesma
origem e é preenchida por `textContent`, então não há vetor.

## Observações

1. **Fora da área (Segurança/Metas), para o auditor de lá confirmar:** `tarefa_criar_para_pasta`
   (`src/Controller/TarefaController.php:143-200`) não confere acesso à pasta (`canAccessResource` view ou edit).
   Confere só o módulo `tarefas`, o tenant (por vínculo do criador ou do responsável) e a lápide (`#[PastaPorId]`,
   `PastaSomenteLeituraListener`). Quem tem o módulo pode criar meta em pasta que não pode ver, desde que saiba o
   id. O `inventario-seguranca.md:104,128` contou essa rota como F para tenant e IDOR. O FPN-014c escondeu o botão
   na tela, mas não há recusa no servidor. Não confirmei se a pasta restrita fica fora do `find` por algum filtro.
2. A colisão de IDs IA-033/IA-034 (inventário × ledger) segue com o sufixo L no ledger. Os novos deste relatório
   usam C6-IA-NN e não colidem.
3. Itens C do §3.4 ainda sem decisão: IA-024 (10 comandos × 7 agentes), IA-028 (enviar ao cliente), IA-032
   (Intelligence do Dashboard por regras). IA-030 (Anexo I sem fornecedor) e IA-018 (adaptador) continuam
   bloqueando a ativação, como o runbook §0 já diz.

## Matriz

O denominador do inventário era 113. Os 4 itens novos entram nele, e os 2 itens de função do ledger (IA-033L e
IA-034L, já feitos) também. As 13 A feitas passam a F: IA-004/005/006 em Visual; IA-007/008/010/011 em Interações;
IA-001/002/003/012/013/014 em Funções. O IA-008 conta como F com a ressalva C6-IA-01, que tem linha própria.

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual | 24 | 36 (+C6-IA-02, C6-IA-04) |
| Interações | 17 | 39 (+C6-IA-01, C6-IA-03) |
| Funções (persistência/backend/segurança) | 35 | 35 (+IA-033L, IA-034L) |
| Responsivo | 4 | 4 |
| Dark mode | 5 | 5 (novos itens com tokens dark: `pasta-ia-agentes.css:64-103`, `pasta-ia.css:80`) |
| **Total** | **85** | **119** |

Abertos: 34 = 30 do inventário (IA-009 B/C, IA-015 a IA-043) + 4 novos.

## Resumo

- Feitos conferidos: 15 (14 ✓, 1 ◐ IA-008, 0 ✗). Regressões: 0.
- Pendências A do inventário: 0. IA-009 foi reclassificado para B/C.
- Novas: 4, todas A (C6-IA-01 P4 M · C6-IA-02 P5 P · C6-IA-03 P4 P · C6-IA-04 P5 P). Nenhuma exige migration.
  Nenhuma toca arquivo compartilhado: só os parciais de `templates/inteligencia/`, `pasta-ia-agentes.css`,
  `pasta-ia-agentes.js` e `src/Inteligencia/`.
- Achado fora da área: `tarefa_criar_para_pasta` sem `canAccessResource`. Encaminhar à reauditoria de Segurança.
