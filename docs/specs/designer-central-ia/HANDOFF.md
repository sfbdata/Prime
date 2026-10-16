# HANDOFF — frente `designer-central-ia` (Central de Comunicação)

Consolidado em **15/10/2026** e atualizado em **16/10/2026** (auditoria integral, decisões e preparo de B1/B2; ver §11
e `AUDITORIA-FIDELIDADE.md`) para a transferência a uma nova sessão. Este documento substitui as versões anteriores
(que cresceram por camadas, §1–§16); o histórico delas está no `git log` deste arquivo. O detalhe de regra mora nas
specs citadas; aqui ficam o estado real, as decisões, os riscos e o ponto exato de retomada. **Nada foi publicado.**

---

## 1. Onde está

**Verificado em 16/10 (atualização desta transferência):** branch `designer-central-ia`, HEAD `419af346`
(`origin/master` @ `a3919b5c`, 40 à frente), árvore limpa exceto os dois documentos desta transferência
(`HANDOFF.md` alterado e `AUDITORIA-FIDELIDADE.md` novo, **não commitados**, à espera de autorização). Nenhum código
mudou depois da Fase A. A tabela abaixo é a de 15/10 e segue válida no que não foi atualizado aqui.

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

**Aprovadas pelo dono em 15/10/2026** (eram o padrão em vigor; nenhuma mudança de código; `central-comunicacao-b.md` §8):
1. Quem entra no grupo vê o histórico anterior (como no desenho). **Ressalva expressa:** o acesso ao histórico
   respeita as permissões e o isolamento dos escritórios, inclusive para participantes novos (só entra colaborador
   ativo do mesmo escritório; cada leitura exige vínculo ativo, participação ativa e o mesmo `tenant`; detalhe e
   evidência na spec B §8).
2. Silenciar mantém o aviso de menção no sino (o desenho só tira o som).
3. Qualquer participante renomeia o grupo (como no desenho).
4. Administração vaga (administrador sem vínculo ou fora do grupo) passa ao participante ativo mais antigo com vínculo.
5. Lançador: janela presa a 8 px da borda em tela estreita (o desenho dá x = −2 e corta 2 px). Desvio aprovado.

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
  usuário no cabeçalho da lista. Título "Central de Comunicação" (decisão do dono). Desvio de 8 px (§3 item 5,
  aprovado em 15/10).
- **Conferência com o projeto online (15/10, só leitura):** o projeto "Redesign de pasta jurídica" do Claude Designer
  tem `bluejus-central.js` e `02 - EXPEDIENTES 1.2.3.dc.html` **idênticos** ao pacote local de 13/10 (última edição
  online em 12/10; sem comentários). Nenhuma divergência vem de mudança no desenho. Inventário desenho × implementado
  apresentado ao dono na mesma data (prioridades: P1 decidir notificações dos módulos na Caixa × sino; P2 pacote
  "só trabalho" e API `BJCentral.abrir/conversar`; P3 ações de mensagem e anexos).
- **Aceitas/abertas:** abrir não leva o foco para a janela e Esc não a fecha (o desenho também não); com modal
  Bootstrap aberto o foco do modal prende o teclado; o ícone pode cobrir avisos de canto de outras telas (z-index do
  desenho, 2147483000). JS sem teste automatizado (cobertura por arranjo HTML + teste visual).
- **Regras da integração:** com a janela fechada **nada vira lido** (`marcarLida` exige `janelaAberta`); polling 30 s
  fechada / 5 s aberta / parado com a aba oculta; carga por página = 1 requisição (lista e contatos só na 1ª
  abertura); alerta (salto/som) só quando o total de não lidas do servidor sobe (silenciadas não contam).
- **Layout:** uma linha condicional em `app/templates/base.html.twig` (após o modal do ponto):
  `central_disponivel()` (vínculo ativo; nunca lança) e fora da rota `central_index`.

---

## 4.1 Fidelidade ao Designer: diagnóstico e Fase A (15/10, fim do dia)

- **O dono reprovou a fidelidade visual da demo 8001.** Diagnóstico (só leitura, Playwright lado a lado com o protótipo
  servido localmente): lançador e moldura já eram cópia quase 1:1 (mesmos SVGs, medidas, keyframes; geometria igual em
  1366×800 e 375×740); as TELAS INTERNAS foram reinterpretadas com componentes próprios do `central.css` (menu ⋮,
  confirmação nativa, Informações e Novo grupo, avatar de grupo, ações no hover da bolha, Painel vazio) e faltava o
  balão de dica (`bj-dica.js`). Na demo, a conta sem não lidas deixava o ícone sempre "calmo", e a barra do Symfony e o
  modal do Ponto cobriam a Central. Estratégia aprovada: **portar o código do protótipo, não reinterpretar**.
- **Decisões do dono para a Fase A:** balão só dentro da Central; nomes como estão cadastrados; sem "Destacar" nesta
  etapa; estados demonstrados com dados fictícios isolados de conversas reais.
- **Fase A entregue (lançador e ambiente):**
  - balão de dica fiel ao `bj-dica.js`, restrito às raízes da Central (`central-dica.js` + `.bj-central-dica`); o title
    só sai no hover (como no desenho) e vai para `aria-description` quando o elemento não tem nome próprio;
  - a animação de abertura não fica mais presa na janela (o teste comparativo pegou: ela compunha com a de minimizar);
  - demonstração por worktree: `scripts/central-demo.sh <frente> <porta>` + `scripts/central-demo-router.php` (sem a
    barra do Symfony e sem o lembrete do ponto, só naquele servidor; recusa porta de outra frente);
  - estados calmo, com não lidas e com menção: SEM gravar no banco. No Playwright toda chamada `/central/*` é
    interceptada com dados fictícios (cada teste exige zero escritas). O `saas_ux` não foi tocado;
  - `e2e/tests/central-fidelidade.spec.js` (projeto `central-fidelidade`, só com `CENTRAL_E2E_EMAIL` no comando):
    7 testes protótipo × sistema (geometria, formas do SVG, estilo computado, keyframes e tempos de pontos, salto,
    abrir e minimizar, hover, balão, celular). Provado por mutação: cor do balão e animação presa derrubam o teste certo.
- **Pendente (fases B a E, ver o diagnóstico):** B moldura (menu ⋮ em painel, confirmação na janela, avisos no lugar de
  `alert`, avatar no cabeçalho da lista); C lista e Painel (avatar de grupo, linha, vazios, `bjcIn` do `central.css`);
  D conversa (ações no hover, cabeçalho clicável com Pesquisar, compositor, "Novas mensagens", recibo "Visualizada");
  E grupo (Informações e Novo grupo). Cada uma ganha seus testes no mesmo spec.

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

**Demonstração da frente (Fase A):** `scripts/central-demo.sh designer-central-ia 8002` (porta 8002; a 8001 é da
demo integrada e não foi alterada). Testes comparativos: `cd e2e && npm ci && CENTRAL_E2E_EMAIL=... CENTRAL_E2E_SENHA=...
CENTRAL_E2E_ESCRITORIO="..." npx playwright test --project=central-fidelidade` (protótipo em
`/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo`, servido pelo próprio teste).

**Próxima ação AUTORIZADA (16/10): B1 Moldura, depois B2 Destacar.** Escopo, critérios de aceite e restrições em
§11. Comece por: (1) os comandos de diagnóstico acima; (2) `scripts/central-demo.sh designer-central-ia 8002` (a
máquina reinicia com frequência: a demo, o proxy e às vezes os containers caem); (3) ler `AUDITORIA-FIDELIDADE.md` §2
(regras do protótipo para comandos e Destacar) e §3 (matriz); (4) commitar os dois documentos desta transferência, se
ainda não estiverem commitados, num commit só de docs.

**Integração (histórico):** conferir se a R2 já concluiu e validou a renomeação para `Version20261015213000`
(§9: `git ls-tree --name-only -r r2-gravacao-dupla -- app/migrations | grep 20261015`). **Até lá, não integrar a
Central** (nem ao master, nem a outra branch de integração). Em paralelo, conforme o dono pedir: (a) avaliação visual do lançador (incorporar à demo os commits
`f118f3c7`, `66b67c9c`, `7de93350`, `007c7876`, `04ced658` por cherry-pick individual na `demo-expediente-central`,
limpar o cache de dev dela e religar a 8001); (b) conferir o projeto online do Claude Designer via
`mcp__claude_design__*` contra o pacote local de 13/10 e registrar diferenças; (c) decisões §3 itens 1–5.

**Regras que valem para quem continua:** commit local só na `designer-central-ia` (hook `pre-commit` exige o
`.frente`); cherry-pick um commit por vez; push/merge/reset/deploy são do humano; no máximo 2 subagentes e uma suíte
por vez; smoke no navegador só quando o dono pedir com essas palavras; migrations no dev só com backup validado e
`execute --up` da versão exata; nenhum texto visível com travessão.

---

## 11. Auditoria integral, decisões de 16/10 e preparo de B1/B2

**Auditoria (15/10, noite, só leitura): NÃO refazer.** Está em `docs/specs/designer-central-ia/AUDITORIA-FIDELIDADE.md`:
diagnóstico, regras extraídas do protótipo (comandos, Destacar, lançador, presença, filtros, Painel, notificações, IA,
menu ⋮, bj-contas, integrações do Expediente), matriz de fidelidade, comandos ausentes, o que está comprovado,
backend existente e faltante, riscos e plano B1, B2, C, D, E, F. Evidências em
`/home/prime/projetos/jusprime/.playwright-mcp/fid/` (`auditoria/`, `faseA/`, `p-*`/`d-*`), pasta ignorada pelo git.
Conclusão central: o Designer mostra atividade fictícia (notificações geradas pelo próprio protótipo, presença
simulada); o sistema só tem mensagens e menções; as telas internas foram reinterpretadas; os testes da Fase A provam
aparência com dado simulado, não a existência de dado real.

**Situação da R2 (16/10):** `r2-gravacao-dupla` @ `bcaf2d11` já tem `Version20261015213000` commitada (colisão de
versão resolvida do lado dela). A validação é da sessão da R2; a regra de integrar uma frente com migration por vez
continua.

### 11.1 Decisões APROVADAS pelo dono (16/10)

1. **Próximas entregas autorizadas: B1 (Moldura) e B2 (Destacar)**, a iniciar na PRÓXIMA sessão.
   - B1: corrigir a restauração da janela; reproduzir o cabeçalho e o menu ⋮ do Designer exibindo **só comandos que
     funcionam de verdade**; substituir `window.alert`/`window.confirm` por componentes visuais fiéis ao desenho.
   - B2: Destacar com Document Picture-in-Picture e alternativa compatível (pop-up `window.open`); permitir levar a
     Central a outro monitor; preservar estado da conversa, polling e retorno à janela original.
2. **NÃO autorizados nesta etapa:** notificações, presença, IA, anexos, ligações, migrations e qualquer alteração de
   backend fora do escopo B1/B2.

### 11.2 Recomendações AINDA NÃO APROVADAS (decidir antes das fases que dependem delas)

- Integrar as notificações do sino na Caixa (F1) **com revalidação de acesso na leitura e tenant explícito**.
- Restringir "Equipe · em aberto" a gestores autorizados (o desenho mostra a todos).
- Adiar recibos detalhados de leitura ("Visualizada" e "visualizado por").
- Manter o título "Central de Comunicação" (o desenho diz "Chat I.A").
- Não criar funcionalidades fictícias para preencher menus (Governança, Ocorrências, Ligações, Central de Atenção
  ficam fora até existirem).
- Preferir demonstrações de estados isoladas em Playwright (sem semear dado no `saas_ux`).
- Regra de urgência proposta para F1 (do desenho): Urgente = meta atrasada, alerta, cobrança; Atenção = prazo em até
  7 dias, demanda, chamado de prioridade alta/crítica.
- Presença (F2) por polling, com última atividade por vínculo e status manual (exige migration e decisão).

### 11.3 Critérios de aceite

**B1 Moldura**
1. Restaurar após maximizar volta a 380×580 (ou o tamanho salvo) sem nenhum quadro intermediário fora da tela:
   medir a caixa da janela logo após o clique e 100 ms depois (hoje fica ~1 s em 1342×776 a partir de x=968).
2. Cabeçalho da lista com o avatar do usuário (30 px, anel `0 0 0 2px rgba(255,255,255,.5)`), **sem** ponto de
   status e **sem** pílula "on-line" (presença não autorizada); título "Central de Comunicação" (pendente de decisão,
   ver 11.2) e subtítulo "nome · N não lidas | em dia | não perturbe" como no desenho (C:6338).
3. Menu ⋮ em painel absoluto (right 8, top 52, largura 272, max-height `calc(100% - 64px)`, sombra
   `0 6px 24px rgba(0,0,0,.22)`, padding 5, `bjcIn .14s`), títulos de seção 10,5 px/700/.07em #7b93a2, separador 1 px
   #eef1f3, itens 8px 10px raio 3 com ícone 16 px #5f7684, chaves 30×17 (ligada #0a7aad, desligada #cfd9e0), valores
   em azul 12/700 (C:6244-6293). **Só itens funcionais hoje:** Novo grupo; Ver/ocultar arquivadas; Não perturbe 1 h
   (chave, "até HH:MM"); Som (chave); Alertas visuais (chave); Tamanho do texto (valor Normal/Grande); Restaurar
   posição e Restaurar tamanho (só quando houver o que restaurar); "Abrir em janela separada" entra com B2. Itens
   que exigem backend não autorizado ficam fora (sem placeholder).
4. Confirmação interna (sobreposição `rgba(18,36,47,.38)`, caixa até 300 px, padding 16, raio 4, sombra
   `0 10px 30px rgba(0,0,0,.3)`, texto 13,5 px #1d3441, botões 32 px 13/700: Cancelar #eef1f3, ação vermelha #c0392b
   para Excluir/Remover/Sair ou azul #0a7aad) no lugar de todo `window.confirm`; erros como aviso escuro (o mesmo do
   lançador) no lugar de todo `window.alert`. Busca no código: zero `window.confirm`/`window.alert` na Central.
5. Playwright comparativo (no spec `central-fidelidade`) para cada item: geometria, estilo computado e keyframes
   iguais ao protótipo; prova por mutação de pelo menos um item; `tests/Central` verde; revisão independente.

**B2 Destacar**
1. Botão no cabeçalho (ordem do desenho: Destacar, Minimizar, Maximizar, Fechar; 25×25, ícone
   `bi-box-arrow-up-right`, dica "Destacar: levar o chat para qualquer lugar da tela") e item "Abrir em janela
   separada" no ⋮.
2. Com `documentPictureInPicture`: `requestWindow` com W=max(420, tam.w‖440), H=max(560, tam.h‖660); sem ele:
   `window.open('', 'bluejus-chat', 'popup=yes,...')`; bloqueado: aviso "O navegador bloqueou a janela. Permita pop-ups
   para este site e tente de novo." Textos de sucesso do desenho (AUDITORIA §2).
3. O MESMO nó da janela é movido (não clonado): conversa aberta, rascunho, cursor do polling e contadores continuam;
   folhas de estilo copiadas; janela destacada em 100% do espaço; Minimizar e Maximizar somem; Destacar vira "Voltar
   para dentro do BlueJus".
4. Fechar a janela externa (ou "Voltar") devolve o nó ao lugar e ao modo anteriores; o polling não duplica nem para.
   Decidir e testar o caso "Fechar dentro da janela destacada" (no protótipo não volta; provavelmente defeito dele).
5. Testes: Playwright com Chromium (PiP disponível) cobrindo abrir, estado preservado, voltar ao fechar, e o caminho
   do pop-up (forçando ausência de PiP); nenhuma escrita a mais em `/central/*`; mover para outro monitor é do sistema
   operacional (validar manualmente com o dono).

### 11.4 Restrições de execução (B1/B2)

- Só a worktree `designer-central-ia`; não tocar `master`, R2, Trabalho Externo, `fidelidade-designer`,
  `demo-expediente-central` (8001) nem o servidor de outra sessão (o `central-demo.sh` recusa porta alheia).
- Sem migration, sem alteração de banco e sem backend (salvo o mínimo de B1/B2, se houver, com aprovação).
- Não usar componentes globais (`base.html.twig`, `app.css`, `bj-dica` global) sem avaliação de impacto.
- Uma suíte por vez e nunca junto com a de outra frente (conferir com `docker top jusprime_php_dev -eo pid,args |
  grep bin/phpunit`, que mostra também a worktree pelo cwd do PID no host).
- Playwright comparativo com `/central/*` interceptado; nada de dado fictício em produção nem no `saas_ux`.
- Commits locais pequenos por assunto; push, merge, tag e deploy são do dono.

### 11.5 Riscos e dependências a lembrar

- Prévia de notificação continua visível após revogação de acesso no sino: qualquer F1 precisa rechecar acesso.
- Toda consulta nova da Central com tenant explícito (o sino depende do TenantFilter).
- Notificações do Designer são simuladas; as reais do sistema são outras (ver AUDITORIA §4) e não têm prioridade.
- Anexos dependem da frente R2 (categoria nova, armazenamento por tenant).
- IA: D1-a…f (ZDR, LGPD art. 33, modelo, região, piloto, orçamento) antes de qualquer conversa BlueJus IA.
- Outras worktrees usam o mesmo container e portas (8000 `fidelidade-designer`, 8001 demo integrada): não derrubar.

