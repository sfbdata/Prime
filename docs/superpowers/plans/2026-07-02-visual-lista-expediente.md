# Modo Lista (cartões) para as pastas do Expediente — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar um toggle **Tabela ↔ Lista** na listagem de pastas do Expediente, onde o modo Lista renderiza cada pasta como um cartão vertical moderno, com **paridade total** das ações inline.

**Architecture:** Os dois layouts (tabela + lista de cartões) são renderizados no **mesmo fragmento Twig** (`pasta/_tabela.html.twig`, que já volta pronto do servidor a cada recarga AJAX de `#pastas-resultado`). Um wrapper `#pastasView` recebe a classe `pastas-view--tabela` / `pastas-view--lista` e o CSS mostra um layout e esconde o outro. O cartão **reusa as mesmas classes/`data-*`** dos controles da tabela (`pasta-resp-select`, `js-toggle-situacao`, `js-mover-para`, `marcadores-extra`, `pasta-row-link`), então as ações inline delegadas no shell funcionam sem JS novo de negócio. A escolha do modo persiste em `localStorage`.

**Tech Stack:** Symfony 7.4 · Twig · Bootstrap 5 (variáveis CSS) · Bootstrap Icons · JS vanilla inline (sem Webpack/importmap) · PHPUnit (functional) · Playwright (E2E).

## Global Constraints

- **Risco BAIXO** — visual/frontend; **não tocar** em controller PHP, repositório, entidade ou modelo de dados. Só templates + 1 teste functional (+ smoke E2E).
- **Idioma:** pt-BR. O código real desta tela é **pt-BR hardcoded** (apesar do `templates/CLAUDE.md` pregar `|trans`) — seguir o padrão ao redor, **não** introduzir `|trans` novo.
- **Multi-tenant:** nenhuma query nova; o endpoint AJAX já filtra por tenant. Não regredir isolamento.
- **XSS:** dados em `data-*` já usam `|json_encode` no padrão existente; manter. Nada de `|raw` em dado variável.
- **Sem `id=` duplicado:** tabela e cartão coexistem no DOM — usar só **classes** e `data-*`, nunca `id=` por-pasta.
- **Convenção de partial:** `snake_case` com prefixo `_`, na pasta `templates/pasta/`.
- **Git:** commits são executados pelo **humano** (git de escrita é humano no JusPrime). Cada passo "Commit" mostra a mensagem no padrão do projeto (imperativo pt-BR, ≤72 chars); o orquestrador entrega o comando, o humano roda.
- **Testes rodam no container:** `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit ...'`. E2E fora do container em `e2e/`.

---

## Estrutura de arquivos

- **Criar** `app/templates/pasta/_card.html.twig` — componente de cartão de uma pasta (modo lista desktop + mobile). Responsabilidade única: renderizar UM cartão reusando as classes/`data-*` da tabela.
- **Modificar** `app/templates/pasta/_tabela.html.twig` — envolver os resultados em `#pastasView`; trocar o bloco de cards mobile pelo loop de `_card`; adicionar toggle + "Ordenar por" na linha de resultados; CSS do cartão/toggle; script inline de aplicação da view.
- **Modificar** `app/templates/expediente/index.html.twig` — 3 edições cirúrgicas no JS do shell: (1) generalizar o seletor de linha clicável; (2) tratar o dropdown "Ordenar por"; (3) generalizar o callback de refresh de marcador para atualizar o cartão também.
- **Modificar** `app/tests/Expediente/Functional/ExpedienteFiltroPastasControllerTest.php` — 1 teste novo: o fragmento AJAX contém cartões + toggle + dropdown de ordenar.
- **Criar (opcional)** `e2e/tests/expediente-modo-lista.spec.ts` — smoke: alternar view, persistência, ação inline no cartão.

---

## Task 1: Componente de cartão + estrutura de views no `_tabela`

Renderiza o cartão e coloca os dois layouts sob `#pastasView`. Ainda **sem** toggle (default = tabela; a lista fica no DOM, escondida no desktop). Deliverable: o fragmento AJAX passa a conter `pasta-card`.

**Files:**
- Create: `app/templates/pasta/_card.html.twig`
- Modify: `app/templates/pasta/_tabela.html.twig`
- Test: `app/tests/Expediente/Functional/ExpedienteFiltroPastasControllerTest.php`

**Interfaces:**
- Consome (do controller, já passados ao `_tabela` e repassados via `include(..., {pasta, usuarios})`): `pasta` (entidade `Pasta` com getters `nup, clientes[].nomeExibicao, nomeCliente, processoPrincipal.{classeProcessual,assuntoProcessual,numeroProcesso}, nomeAcao, prioridade.value, prioridadeBadgeClass, prioridadeLabel, situacao, responsavel.id, marcadores[].{id,nome,cor}, tarefas[].status, dataAbertura`), `usuarios` (lista de responsáveis com `id, fullName`).
- Produz: markup do cartão com as classes de paridade `pasta-row-link` (+`data-href`), `pasta-resp-select`, `js-toggle-situacao`, `js-mover-para`, `pasta-marcadores-col`, `marcadores-extra`, `clientes-resumo`. Wrapper `#pastasView.pastas-view--tabela` e containers `.pastas-tabela-wrap` / `.pastas-lista`.

- [ ] **Step 1: Escrever o teste falho** — adicionar ao final da classe (antes do `// ---- helpers`), em `ExpedienteFiltroPastasControllerTest.php`:

```php
    #[TestDox('acervo geral: fragmento traz o modo lista (cartões) além da tabela')]
    public function testAcervoGeralRenderizaCartoesDoModoLista(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Lista', admin: true);

        $sufixo = strtoupper(uniqid());
        $pasta  = $this->criarPasta($tenant, 'LISTA-' . $sufixo, nomeAcao: 'Ação Lista ' . $sufixo);

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="pastasView"', $body);
        self::assertStringContainsString('pastas-lista', $body);
        self::assertStringContainsString('pasta-card', $body);
        self::assertStringContainsString($pasta->getNup(), $body);
    }
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter testAcervoGeralRenderizaCartoesDoModoLista'`
Expected: FAIL — `Failed asserting that '...' contains "id=\"pastasView\""` (a estrutura ainda não existe).

- [ ] **Step 3: Criar `app/templates/pasta/_card.html.twig`** com o conteúdo completo:

```twig
{# Cartão de UMA pasta — modo lista (desktop) e mobile.
   Reaproveita as MESMAS classes/data-* da tabela (_tabela.html.twig) para manter a
   paridade das ações inline (responsável, situação, mover marcador) sem JS novo.
   NÃO usar id= por-pasta: tabela e cartão coexistem no DOM. #}
{% set dias = date().diff(pasta.dataAbertura).days %}
{% set acaoTexto = pasta.processoPrincipal
    ? (pasta.processoPrincipal.classeProcessual ?: pasta.processoPrincipal.assuntoProcessual ?: pasta.processoPrincipal.numeroProcesso)
    : pasta.nomeAcao %}
<div class="pasta-card pasta-row-link pasta-prio-{{ pasta.prioridade.value }}"
     data-href="{{ path('pasta_show', {id: pasta.id}) }}">

    {# Linha 1: NUP + cliente | prioridade + situação #}
    <div class="pasta-card-topo">
        <div class="pasta-card-ident">
            <span class="pasta-card-nup"><i class="bi bi-folder2 me-1"></i>{{ pasta.nup }}</span>
            <span class="pasta-card-cliente">
                {% if pasta.clientes|length > 0 %}
                    {% if pasta.clientes|length == 1 %}
                        {{ pasta.clientes[0].nomeExibicao }}
                    {% else %}
                        <span class="clientes-resumo" data-bs-placement="bottom"
                              data-clientes="{{ pasta.clientes|map(c => c.nomeExibicao)|join('||') }}">
                            {{ pasta.clientes[0].nomeExibicao }}
                            <span class="badge text-bg-secondary ms-1" style="font-size:.6rem;">+{{ pasta.clientes|length - 1 }}</span>
                        </span>
                    {% endif %}
                {% elseif pasta.nomeCliente %}
                    {{ pasta.nomeCliente }}
                {% else %}
                    <span class="text-muted">—</span>
                {% endif %}
            </span>
        </div>
        <div class="pasta-card-flags">
            <span class="badge {{ pasta.prioridadeBadgeClass }}{% if pasta.prioridade.value == 'urgente' %} badge-urgente-pulso{% endif %}">
                {{ pasta.prioridadeLabel }}
            </span>
            <span class="badge js-toggle-situacao {{ pasta.situacao == 'ativo' ? 'text-bg-success' : 'text-bg-secondary' }}"
                  style="cursor:pointer;user-select:none;"
                  data-situacao="{{ pasta.situacao }}"
                  data-url="{{ path('pasta_alternar_situacao', {id: pasta.id}) }}"
                  data-csrf="{{ csrf_token('pasta_alternar_situacao_' ~ pasta.id) }}"
                  title="Dê duplo-clique para alternar entre ativo e arquivado">
                {{ pasta.situacao == 'ativo' ? 'Ativo' : 'Arquivado' }}
            </span>
        </div>
    </div>

    {# Linha 2: ação / processo #}
    {% if acaoTexto %}
    <div class="pasta-card-acao"><i class="bi bi-briefcase me-1"></i>{{ acaoTexto }}</div>
    {% endif %}

    {# Linha 3: responsável | marcadores | metas | dias #}
    <div class="pasta-card-acoes">
        <select class="form-select form-select-sm pasta-resp-select"
                data-pasta-id="{{ pasta.id }}"
                data-url="{{ path('pasta_atualizar_responsavel', {id: pasta.id}) }}"
                data-csrf="{{ csrf_token('pasta_responsavel_' ~ pasta.id) }}">
            <option value="">— responsável —</option>
            {% for u in usuarios %}
                <option value="{{ u.id }}" {% if pasta.responsavel and pasta.responsavel.id == u.id %}selected{% endif %}>{{ u.fullName }}</option>
            {% endfor %}
        </select>

        {# Mesma estrutura da coluna de marcadores da tabela: .pasta-marcadores-col > .d-flex,
           para o callback moverParaAtualizarBadgesTabela atualizar o cartão também. #}
        <div class="pasta-card-marc pasta-marcadores-col" data-pasta-id="{{ pasta.id }}">
            <div class="d-flex flex-nowrap gap-1 align-items-center">
                {% if pasta.marcadores|length > 0 %}
                    {% set primeiro = pasta.marcadores|first %}
                    <span class="badge rounded-pill px-2 marcador-principal"
                          style="{% if primeiro.cor %}background:{{ primeiro.cor }};color:#333;border:1px solid rgba(0,0,0,.1);{% else %}background:var(--bs-tertiary-bg);color:var(--bs-body-color);{% endif %}font-size:.68rem;">
                        {{ primeiro.nome }}
                    </span>
                    {% if pasta.marcadores|length > 1 %}
                        <span class="badge rounded-pill px-2 marcadores-extra"
                              style="background:var(--bs-tertiary-bg);color:var(--bs-body-color);font-size:.68rem;cursor:default;"
                              data-marcadores-json="{{ pasta.marcadores|slice(1)|map(m => {nome: m.nome, cor: m.cor})|json_encode }}">
                            +{{ pasta.marcadores|length - 1 }}
                        </span>
                    {% endif %}
                {% endif %}
                <button type="button"
                        class="btn btn-xs py-0 px-1 btn-outline-secondary js-mover-para"
                        style="font-size:.7rem;line-height:1.4;"
                        data-pasta-id="{{ pasta.id }}"
                        data-pasta-nome="{{ pasta.nup }}"
                        data-marcadores-ativos="{{ pasta.marcadores|map(m => m.id)|join(',') }}"
                        data-csrf-token="{{ csrf_token('pasta_marcadores_' ~ pasta.id) }}"
                        title="Mover para…">
                    <i class="bi bi-tags"></i>
                </button>
            </div>
        </div>

        <div class="pasta-card-metas">
            {% set tarefasAtivas = pasta.tarefas|filter(t => t.status != 'concluida') %}
            {% set totalTarefas = pasta.tarefas|length %}
            {% if totalTarefas > 0 %}
                {% set temRevisao = pasta.tarefas|filter(t => t.status == 'em_revisao')|length > 0 %}
                <a href="{{ path('pasta_show', {id: pasta.id}) }}#tarefas"
                   class="badge {{ temRevisao ? 'text-bg-warning' : (tarefasAtivas|length > 0 ? 'text-bg-secondary' : 'text-bg-success') }} text-decoration-none"
                   title="{{ totalTarefas }} meta(s) · {{ tarefasAtivas|length }} ativa(s)">
                    <i class="bi bi-check2-square me-1"></i>{{ totalTarefas }}
                </a>
            {% endif %}
        </div>

        <span class="pasta-card-dias text-muted ms-auto">
            {% if dias == 0 %}Hoje{% elseif dias == 1 %}1d{% else %}{{ dias }}d{% endif %}
        </span>
    </div>
</div>
```

- [ ] **Step 4: Reestruturar `_tabela.html.twig` — abrir o wrapper de views e a linha de resultados.**

Substituir o bloco atual (linhas ~19–31), que hoje é:

```twig
{% if pastas|length > 0 %}
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
    <span class="text-muted">
        <i class="bi bi-list-ul me-1"></i> {{ pastas|length }} resultado(s) encontrado(s)
    </span>
    {% if pagination is defined and pagination is not null %}
        {{ include('expediente/_paginacao.html.twig') }}
    {% endif %}
</div>

{# ── Tabela desktop ─────────────────────────────────────────────── #}
<div class="card d-none d-md-block">
```

por:

```twig
{% if pastas|length > 0 %}
<div class="pastas-view pastas-view--tabela" id="pastasView">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
    <span class="text-muted">
        <i class="bi bi-list-ul me-1"></i> {{ pastas|length }} resultado(s) encontrado(s)
    </span>
    <div class="d-flex align-items-center flex-wrap gap-2">
        {# "Ordenar por" — só no modo lista (no desktop) e no mobile (nenhum tem cabeçalho de coluna) #}
        {% set ordAtual = (ordCol ?: '') ~ '|' ~ ordDir %}
        <div class="pasta-ordenar-wrap">
            <select class="form-select form-select-sm js-pasta-ordenar" aria-label="Ordenar por" title="Ordenar por">
                <option value="" {% if ordCol == '' %}selected{% endif %} disabled>Ordenar por…</option>
                <option value="nup|asc"          {% if ordAtual == 'nup|asc' %}selected{% endif %}>NUP (crescente)</option>
                <option value="nup|desc"         {% if ordAtual == 'nup|desc' %}selected{% endif %}>NUP (decrescente)</option>
                <option value="cliente|asc"      {% if ordAtual == 'cliente|asc' %}selected{% endif %}>Cliente (A–Z)</option>
                <option value="cliente|desc"     {% if ordAtual == 'cliente|desc' %}selected{% endif %}>Cliente (Z–A)</option>
                <option value="prioridade|desc"  {% if ordAtual == 'prioridade|desc' %}selected{% endif %}>Prioridade (maior primeiro)</option>
                <option value="prioridade|asc"   {% if ordAtual == 'prioridade|asc' %}selected{% endif %}>Prioridade (menor primeiro)</option>
                <option value="acao|asc"         {% if ordAtual == 'acao|asc' %}selected{% endif %}>Ação (A–Z)</option>
                <option value="responsavel|asc"  {% if ordAtual == 'responsavel|asc' %}selected{% endif %}>Responsável (A–Z)</option>
                <option value="situacao|asc"     {% if ordAtual == 'situacao|asc' %}selected{% endif %}>Situação</option>
            </select>
        </div>
        {# Toggle Tabela | Lista — só desktop (mobile é sempre cartão) #}
        <div class="btn-group btn-group-sm pasta-view-toggle d-none d-md-inline-flex" role="group" aria-label="Alternar visual">
            <button type="button" class="btn btn-outline-secondary js-view-toggle" data-view="tabela" title="Ver em tabela">
                <i class="bi bi-table"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary js-view-toggle" data-view="lista" title="Ver em lista">
                <i class="bi bi-card-list"></i>
            </button>
        </div>
        {% if pagination is defined and pagination is not null %}
            {{ include('expediente/_paginacao.html.twig') }}
        {% endif %}
    </div>
</div>

{# ── Tabela desktop ─────────────────────────────────────────────── #}
<div class="pastas-tabela-wrap card">
```

> Nota: a `<div class="card d-none d-md-block">` vira `<div class="pastas-tabela-wrap card">` — o `d-none d-md-block` sai (a visibilidade passa a ser controlada pelas classes de view + media query).

- [ ] **Step 5: Trocar o bloco de cards mobile pelo loop de `_card` e fechar o wrapper.**

Substituir todo o bloco atual (linhas ~165–282), de:

```twig
{# ── Cards mobile ───────────────────────────────────────────────── #}
<div class="d-md-none pasta-cards-lista">
    {% for pasta in pastas %}
    ... (todo o markup do card mobile antigo) ...
    {% endfor %}
</div>
{% else %}
```

por:

```twig
{# ── Modo lista (cartões) — desktop quando view=lista, e sempre no mobile ── #}
<div class="pastas-lista">
    {% for pasta in pastas %}
        {{ include('pasta/_card.html.twig', {pasta: pasta, usuarios: usuarios}) }}
    {% endfor %}
</div>
</div>{# /#pastasView #}
{% else %}
```

- [ ] **Step 6: Adicionar o CSS do cartão/toggle/views** — dentro do `<style>` final de `_tabela.html.twig` (o que começa na linha ~294), **substituir o bloco "Cards mobile"** (`.pasta-cards-lista { ... }` até `.pasta-card-footer { ... }`, linhas ~370–451) por:

```css
/* ── Modo lista: cartões (desktop view=lista + mobile) ─────────── */
.pastas-lista { display: flex; flex-direction: column; gap: 10px; padding: 4px 2px; }

.pasta-card {
    position: relative;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 12px;
    padding: 12px 16px 12px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    transition: box-shadow .15s, transform .05s;
    cursor: pointer;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.pasta-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.10); }
.pasta-card:active { transform: scale(.998); }
/* Barra lateral colorida pela prioridade */
.pasta-card::before {
    content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 5px;
    background: var(--bs-secondary-color);
}
.pasta-card.pasta-prio-prioridade::before { background: var(--bs-warning); }
.pasta-card.pasta-prio-urgente::before {
    background: var(--bs-danger);
    animation: urgente-pulso 1.2s ease-in-out infinite;
}

.pasta-card-topo { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.pasta-card-ident { display: flex; align-items: baseline; gap: 10px; min-width: 0; }
.pasta-card-nup { font-weight: 600; font-size: .78rem; color: var(--bs-secondary-color); white-space: nowrap; }
.pasta-card-cliente {
    font-size: .95rem; font-weight: 600; color: var(--bs-body-color); text-transform: uppercase;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.pasta-card-flags { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pasta-card-flags .badge { font-size: .72rem; }

.pasta-card-acao {
    font-size: .82rem; color: var(--bs-secondary-color); text-transform: uppercase;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.pasta-card-acoes { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pasta-card-acoes .pasta-resp-select { max-width: 200px; font-size: .8rem; }
.pasta-card-marc .d-flex { flex-wrap: wrap; }
.pasta-card-dias { font-size: .75rem; flex-shrink: 0; }

/* Toggle de view */
.pasta-view-toggle .btn.active {
    background: var(--bs-secondary-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
}
.pasta-ordenar-wrap .js-pasta-ordenar { width: auto; min-width: 168px; border-radius: 20px; }

/* ── Alternância de layout ─────────────────────────────────────── */
/* Desktop: respeita a view escolhida */
@media (min-width: 768px) {
    .pastas-view--tabela .pastas-lista { display: none; }
    .pastas-view--lista  .pastas-tabela-wrap { display: none; }
    .pastas-view--tabela .pasta-ordenar-wrap { display: none; } /* ordena por cabeçalho na tabela */
}
/* Mobile: sempre cartões, nunca tabela; toggle escondido pelo d-none */
@media (max-width: 767.98px) {
    .pastas-tabela-wrap { display: none; }
}
```

> As regras `.pasta-cards-lista`, `.pasta-card-header`, `.pasta-card-body`, `.pasta-card-row`, `.pasta-card-label`, `.pasta-card-value`, `.pasta-card-meta`, `.pasta-card-footer` do card mobile antigo **saem** (não há mais markup que as use). A animação `@keyframes urgente-pulso` continua definida no topo do arquivo (linha ~10).

- [ ] **Step 7: Rodar o teste e confirmar que passa**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter testAcervoGeralRenderizaCartoesDoModoLista'`
Expected: PASS.

- [ ] **Step 8: Commit** (humano executa)

```bash
# Execute manualmente no terminal externo
git add app/templates/pasta/_card.html.twig app/templates/pasta/_tabela.html.twig app/tests/Expediente/Functional/ExpedienteFiltroPastasControllerTest.php
git commit -m "Adicionar componente de cartão e estrutura de views das pastas" \
           -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Toggle Tabela | Lista + persistência em localStorage

O botão já está no DOM (Task 1). Falta o comportamento: alternar a classe de `#pastasView`, marcar o botão ativo e lembrar a escolha. Tudo em um script inline no `_tabela` (re-executa a cada swap AJAX; handler de clique guardado no document). Deliverable: clicar em Lista troca o visual e persiste após reload.

**Files:**
- Modify: `app/templates/pasta/_tabela.html.twig` (novo `<script>` inline)

**Interfaces:**
- Consome: `#pastasView` e os botões `.js-view-toggle[data-view]` (Task 1).
- Produz: chave `localStorage['pastasView']` ∈ {`tabela`,`lista`}; aplica `pastas-view--lista`/`pastas-view--tabela` em `#pastasView` e `.active` no botão correspondente.

- [ ] **Step 1: Adicionar o script inline** ao final de `_tabela.html.twig` (depois do último `</script>`, antes do fim do arquivo):

```html
<script>
(function () {
    var STORAGE_KEY = 'pastasView';

    function aplicarView(view) {
        var wrap = document.getElementById('pastasView');
        if (!wrap) return;
        var lista = view === 'lista';
        wrap.classList.toggle('pastas-view--lista', lista);
        wrap.classList.toggle('pastas-view--tabela', !lista);
        wrap.querySelectorAll('.js-view-toggle').forEach(function (b) {
            b.classList.toggle('active', b.dataset.view === view);
        });
    }

    /* Reaplica a view salva a cada injeção do fragmento (idempotente). */
    var salvo = null;
    try { salvo = localStorage.getItem(STORAGE_KEY); } catch (e) { /* indisponível */ }
    aplicarView(salvo === 'lista' ? 'lista' : 'tabela');

    /* Handler do toggle ligado uma única vez no document (guarda evita empilhar,
       já que este script re-executa a cada troca de resultados). */
    if (!document.__pastaViewToggleBound) {
        document.__pastaViewToggleBound = true;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-view-toggle');
            if (!btn) return;
            var view = btn.dataset.view === 'lista' ? 'lista' : 'tabela';
            try { localStorage.setItem('pastasView', view); } catch (err) { /* ignora */ }
            aplicarView(view);
        });
    }
}());
</script>
```

- [ ] **Step 2: Verificar manualmente no app** (o comportamento é JS; sem asserção PHPUnit). Subir o app e conferir:

Run: navegar em `/expediente` → "Todas as pastas" (dataset real do dev; login `Prime123!`).
Expected: botões Tabela/Lista aparecem no desktop; clicar em **Lista** troca para cartões e destaca o botão; **F5** mantém o modo Lista; filtrar/paginar mantém o modo.

- [ ] **Step 3: Commit** (humano executa)

```bash
# Execute manualmente no terminal externo
git add app/templates/pasta/_tabela.html.twig
git commit -m "Alternar tabela e lista das pastas com persistencia local" \
           -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: "Ordenar por" no modo lista (server-side)

O dropdown já está no DOM (Task 1). Falta ligá-lo: no `change`, escrever nos hidden `ordenar`/`direcao` do form de filtros e recarregar via a função que já existe. Deliverable: escolher uma ordem no modo lista reordena o acervo inteiro (server-side), como os cabeçalhos fazem na tabela.

**Files:**
- Modify: `app/templates/expediente/index.html.twig` (listener `change` do painel, ~linha 1707)
- Test: `app/tests/Expediente/Functional/ExpedienteFiltroPastasControllerTest.php`

**Interfaces:**
- Consome: `.js-pasta-ordenar` (value `campo|direcao`), o form `#formFiltrosPasta` com hidden `[name="ordenar"]`/`[name="direcao"]`, e a função `recarregarResultadosPastas(extraParams)` (escopo do shell).
- Produz: recarga de `#pastas-resultado` ordenada. `campo` ∈ allowlist do controller (`nup,cliente,acao,prioridade,responsavel,marcadores,situacao`).

- [ ] **Step 1: Estender o teste** — no método `testAcervoGeralRenderizaCartoesDoModoLista`, acrescentar após a última asserção:

```php
        self::assertStringContainsString('js-pasta-ordenar', $body);
        self::assertStringContainsString('pasta-view-toggle', $body);
```

- [ ] **Step 2: Rodar e confirmar PASS** (o markup já existe desde a Task 1):

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter testAcervoGeralRenderizaCartoesDoModoLista'`
Expected: PASS (garante que o toggle e o dropdown estão no fragmento).

- [ ] **Step 3: Adicionar o branch do "Ordenar por"** no listener `change` do painel. Localizar em `index.html.twig` (~linha 1707):

```javascript
    // Dropdowns (status/responsável/prioridade) e datas aplicam ao mudar
    painel.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('js-pasta-filtro')) return;
        aplicarFiltrosPastas();
    });
```

e substituir por:

```javascript
    // Dropdowns (status/responsável/prioridade), datas e "Ordenar por" aplicam ao mudar
    painel.addEventListener('change', function (e) {
        // "Ordenar por" (modo lista): grava nos hidden ordenar/direcao e recarrega o acervo
        if (e.target.classList && e.target.classList.contains('js-pasta-ordenar')) {
            var partes = (e.target.value || '').split('|');
            if (!partes[0]) return;
            var f = document.getElementById('formFiltrosPasta');
            if (f) {
                var co = f.querySelector('[name="ordenar"]');
                var cd = f.querySelector('[name="direcao"]');
                if (co) co.value = partes[0];
                if (cd) cd.value = (partes[1] === 'desc' ? 'desc' : 'asc');
            }
            recarregarResultadosPastas({ page: 1 });
            return;
        }
        if (!e.target.classList || !e.target.classList.contains('js-pasta-filtro')) return;
        aplicarFiltrosPastas();
    });
```

- [ ] **Step 4: Verificar no app** (comportamento JS):

Run: `/expediente` → Lista → escolher "Prioridade (maior primeiro)".
Expected: os cartões reordenam com URGENTE no topo, considerando o acervo inteiro (não só a página); a escolha reflete na tabela ao voltar (hidden `ordenar/direcao` compartilhados).

- [ ] **Step 5: Commit** (humano executa)

```bash
# Execute manualmente no terminal externo
git add app/templates/expediente/index.html.twig app/tests/Expediente/Functional/ExpedienteFiltroPastasControllerTest.php
git commit -m "Ordenar pastas no modo lista via dropdown server-side" \
           -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Cartão clicável + refresh de marcador no cartão (paridade)

Duas edições cirúrgicas no shell para fechar a paridade total: (1) o cartão abre a pasta ao clicar; (2) ao mover marcador no modo lista, o cartão visível atualiza os badges na hora (não só o `<td>` escondido). Deliverable: clicar no cartão navega; mover marcador reflete no cartão.

**Files:**
- Modify: `app/templates/expediente/index.html.twig` (linha ~909 e função ~1908)

**Interfaces:**
- Consome: `.pasta-row-link[data-href]` (agora tabela **e** cartão), `.pasta-marcadores-col[data-pasta-id]` (tabela `<td>` **e** cartão `<div>`).
- Produz: navegação por clique no cartão; callback `moverParaAtualizarBadgesTabela` que atualiza **todas** as colunas de marcadores da pasta.

- [ ] **Step 1: Generalizar o seletor de linha/cartão clicável.** Localizar (~linha 908):

```javascript
document.addEventListener('click', function (e) {
    var tr = e.target.closest('tr.pasta-row-link');
    if (!tr) return;
    if (e.target.closest('button, a, form, input, select, .js-toggle-situacao')) return;
    window.location.href = tr.dataset.href;
});
```

e substituir por (aceita `<tr>` da tabela **e** o `.pasta-card`, ambos com `pasta-row-link` + `data-href`):

```javascript
document.addEventListener('click', function (e) {
    var alvo = e.target.closest('.pasta-row-link[data-href]');
    if (!alvo) return;
    if (e.target.closest('button, a, form, input, select, .js-toggle-situacao')) return;
    window.location.href = alvo.dataset.href;
});
```

- [ ] **Step 2: Generalizar o callback de refresh de marcador.** Localizar a função `window.moverParaAtualizarBadgesTabela` (~linha 1908–1938) e substituí-la inteira por:

```javascript
    // ── Callback pós-salvar do modal "Mover para": atualiza badges na tabela E no cartão ──
    window.moverParaAtualizarBadgesTabela = function (data, escHtml) {
        var cols = document.querySelectorAll('.pasta-marcadores-col[data-pasta-id="' + data.pastaId + '"]');
        if (!cols.length) return;

        // Contadores da sidebar: computa o delta UMA vez (a partir do primeiro botão)
        var primeiroBtn = document.querySelector('.js-mover-para[data-pasta-id="' + data.pastaId + '"]');
        var oldIds = primeiroBtn && primeiroBtn.dataset.marcadoresAtivos
            ? primeiroBtn.dataset.marcadoresAtivos.split(',').filter(Boolean).map(Number)
            : [];
        var newIds = data.marcadores.map(function (m) { return m.id; });
        atualizarContadoresSidebar(oldIds, newIds);

        // Monta o HTML dos badges uma vez
        var novosHtml = '';
        if (data.marcadores.length > 0) {
            var primeiro = data.marcadores[0];
            var bg0    = primeiro.cor || 'var(--bs-tertiary-bg)';
            var cor0   = primeiro.cor ? '#333' : 'var(--bs-body-color)';
            var borda0 = primeiro.cor ? 'border:1px solid rgba(0,0,0,.1);' : '';
            novosHtml += '<span class="badge rounded-pill px-2 marcador-principal" style="background:' + bg0 + ';color:' + cor0 + ';' + borda0 + 'font-size:.68rem;">' + escHtml(primeiro.nome) + '</span>';
            if (data.marcadores.length > 1) {
                var extrasJson = JSON.stringify(data.marcadores.slice(1).map(function (m) { return { nome: m.nome, cor: m.cor || '' }; }));
                novosHtml += ' <span class="badge rounded-pill px-2 marcadores-extra" style="background:var(--bs-tertiary-bg);color:var(--bs-body-color);font-size:.68rem;cursor:default;" data-marcadores-json=\'' + extrasJson.replace(/'/g, '&#39;') + '\'>+' + (data.marcadores.length - 1) + '</span>';
            }
        }

        // Aplica em TODAS as colunas da pasta (td da tabela + div do cartão)
        cols.forEach(function (col) {
            var wrapper = col.querySelector('.d-flex');
            if (wrapper) {
                wrapper.querySelectorAll('span.badge').forEach(function (s) { s.remove(); });
                wrapper.insertAdjacentHTML('afterbegin', novosHtml);
            }
            var btn = col.querySelector('.js-mover-para');
            if (btn) btn.dataset.marcadoresAtivos = newIds.join(',');
        });
    };
```

- [ ] **Step 3: Verificar no app** (comportamento JS):

Run: `/expediente` → Lista → clicar num cartão (fora dos controles) abre a pasta; voltar → no cartão, botão de marcadores → "Mover para" → salvar.
Expected: navegação OK; badges do cartão atualizam na hora (sem reload) e o contador da sidebar muda uma vez. Conferir também na tabela (modo Tabela) que nada regrediu.

- [ ] **Step 4: Commit** (humano executa)

```bash
# Execute manualmente no terminal externo
git add app/templates/expediente/index.html.twig
git commit -m "Tornar cartao clicavel e refletir marcadores no modo lista" \
           -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Smoke E2E + suíte completa verde

Fecha com a rede de segurança: suíte PHPUnit inteira sem regressão e um smoke E2E do fluxo novo. Deliverable: `php bin/phpunit` verde e smoke Playwright passando.

**Files:**
- Create (opcional): `e2e/tests/expediente-modo-lista.spec.ts`

- [ ] **Step 1: Rodar a suíte functional do Expediente** (garantir que os filtros/ordenação não quebraram):

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Expediente'`
Expected: OK (todos verdes, sem deprecations).

- [ ] **Step 2: Rodar a suíte completa** (o projeto trava a suíte em qualquer deprecation/notice):

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'`
Expected: OK — total ≥ 1062 (os 1061 atuais + 1 novo), 0 falhas.

- [ ] **Step 3 (opcional): Criar smoke E2E** `e2e/tests/expediente-modo-lista.spec.ts`:

```ts
import { test, expect } from '@playwright/test';

// Smoke do modo lista das pastas do Expediente (dataset real do dev; senha Prime123!).
test('alterna tabela/lista, persiste e mantém ação inline', async ({ page }) => {
  await page.goto('/expediente');
  // abre "Todas as pastas"
  await page.getByText('Todas as pastas', { exact: false }).first().click();

  const btnLista = page.locator('.js-view-toggle[data-view="lista"]').first();
  await btnLista.click();
  await expect(page.locator('#pastasView.pastas-view--lista')).toBeVisible();
  await expect(page.locator('.pasta-card').first()).toBeVisible();

  // persistência
  await page.reload();
  await expect(page.locator('#pastasView.pastas-view--lista')).toBeVisible();

  // ação inline: trocar responsável no cartão não deve navegar
  const resp = page.locator('.pasta-card .pasta-resp-select').first();
  await resp.selectOption({ index: 1 });
  await expect(page).toHaveURL(/\/expediente/);
});
```

- [ ] **Step 4 (opcional): Rodar o smoke E2E**

Run: `cd e2e && npm test -- expediente-modo-lista`
Expected: PASS. (Se o seletor "Todas as pastas" divergir do dataset local, ajustar o texto — é smoke, não regressão dura.)

- [ ] **Step 5: Commit** (humano executa; só se criou o E2E)

```bash
# Execute manualmente no terminal externo
git add e2e/tests/expediente-modo-lista.spec.ts
git commit -m "Adicionar smoke E2E do modo lista das pastas" \
           -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Auto-revisão do plano (checagem contra a spec)

- **Cobertura da spec:** Formato cartão vertical → Task 1. Paridade inline (responsável/situação/mover marcador) → Task 1 (reuso de classes) + Task 4 (refresh de marcador). Toggle + persistência localStorage → Task 2. "Ordenar por" server-side → Task 3. Reuso do cartão no mobile → Task 1 (substitui o card mobile antigo). Testes functional + E2E → Task 1/3/5. Zero mudança no controller → confirmado (só templates + teste). ✅
- **Placeholders:** nenhum "TBD/TODO"; todo passo traz código/comandos concretos. ✅
- **Consistência de tipos/nomes:** classes de paridade batem com as do `_tabela` real (`pasta-resp-select`, `js-toggle-situacao`, `js-mover-para`, `pasta-marcadores-col`, `marcadores-extra`, `pasta-row-link`); `recarregarResultadosPastas`, `atualizarContadoresSidebar`, `moverParaAtualizarBadgesTabela` existem no shell; endpoint `expediente_acervo_geral` e rotas de ação conferem. ✅
- **Caveat conhecido:** no primeiro carregamento, se o controller não mandar `ordenar`, o dropdown mostra o placeholder "Ordenar por…" enquanto o servidor aplica sua ordem default — cosmético; ao escolher qualquer ordem fica exato. Sem impacto funcional.
```
