# Filtro Reutilizável nas Tabelas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline, escolha do usuário) para implementar tarefa a tarefa. Steps usam checkbox (`- [ ]`).

**Goal:** Levar o filtro do Expediente (busca livre + facetas + chips + auto-apply em tempo real) para Demandas, Processo, Minhas Metas e Dashboard, via um componente reutilizável.

**Architecture:** Um motor JS estático (`filtro-tabela.js`) + CSS + duas partials Twig genéricas formam o componente. Cada página expõe um endpoint que, em XHR, devolve só o fragmento de resultado; o motor troca o `[data-filtro-resultado]` no DOM sem recarregar a página. Repositórios ganham `findByFiltros/count` com paginação server-side.

**Tech Stack:** PHP 8.2 / Symfony 7.4 / Doctrine ORM 3 / Twig / Bootstrap 5.3 (sem build de assets; JS/CSS estáticos em `public/`) / PHPUnit + Foundry v2 + DAMA.

## Global Constraints

- Idioma pt-BR em código, comentários e commits. `camelCase` métodos/vars · `PascalCase` classes · `snake_case` rotas/templates/colunas.
- `declare(strict_types=1);` em todo PHP novo; type hints 100%; `final` (exceto entidades); `===`/`!==`; construtor com `private readonly`; sem `else` após `return`.
- **Todo comando roda no container:** `docker exec jusprime_php_dev bash -c 'cd app && <cmd>'`. Nunca `php`/`composer`/`bin/console` fora do container.
- Teste roda em `APP_ENV=test` com `failOnDeprecation/Notice/Warning` — um deprecation derruba a suíte. DAMA faz rollback por teste; Foundry v2 para factories.
- **Multi-tenancy inegociável:** `TenantFilter` global escopa DQL de entidades `TenantAware` no fluxo web. Métodos novos que importam recebem `Tenant` explícito + `andWhere('...tenant = :tenant')` (defesa em profundidade). No teste o filtro fica desligado — validar o `andWhere` explícito.
- **Git é humano:** o orquestrador NÃO executa git de escrita. Cada "Commit" abaixo é entregue como bloco `# Execute manualmente no terminal externo`. Convenção: imperativo pt-BR, ≤72 chars, sem ponto final.
- Suíte hoje verde (referência 1070/1070 na branch anterior; nesta branch a partir do master, rodar a suíte para fixar a linha de base).
- Padrão do XHR: controller checa `isXmlHttpRequest()` e renderiza **só** o partial `_resultado` (o innerHTML que o motor injeta). Sem XHR, renderiza a página cheia.

---

## File Structure

**Componente (novo):**
- `app/public/js/filtro-tabela.js` — motor genérico (delegação no root, debounce, chips, swap AJAX).
- `app/public/css/filtro-tabela.css` — estilos `.filtro-*` (espelham o visual do Expediente).
- `app/templates/_partials/_filtro_barra.html.twig` — barra parametrizável por `facetas`.
- `app/templates/_partials/_filtro_paginacao.html.twig` — paginação genérica.

**Demandas:** `app/src/Pasta/Controller/DemandasController.php`, `app/src/Pasta/Repository/PastaRepository.php`, `app/templates/pasta/demandas.html.twig` (casca), novo `app/templates/pasta/demandas/_resultado.html.twig`.
**Processo:** `app/src/Processo/Controller/ProcessoController.php`, `app/src/Processo/Repository/ProcessoRepository.php`, `app/templates/processo/index.html.twig` (casca), novo `app/templates/processo/_resultado.html.twig`.
**Minhas Metas:** `app/src/Controller/TarefaController.php` (legado — só aditivo), `app/src/Tarefa/Repository/TarefaRepository.php`, `app/templates/tarefa/minhas.html.twig` (casca), novo `app/templates/tarefa/_resultado.html.twig`.
**Dashboard:** `app/src/Dashboard/Controller/DashboardController.php`, `app/src/Dashboard/UseCase/ObterDadosDashboardUseCase.php`, `app/src/Tarefa/Repository/TarefaRepository.php`, `app/src/Pasta/Repository/PastaRepository.php`, `app/templates/dashboard/index.html.twig` (casca), novo `app/templates/dashboard/_resultado.html.twig`.

---

## Fase 0 — Componente compartilhado

Sem teste automatizado de JS no projeto; o componente é validado ponta a ponta pela Fase 1 (Demandas) + smoke. Escrever os 4 arquivos e verificar sintaxe/carregamento.

### Task 0.1: Motor JS

**Files:** Create `app/public/js/filtro-tabela.js`

**Produces:** comportamento sobre qualquer `[data-filtro-root][data-filtro-endpoint]` contendo `[data-filtro-form]` e `[data-filtro-resultado]`; classes que a barra/fragmento devem usar: `.js-filtro-busca`, `.js-filtro-campo`, `.js-filtro-ordenar`, `.js-filtro-chip-remover[data-campo]`, `.js-filtro-limpar`, `.js-filtro-pagina[data-page]`, `.js-filtro-calendario`, `th[data-ordenar]`, container `[data-filtro-chips]`, hidden `ordenar`/`direcao`. Loading via classe `.filtro-carregando`.

- [ ] **Step 1:** Escrever o arquivo com o conteúdo abaixo (IIFE, delegação no root, debounce 350ms, chips, swap com reexecução de `<script>`, blindagem `try/catch`).

```javascript
(function () {
  'use strict';
  var DEBOUNCE_MS = 350;

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function formatarData(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
    return m ? m[3] + '/' + m[2] + '/' + m[1] : v;
  }
  function injetarHtml(alvo, html) {
    alvo.innerHTML = html;
    alvo.querySelectorAll('script').forEach(function (velho) {
      var novo = document.createElement('script');
      if (velho.src) { novo.src = velho.src; } else { novo.textContent = velho.textContent; }
      velho.parentNode.replaceChild(novo, velho);
    });
  }
  function iniciar(root) {
    var endpoint = root.getAttribute('data-filtro-endpoint');
    var form = root.querySelector('[data-filtro-form]');
    var resultado = root.querySelector('[data-filtro-resultado]');
    if (!endpoint || !form || !resultado) { return; }
    var timer = null;

    function setHidden(name, value) {
      var el = form.querySelector('[name="' + name + '"]');
      if (el) { el.value = value; }
    }
    function construirChips() {
      var box = form.querySelector('[data-filtro-chips]');
      if (!box) { return; }
      var chips = [];
      var busca = form.querySelector('.js-filtro-busca');
      if (busca && busca.value.trim() !== '') {
        chips.push({ campo: busca.name, rotulo: busca.dataset.rotulo || 'Busca', valor: busca.value.trim() });
      }
      form.querySelectorAll('.js-filtro-campo').forEach(function (el) {
        if (el.value === '') { return; }
        var valor;
        if (el.tagName === 'SELECT') {
          var opt = el.options[el.selectedIndex];
          valor = opt ? opt.textContent.trim() : el.value;
        } else if (el.type === 'date') {
          valor = formatarData(el.value);
        } else {
          valor = el.value;
        }
        chips.push({ campo: el.name, rotulo: el.dataset.rotulo || '', valor: valor });
      });
      if (chips.length === 0) { box.innerHTML = ''; return; }
      var html = chips.map(function (c) {
        return '<span class="filtro-chip">'
          + (c.rotulo ? '<span class="filtro-chip-rotulo">' + escHtml(c.rotulo) + ':</span> ' : '')
          + '<span class="filtro-chip-valor">' + escHtml(c.valor) + '</span>'
          + '<button type="button" class="filtro-chip-remover js-filtro-chip-remover" data-campo="'
          + escHtml(c.campo) + '" aria-label="Remover"><i class="bi bi-x"></i></button></span>';
      }).join('');
      html += '<button type="button" class="filtro-limpar js-filtro-limpar">Limpar tudo</button>';
      box.innerHTML = html;
    }
    function recarregar(extra) {
      var params = new URLSearchParams(new FormData(form));
      if (extra) { Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); }); }
      resultado.classList.add('filtro-carregando');
      fetch(endpoint + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.text(); })
        .then(function (html) { injetarHtml(resultado, html); resultado.classList.remove('filtro-carregando'); })
        .catch(function () { resultado.classList.remove('filtro-carregando'); });
    }
    function aplicar(extra) { construirChips(); recarregar(extra || { page: 1 }); }

    root.addEventListener('input', function (e) {
      if (!e.target.classList.contains('js-filtro-busca')) { return; }
      construirChips();
      clearTimeout(timer);
      timer = setTimeout(function () { recarregar({ page: 1 }); }, DEBOUNCE_MS);
    });
    root.addEventListener('change', function (e) {
      if (e.target.classList.contains('js-filtro-ordenar')) {
        var p = (e.target.value || '').split('|');
        if (!p[0]) { return; }
        setHidden('ordenar', p[0]); setHidden('direcao', p[1] || 'asc');
        recarregar({ page: 1 }); return;
      }
      if (e.target.classList.contains('js-filtro-campo')) { aplicar({ page: 1 }); }
    });
    root.addEventListener('click', function (e) {
      var th = e.target.closest('th[data-ordenar]');
      if (th && resultado.contains(th)) {
        var atual = form.querySelector('[name="ordenar"]');
        var dir = form.querySelector('[name="direcao"]');
        var col = th.getAttribute('data-ordenar');
        var mesma = atual && atual.value === col;
        setHidden('ordenar', col);
        setHidden('direcao', (mesma && dir && dir.value === 'asc') ? 'desc' : 'asc');
        recarregar({ page: 1 }); return;
      }
      var cal = e.target.closest('.js-filtro-calendario');
      if (cal) {
        var d = cal.parentNode.querySelector('input[type="date"]');
        if (d && d.showPicker) { d.showPicker(); } else if (d) { d.focus(); }
        return;
      }
      var rem = e.target.closest('.js-filtro-chip-remover');
      if (rem) {
        var alvo = form.querySelector('[name="' + rem.getAttribute('data-campo') + '"]');
        if (alvo) { alvo.value = ''; }
        aplicar({ page: 1 }); return;
      }
      if (e.target.closest('.js-filtro-limpar')) {
        form.querySelectorAll('.js-filtro-busca, .js-filtro-campo').forEach(function (el) { el.value = ''; });
        aplicar({ page: 1 }); return;
      }
      var pag = e.target.closest('.js-filtro-pagina[data-page]');
      if (pag) { recarregar({ page: pag.getAttribute('data-page') }); }
    });

    construirChips();
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-filtro-root]').forEach(iniciar);
  });
})();
```

- [ ] **Step 2:** Verificar sintaxe: `docker exec jusprime_php_dev bash -c 'node --check /var/www/app/public/js/filtro-tabela.js'` (se node existir) ou revisão manual. Esperado: sem erro.

### Task 0.2: CSS

**Files:** Create `app/public/css/filtro-tabela.css`

- [ ] **Step 1:** Portar o visual da barra do Expediente (`app/templates/pasta/_filtros.html.twig`, linhas ~73-238) para classes genéricas: `.filtro-barra`, `.filtro-busca-linha`, `.filtro-busca` (pill com lupa), `.filtro-linha` (flex-wrap gap 8px), `.filtro-select` (min-width 128px, border-radius 20px), `.filtro-data-campo`/`.filtro-data-btn`, `.filtro-periodo`, `.filtro-chips` (flex-wrap gap 6px), `.filtro-chip`/`.filtro-chip-rotulo`/`.filtro-chip-valor`/`.filtro-chip-remover`, `.filtro-limpar`, `.filtro-carregando` (opacity .5 + pointer-events none), `.filtro-paginacao`. Cores via variáveis Bootstrap (`var(--bs-*)`) para compatibilidade com tema claro/escuro. Responsivo: `@media (max-width:575.98px)` facetas com `flex:1 1 calc(50% - 4px)`.

### Task 0.3: Barra Twig

**Files:** Create `app/templates/_partials/_filtro_barra.html.twig`

**Interfaces — Consumes (include params):** `facetas` = lista de `{name, rotulo, tipo:'select'|'date', opcoes:[{valor,label}]}`; `filtros` = mapa dos valores atuais; `busca_placeholder`; opcional `busca_name` (default `'busca'`).

- [ ] **Step 1:** Escrever a partial:

```twig
{% set busca_campo = busca_name|default('busca') %}
<form data-filtro-form method="get" class="filtro-barra">
    <div class="filtro-busca-linha">
        <div class="filtro-busca">
            <i class="bi bi-search"></i>
            <input type="text" name="{{ busca_campo }}" class="form-control form-control-sm js-filtro-busca"
                   data-rotulo="Busca" placeholder="{{ busca_placeholder|default('Buscar…') }}"
                   autocomplete="off" value="{{ filtros[busca_campo]|default('') }}">
        </div>
    </div>
    <div class="filtro-linha">
        {% for f in facetas %}
            {% if f.tipo == 'select' %}
                <select name="{{ f.name }}" class="form-select form-select-sm filtro-select js-filtro-campo" data-rotulo="{{ f.rotulo }}">
                    <option value="">{{ f.rotulo }}</option>
                    {% for o in f.opcoes %}
                        <option value="{{ o.valor }}" {{ (filtros[f.name]|default('')) == (o.valor ~ '') ? 'selected' : '' }}>{{ o.label }}</option>
                    {% endfor %}
                </select>
            {% elseif f.tipo == 'date' %}
                <span class="filtro-data-campo">
                    <input type="date" name="{{ f.name }}" class="form-control form-control-sm js-filtro-campo"
                           data-rotulo="{{ f.rotulo }}" value="{{ filtros[f.name]|default('') }}">
                    <button type="button" class="filtro-data-btn js-filtro-calendario"><i class="bi bi-calendar3"></i></button>
                </span>
            {% endif %}
        {% endfor %}
    </div>
    <input type="hidden" name="ordenar" value="{{ filtros.ordenar|default('') }}">
    <input type="hidden" name="direcao" value="{{ filtros.direcao|default('') }}">
    <div class="filtro-chips" data-filtro-chips></div>
</form>
```

### Task 0.4: Paginação Twig

**Files:** Create `app/templates/_partials/_filtro_paginacao.html.twig`

**Consumes:** `pagina` (int, 1-based), `total_paginas` (int).

- [ ] **Step 1:** Escrever:

```twig
{% if total_paginas > 1 %}
<nav class="filtro-paginacao" aria-label="Paginação">
    <button type="button" class="btn btn-sm btn-outline-secondary js-filtro-pagina" data-page="{{ pagina - 1 }}" {{ pagina <= 1 ? 'disabled' : '' }}>
        <i class="bi bi-chevron-left"></i>
    </button>
    <span class="filtro-paginacao-info">{{ pagina }} / {{ total_paginas }}</span>
    <button type="button" class="btn btn-sm btn-outline-secondary js-filtro-pagina" data-page="{{ pagina + 1 }}" {{ pagina >= total_paginas ? 'disabled' : '' }}>
        <i class="bi bi-chevron-right"></i>
    </button>
</nav>
{% endif %}
```

**Commit da Fase 0** (handoff):
```bash
# Execute manualmente no terminal externo
git add app/public/js/filtro-tabela.js app/public/css/filtro-tabela.css \
        app/templates/_partials/_filtro_barra.html.twig app/templates/_partials/_filtro_paginacao.html.twig
git commit -m "Adicionar componente reutilizavel de filtro de tabela"
```

---

## Fase 1 — Demandas

### Task 1.1: Repositório `findMinhasDemandasPaginado` + `countMinhasDemandas`

**Files:** Modify `app/src/Pasta/Repository/PastaRepository.php`; Test `app/tests/Pasta/Unit/PastaRepositoryDemandasTest.php` (ou Integration conforme padrão do domínio para repo).

**Produces:**
- `findMinhasDemandasPaginado(User $responsavel, Tenant $tenant, array $filtros, int $pagina, int $porPagina, string $ordenar, string $direcao): array` — `Pasta[]`.
- `countMinhasDemandas(User $responsavel, Tenant $tenant, array $filtros): int`.
- Chaves de `$filtros`: `busca`, `status` (`''|'ativo'|'arquivado'`), `prioridade`, `data_de`, `data_ate`.

- [ ] **Step 1:** Teste que falha — criar duas pastas do mesmo responsável/tenant (Foundry), uma `ativo` prioridade `urgente`, outra `arquivado`; asserir que filtro `status=ativo` retorna 1, `busca` por nome do cliente retorna a certa, e `countMinhasDemandas` bate. Reaproveitar o padrão de teste de repositório já usado no Expediente (ver testes de `PastaRepository`/`findByFilters`).
- [ ] **Step 2:** Rodar e ver falhar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter PastaRepositoryDemandasTest'`. Esperado: erro "método não existe".
- [ ] **Step 3:** Implementar os dois métodos reutilizando `aplicarFiltrosPasta()`/`aplicarOrdenacao()` existentes (estender o array de filtros com `responsavel` fixo). Escopar por `p.responsavel = :responsavel` e `p.tenant = :tenant` explícito; `setFirstResult((pagina-1)*porPagina)`, `setMaxResults($porPagina)`; contagem via `COUNT(DISTINCT p.id)`.
- [ ] **Step 4:** Rodar e ver passar (mesmo comando). Esperado: PASS.

### Task 1.2: Controller `DemandasController` — ramo XHR + filtros

**Files:** Modify `app/src/Pasta/Controller/DemandasController.php`; Test `app/tests/Pasta/Functional/DemandasControllerTest.php`.

**Consumes:** métodos da Task 1.1.

- [ ] **Step 1:** Teste functional que falha: (a) GET `/demandas` (autenticado) 200 e contém a barra (`data-filtro-root`); (b) GET `/demandas?busca=...` com header `X-Requested-With: XMLHttpRequest` retorna 200 e o fragmento (contém `data-filtro-resultado`? não — o fragmento é o innerHTML; asserir presença de marcador do `_resultado`, ex.: uma classe da tabela) e NÃO contém o `<html>`/layout; (c) faceta `status=arquivado` filtra.
- [ ] **Step 2:** Rodar e ver falhar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter DemandasControllerTest'`.
- [ ] **Step 3:** Implementar: extrair filtros/ordenação/página da request; chamar Task 1.1; calcular `total_paginas`; se `isXmlHttpRequest()` → `render('pasta/demandas/_resultado.html.twig', ...)`; senão → `render('pasta/demandas.html.twig', ...)` com os mesmos dados + `facetas`/`filtros` para a barra.
- [ ] **Step 4:** Rodar e ver passar.

### Task 1.3: Templates Demandas (casca + `_resultado`)

**Files:** Modify `app/templates/pasta/demandas.html.twig`; Create `app/templates/pasta/demandas/_resultado.html.twig`.

- [ ] **Step 1:** Mover a tabela+cards atuais de `demandas.html.twig` para `demandas/_resultado.html.twig`, adicionando ao final `{{ include('_partials/_filtro_paginacao.html.twig', {pagina: pagina, total_paginas: total_paginas}) }}`. Nenhum `id=` duplicado; usar classes/`data-*`.
- [ ] **Step 2:** Em `demandas.html.twig`: envolver com `<div data-filtro-root data-filtro-endpoint="{{ path('demandas_index') }}">`, incluir a barra com `facetas` = `[{name:'status',rotulo:'Status',tipo:'select',opcoes:[{valor:'ativo',label:'Ativo'},{valor:'arquivado',label:'Arquivado'}]},{name:'prioridade',rotulo:'Prioridade',tipo:'select',opcoes:[...]},{name:'data_de',rotulo:'De',tipo:'date'},{name:'data_ate',rotulo:'Até',tipo:'date'}]`, e `<div data-filtro-resultado>{{ include('pasta/demandas/_resultado.html.twig') }}</div>`. Remover o form de filtro antigo (cliente/prioridade + botão Filtrar). Carregar `filtro-tabela.js`/`.css` via `{% block javascripts %}`/estilos.
- [ ] **Step 3:** `docker exec jusprime_php_dev bash -c 'cd app && php bin/console cache:clear --env=dev'` e rodar a suíte de Demandas de novo (garantir templates válidos).
- [ ] **Step 4:** Smoke manual `/demandas`: digitar na busca atualiza sem reload; Status/Prioridade/Período aplicam na hora; chips removem; "Limpar tudo" zera; paginação navega; foco da busca não se perde.

**Commit da Fase 1** (handoff):
```bash
# Execute manualmente no terminal externo
git add app/src/Pasta/Repository/PastaRepository.php app/src/Pasta/Controller/DemandasController.php \
        app/templates/pasta/demandas.html.twig app/templates/pasta/demandas/_resultado.html.twig \
        app/tests/Pasta/
git commit -m "Aplicar filtro reutilizavel em tempo real nas Demandas"
```

---

## Fase 2 — Processo

### Task 2.1: Repositório `findByFiltrosPaginado` + `countByFiltros` + hardening tenant

**Files:** Modify `app/src/Processo/Repository/ProcessoRepository.php`; Test `app/tests/Processo/Unit/ProcessoRepositoryFiltrosTest.php` (inclui caso cross-tenant).

**Produces:**
- `findByFiltrosPaginado(Tenant $tenant, array $filtros, int $pagina, int $porPagina, string $ordenar, string $direcao): array`.
- `countByFiltros(Tenant $tenant, array $filtros): int`.
- Chaves: `busca` (LIKE em numeroProcesso/classeProcessual/assuntoProcessual), `tribunal`, `situacao`, `data_de`, `data_ate` (se houver campo de data — confirmar em `Processo`).

- [ ] **Step 1:** Teste cross-tenant que falha: criar tenant A e B (Foundry), 2 processos em A e 1 em B; com o `TenantFilter` DESLIGADO (contexto de teste), `findByFiltrosPaginado(tenantA, [], 1, 50, '', '')` retorna só os 2 de A; busca por número/classe filtra; `countByFiltros` bate.
- [ ] **Step 2:** Rodar e ver falhar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ProcessoRepositoryFiltrosTest'`.
- [ ] **Step 3:** Implementar com `->andWhere('p.tenant = :tenant')` explícito; busca livre via `orX` LIKE nos 3 campos; facetas tribunal/situacao; ordenação (default `p.id DESC`); paginação. Ajustar `findAllTribunais()` para receber `Tenant` e escopar explicitamente (as opções do facet). Confirmar campo de data de `Processo`; se não houver, omitir a faceta Período.
- [ ] **Step 4:** Rodar e ver passar.
- [ ] **Step 5:** `grep` por usos de `findByFilters`/`findAllClasses`/`findAllAssuntos`/`findAllNumerosProcesso` — se órfãos após a mudança, remover; senão manter. Registrar no commit.

### Task 2.2: Controller `ProcessoController` — ramo XHR + filtros

**Files:** Modify `app/src/Processo/Controller/ProcessoController.php`; Test `app/tests/Processo/Functional/ProcessoControllerTest.php`.

- [ ] **Step 1:** Teste functional: GET `/processos` 200 com barra; XHR com `busca`/`tribunal`/`situacao` retorna fragmento filtrado sem layout.
- [ ] **Step 2:** Rodar e ver falhar.
- [ ] **Step 3:** Implementar extração de filtros + `isXmlHttpRequest()` → `processo/_resultado.html.twig`; passar `$user->getTenant()` explícito ao repositório; montar `facetas`/`filtros` + opções de tribunal.
- [ ] **Step 4:** Rodar e ver passar.

### Task 2.3: Templates Processo (casca + `_resultado`)

**Files:** Modify `app/templates/processo/index.html.twig`; Create `app/templates/processo/_resultado.html.twig`.

- [ ] **Step 1:** Mover a tabela de processos para `_resultado.html.twig` + paginação genérica.
- [ ] **Step 2:** `index.html.twig` vira casca (root + barra com facetas Tribunal/Situação/[Período]); remover os 5 selects + botões Filtrar/Limpar antigos; carregar js/css.
- [ ] **Step 3:** `cache:clear` + rodar suíte de Processo.
- [ ] **Step 4:** Smoke `/processos`.

**Commit da Fase 2** (handoff):
```bash
# Execute manualmente no terminal externo
git add app/src/Processo/ app/templates/processo/ app/tests/Processo/
git commit -m "Aplicar filtro reutilizavel e reforcar tenant nos Processos"
```

---

## Fase 3 — Minhas Metas

### Task 3.1: Repositório `findByResponsavelComFiltros`

**Files:** Modify `app/src/Tarefa/Repository/TarefaRepository.php`; Test `app/tests/Tarefa/Unit/TarefaRepositoryFiltrosTest.php`.

**Produces:** `findByResponsavelComFiltros(User $usuario, array $filtros): array`. Chaves: `busca` (titulo/descricao), `status`, `prioridade` (da pasta: `join t.pasta`, `p.prioridade`), `prazo` (`''|'vencidas'|'proximas'|'sem'`).

- [ ] **Step 1:** Teste que falha: tarefas do usuário com status/prazos variados; filtro `status=concluida` e `prazo=vencidas` retornam o subconjunto certo; `busca` no título filtra; mantém `dataCriacao DESC`.
- [ ] **Step 2:** Rodar e ver falhar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter TarefaRepositoryFiltrosTest'`.
- [ ] **Step 3:** Implementar sobre o `where(':usuario MEMBER OF t.responsaveis OR t.criadoPor = :usuario')` existente, somando os `andWhere` dos filtros; `prazo`: vencidas = `t.prazo < :agora AND t.status != concluida`, proximas = `t.prazo BETWEEN :agora AND :agora+7d`, sem = `t.prazo IS NULL`.
- [ ] **Step 4:** Rodar e ver passar.

### Task 3.2: Controller `TarefaController::minhas` — ramo XHR (aditivo, legado)

**Files:** Modify `app/src/Controller/TarefaController.php`; Test `app/tests/Tarefa/Functional/TarefaMinhasControllerTest.php` (criar se não existir — cobre o comportamento atual antes de mexer no legado).

- [ ] **Step 1:** Teste do comportamento atual + novo: GET `/tarefas/minhas` 200 com barra e os dois grupos; XHR com `status`/`prazo` devolve o fragmento filtrado.
- [ ] **Step 2:** Rodar e ver falhar.
- [ ] **Step 3:** Implementar: extrair filtros; usar `findByResponsavelComFiltros`; `isXmlHttpRequest()` → `tarefa/_resultado.html.twig`; sem XHR → `tarefa/minhas.html.twig`. Aditivo — não mover o controller.
- [ ] **Step 4:** Rodar e ver passar.

### Task 3.3: Templates Minhas Metas (casca + `_resultado`, mantém cartões/agrupamento)

**Files:** Modify `app/templates/tarefa/minhas.html.twig`; Create `app/templates/tarefa/_resultado.html.twig`.

- [ ] **Step 1:** Mover os dois grupos de cartões (Em aberto / Concluídas) para `_resultado.html.twig`. Sem paginação.
- [ ] **Step 2:** `minhas.html.twig` vira casca (root + barra: facetas Status/Prioridade/Prazo). Endpoint = `path('tarefa_minhas')`. Carregar js/css.
- [ ] **Step 3:** `cache:clear` + rodar suíte de Tarefa.
- [ ] **Step 4:** Smoke `/tarefas/minhas`.

**Commit da Fase 3** (handoff):
```bash
# Execute manualmente no terminal externo
git add app/src/Tarefa/ app/src/Controller/TarefaController.php app/templates/tarefa/ app/tests/Tarefa/
git commit -m "Aplicar filtro reutilizavel nas Minhas Metas"
```

---

## Fase 4 — Dashboard (agregações)

### Task 4.1: Repositórios de contagem aceitam `$filtros`

**Files:** Modify `app/src/Tarefa/Repository/TarefaRepository.php`, `app/src/Pasta/Repository/PastaRepository.php`; Test estender os testes de repositório existentes (ou `DashboardCountFiltrosTest`).

**Produces:** parâmetro `array $filtros = []` (chaves `data_de`, `data_ate`, `responsavel`) nos métodos usados pelo Dashboard: `countMetasAtivas`, `countMetasGlobal`, `countPorResponsavel`, `countAtivasPorResponsavel` (Tarefa) e `countUrgentes`, `countPorResponsavel`, `countAtivasPorResponsavel` (Pasta). `countVencidas/PrazosProximos` mantêm semântica relativa a `now` (Período NÃO aplica — documentado).

- [ ] **Step 1:** Teste que falha: com Período restringindo, `countMetasGlobal` conta só as criadas no intervalo; com `responsavel`, restringe ao colaborador; sem filtros, resultado idêntico ao atual (não-regressão).
- [ ] **Step 2:** Rodar e ver falhar.
- [ ] **Step 3:** Implementar helper privado `aplicarFiltrosDashboard(QueryBuilder $qb, array $filtros)` (data em `t.dataCriacao`/`p.dataAbertura`, `responsavel` no join). Manter tenant explícito já presente.
- [ ] **Step 4:** Rodar e ver passar.

### Task 4.2: UseCase `ObterDadosDashboardUseCase::executar($tenant, $filtros)`

**Files:** Modify `app/src/Dashboard/UseCase/ObterDadosDashboardUseCase.php`; Test `app/tests/Dashboard/Unit/ObterDadosDashboardUseCaseTest.php`.

- [ ] **Step 1:** Teste unit que falha: mock dos repositórios; verifica que `$filtros` é repassado às contagens e que `cargo` filtra a lista de colaboradores; sem filtros = comportamento atual.
- [ ] **Step 2:** Rodar e ver falhar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ObterDadosDashboardUseCaseTest'`.
- [ ] **Step 3:** Implementar: assinatura `executar(Tenant $tenant, array $filtros = [])`; repassar Período/Responsável às contagens; filtrar colaboradores por `cargo`.
- [ ] **Step 4:** Rodar e ver passar.

### Task 4.3: Controller `DashboardController` — ramo XHR + filtros

**Files:** Modify `app/src/Dashboard/Controller/DashboardController.php`; Test `app/tests/Dashboard/Functional/DashboardControllerTest.php`.

- [ ] **Step 1:** Teste functional: GET `/dashboard` 200 com barra; XHR com `data_de`/`responsavel`/`cargo` devolve o fragmento (cards + tabela) recalculado, sem layout.
- [ ] **Step 2:** Rodar e ver falhar.
- [ ] **Step 3:** Implementar extração de filtros; `isXmlHttpRequest()` → `dashboard/_resultado.html.twig`; montar facetas (Período, Responsável=colaboradores do tenant, Cargo).
- [ ] **Step 4:** Rodar e ver passar.

### Task 4.4: Templates Dashboard (casca + `_resultado`)

**Files:** Modify `app/templates/dashboard/index.html.twig`; Create `app/templates/dashboard/_resultado.html.twig`.

- [ ] **Step 1:** Mover cards de KPI + tabela por advogado para `_resultado.html.twig`.
- [ ] **Step 2:** `index.html.twig` vira casca (root + barra: Período/Responsável/Cargo). Remover o dropdown de Cargo client-side antigo. Endpoint `path('dashboard_index')`. Carregar js/css.
- [ ] **Step 3:** `cache:clear` + rodar suíte do Dashboard.
- [ ] **Step 4:** Smoke `/dashboard`: Período/Responsável/Cargo recalculam cards + tabela na hora.

**Commit da Fase 4** (handoff):
```bash
# Execute manualmente no terminal externo
git add app/src/Dashboard/ app/src/Tarefa/Repository/TarefaRepository.php \
        app/src/Pasta/Repository/PastaRepository.php app/templates/dashboard/ app/tests/Dashboard/
git commit -m "Aplicar filtro global reutilizavel no Dashboard"
```

---

## Fechamento

- [ ] Rodar a suíte completa: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'`. Esperado: verde.
- [ ] Disparar `/review` (feature-review-agent) contra a spec, fase a fase.
- [ ] Smoke final nas 4 telas.

## Self-Review (feito ao escrever o plano)

- **Cobertura da spec:** Fase 0 (componente) ✓ · Demandas ✓ · Processo + hardening/cross-tenant ✓ · Minhas Metas (cartões, sem paginação) ✓ · Dashboard (global, datas relativas documentadas) ✓ · testes por página ✓.
- **Placeholders:** o único "confirmar" é o campo de data do `Processo` (faceta Período condicional) — decisão de dados legítima resolvida na Task 2.1, não um TODO de código.
- **Consistência de tipos:** assinaturas de repositório (`findMinhasDemandasPaginado`, `findByFiltrosPaginado`, `findByResponsavelComFiltros`, `executar($tenant,$filtros)`) e classes JS (`js-filtro-*`, `data-filtro-*`) usadas de forma idêntica entre plano/motor/partials.
