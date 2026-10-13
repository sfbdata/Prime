# Reauditoria C7: BlueJus Intelligence (IA)

Auditoria de convergência, somente leitura, em 13/10/2026, no checkout principal, com o master em `4ba51e14`.
O prefixo é IA. Base: `c6/reauditoria-ia.md`. A suíte não foi rodada, porque o briefing proíbe. O visual foi
conferido lendo o CSS e o template, não na tela.

Commits do C6 nos arquivos da área (`git log 3ff6d9b0..HEAD`): `bbb0deee` (S6-MISC), `a15be1d5` (S6-PUSH),
`ca97a7e7` e `86709cfe` (S6-IA01). Commits de outras frentes que tocaram arquivos vizinhos: `4c2a991c` e
`81ed4da1` (`pasta/_cabecalho.html.twig`) e `76671b8a` (um comentário em `AnalisePushController.php:319`).

## Conferência

| ID | Lote · commits | Veredito | Evidência |
|---|---|---|---|
| C6-IA-01 (download e impressão no Registro de auditoria) | S6-IA01 · `ca97a7e7`, `86709cfe` | ✓ | Rota POST com CSRF por análise: `AnalisePushController.php:206-227`. As recusas vêm antes de gravar: 404, 403, 403 do token, 422 da ação e 404 da análise. `UseCase/RegistrarExportacaoDaAnaliseUseCase.php:60-83` grava 1 `AuditLog` (`ia_baixar`/`ia_imprime`) só com metadados. A leitura tem tenant explícito e fica nos ids das análises da pasta (`AnaliseDeInteligenciaRepository::listarExportacoesDoAlvo`/`contarExportacoesDoAlvo`). O rodapé mostra "Download: …"/"Impressão: …" e soma no N (`_auditoria.html.twig:38-42`), como o dc L5742-5743. O JS registra o download no clique (`pasta-ia-agentes.js:518-522`) e a impressão só quando o texto chega (`:540`). Depois, o rodapé recarrega (`:513`). A cópia não é registrada. A lápide está liberada (`PastaSomenteLeituraListener::ROTAS_LIBERADAS`). O GET do `.txt` continua sem gravar nada. |
| C6-IA-02 (keyframes e fundo do drawer) | S6-MISC · `bbb0deee` | ✓ | Os 5 keyframes de `pasta-ia-agentes.css:107-111` têm os valores do dc L38-44: `iaIn` 100%, `iaFade`, `iaPulse` .55/.92 → 1/1.08, `iaEstrela` com 1.12/8deg e drop-shadow, `iaBrilho` .35/.7. O fundo é `rgba(10,30,45,.18)` com `iaFade .25s` (`:38`, `:147`), igual ao dc L842. A estrela usa 3.2s com atrasos de .8s e 1.6s (`:134-136`), igual ao dc L1141. O `prefers-reduced-motion` cobre o fundo. O dark (`:80`) é um token próprio, que o desenho não define. |
| C6-IA-03 (Criar tarefa só com EDIT e pasta viva) | S6-PUSH · `a15be1d5` | ✓ | `iaPodeCriarTarefa` = `not nota_tecnica_somente_leitura(pastaId) and can_access_resource('pasta', pastaId, 'edit')`, nos 2 parciais (`_analises_push.html.twig:34,107`; `_analises_agente.html.twig:31,105`). O valor vale também na recarga XHR, que só traz o `pastaId`. O teste é `IaMenuEscritaEDesenhoTelaTest`. A recusa no servidor (`tarefa_criar_para_pasta`) é de outra área: C6-SEC-03 parte C, que espera decisão do Samuel. |
| C6-IA-04 ("Marcar como lido" e ícone do ⋮) | S6-PUSH · `a15be1d5` | ✓ | "Marcar como lido" aparece nos 4 lugares. No ⋮, os dois estados usam `bi-check2-all` (`_analises_push:98,104`; `_analises_agente:96,102`), como o dc L2446. Na linha de ações, o estado lido continua com `bi-envelope` (`_analises_push:177`; `_analises_agente:178`), como o `lidoIc` do dc L5151. |

Total: **4 ✓**, 0 ◐, 0 ✗.

## Regressões

Nenhuma.
- `4c2a991c` e `81ed4da1` mudaram em `_cabecalho.html.twig` só os itens Restaurar, Excluir e Imprimir resumo, o
  script de favoritos e o selo da aba Documentos. O botão Intelligence e o drawer não foram tocados.
- `76671b8a` só troca um comentário.
- `85686de6` e `27cdae74` (S6-PUSH) mexem em `pasta-push.js` e `_push_processual.html.twig` fora do bloco da IA. O
  "Marcar como lida" que continua no Push é da publicação (área FPN), não da análise IA.

## Pendências A

Nenhuma. As 4 A do C6 estão feitas, e o inventário já não tinha A aberta (IA-009 → B/C). Continuam fora da classe
A, sem mudança:
- **Decisões do §3.4:** IA-024, IA-028 e IA-032.
- **Bloqueios da ativação:** IA-018 e IA-030.
- **Observação 2 do inventário:** não há trava entre a leitura do status e o `iniciarProcessamento()` no handler.
  Isso conta antes de haver mais de 1 worker.

Ressalva de projeto, já registrada no ledger (S6-IA01) e que não é divergência: o registro é informativo. O
download (GET) e o registro (POST, `keepalive`) são pedidos separados. Se o POST falhar, o download acontece sem
deixar linha.

## Novas

Nenhuma divergência A nova. Revisei os pontos que o C6 tocou e as vizinhanças (menu ⋮ do Push, contra o dc
L2442-2450; botões Copiar · Baixar · Imprimir do cartão, contra o dc L5742-5743; o rodapé). Não achei nada novo que
o desenho mostre claramente e que tenha dado para isso.

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| — | | | | | | | | | |

## Matriz

As 4 A do C6 passam a F: C6-IA-02 e C6-IA-04 em Visual, C6-IA-01 e C6-IA-03 em Interações. O IA-008 deixa de ter
a ressalva.

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual | 26 | 36 |
| Interações | 19 | 39 |
| Funções (persistência/backend/segurança) | 35 | 35 |
| Responsivo | 4 | 4 |
| Dark mode | 5 | 5 |
| **Total** | **89** | **119** |

Ficam abertos 30, todos fora da classe A: IA-009 (B/C) e IA-015 a IA-043 (B/C/D/E).
