# HANDOFF — frente `designer-central-ia` (14/10/2026)

Continuidade da integração do pacote do Claude Designer de 13/10 (Central de Comunicação, Chat I.A, Agente de Pastas,
comandos) à arquitetura real do BlueJus. **Nada publicado.** Este documento resume e aponta; o detalhe mora nas specs
referenciadas — não duplique.

## 1. Resumo executivo

Três entregas implementadas e commitadas localmente: **Agente de Pastas Fase 1** (API de leitura), **Central A1**
(conversas 1:1 persistidas, polling, página `/central`) e **adaptador Anthropic dormente** (D1). A IA continua desligada,
sem credencial, sem chamada real. O resto (Central A2, Chat I.A, comandos, Agente Fase 3) está pendente ou bloqueado
por decisão do dono.

## 2. Estado Git (verificado em 14/10, fim da sessão)

- Repositório `/home/prime/projetos/jusprime` · worktree `.claude/worktrees/designer-central-ia` · branch
  `designer-central-ia` · HEAD `d9503eb3` · árvore limpa.
- Base `origin/master` @ `a3919b5c` (não andou desde a abertura): **6 à frente, 0 atrás**.

| Commit | O quê |
|---|---|
| `67d52aa4` | matriz Designer × BlueJus, decisões D1–D9, registro da frente |
| `6571705c` | Agente de Pastas Fase 1 |
| `fce85179` | spec da Central A1 |
| `24c22ed6` | Central A1 (código, migration, testes) |
| `bfd4b98b` | proposta de integração Anthropic |
| `d9503eb3` | adaptador Anthropic dormente |

- Banco de teste da frente: `saas_testdesigner-central-ia` (clone do `saas_test`), **já com** a migration
  `Version20261014180000` (aplicada por `doctrine:migrations:execute --up`).
- ⚠️ A migration **não** está no `saas_ux` (tela) nem no `saas_test` (suíte do master): sem ela `/central` não abre na
  tela, e a suíte do master quebra depois da integração. Ver `memory/feedback_saas_test_nao_recebe_migration_sozinho`.

## 3. Stack real (medida)

PHP 8.2 · Symfony 7.4.5 · Twig 3.23 · Doctrine ORM 3.6.2 · Messenger 7.4 (transporte Doctrine) · symfony/http-client
7.4 · PHPUnit 11.5. Frontend sem bundler, tudo por CDN em `base.html.twig`: Bootstrap 5.3.3, Bootstrap Icons 1.13.1,
AdminLTE 4.0.0-rc3, jQuery 3.6.0. JS/CSS próprios em `app/public/{js,css}/`. Sem Mercure/WebSocket/SSE.
Detalhe: `docs/specs/designer-central-ia.md` §1.

## 4. Arquivos e módulos

| Área | Onde |
|---|---|
| Matriz, decisões, ordem | `docs/specs/designer-central-ia.md` |
| Classificação dos 321 comandos | `docs/specs/designer-central-ia-comandos.md` |
| Central — proposta das fases | `docs/specs/central-comunicacao-proposta.md` |
| Central A1 — spec | `docs/specs/central-comunicacao-a1.md` |
| Anthropic — proposta e checklist | `docs/specs/inteligencia-provedor-anthropic.md` |
| Agente Fase 1 | `app/src/Inteligencia/{Controller/AgenteDePastasController, Service/ConsultaDePastasDoAgente, UseCase/*DoAgente*, DTO/FiltroDoAgenteInput}` |
| Central | `app/src/Central/` (+ `CLAUDE.md`), `app/templates/central/`, `app/public/{css,js}/central.*`, `app/migrations/Version20261014180000.php` |
| Adaptador | `app/src/Inteligencia/Service/ProvedorAnthropic.php`, alias e parâmetros em `app/config/services.yaml` |
| Regras de domínio | `app/src/Inteligencia/CLAUDE.md`, `app/src/Central/CLAUDE.md` |
| Compartilhados tocados | `config/packages/doctrine.yaml` (mapping `AppCentral`), `PurgarEscritorioUseCase` (3 tabelas), `AuditavelCoberturaTest` (3 entidades), `app/src/CLAUDE.md`, `CHANGELOG.md`, `docs/frentes-ativas.md` |
| Designer (fora do repo) | `/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/` |

## 5. Estado das entregas

| Item | Estado | Evidência / observação |
|---|---|---|
| Agente de Pastas Fase 1 — `GET /api/agente/pastas` (+`contar=1`), `/pastas/{id}/documentos`, `/opcoes` | **CONCLUÍDO** | mesma consulta e chaves da Lista do Expediente (`busca`, não `cliente`/`acao`); 422 em vez de filtro ignorado; tenant explícito provado sem o `TenantFilter`; documentos com 404 antes de 403. Spec §3 |
| Agente — `ler_documento`, `semMetas`, `responsavel=sem` | **BLOQUEADO** | D8 (extrator), D7 (ambíguo), chega com a E8 da `fidelidade-designer` |
| Agente Fase 3 (plano assinado, lote, desfazer) | **BLOQUEADO** | D3 do dono: nada de alteração em massa até regras aprovadas |
| Agente — janela lateral / planejador | **PENDENTE** | planejador depende da ativação da IA; janela mora em tela da `fidelidade-designer` |
| Central A1 — conversa 1:1, histórico, não lidas, polling, `/central` | **CONCLUÍDO** | spec `central-comunicacao-a1.md`; 31 testes; 2 rodadas de revisão |
| Central A1 — título da janela | **PARCIAL** | o código ainda mostra "Chat I.A"; o dono definiu **"Central de Comunicação"** (§7) — trocar em `_janela.html.twig` e `central.js` |
| Central A2 — @menção → notificação, menus de botão direito (conversa/mensagem) | **PENDENTE** | não iniciada; usa `App\Entity\Notificacao` existente |
| Central — grupos, anexos, presença | **PENDENTE** | grupos exigem antes a janela de sobreposição no cursor (limitação na spec A1 §5) |
| Central — botão global / link no menu | **BLOQUEADO** | D6: `base.html.twig` e `_sidebar.html.twig` só com coordenação com a `fidelidade-designer` |
| Ligações (BlueJus Connect) | **BLOQUEADO** | D-CONNECT: servidor de sinalização/TURN, frente própria |
| Adaptador Anthropic | **CONCLUÍDO (dormente)** | sem `ANTHROPIC_API_KEY`+`IA_MODELO` válidos não sai chamada e `nome()` = `nao_configurado` |
| Ativação da IA | **BLOQUEADO** | D1-a…f (§7) e aprovação do dono |
| BlueJus IA já no master (Push + 7 agentes da pasta, cota, auditoria, fila) | **CONCLUÍDO (desligado)** | `app/src/Inteligencia/CLAUDE.md`; specs `inteligencia-resumo-do-push.md`, `inteligencia-agentes-da-pasta.md` |
| Chat I.A na Central | **PENDENTE** | desenho em `inteligencia-provedor-anthropic.md` §4: fila Messenger → resposta como mensagem → polling; exige autor "BlueJus I.A" na `central_mensagem` |
| Comandos `/` classe (a) | **PENDENTE** | ~14 candidatos com perguntas abertas; `/perdi` e `/timeline` sem pergunta pendente — `designer-central-ia-comandos.md` |
| Comandos classe (b) / (c), invisíveis | **BLOQUEADO** | (b) depende da IA ativa; (c) exige especificação do Farlei |
| Fidelidade visual das telas (E1–E11) | outra frente | `fidelidade-designer`, ver §9 |

## 6. Testes (todos no banco da frente, via `scripts/frente-testar.sh`)

- Última suíte completa: **9288/9288** (51.997 asserções), rodada sozinha, com o HEAD atual.
- `tests/Central`: 31/31 · `tests/Inteligencia`: 713/713 · `ProvedorAnthropicTest`: 21+ casos, só `MockHttpClient`.
- Prova por reintrodução de defeito em todas as guardas (~30 mutações: tenant, participação, CSRF, cursor, polling, ids
  int4, ordem da lista, chave/segredo, redirect, temperature, 429 do teto). Cada uma derrubou o teste certo.
- Arquitetura: `AuditavelCoberturaTest`, `TenantAwareCoberturaTest`, `PurgaCoberturaSchemaTest` verdes;
  `lint:container`, `lint:twig`, `doctrine:schema:validate --skip-sync` OK.
- **Não testado:** visual na tela (é do dono), JS (sem teste automatizado), concorrência real do cursor (garantida por
  construção: `UPDATE … WHERE ultima_lida_id < :ate`).

## 7. Decisões

**Aprovadas pelo dono (14/10/2026):**
- **D1** — provedor de IA: **exclusivamente a Anthropic** (API oficial). Sem contratação, credencial, ativação ou envio de
  dado jurídico real sem nova aprovação.
- **D2** — Central por **polling**, sem Mercure/WebSocket nesta etapa.
- **D3** — reaproveitar o modelo atual de permissões; sem papel novo; **sem alteração em massa** até regras aprovadas.
- **D4** — manter as situações da pasta (`ativo`, `arquivado`).
- **D5** — só comandos com semântica e autorização comprováveis no código; nada ambíguo ou inventado.
- **D6** — componentes da Central isolados (Twig/CSS/JS nativo); **não** tocar `base.html.twig` até coordenar com a
  Fidelidade Visual.
- **Título da interface: "Central de Comunicação"** (substitui o "Chat I.A" do desenho — ainda não aplicado no código).
- **Readmissão:** a mesma identidade readmitida no mesmo escritório **volta a ver o histórico**, sujeito à retenção
  (é o comportamento atual: a participação não é apagada na remoção).

**Escolhas técnicas desta sessão (dentro do autorizado):** HTTP direto pelo `symfony/http-client` (sem SDK); acesso à
Central = vínculo ativo + participação, sem `PermissionChecker`; entidades da Central **fora** de `Auditavel` (o
`audit_log` exporia a conversa); o agente usa a `busca` da Lista.

**Propostas, NÃO aprovadas** (`inteligencia-provedor-anthropic.md` §6):
- D1-a — SDK oficial × HTTP direto (recomendado: HTTP direto).
- D1-b — modelo/esforço: **candidato** `claude-sonnet-5-5` + `medium`, sujeito a confirmação.
- D1-c — ZDR e confirmação por escrito da retenção (as fontes oficiais divergem).
- D1-d — base legal da transferência internacional (LGPD art. 33) e Anexo I da Política — com o jurídico.
- D1-e — `inference_geo` `global` × `us` (+10%). · D1-f — escritório piloto e orçamento mensal.
- D7 `semMetas` · D8 extrator de texto · D9 token do MCP · classes dos comandos (D5 detalhada) · link `/central` no menu.

## 8. Riscos e cuidados de segurança

- **Nunca** credencial no repositório; chave só no `.env.prod` da VPS, linha LF sem espaço/`\r`.
- Exceção de transporte do http-client **cita o cabeçalho** (chave) e o Messenger serializa o `previous`: nunca encadear.
- Serviço usado pela extensão Twig da IA **não pode lançar no construtor** (derruba toda página).
- Modelos 5.5: `temperature`/`top_p`/`top_k`, prefill e `thinking` desligado dão 400; raciocínio conta no `max_tokens`
  (margem 8.000); **não usar Fable/Mythos** (retenção obrigatória de 30 dias); Batch fica fora do ZDR.
- A plataforma redireciona quem não tem vínculo **antes** do controller (302 → `/escritorio/selecionar`): o teste HTTP
  prova essa barreira, não a da Central — a guarda da Central é provada em `AcessoACentralTest`. Idem `TenantFilter`
  × guarda explícita do agente (`ConsultaDePastasDoAgenteTest`).
- Remover colaborador **apaga** o `user_tenant` (hard delete) e reatribui/anula o responsável das pastas.
- O banco de dev (`saas_ux`) está muito atrás do mapeamento: **nunca** `make:migration` puro — filtrar o `--dump-sql`.
- WSL: no máximo 2 subagentes; **uma suíte por vez** (`docker top jusprime_php_dev | grep phpunit`). `TaskStop` deixa
  o phpunit órfão no container; esperar ele sair antes da próxima suíte (o `kill` é negado pelo classificador).

## 9. Coordenação com `fidelidade-designer`

- Worktree própria, sessão própria, HEAD `2467352e` (E8 feita; próximas E9 Peticionar, E10 Agenda, E11 Perfis; E6
  travada por P1). **Não alterar nada dela.**
- Arquivo em comum hoje: só `docs/frentes-ativas.md` (cada frente edita a própria linha — conflito textual simples).
- Pontos de contato futuros: `base.html.twig` e `_sidebar.html.twig` (botão global/link da Central — D6);
  `PastaRepository::aplicarFiltrosPasta` (a E8 traz `responsavel=sem`, que o agente hoje recusa); telas do Expediente
  (janela do agente). Integração em série; a frente com migration (esta) vai sozinha.

## 10. Próxima tarefa recomendada

**Central A2**, dentro de D2/D3/D5/D6:
1. Trocar o título da janela para **"Central de Comunicação"** (`_janela.html.twig`, `central.js`).
2. **@menção na conversa → `Notificacao`** existente (só a quem participa; sem texto da mensagem na notificação além
   do necessário; respeitar a privacidade da conversa).
3. **Menus de botão direito** da conversa (abrir, marcar lida/não lida) e da mensagem (copiar, responder), **só** com
   ações que existem no servidor.
Antes de codar: spec curta `docs/specs/central-comunicacao-a2.md` (risco MÉDIO) com o que entra e o que fica fora.

## 11. Comandos para retomar

```bash
cd /home/prime/projetos/jusprime/.claude/worktrees/designer-central-ia
git status && git branch -vv | grep '^\*' && git log --oneline -7 && git worktree list
docker ps --format '{{.Names}}' | grep jusprime_          # subir à mão se não estiver: docker start jusprime_db_dev jusprime_php_dev
docker top jusprime_php_dev | grep -c phpunit             # 0 antes de rodar suíte
cd /home/prime/projetos/jusprime && scripts/frente-testar.sh designer-central-ia tests/Central       # direcionado
cd /home/prime/projetos/jusprime && scripts/frente-testar.sh designer-central-ia                     # completa, sozinha
# cache da worktree estoura 128 MB: php -d memory_limit=1G bin/console cache:clear (dentro do container, no caminho da worktree)
```

## 12. Critérios de aceite da próxima entrega (Central A2)

- Spec A2 registrada antes do código; nada fora dela.
- Menção só gera notificação a participante com vínculo ativo; nenhuma notificação vaza conversa a terceiro; isolamento
  entre escritórios provado (incluindo pessoa com vínculo em dois).
- Menus só com ações reais; nenhum item simulado.
- Testes unitários + funcionais, prova por reintrodução das guardas novas, `tests/Central` e suíte completa verdes
  (sozinha).
- Revisão `feature-review-agent` sem bloqueante/importante aberto; commits locais; nada de push/merge/deploy.
- Visual descrito ao dono para conferência (o smoke é dele).
