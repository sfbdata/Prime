# HANDOFF — frente `designer-central-ia` (atualizado em 15/10/2026: Central B, demo integrada e lançador flutuante)

Continuidade da integração do pacote do Claude Designer de 13/10 (Central de Comunicação, Chat I.A, Agente de Pastas,
comandos) à arquitetura real do BlueJus. **Nada publicado.** Este documento resume e aponta; o detalhe mora nas specs
referenciadas — não duplique.

## 1. Resumo executivo

Quatro entregas implementadas e commitadas localmente: **Agente de Pastas Fase 1** (API de leitura), **Central A1**
(conversas 1:1 persistidas, polling, página `/central`), **adaptador Anthropic dormente** (D1) e **Central A2**
(título "Central de Comunicação", @menção com aviso no sino, responder, marcar como não lida, menus de botão direito).
Em 15/10: correções da A2 (trava contra aviso duplicado, teto pelo texto visível) e **Central B** (grupos,
participação, preferências individuais, polling com janela) — §14. A IA continua desligada,
sem credencial, sem chamada real. O resto (Chat I.A, comandos, grupos/anexos da Central, Agente Fase 3) está pendente ou bloqueado
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
| `f4a04874` | este HANDOFF |
| `838e84fc` | spec da Central A2 |
| `aefbc3e1` | Central A2 no servidor (menção, resposta, não lida, migration `Version20261015090000`) |
| `8315b8e2` | teste: menção exige participar desta conversa |
| `98a591f6` | Central A2 na tela (menus, citação, autocomplete) |
| `7911add9` | correção da revisão: rótulo forjado em menção aninhada (I1), citação com autor |
| `dbee6e4e` | fidelidade ao desenho: citação, faixa de resposta, autocomplete, aviso de cópia |

> A tabela acima é da sessão de 14/10. O HEAD final e a contagem à frente estão no fim deste documento (§13).

- Banco de teste da frente: `saas_testdesigner-central-ia` (clone do `saas_test`), **já com** as migrations
  `Version20261014180000` e `Version20261015090000` (aplicadas por `doctrine:migrations:execute --up`).
- ⚠️ As migrations **não** estão no `saas_ux` (tela) nem no `saas_test` (suíte do master): sem ela `/central` não abre na
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
| Central — título da janela | **CONCLUÍDO** | "Central de Comunicação" em `_janela.html.twig` e `central.js` |
| Central A2 — @menção → `Notificacao`, responder, não lida, menus (conversa: abrir, lida/não lida; mensagem: responder, copiar, mencionar) | **CONCLUÍDO** | spec `central-comunicacao-a2.md`; 49 testes em `tests/Central`; 13 guardas provadas por reintrodução; revisão sem bloqueante, I1 corrigido |
| Central B — grupos (criar, adicionar/remover pelo administrador, sair, renomear, linhas de sistema, menção e aviso), fixar/silenciar/arquivar por pessoa, janela do polling | **CONCLUÍDO** | spec `central-comunicacao-b.md`; §14 |
| Central — ocultar, excluir conversa; editar/excluir/fixar/favoritar/encaminhar mensagem; reações; `@Todos`; foto do grupo | **PENDENTE (D5)** | sem regra aprovada; nenhum item aparece no menu |
| Central — anexos, presença | **PENDENTE** | anexos pelo storage E2; presença exige tempo real (D2) |
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

- Última suíte completa: **9333/9333**, sobre `cc6d8ae4` (Central B) — §14.
- `tests/Central`: 76/76 (A1 + A2 + B) · `tests/Inteligencia`: 713/713 · `ProvedorAnthropicTest`: 21+ casos, só `MockHttpClient`.
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
  prova essa barreira, não a da Central — a guarda das consultas é provada em `IsolamentoDosRepositoriosDaCentralTest`
  (TenantFilter desligado); o `AcessoACentralTest` usa stubs e NÃO a provava (achado de 15/10). Idem `TenantFilter`
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

Central B entregue (§14). Decisões que ficam com o dono — o padrão em vigor é reversível
(`central-comunicacao-b.md` §8): histórico visível a quem entra; silenciar mantém o aviso de menção; renomear por
qualquer participante; administração vaga ao mais antigo com vínculo; `@Todos` fora.

Próxima entrega sugerida: **o smoke do dono na demo (§15)** — as migrations A1/A2/B já estão no `saas_ux`; depois,
**anexos da Central** (storage E2) ou o **link da Central no menu** (D6, com a `fidelidade-designer`).

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

## 12. Central A2: critérios cumpridos

- Spec registrada antes do código (`838e84fc`).
- A menção só avisa participante desta conversa com vínculo ativo. Ficam de fora o terceiro do mesmo escritório,
  quem é de outro escritório e quem tem vínculo nos dois mas não participa. O aviso nunca leva o texto, nem para o
  `audit_log`.
- Os menus só têm ações reais.
- Unit e functional cobrem a A2, com 13 guardas provadas por reintrodução de defeito.
- `tests/Central` 49/49 e suíte completa 9306/9306, rodada sozinha.
- Revisão do `feature-review-agent`: nenhum bloqueante; o I1 foi corrigido e provado; os menores estão no §10.
- **Visual: é o dono quem confere.** Antes disso, é preciso aplicar as duas migrations no `saas_ux`.

## 13. Estado do Git (A2)

Branch `designer-central-ia`, 14 commits locais à frente de `origin/master` @ `a3919b5c` (HEAD = o commit que registra a A2 neste HANDOFF). Nada publicado.

## 14. Central B (15/10/2026)

- **Entregue:** grupos (criar com nome e colegas do mesmo escritório; administrador do grupo = coluna, não papel;
  adicionar/remover pelo administrador efetivo; sair com passagem da administração a quem tem vínculo; renomear por
  participante; linhas de sistema; menção e aviso "adicionou você", sem conteúdo); fixar, silenciar e arquivar por
  pessoa; polling com janela de 30 s em consulta à parte; tela com lista mista, ⋮ (novo grupo, arquivadas), telas de
  novo grupo e informações. Correções da revisão da A2 (rótulo dos mencionáveis, corte bruto, READ COMMITTED
  registrado, risco residual envio × leitura na spec A2).
- **Migration:** `Version20261015180000` (só acrescenta; dois `DROP NOT NULL` no par, seguros; `down` aborta se houver
  grupo). Aplicada no banco de teste da frente e, em 15/10, no `saas_ux` (§15).
- **Testes:** suíte completa **9333/9333** (52.284 asserções), sozinha, sobre `cc6d8ae4`; `tests/Central` 76/76; prova por reintrodução: 17/18 guardas da B derrubaram o teste certo na primeira
  rodada — a 18ª (tenant explícito da participação) revelou que nenhum teste provava a guarda das consultas sem o
  TenantFilter, desde a A1; criado `IsolamentoDosRepositoriosDaCentralTest`, que derruba as 4 mutações de tenant.
  Correções da revisão: 9/10 mutações derrubaram; a 10ª (sucessor sem vínculo) é coberta por outra guarda (a
  administração efetiva), comportamento correto.
- **Revisões:** A2/Etapa 2 sem bloqueante (menores corrigidos). Central B sem bloqueante; 3 importantes corrigidos
  (laço do polling com janela cheia, mensagem perdida com a tela de informações aberta, administração presa em quem
  perdeu o vínculo) e menores (readmissão concorrente, preferências antigas na readmissão, id inválido, avisos de
  quem saiu, rótulo da tela de informações, regra 6 do CLAUDE.md).
- **Limites conhecidos:** transação que demore > 30 s para confirmar uma mensagem escapa ao polling (aparece ao
  reabrir); JS sem teste automatizado (o smoke é do dono); confirmações usam `window.confirm` (a janela de
  confirmação do desenho mora na `fidelidade-designer`, E7).
- **Git:** branch `designer-central-ia`, commits locais à frente de `origin/master` @ `a3919b5c`; nada publicado.

## 15. Demo integrada com a Central B (15/10/2026)

- **Onde:** branch local `demo-expediente-central` (worktree `.claude/worktrees/demo-expediente-central`), servida em
  **http://127.0.0.1:8001** (servidor embutido do PHP no container + proxy `smoke-demo-proxy`; o `:8080` segue servindo o
  master, sem a Central). Composição: `designer-central-ia` até `a45b5d29` + 27 commits da `fidelidade-designer`
  (E2–E8) + os 8 da Central B (`a123a8f4`…`2fdf46a2`), todos por cherry-pick individual, sem conflito (só
  `docs/frentes-ativas.md` combinou as duas linhas). Conferido por conteúdo: cada arquivo das duas frentes é idêntico ao
  da frente; `designer-central-ia`, `fidelidade-designer`, `master` e `origin/master` intactos. **Não** é publicação: é
  só para avaliação local.
- **Banco `saas_ux`** (local, container `db`, rede privada do Docker; só a sessão de dev conectada): backup
  `/home/prime/backups-dev/saas_ux_antes_central_b_20261015_113906.dump` (sha256 `d3d7603b…`), restaurado num banco
  descartável com as mesmas contagens nas 111 tabelas e apagado em seguida. Aplicada só a `Version20261015180000`
  (`execute --up`; o `migrate` arrastaria 2 migrations antigas de abril cujo schema já veio no dump). Antes × depois:
  nenhuma linha de dado mudou (só +1 em `doctrine_migration_versions`); o schema só ganhou colunas/índices em
  `central_*`; mapeamento e banco em dia. As tabelas da Central estavam vazias.
- **Testes sobre o código integrado** (banco de teste da frente): `tests/Central` + `tests/Expediente` 141/141;
  notificação, telas da Pasta/Lista e coberturas de arquitetura 176/176; **suíte completa 9533/9533** — master +
  Central A1/A2/B + Expediente E2–E8 juntos pela primeira vez.
- **Contas de dev** (cópia de dados reais, local): `farlei.rocha@gmail.com` e `jusprime.samuel@gmail.com`, senha
  `Prime123!` (conferida pelo hash, sem alterar nada), ambas no escritório 1; a primeira também no escritório 2.
  Para um grupo de 3+, os demais colegas ativos do escritório 1 aparecem na escolha (não há senha conhecida deles).
- **Desligar:** `docker rm -f smoke-demo-proxy` e matar o `php -S 0.0.0.0:8001` no container. **Retomar:** comandos no
  relatório de 15/10 (servidor embutido na worktree da demo + `alpine/socat` publicando `127.0.0.1:8001`).

## 16. Lançador flutuante da Central (15/10/2026)

- **Fonte do desenho:** o MCP `claude_design` (api.anthropic.com/v1/design/mcp) **não estava disponível** na sessão (só
  a `DesignSync`, que é para design system). Usada a cópia local do pacote aprovado de 13/10
  (`/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo/`: `02 - EXPEDIENTES 1.2.3.dc.html` +
  `bluejus-central.js`). Se o projeto online mudou depois de 13/10, a diferença não foi vista.
- **Entregue:** lançador (3 SVGs do desenho: calmo, aberto, com não lidas; contador laranja; "@n" de menções; hover;
  salto ao chegar mensagem; pontos animados; arraste com 64 px sempre à vista; reduced-motion); janela 380×580
  independente do lançador (abre com bjcIn; minimizar animado até o lançador; maximizar/restaurar; fechar; mover pelo
  cabeçalho com ímã de 14 px; encaixe em meia tela ou tela cheia com guia; duplo clique; setas; 8 alças com mínimo
  320); abas Caixa de entrada/Painel; filtros Tudo/Menções; Painel com cartões Menções/Mensagens; ⋮ com som (WebAudio),
  alertas visuais, não perturbe por 1 hora, texto grande, restaurar posição/tamanho. Preferências por usuário no
  localStorage (como no desenho); a janela sempre começa fechada.
- **Integração (sem segundo backend):** é a MESMA janela da `/central` (`_janela.html.twig` em modo flutuante), o
  mesmo `central.js`. Servidor só ganhou `conversasComMencao` no polling. Com a janela fechada **nada vira lido** e o
  polling cai para 30 s; aberta, 5 s. Layout: **uma** inclusão condicional no `base.html.twig`
  (`central_disponivel()`, nunca lança; fora da `/central`). Coordenação: nenhuma frente mexeu no `base.html.twig`
  desde `a9653e66`; aviso enviado à sessão vizinha.
- **Fora (D5, sem dado real):** presença/"N on-line", status, lista "Todos", "Equipe · em aberto", cartões
  Urgente/Atenção/Metas, filtros Conversas/Urgente/Atenção/Metas/Para revisar, notificações de outros módulos na Caixa,
  "Destacar" (Picture-in-Picture), som de "toque". Título "Central de Comunicação" (decisão do dono), não "Chat I.A".
- **Desvio para o dono aprovar:** em tela estreita a janela é presa a 8 px da borda quando não há posição salva (a
  fórmula do desenho dá x = -2 e corta 2 px).
- **Teste visual (pedido do dono), em `http://127.0.0.1:8002`:** lançador e janela medidos iguais ao protótipo servido
  no mesmo navegador (lançador 1282,728 66×54; janela 968,136 380×580; cabeçalho 52 px `#0a7aad`); maximizar,
  restaurar, encaixe à esquerda, soltar, mínimo 320, minimizar, persistência após recarregar, menção real Samuel →
  Farlei com contador e "@1", leitura só com a janela aberta, Painel, filtro, ⋮, texto grande, celular 375 px sem
  rolagem horizontal. Três defeitos achados no teste visual e corrigidos (`007c7876`): "@n" só aparecia no polling
  seguinte; "@" não saía ao ler; tamanho salvo errado ao redimensionar.
- **Testes:** `tests/Central` 82/82; **suíte completa 9339/9339** (52.314 asserções), sozinha, sobre `04ced658`.
- **Revisão read-only (feature-review-agent):** nenhum bloqueante. Corrigidos (`04ced658`): conversa silenciada fazia o
  lançador saltar e tocar (agora o alerta segue o total de não lidas do servidor, que exclui silenciadas, como o
  desenho L137); carga em toda página reduzida a 1 requisição (lista e contatos só na primeira abertura); som com o
  envelope literal do desenho (L91-104); alerta repetido ao trocar de página; `role="dialog"`; captura de ponteiro e
  `pointercancel` no arraste; include com `only`; menu fechado junto com a janela. Testes novos: vínculo inativo sem
  lançador, leitura do id no link das menções, menção nos dois escritórios. Prova por reintrodução: 3/4 derrubaram; a
  4ª (tirar o filtro de escritório da busca de avisos) é barrada por outra camada (interseção com as conversas ativas
  do escritório) — correto por defesa em camadas.
- **Aceitos/abertos (para o dono):** foco não vai para a janela ao abrir e Esc não a fecha (o desenho também não);
  com um modal Bootstrap aberto o foco do modal prende o teclado; o lançador pode cobrir avisos de canto de outras
  telas (o z-index é o do desenho); "Para revisar"/urgência/presença dependem de dado que o BlueJus não tem; o polling
  de 30 s por aba com a janela fechada é carga real (1 requisição por aba a cada 30 s).
- **Incorporar à demo (porta 8001):** cherry-pick individual, na `demo-expediente-central`, dos commits da frente
  posteriores a `2096554a` (`26712dcb` em diante, ver `git log demo-expediente-central..designer-central-ia`); sem
  migration nova (a do lançador não existe: só código e template); limpar o cache de dev da demo; o lançador aparece em
  toda página (exceto `/central`).

