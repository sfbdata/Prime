# HANDOFF — frente `designer-central-ia` (Central de Comunicação)

Consolidado em **15/10/2026** para a transferência a uma nova sessão. Este documento substitui as versões anteriores
(que cresceram por camadas, §1–§16); o histórico delas está no `git log` deste arquivo. O detalhe de regra mora nas
specs citadas; aqui ficam o estado real, as decisões, os riscos e o ponto exato de retomada. **Nada foi publicado.**

---

## 1. Onde está (verificado em 15/10, fim da sessão)

| Item | Valor |
|---|---|
| Repositório | `/home/prime/projetos/jusprime` |
| Worktree da frente | `/home/prime/projetos/jusprime/.claude/worktrees/designer-central-ia` |
| Branch | `designer-central-ia` (só local) |
| HEAD | o commit que registra este HANDOFF (o código parou em `04ced658`; docs em `f3caeacf` e depois) |
| Base | `origin/master` @ `a3919b5c` — **0 atrás**, 33 commits à frente |
| Árvore | limpa (nenhuma alteração pendente de código) |
| Banco de teste da frente | `saas_testdesigner-central-ia` (clone do `saas_test`) com as 3 migrations da Central |
| Banco de dev (`saas_ux`) | com as 3 migrations da Central (backups em `/home/prime/backups-dev/`, §8) |

Commits da frente, em ordem (os mais relevantes):

| Etapa | Commits |
|---|---|
| Matriz Designer × BlueJus, decisões D1–D9 | `67d52aa4` |
| Agente de Pastas Fase 1 (leitura) | `6571705c` |
| Central A1 | `fce85179` spec · `24c22ed6` código + `Version20261014180000` |
| Anthropic dormente | `bfd4b98b` proposta · `d9503eb3` adaptador |
| Central A2 | `838e84fc` spec · `aefbc3e1` servidor + `Version20261015090000` · `98a591f6` tela · `7911add9`/`dbee6e4e` revisão e fidelidade |
| A2, Etapa 2 (correções) | `c99691fd` trava anti-duplicata · `1bd90124` teto visível · `a45b5d29` decisões · `aa108eb1` menores da revisão |
| Central B | `a123a8f4` spec · `e241141d` servidor + `Version20261015180000` · `b8fdfbaa` isolamento sem filtro · `a7d83eaf` tela · `cc6d8ae4` revisão |
| Lançador flutuante | `f118f3c7` base · `66b67c9c` componente · `7de93350` linha no `base.html.twig` · `007c7876` teste visual · `04ced658` revisão · `f3caeacf` docs |

---

## 2. Estado das entregas

| Entrega | Estado | Spec / evidência |
|---|---|---|
| Agente de Pastas Fase 1 (`/api/agente/*`, só leitura) | **CONCLUÍDO** | `designer-central-ia.md` §3 |
| Adaptador Anthropic (HTTP direto) | **CONCLUÍDO, DORMENTE** | sem `ANTHROPIC_API_KEY`/`IA_MODELO` nada sai; `inteligencia-provedor-anthropic.md` |
| **Central A1** — conversa 1:1, histórico, não lidas, polling, página `/central` | **CONCLUÍDO** | `central-comunicacao-a1.md` |
| **Central A2** — título "Central de Comunicação", @menção com aviso no sino, responder, marcar como não lida, menus de botão direito | **CONCLUÍDO** | `central-comunicacao-a2.md` |
| A2 Etapa 2 — aviso sem duplicata em envio simultâneo; teto de 4.000 pelo texto visível | **CONCLUÍDO** | `central-comunicacao-a2.md` §2 |
| **Central B** — grupos (criar, adicionar/remover pelo administrador, sair, renomear, linhas de sistema, menção e aviso "adicionou você"), fixar/silenciar/arquivar por pessoa, polling com janela de 30 s | **CONCLUÍDO** | `central-comunicacao-b.md` |
| **Lançador flutuante** (ícone + janela do Designer em todas as telas) | **CONCLUÍDO, aguardando avaliação visual do dono** | §4 |
| Central — ocultar/excluir conversa, editar/excluir/fixar/favoritar/encaminhar mensagem, reações, `@Todos`, foto do grupo, anexos, presença | **PENDENTE** | sem regra aprovada (D5) ou dependem de infraestrutura |
| Chat I.A dentro da Central, comandos `/` | **BLOQUEADO** | IA desligada (D1-a…f) |
| Ligações (BlueJus Connect) | **BLOQUEADO** | servidor de sinalização/TURN, frente própria |
| Agente Fase 3 (alteração em massa) | **BLOQUEADO** | D3: nada em massa sem regra aprovada |

---

## 3. Decisões de produto

**Aprovadas pelo dono:**
- **D1** só Anthropic; nenhuma credencial, ativação ou envio real sem nova aprovação. **D2** polling, sem
  WebSocket/Mercure. **D3** sem papel novo; nada em massa. **D4** situações da pasta mantidas. **D5** só ação com regra
  e servidor de verdade (nada simulado). **D6** layout global só com coordenação (feito: uma linha, §4).
- Título da interface: **"Central de Comunicação"** (não "Chat I.A").
- Readmissão no escritório: a pessoa volta a ver o histórico (participação não é apagada).
- A2: auditoria de **metadado** da menção mantida (nunca o texto); "ler a conversa lê todas as menções dela"
  mantido; teto de 4.000 **visíveis** (a menção conta como `@Nome`).
- B: fixar, silenciar e arquivar são **por pessoa**; silenciar não impede receber; arquivar não apaga e reabre.
- **Sem travessão (— ou –) em texto que o usuário vê** (regra global de 15/10; varrido na Central, nada a corrigir).

**Em vigor como padrão, aguardando confirmação do dono** (reversíveis; `central-comunicacao-b.md` §8):
1. Quem entra no grupo vê o histórico anterior (como no desenho).
2. Silenciar mantém o aviso de menção no sino (o desenho só tira o som).
3. Qualquer participante renomeia o grupo (como no desenho).
4. Administração vaga (administrador sem vínculo ou fora do grupo) passa ao participante ativo mais antigo com vínculo.
5. Lançador: janela presa a 8 px da borda em tela estreita (o desenho dá x = −2 e corta 2 px).

**Pendentes (não aprovadas):** D1-a…f da IA (SDK × HTTP, modelo, ZDR, LGPD art. 33, região, piloto e orçamento);
D7 `semMetas`; D8 extrator de texto; D9 token do MCP; link da Central no menu lateral.

---

## 4. Integração visual com o Claude Designer

- **Fonte:** o MCP `claude_design` não estava disponível na sessão em que o lançador foi feito; foi usada a cópia
  local do pacote aprovado de 13/10 (`/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo/`:
  `02 - EXPEDIENTES 1.2.3.dc.html` + `bluejus-central.js`). Se o projeto online mudou depois de 13/10, isso não foi
  visto. Ao fim desta sessão as ferramentas `mcp__claude_design__*` passaram a aparecer como disponíveis: **conferir o
  projeto online contra o pacote local é a primeira verificação recomendada** (§9).
- **Lançador** (`templates/central/_flutuante.html.twig`, `public/js/central-flutuante.js`,
  `public/css/central-flutuante.css`): 3 SVGs do desenho (calmo, aberto, com não lidas), contador laranja, "@n",
  hover, salto, pontos animados, arraste com 64 px à vista, reduced-motion.
- **Janela:** a MESMA da `/central` (`_janela.html.twig` em modo `flutuante`, mesmo `central.js`): abre com bjcIn,
  minimiza voando até o ícone, maximiza/restaura, move pelo cabeçalho com ímã de 14 px, encaixa em meia tela ou tela
  cheia com guia, duplo clique, setas, 8 alças (mínimo 320). Abas Caixa de entrada/Painel, filtros Tudo/Menções, Painel
  com cartões Menções/Mensagens, ⋮ com som (envelope literal do desenho), alertas visuais, não perturbe 1 h, texto
  grande, restaurar posição/tamanho. Preferências de tela no localStorage por usuário; a janela sempre começa fechada.
- **Medido no navegador contra o protótipo servido no mesmo navegador (1366×800):** lançador 1282,728 66×54 e janela
  968,136 380×580 iguais nos dois; cabeçalho 52 px `#0a7aad`. Celular 375 px sem rolagem horizontal.
- **Divergências reais (fora por falta de dado real, D5):** presença e "N on-line", ponto de status, lista "Todos",
  "Equipe · em aberto", cartões Urgente/Atenção/Metas, filtros Conversas/Urgente/Atenção/Metas/Para revisar,
  notificações de outros módulos dentro da Caixa, botão "Destacar" (Picture-in-Picture), som de "toque", avatar do
  usuário no cabeçalho da lista. Título "Central de Comunicação" (decisão do dono). Desvio de 8 px (§3 item 5).
- **Aceitas/abertas:** abrir não leva o foco para a janela e Esc não a fecha (o desenho também não); com modal
  Bootstrap aberto o foco do modal prende o teclado; o ícone pode cobrir avisos de canto de outras telas (z-index do
  desenho, 2147483000). JS sem teste automatizado (cobertura por arranjo HTML + teste visual).
- **Regras da integração:** com a janela fechada **nada vira lido** (`marcarLida` exige `janelaAberta`); polling 30 s
  fechada / 5 s aberta / parado com a aba oculta; carga por página = 1 requisição (lista e contatos só na 1ª
  abertura); alerta (salto/som) só quando o total de não lidas do servidor sobe (silenciadas não contam).
- **Layout:** uma linha condicional em `app/templates/base.html.twig` (após o modal do ponto):
  `central_disponivel()` (vínculo ativo; nunca lança) e fora da rota `central_index`.

---

## 5. Limitações conhecidas

- Polling: transação que demore > 30 s para confirmar uma mensagem escapa ao polling (aparece ao reabrir).
- Aviso de menção: a trava cobre envio × envio, não envio × "marcar como lida" (spec A2 §2, risco residual).
- Dois envios simultâneos que avisam as mesmas pessoas esperam em ordem de id (sem deadlock; depende de READ COMMITTED).
- Confirmações de remover/sair usam `window.confirm` (a janela de confirmação do desenho mora na `fidelidade-designer`).
- Polling de 30 s por aba aberta, mesmo com a janela fechada (carga real, aceita).

---

## 6. Testes (todos pelo `scripts/frente-testar.sh designer-central-ia`, banco da frente)

| O quê | Resultado | Sobre |
|---|---|---|
| Suíte completa da frente | **9339/9339** (52.314 asserções), sozinha | `04ced658` |
| `tests/Central` | **82/82** | `04ced658` |
| Suíte completa da demo integrada (master + A1/A2/B + Expediente E2–E8) | **9533/9533** | demo `2096554a` (antes do lançador) |
| Prova por reintrodução de defeito | ~70 mutações nas guardas de A1/A2/B/lançador; todas derrubaram o teste certo, exceto 3 cobertas por outra camada (documentadas: sucessor sem vínculo × administração efetiva; filtro de escritório da busca de avisos × interseção com conversas ativas; e o isolamento HTTP que prova o `TenantFilter` — por isso existe `IsolamentoDosRepositoriosDaCentralTest`, com o filtro desligado) | |
| Revisões `feature-review-agent` | A1, A2, Etapa 2, B e lançador: nenhum bloqueante; todos os importantes corrigidos | |
| Teste visual no navegador (pedido do dono) | lançador e janela medidos iguais ao protótipo; 3 defeitos achados e corrigidos (`007c7876`) | `http://127.0.0.1:8002` (servidor temporário da worktree, hoje desligado) |

**Falhas em aberto:** nenhuma. **Não testado automaticamente:** JS (só arranjo HTML e o teste visual manual).

---

## 7. Demo integrada `demo-expediente-central` (porta 8001) — NÃO alterada nesta etapa

- Worktree `/home/prime/projetos/jusprime/.claude/worktrees/demo-expediente-central`, branch local
  `demo-expediente-central`, HEAD `2096554a`: `designer-central-ia` até `a45b5d29` + 27 commits da
  `fidelidade-designer` (até `2467352e`) + os 8 da Central B, por cherry-pick individual. **Não contém o lançador.**
- `git log demo-expediente-central..designer-central-ia` lista 15+ commits, mas os 8 da Central B (`a123a8f4` …
  `2fdf46a2`) **já estão na demo** com outros hashes (cherry-pick). Faltam de verdade os de `26712dcb` em diante; os
  que mudam código: `f118f3c7`, `66b67c9c`, `7de93350`, `007c7876`, `04ced658` (os demais são documentação).
- Servida em `http://127.0.0.1:8001` (servidor embutido no container + proxy `smoke-demo-proxy`); **cai a cada
  reinício da máquina** — religar com os comandos de §9. Contas: `farlei.rocha@gmail.com` e
  `jusprime.samuel@gmail.com`, senha `Prime123!` (dev, conferida pelo hash).
- A `fidelidade-designer` andou depois da demo (`2467352e` → `5fe26dfc`): a demo não tem a E8b.

---

## 8. Banco de dev e backups

- `saas_ux` (container `db`, local): Central A1+A2 em 14/10 e B em 15/10, sempre por `doctrine:migrations:execute
  --up` da versão exata (o `migrate` arrastaria 2 migrations antigas de abril, cujo schema já veio no dump).
- Backups validados (restaurados em banco descartável com as mesmas contagens):
  `/home/prime/backups-dev/saas_ux_antes_central_a2_20261014_191512.dump` e
  `/home/prime/backups-dev/saas_ux_antes_central_b_20261015_113906.dump` (sha256 `d3d7603b…`).
- Há dado de teste da Central no `saas_ux` (grupo "SMOKE CENTRAL B CODEX 20261015", conversa Samuel × Farlei).

---

## 9. Dependências e conflitos com outras frentes (medido em 15/10, só leitura)

| Frente | HEAD / base | Sobreposição com a Central | Risco |
|---|---|---|---|
| `r2-gravacao-dupla` | `89aaaad8` / `a3919b5c` | **migration com o MESMO nome de versão `Version20261015180000`** (a dela cria `armazenamento_replicacao`); `doctrine.yaml` (mapping novo ao lado do `AppCentral`), `services.yaml`, `docs/frentes-ativas.md` | 🔴 **Decidido pelo dono (15/10):** a Central **mantém** `Version20261015180000` sem alteração; a R2 renomeia **só a dela** para `Version20261015213000` (arquivo e classe), reconfirmando que o identificador está livre (conferido aqui em 15/10: nenhuma branch local ou remota o usa). A sessão R2 recebeu a instrução e cuida da correção, dos testes e da revisão. **Nenhuma das duas frentes é integrada antes de a correção da R2 estar concluída e validada.** A Central não altera código da R2. |
| `modulo-trabalho-externo` | `430847f3` / `a3919b5c` | `app/templates/base.html.twig` (link no menu do topo; a Central acrescenta a linha do lançador perto do modal do ponto — trechos diferentes), `PurgarEscritorioUseCase` (linhas novas em listas vizinhas), `doctrine.yaml` (mapping ao lado), `services.yaml`, `docs/frentes-ativas.md`; migration `Version20261015210000` (posterior, sem colisão) | 🟡 conflitos textuais simples; atenção ao vocabulário: o Trabalho Externo tem uma "Central" própria (sede da central de demandas), sem relação com a Central de Comunicação |
| `fidelidade-designer` | `5fe26dfc` / `a9653e66` (17 atrás do master) | só `docs/frentes-ativas.md`; não toca `base.html.twig`, `_sidebar`, `app.css` | 🟢 baixo; a janela de confirmação do desenho (E7) está lá — a Central usa `window.confirm` até integrar |

Ordem sugerida de integração: frentes **com migration uma de cada vez** (Central, R2 e Trabalho Externo têm); trazer
o master para dentro e rodar a suíte antes e depois de cada uma.

---

## 10. Como retomar (próxima sessão)

```bash
cd /home/prime/projetos/jusprime/.claude/worktrees/designer-central-ia
git status && git branch -vv | grep '^\*' && git log --oneline -5 && git worktree list | grep -E 'central|demo'
docker ps --format '{{.Names}}' | grep jusprime_        # se vazio: docker start jusprime_db_dev jusprime_php_dev jusprime_nginx_dev
docker top jusprime_php_dev | grep -c phpunit           # 0 antes de rodar suíte
cd /home/prime/projetos/jusprime && scripts/frente-testar.sh designer-central-ia tests/Central   # esperado 82/82
```

**Religar a demo (8001) depois de reiniciar a máquina:**
```bash
docker exec -d -e APP_ENV=dev -e PHP_CLI_SERVER_WORKERS=4 jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/demo-expediente-central/app && php -d memory_limit=512M -S 0.0.0.0:8001 -t public var/router-smoke.php > /tmp/smoke-demo.log 2>&1'
docker run -d --rm --name smoke-demo-proxy --network jusprime_saas_net -p 127.0.0.1:8001:8001 alpine/socat tcp-listen:8001,fork,reuseaddr tcp-connect:jusprime_php_dev:8001
```
(Para ver o lançador sem mexer na demo: o mesmo par de comandos com a worktree `designer-central-ia` e a porta 8002.)

**Primeira ação recomendada:** conferir se a R2 já concluiu e validou a renomeação para `Version20261015213000`
(§9: `git ls-tree --name-only -r r2-gravacao-dupla -- app/migrations | grep 20261015`). **Até lá, não integrar a
Central** (nem ao master, nem a outra branch de integração). Em paralelo, conforme o dono pedir: (a) avaliação visual do lançador (incorporar à demo os commits
`f118f3c7`, `66b67c9c`, `7de93350`, `007c7876`, `04ced658` por cherry-pick individual na `demo-expediente-central`,
limpar o cache de dev dela e religar a 8001); (b) conferir o projeto online do Claude Designer via
`mcp__claude_design__*` contra o pacote local de 13/10 e registrar diferenças; (c) decisões §3 itens 1–5.

**Regras que valem para quem continua:** commit local só na `designer-central-ia` (hook `pre-commit` exige o
`.frente`); cherry-pick um commit por vez; push/merge/reset/deploy são do humano; no máximo 2 subagentes e uma suíte
por vez; smoke no navegador só quando o dono pedir com essas palavras; migrations no dev só com backup validado e
`execute --up` da versão exata; nenhum texto visível com travessão.
