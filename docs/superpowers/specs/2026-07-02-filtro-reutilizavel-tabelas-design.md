# Design — Filtro reutilizável (padrão Expediente) em Dashboard, Demandas, Processo e Minhas Metas

**Data:** 2026-07-02 · **Branch:** `visual-lista-expediente` (ou nova branch dedicada) · **Risco:** BAIXO
(frontend + queries de listagem; não toca em permissão, identidade User/Tenant, Role/Profile).
Ponto de atenção único: **hardening** de tenant no `ProcessoRepository` (defesa em profundidade — não é correção de vazamento vivo; ver §"Nota sobre multi-tenancy").

## Objetivo

Levar o filtro moderno do Expediente — **busca livre + facetas + chips + auto-apply em
tempo real** (sem botão "Filtrar", sem recarregar a página) — para as tabelas de
**Demandas**, **Processo**, **Minhas Metas** e para o painel do **Dashboard**, no mesmo
padrão visual e de comportamento.

Em vez de copiar o padrão 4×, extrai-se **um componente de filtro reutilizável** (um motor
JS genérico + uma barra Twig parametrizável), e cada página apenas o pluga com sua
configuração de facetas e seu endpoint de fragmento. O **Expediente não é alterado** nesta
entrega (já está polido; migração para o componente fica como follow-up opcional).

## Decisões travadas no brainstorming

1. **Componente reutilizável**, não cópia por página. Um JS + uma barra genérica.
2. **Dashboard = filtro global do painel:** a barra filtra os próprios números/KPIs por
   Período/Responsável/Cargo, recalculando cards + tabela por advogado (refactor mais pesado).
3. **Minhas Metas:** mantém o layout de **cartões agrupados por status**; a barra entra
   **por cima**, sem virar tabela.
4. **Processo:** ao reescrever o repositório para filtros/paginação, passa `Tenant`
   explícito + `andWhere('p.tenant = :tenant')` (hardening) e cobre com teste cross-tenant.
5. **Entrega:** spec cobre tudo; implementação emenda as Fases 0→4 de uma vez, com
   `/review` ao fim de cada fase.
6. **Expediente intocado** nesta entrega.

## Nota sobre multi-tenancy (correção de premissa)

A investigação inicial marcou o `ProcessoRepository::findByFilters()` como "furo crítico de
tenant". **Isso é falso positivo no fluxo web.** Existe um `TenantFilter` (Doctrine SQLFilter,
`App\Shared\Doctrine\Filter\TenantFilter`) ligado a cada request pelo `TenantFilterListener`,
que injeta `tenant_id = :tenant` em toda query DQL de entidade `TenantAware`. `Processo`,
`Tarefa` e `Pasta` **implementam `TenantAware`**, então suas queries via `createQueryBuilder`
já são escopadas automaticamente. O filtro só fica inerte em CLI/teste (ligado manualmente).

Portanto **não há vazamento vivo para corrigir**. O que se faz é **defesa em profundidade**:
os novos `findByFilters/countByFilters` recebem `Tenant` e adicionam `andWhere` explícito —
seguindo o padrão que o próprio domínio já usa (`findOneByIdDoTenant`, `buscarPorTermoDoTenant`).
Isso protege se o método vier a ser chamado em contexto sem o filtro (CLI, super-admin) e é
verificado por um teste cross-tenant.

## Abordagem — o componente reutilizável (Fase 0)

Sem pipeline de assets/Stimulus no projeto (sem importmap, sem `assets/`; JS inline nos
templates, `public/js` e `public/css` servidos, `base.html.twig` com `{% block javascripts %}`
e bloco de estilos). O componente é, então, **um arquivo JS estático + um CSS estático +
partials Twig genéricos**.

### Contrato de DOM

```
<div data-filtro-root data-filtro-endpoint="{{ path('<rota_fragmento>') }}">
    {# barra persistente (não é trocada pelo AJAX) #}
    {{ include('_partials/_filtro_barra.html.twig', { facetas: [...], filtros: filtros, busca_placeholder: '…' }) }}

    {# container trocável — o motor substitui só o innerHTML disto #}
    <div data-filtro-resultado>
        {{ include('<pagina>/_resultado.html.twig') }}
    </div>
</div>
```

- **Barra (`_partials/_filtro_barra.html.twig`)** — parâmetros:
  - `facetas`: lista de `{ name, rotulo, tipo: 'select'|'date', opcoes?: [{valor,label}], valor? }`.
    Período = duas facetas `date` (`data_de`/`data_ate`) agrupadas com separador "–".
  - `filtros`: valores atuais (para preservar seleção após reload).
  - `busca_placeholder`: texto do campo de busca.
  - Emite: `form[data-filtro-form]` (method GET), campo de busca `.js-filtro-busca`,
    selects/datas `.js-filtro-campo`, hidden `ordenar`/`direcao`, container de chips
    `[data-filtro-chips]`. Reproduz o visual pill do Expediente (`_filtros.html.twig`).
- **Paginação (`_partials/_filtro_paginacao.html.twig`)** — botões `.js-filtro-pagina[data-page]`;
  fica **dentro** de `[data-filtro-resultado]`, então re-renderiza a cada swap. Só aparece se a
  página usar paginação.

### Motor JS (`public/js/filtro-tabela.js`)

- **Auto-inicializa** em `DOMContentLoaded` para cada `[data-filtro-root]`.
- **Delegação de eventos no root persistente** (nunca re-liga handlers no fragmento — mesma
  armadilha já documentada do Expediente: o fragmento re-executa a cada swap).
- Eventos:
  - `input` em `.js-filtro-busca` → reconstrói chips + **debounce 350ms** → recarrega (page=1).
  - `change` em `.js-filtro-campo` (selects e datas) → reconstrói chips + recarrega **na hora** (page=1).
  - `change` em `.js-filtro-ordenar` (dropdown opcional) → seta hidden `ordenar`/`direcao` → recarrega.
  - `click` em `th[data-ordenar]` (opcional, quando houver tabela com cabeçalho) → alterna asc/desc → recarrega.
  - `click` em `.js-filtro-chip-remover[data-campo]` → limpa o campo → reconstrói + recarrega.
  - `click` em `.js-filtro-limpar` → limpa busca + facetas → reconstrói + recarrega.
  - `click` em `.js-filtro-pagina[data-page]` → recarrega naquela página.
  - `click` em `.js-filtro-calendario` → abre o date picker (`showPicker()` com fallback `focus`).
- **`recarregar(extra)`** — monta query do form + `extra`, `fetch(endpoint + '?' + qs, {headers:{'X-Requested-With':'XMLHttpRequest'}})`,
  aplica classe de loading no resultado, e no retorno faz `resultado.innerHTML = respostaHtml`
  reexecutando `<script>` embutidos (helper `injetarHtmlComScripts` local). **Contrato:** o
  endpoint XHR devolve **exatamente o innerHTML do `_resultado`** (o partial de resultado), não a
  página inteira — mais enxuto que o Expediente (que extrai por id).
- **`construirChips()`** — lê `.js-filtro-busca` + `.js-filtro-campo` (select mostra o texto da
  opção; data formatada `DD/MM/YYYY`) e injeta chips em `[data-filtro-chips]` + botão "Limpar tudo".
  Escapa HTML.
- **Blindagem:** se faltar root/form/resultado, no-op silencioso; `try/catch` no fetch com
  remoção da classe de loading (não deixa o resultado preso em "carregando").

### CSS (`public/css/filtro-tabela.css`)

Classes genéricas espelhando o visual do Expediente: `.filtro-barra`, `.filtro-busca`,
`.filtro-linha`, `.filtro-select` (pill, border-radius 20px), `.filtro-periodo`,
`.filtro-data-campo`, `.filtro-chips`, `.filtro-chip`, `.filtro-chip-remover`, `.filtro-limpar`,
`.filtro-carregando`. Cores via variáveis Bootstrap (compatível com tema claro/escuro).
Responsivo: facetas quebram para 50% da linha em telas pequenas.

### Alternativas descartadas

- **(B) Copiar o `_filtros`/JS do Expediente por página** — 4 duplicatas de ~250 linhas de
  JS/CSS que divergem com o tempo; contraria "isolamento e clareza". Rejeitado.
- **(C) Migrar o Expediente para o componente agora** — mexe numa tela recém-polida (menu de
  responsável, marcadores, toggle, sessionStorage); risco fora do pedido. Fica como follow-up.

## Contratos por página

Todas: no XHR (`isXmlHttpRequest()`) o controller renderiza **só** o `_resultado`; sem XHR
renderiza a página cheia (root + barra + resultado). Query params comuns: `busca`, facetas,
`ordenar`, `direcao`, `page`.

### Fase 1 — Demandas (`Pasta`, "minhas")

- **Controller:** `App\Pasta\Controller\DemandasController::index` — ramo XHR devolve
  `pasta/demandas/_resultado.html.twig`; extrai filtros (busca, status, prioridade, data_de,
  data_ate, ordenar, direcao, page).
- **Repositório:** substitui `findAtivasPorResponsavel(...)` por
  `findMinhasDemandasPaginado(User $responsavel, Tenant $tenant, array $filtros, int $page, int $perPage, string $ordenar, string $direcao)`
  + `countMinhasDemandas(...)`. Mantém o escopo por `responsavel`. Busca livre em NUP / nome do
  cliente / ação (espelhando o Expediente). Status: ativo/arquivado (hoje só mostra ativas).
  Prioridade: enum. Período: `p.dataAbertura`. Paginação server-side.
- **Facetas:** Status · Prioridade · Período. **Sem** facetas de Responsável (é "minhas").
- **Template:** `pasta/demandas.html.twig` vira a casca (root + barra); a tabela+cards atuais
  migram para `pasta/demandas/_resultado.html.twig` (com a paginação genérica). Mantém o layout
  híbrido tabela(desktop)+cards(mobile) que já existe.

### Fase 2 — Processo (`Processo`)

- **Controller:** `App\Processo\Controller\ProcessoController::index` — ramo XHR devolve
  `processo/_resultado.html.twig`; extrai filtros (busca, tribunal, situacao, [período se houver
  campo de data adequado — confirmar `dataDistribuicao`/afins na implementação], ordenar,
  direcao, page).
- **Repositório:** reescreve `findByFilters(array $filters)` como
  `findByFiltrosPaginado(Tenant $tenant, array $filtros, int $page, int $perPage, string $ordenar, string $direcao)`
  + `countByFiltros(Tenant $tenant, array $filtros)`. **Passa `Tenant` explícito** e
  `andWhere('p.tenant = :tenant')` (hardening). Busca livre dobra número/classe/assunto num
  único campo (LIKE em `numeroProcesso`/`classeProcessual`/`assuntoProcessual`). Facetas:
  Tribunal (via `findAllTribunais`, que também deve escopar tenant explícito) e Situação (enum).
  Paginação server-side.
- **Facetas:** Tribunal · Situação · (Período opcional).
- **Nota:** os selects atuais de número/classe/assunto viram a busca livre;
  `findAllClasses/findAllAssuntos/findAllNumerosProcesso` podem ficar órfãos → remover se não
  usados em outro lugar (grep antes) ou deixar documentado. `findAllTribunais` continua para as
  opções de Tribunal, com filtro de tenant explícito.
- **Teste cross-tenant obrigatório** cobrindo o `findByFiltrosPaginado` (dois tenants; um não
  enxerga processos do outro), com o `TenantFilter` desligado (contexto de teste) para provar o
  `andWhere` explícito.

### Fase 3 — Minhas Metas (`Tarefa`)

- **Controller:** `App\Controller\TarefaController::minhas` (legado — ver §"Legado") — ramo XHR
  devolve `tarefa/_resultado.html.twig` (os dois grupos de cartões). Extrai filtros (busca,
  status, prioridade, prazo).
- **Repositório:** ao lado de `findByResponsavel(User)` cria
  `findByResponsavelComFiltros(User $usuario, array $filtros)`. Filtros: busca (título/descrição),
  status (enum), prioridade da pasta vinculada (`join t.pasta`, `p.prioridade`), prazo
  (bucket: `vencidas` = prazo < now & não concluída; `proximas` = prazo em [now, now+7d]; `sem` =
  prazo IS NULL). Mantém a ordenação por `dataCriacao DESC`. `Tarefa` é `TenantAware` (auto-escopo);
  mantém a semântica de "minhas".
- **Facetas:** Status · Prioridade · Prazo.
- **Sem paginação** (lista de um usuário é pequena; o agrupamento por status permanece — o motor
  só ativa paginação se houver markup dela). O template mantém o agrupamento Em aberto/Concluídas.
- **Template:** `tarefa/minhas.html.twig` vira a casca (root + barra); os dois grupos de cartões
  migram para `tarefa/_resultado.html.twig`.

### Fase 4 — Dashboard (agregações) — a mais pesada

- **Controller:** `App\Dashboard\Controller\DashboardController::index` — ramo XHR devolve
  `dashboard/_resultado.html.twig` (cards de KPI + tabela por advogado). Extrai filtros
  (data_de, data_ate, responsavel, cargo).
- **UseCase:** `ObterDadosDashboardUseCase::executar(Tenant $tenant, array $filtros)` — repassa
  Período/Responsável para as contagens agregadas; Cargo filtra a lista de colaboradores.
- **Repositórios:** os `count...PorResponsavel`/`countMetas...`/`countUrgentes`/`countPorResponsavel`
  de `TarefaRepository` e `PastaRepository` ganham `array $filtros` opcional (dataDe, dataAte,
  responsavelId). **Semântica de datas:** o Período aplica sobre "criadas/abertas no período"
  (`t.dataCriacao` / `p.dataAbertura`) para os totais; **vencidas** e **prazos próximos**
  permanecem relativos a `now` (não fazem sentido dobrados com Período — documentar). Responsável
  estreita para um colaborador; Cargo filtra o conjunto de linhas.
- **Facetas:** Período · Responsável · Cargo. (Busca por nome é opcional/nice-to-have.)
- **Cuidado:** é o refactor mais invasivo (várias queries + UseCase). Entregue por último; o
  teste unit do UseCase é reescrito/estendido para os filtros.

## Arquivos (visão geral)

**Novos (Fase 0):**
- `app/public/js/filtro-tabela.js` — motor genérico.
- `app/public/css/filtro-tabela.css` — estilos genéricos.
- `app/templates/_partials/_filtro_barra.html.twig` — barra parametrizável.
- `app/templates/_partials/_filtro_paginacao.html.twig` — paginação genérica.

**Por fase (editar/criar):**
- Demandas: editar `DemandasController`, `PastaRepository`; `pasta/demandas.html.twig` (casca) +
  novo `pasta/demandas/_resultado.html.twig`.
- Processo: editar `ProcessoController`, `ProcessoRepository`; `processo/index.html.twig` (casca) +
  novo `processo/_resultado.html.twig`.
- Minhas Metas: editar `TarefaController::minhas`, `TarefaRepository`; `tarefa/minhas.html.twig`
  (casca) + novo `tarefa/_resultado.html.twig`.
- Dashboard: editar `DashboardController`, `ObterDadosDashboardUseCase`, `TarefaRepository`,
  `PastaRepository`; `dashboard/index.html.twig` (casca) + novo `dashboard/_resultado.html.twig`.

Cada página carrega `filtro-tabela.js`/`.css` via `{% block javascripts %}` / bloco de estilos.

## Testes

Padrão do projeto (`APP_ENV=test`, DAMA rollback, Foundry v2; `failOnDeprecation` ativo):

- **Functional (por página):** o GET normal renderiza a barra + resultado; o GET com
  `X-Requested-With: XMLHttpRequest` devolve **só** o fragmento; cada faceta e a busca filtram o
  conjunto esperado; paginação (onde houver) devolve a página certa.
- **Processo — teste cross-tenant** do `findByFiltrosPaginado` (com `TenantFilter` desligado):
  tenant A não vê processos do tenant B.
- **Dashboard — unit do UseCase** estendido: Período/Responsável/Cargo alteram os números como
  esperado; sem filtros, resultado idêntico ao atual (não-regressão).
- **Repositórios:** teste dos novos `findByFiltros/countByFiltros` (Demandas, Processo) com
  combinações de facetas + busca + ordenação.
- **Smoke manual** ao final de cada fase (o mapeamento pega ~80%; smoke pega o resto): digitar na
  busca atualiza sem reload, facetas aplicam na hora, chips removem, "Limpar tudo" zera,
  paginação navega, foco da busca não se perde.
- Suíte deve permanecer verde (hoje 1070/1070).

## Fora de escopo

- Alterar o Expediente (migração para o componente = follow-up opcional).
- Paginação em Minhas Metas.
- Ordenação por clique de cabeçalho onde a página não tem tabela com `<th>` (Metas/Dashboard).
- Salvar filtros entre sessões (sessionStorage) — pode vir depois; não é requisito do pedido.

## Armadilhas conhecidas

- **Fragmento re-executa a cada swap:** handlers de negócio ficam **delegados no root
  persistente**; snippets no fragmento têm de ser **idempotentes**. Nunca re-ligar listener
  dentro do `_resultado`.
- **Nunca `id=` duplicado** ao dividir template em casca + fragmento — usar classes/`data-*`.
- **Contrato do XHR:** endpoint devolve o innerHTML do `_resultado`, e o controller precisa
  checar `isXmlHttpRequest()` corretamente (o motor sempre manda `X-Requested-With`).
- **CSRF:** endpoints de leitura por GET não exigem token; qualquer ação inline (POST) mantém o
  token que a página já usa. Não introduzir POST no filtro.
- **Dashboard:** não dobrar Período com "vencidas/próximas" (semântica relativa a `now`).
- **`TenantFilter` em teste fica desligado** — testes que dependem de escopo por tenant devem
  ligá-lo explicitamente **ou** validar o `andWhere` explícito (caso do Processo).

## Legado

`TarefaController` vive em `src/Controller/` (legado). Regra do projeto: antes de editar legado,
garantir teste do comportamento atual; não reescrever e mover no mesmo passo. Aqui **só se
adiciona** o ramo XHR + filtro (comportamento aditivo), sem mover o controller de domínio — a
migração `src/Controller/TarefaController` → `src/Tarefa/Controller/` fica fora desta entrega.
