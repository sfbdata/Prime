# Ajuste 11 — Redesign visual da `cobranca_objeto_show` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reskin a página `cobranca_objeto_show` para um "cockpit financeiro-jurídico" em 2 colunas
(hero do dinheiro, números mono, cartões com sombra), na identidade **verde** do sistema, sem mudar
nenhuma regra de negócio nem quebrar o JS existente.

**Architecture:** Reescrita de **marcação Twig + CSS** apenas. O `show.html.twig` mantém integralmente
seus blocos `javascripts`/`stylesheets`; os ~630 linhas de JS continuam achando os mesmos ids/`data-*`/
classes. Um **teste funcional de contrato** (Task 1) trava esses ganchos antes de qualquer restyle e
precisa ficar verde em todas as tasks seguintes. Cada task junta template + CSS que mudam juntos.

**Tech Stack:** Symfony 7.4 / Twig, Bootstrap 5 + AdminLTE 4, Bootstrap Icons, Source Sans 3,
`public/css/cobrancas.css`. Testes: PHPUnit (DAMA + Foundry) via `docker exec jusprime_php_dev`.

## Global Constraints

- **Idioma:** tudo em pt-BR; `snake_case` em templates/classes CSS de página, `camelCase` em JS.
- **Zero PHP:** nenhum controller/DTO/UseCase/entity/migration muda. Todos os dados já vêm de
  `MontarDetalheObjeto` → `ObjetoDetalheOutput`/`CasoDetalheOutput`.
- **Cor = identidade do sistema:** temar por `--bs-*` + accent do módulo `--jp-accent`
  (`#1f7a4d` / dark `#4cc38a`, `--jp-accent-rgb`). Tema escuro via `html[data-bs-theme="dark"]`. **Proibido**
  introduzir paleta índigo/azul ou um sistema `--cob-*` com hex fixos. Verde é a cor de dinheiro-que-entra.
- **Contrato de JS preservado (spec §2):** NUNCA remover, nesta rodada, nenhum destes — `#objetoTabs`,
  `#tab-cobranca`, `#tab-documentos`, `#tab-historico`, `#documentos-tab`, `#secao-divida`, `.jp-obr`,
  `.jp-check`, `#barraSelecaoDivida` (+ `[data-selecao-qtd]`/`[data-selecao-total]`),
  `[data-acao="acordar"|"acordar-selecionadas"|"limpar-selecao"|"receber"]`, `[data-obrigacao-id]`,
  `[data-valor-centavos]`, `[data-bruto-centavos]`, `[data-modal-erro]`/`[data-modal-erro-acao]`, e TODOS
  os `#modal*` e seus `data-acao-url`/`data-*`. Reveste primeiro; remoção de código morto é outra rodada.
- **Comandos no container:** `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter <X>'`.
- **Commits locais** em pt-BR imperativo ≤72 chars; **sem push**.
- **Fonte de verdade visual:** `tmp/openpencil/cobranca-objeto-show-light.png` / `-dark.png` (layout) —
  recolorido para verde. Spec: `docs/specs/cobranca-ajuste11-redesign-visual-objeto-show.md`.

---

### Task 1: Teste de contrato dos ganchos de JS

Trava os seletores que o JS usa, contra a marcação ATUAL (passa hoje), para que qualquer restyle que
apague um gancho quebre o teste em vez de quebrar em produção.

**Files:**
- Create: `app/tests/Cobranca/Functional/ObjetoShowContratoJsTest.php`

**Interfaces:**
- Consumes: base `App\Tests\Cobranca\Functional\CobrancaWebTestCase` com `criarAdminLogado(KernelBrowser): array` (retorna `[usuario, tenant]`) e `semearGrafo(Tenant): array` (retorna `[objeto|…, caso]`), padrão já usado em `ObjetoPessoaControllerTest`.
- Produces: nada (só guarda). Rota alvo: `GET /cobrancas/objetos/{id}`.

- [ ] **Step 1: Escrever o teste (falha só se um gancho sumir)**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Ajuste 11 (redesign): trava o CONTRATO de marcação que o JS de show.html.twig depende.
 * O redesign é reveste-primeiro: nenhum destes ganchos pode sumir. Se algum sumir, ESTE teste falha.
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoShowContratoJsTest extends CobrancaWebTestCase
{
    #[TestDox('A página do objeto mantém todos os ganchos de id/data-* que o JS usa')]
    public function testGanchosDeJsPresentes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $html = (string) $client->request('GET', '/cobrancas/objetos/' . $objetoId)
            ->html();
        self::assertResponseIsSuccessful();

        // Abas + âncora (pasta-arquivos.js e os redirects #secao-divida dependem destes ids)
        foreach (['id="objetoTabs"', 'id="tab-cobranca"', 'id="tab-documentos"', 'id="tab-historico"',
                  'id="documentos-tab"', 'id="secao-divida"'] as $gancho) {
            self::assertStringContainsString($gancho, $html, "Sumiu o gancho: {$gancho}");
        }

        // Seleção da dívida
        foreach (['id="barraSelecaoDivida"', 'jp-check', 'data-selecao-qtd', 'data-selecao-total',
                  'data-acao="acordar-selecionadas"', 'data-acao="limpar-selecao"'] as $gancho) {
            self::assertStringContainsString($gancho, $html, "Sumiu o gancho: {$gancho}");
        }

        // Modais que o JS abre/rehidrata (ids fixos)
        foreach (['id="modalRegistrarPagamento"', 'id="modalCriarAcordo"', 'id="modalEditarObrigacao"',
                  'id="modalExcluirObrigacao"', 'id="modalConcluirAcao"'] as $gancho) {
            self::assertStringContainsString($gancho, $html, "Sumiu o gancho: {$gancho}");
        }
    }
}
```

- [ ] **Step 2: Rodar e ver PASSAR contra a marcação atual**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ObjetoShowContratoJsTest'`
Expected: PASS (a página de hoje já tem todos os ganchos). Se algum falhar, ajuste a string para o
literal exato encontrado no template atual — o objetivo é retratar o estado REAL, não o ideal.

- [ ] **Step 3: Commit**

```bash
git add app/tests/Cobranca/Functional/ObjetoShowContratoJsTest.php
git commit -m "Travar contrato de ganchos de JS da objeto_show (guarda do redesign)"
```

---

### Task 2: Tokens + casca em 2 colunas + hero do dinheiro

Introduz a grade de 2 colunas e o hero, e move o include do `_pessoa_card` para o trilho direito. As abas
e os partials da dívida/extrato continuam intactos dentro da coluna esquerda.

**Files:**
- Modify: `app/templates/cobranca/objeto/show.html.twig` (bloco `body`: header/cockpit → hero; envolver
  conteúdo em `.cob-grid` com `.cob-main` e `.cob-rail`)
- Modify: `app/public/css/cobrancas.css` (append: tokens `--jp-*` extras, `.cob-grid`, `.cob-main`,
  `.cob-rail`, `.cob-hero`, `.cob-card`)

**Interfaces:**
- Consumes: `objeto` (`ObjetoDetalheOutput`), `caso` (`CasoDetalheOutput`) já no escopo do template.
- Produces: classes `.cob-grid`/`.cob-main`/`.cob-rail`/`.cob-hero`/`.cob-card` reusadas nas Tasks 3–5.

- [ ] **Step 1: Adicionar tokens e classes de layout ao fim de `cobrancas.css`**

```css
/* ── Ajuste 11: cockpit em 2 colunas ─────────────────────────────── */
.cobrancas-page { --cob-radius: 1rem; }

.cob-grid { display: grid; grid-template-columns: minmax(0, 1.62fr) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
@media (max-width: 991.98px) { .cob-grid { grid-template-columns: 1fr; } }
.cob-main, .cob-rail { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }

.cob-card {
    background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
    border-radius: var(--cob-radius); box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.03);
}
html[data-bs-theme="dark"] .cob-card { box-shadow: 0 1px 2px rgba(0,0,0,.3); }

/* Hero do dinheiro — painel verde-escuro (identidade do módulo, não índigo) */
.cob-hero {
    color: #fff; border-radius: var(--cob-radius); padding: 1.35rem 1.5rem;
    background: linear-gradient(135deg, #0f3d2e 0%, #14533b 100%);
    display: flex; flex-direction: column; gap: 1.1rem;
}
.cob-hero-label { font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: #a7e0c3; font-weight: 600; }
.cob-hero-total { font-size: 2.55rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.cob-hero-venc { color: #ff8f9e; font-weight: 700; font-variant-numeric: tabular-nums; }
.cob-hero-bar { height: 10px; border-radius: 999px; background: rgba(255,255,255,.14); overflow: hidden; }
.cob-hero-bar > i { display: block; height: 100%; background: var(--jp-accent); border-radius: 999px; }
```

- [ ] **Step 2: Envolver o corpo da página na grade e trocar o cockpit pelo hero**

No `show.html.twig`, dentro de `<section class="content">`, substituir o bloco `.caso-header`/`.jp-resumo`
(cockpit atual) por um `.cob-hero` (Total em aberto herói, Já vencido, chip Honorários, barra recuperado)
e envolver alertas + abas + partials num `.cob-grid`:

```twig
<div class="cob-grid">
  <div class="cob-main">
    <div class="cob-hero">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
          <div class="cob-hero-label" data-bs-toggle="tooltip" title="Saldo exigível (SPEC §10)">Total em aberto</div>
          <div class="cob-hero-total {{ caso.saldoExigivel == 0 and not caso.encerrado ? 'saldo-zerado' }}">{{ caso.saldoExigivel|centavos }}</div>
          {% if caso.saldoVencido > 0 %}<div class="mt-1"><span class="cob-hero-venc">{{ caso.saldoVencido|centavos }}</span> <span style="color:#c7e6d5">já vencido</span></div>{% endif %}
        </div>
        <div class="text-end" style="color:#c7e6d5">
          <div class="cob-hero-label">Honorários</div>
          <div class="fw-semibold text-white">{{ caso.formaHonorariosLabel }}{% if caso.percentualHonorarios %} ({{ caso.percentualHonorarios }}%){% endif %}</div>
        </div>
      </div>
      {# barra recuperado × total do caso — usar os campos já existentes no output; se algum não existir, omitir esta div (não inventar cálculo) #}
    </div>

    {# alertas: mover para cá o bloco caso.alertas EXISTENTE, sem mudar as ações/âncoras #}
    {# abas #objetoTabs + tab-content EXISTENTES, sem alterar ids #}
  </div>
  <div class="cob-rail">
    {{ include('cobranca/objeto/_partials/_pessoa_card.html.twig') }}
    {# Próxima ação e Ações do caso entram na Task 3 #}
  </div>
</div>
```

> Preservar: o `{{ include('cobranca/_partials/_subnav.html.twig', {ativo: 'carteiras'}) }}`, o botão voltar,
> o título + badges de status, o botão "Registrar contato" e o dropdown `⋯` **permanecem no header** por ora
> (o dropdown migra na Task 3). O include do `_pessoa_card` **sai de cima das abas e vai para o `.cob-rail`**.

- [ ] **Step 3: Rodar o teste de contrato + os testes do controller do objeto**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter "ObjetoShowContratoJsTest|ObjetoPessoaControllerTest"'`
Expected: PASS (abas, âncora e modais continuam presentes; pessoa card só mudou de lugar).

- [ ] **Step 4: Smoke visual (obrigatório)**

Abrir um objeto real no dev (`http://localhost:8080/cobrancas/objetos/<id>`) nos dois temas
(`data-bs-theme` claro/escuro pelo menu) e confirmar: 2 colunas no desktop, 1 coluna < 992px, hero verde
com o total herói, pessoa card no trilho, sem scroll horizontal. Registrar o que viu.

- [ ] **Step 5: Commit**

```bash
git add app/templates/cobranca/objeto/show.html.twig app/public/css/cobrancas.css
git commit -m "Redesign objeto_show: grade 2 colunas + hero do dinheiro"
```

---

### Task 3: Cartões do trilho — Próxima ação e Ações do caso

Promove "Próxima ação" (que estava no cockpit) a cartão de destaque no trilho e move as ações raras
(Judicializar/Encerrar) do dropdown `⋯` do header para um cartão "Ações do caso". Mantém os mesmos modais.

**Files:**
- Modify: `app/templates/cobranca/objeto/show.html.twig` (compor `.cob-rail`; esvaziar/retirar o dropdown
  `⋯` do header movendo seus itens para o cartão)
- Modify: `app/public/css/cobrancas.css` (`.cob-proxima`, `.cob-acao-link`)

**Interfaces:**
- Consumes: `caso.proximaAcao` (`descricao`, `prazo`, `responsavelNome`, `atrasada`), `caso.pastaJudicialId`,
  `caso.prontoParaEncerrar`, `caso.saldoExigivel`, `can_access_module('pastas')`, `podeGerenciar`.
- Produces: nada novo.

- [ ] **Step 1: CSS dos cartões do trilho**

```css
.cob-proxima { border: 1px solid rgba(var(--jp-accent-rgb), .30); background: rgba(var(--jp-accent-rgb), .07); border-radius: var(--cob-radius); padding: 1.1rem; }
.cob-proxima-label { font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--jp-accent); font-weight: 600; }
.cob-acao-link { display: flex; align-items: center; gap: .7rem; padding: .7rem .8rem; border: 1px solid var(--bs-border-color); border-radius: .625rem; text-decoration: none; color: var(--bs-body-color); }
.cob-acao-link:hover { background: var(--bs-tertiary-bg); }
.cob-acao-link.is-disabled { opacity: .55; }
```

- [ ] **Step 2: Compor o trilho (Próxima ação + Pessoa + Ações do caso)**

No `.cob-rail`, ANTES do `_pessoa_card`, inserir o cartão "Próxima ação" reusando o markup EXISTENTE do
`.jp-resumo-acao` (com o botão que abre `#modalConcluirAcao` / `#modalDefinirAcao` — ids intactos). DEPOIS
do `_pessoa_card`, inserir "Ações do caso" com os itens que hoje estão no dropdown `⋯` do header:
`#modalJudicializar` (gate `can_access_module('pastas') and not caso.pastaJudicialId`) e `#modalEncerrarCaso`
(habilitado só com `caso.prontoParaEncerrar`; desabilitado ENSINA o que falta — mesmo texto de hoje).
Remover o `<div class="dropdown">` do header (seus dois itens agora vivem no cartão); manter "Registrar
contato" no header.

> O `#modalConcluirAcao`, `#modalDefinirAcao`, `#modalJudicializar`, `#modalEncerrarCaso` continuam
> renderizados por `_acoes_modais.html.twig` (inalterado). Só os GATILHOS mudam de lugar.

- [ ] **Step 3: Rodar contrato + mutações de caso/ação**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter "ObjetoShowContratoJsTest|CasoMutacaoControllerTest|AcaoMutacaoControllerTest|JudicializarMutacaoControllerTest"'`
Expected: PASS.

- [ ] **Step 4: Smoke** — clicar Concluir/Definir ação, Judicializar (com módulo pastas) e conferir que
"Encerrar cobrança" fica desabilitado com dica quando `saldoExigivel > 0`. Dois temas.

- [ ] **Step 5: Commit**

```bash
git add app/templates/cobranca/objeto/show.html.twig app/public/css/cobrancas.css
git commit -m "Redesign objeto_show: trilho com proxima acao e acoes do caso"
```

---

### Task 4: Revestir a dívida — fila, acordo e barra de seleção

Reveste `_divida.html.twig`: linhas com coluna de vencimento forte, ações Receber/Acordar na linha e
**editar/excluir juntos num menu `⋯`**; acordo como cartão com barra; barra de seleção revestida.

**Files:**
- Modify: `app/templates/cobranca/objeto/_partials/_divida.html.twig`
- Modify: `app/public/css/cobrancas.css` (revestir `.jp-obr`, `.jp-obr-*`, `.jp-acordo*`, `.jp-selbar`; nova
  `.cob-row-menu`)

**Interfaces:**
- Consumes: `caso.obrigacoesAvulsas`, `caso.gruposAcordo` (+ `g.parcelas`, `g.substituidas`), `podeGerenciar`,
  `podeMovimentar`, os `data-*` já emitidos por linha.
- Produces: nada novo. **Preserva** `.jp-obr`, `.jp-check`, `[data-acao=…]`, `[data-obrigacao-id]`,
  `[data-valor-centavos]`, `[data-bruto-centavos]`, `#barraSelecaoDivida`, e os `data-acao-url`/`data-*` dos
  botões editar/excluir (agora dentro do `<ul>` do menu).

- [ ] **Step 1: CSS — revestir linhas + menu da linha**

```css
.jp-obr { display: grid; grid-template-columns: 18px 3px 96px minmax(0,1fr) 120px auto; align-items: center; gap: .9rem; padding: .75rem .65rem; border-radius: .625rem; }
.jp-obr.is-vencida { background: rgba(var(--bs-danger-rgb), .06); }
.jp-obr.is-vencida::before, .jp-obr .jp-espinha { } /* espinha via a 2ª coluna: um <span> 3px */
.jp-obr-data-dia { font-weight: 700; font-variant-numeric: tabular-nums; }
.jp-obr-data-rel.is-atrasado { color: var(--bs-danger); font-weight: 600; }
.jp-obr-valor { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.jp-obr-restante { display: block; font-size: .72rem; color: var(--bs-secondary-color); font-weight: 500; }
.cob-row-menu > .btn { border: 1px solid var(--bs-border-color); }
```

- [ ] **Step 2: Marcação — juntar editar/excluir no `⋯`**

Trocar os dois `<button>` soltos (editar `#modalEditarObrigacao` e excluir `#modalExcluirObrigacao`) por um
`dropdown` Bootstrap: o botão `⋯` abre um `<ul class="dropdown-menu">` com dois `<button class="dropdown-item"
…>` carregando exatamente os MESMOS `data-bs-target`, `data-acao-url`, `data-token`, `data-descricao`,
`data-vencimento`, `data-valor-centavos`, `data-encargos-centavos` de hoje. "Receber" e "Acordar" seguem
como botões diretos na linha (accent sólido / outline). Não tocar nos checkboxes `.jp-check` nem nos
`data-acao`.

> A "espinha" vermelha da linha vencida é a 2ª coluna do grid (`<span>` de 3px com bg `--bs-danger`),
> substituindo o `box-shadow`; alinhe com o padrão `inset 3px 0` que o módulo já usa em `.jp-obr.is-selecionada`.

- [ ] **Step 3: Rodar contrato + testes de acordo/obrigação agrupada**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter "ObjetoShowContratoJsTest|ObrigacoesAgrupadasPorAcordoControllerTest|AcordoSobreObrigacaoPagaTest"'`
Expected: PASS.

- [ ] **Step 4: Smoke (o mais crítico)** — no dev: (a) "Receber" na linha pré-preenche o modal certo;
(b) "Acordar" na linha e "Fazer acordo com estas" abrem `#modalCriarAcordo` com a seleção e o gerador
soma/gera/fecha; (c) editar e excluir pelo `⋯` abrem os modais com os dados da linha; (d) linha vencida com
espinha vermelha. Dois temas. **Bloqueia o commit se qualquer um falhar.**

- [ ] **Step 5: Commit**

```bash
git add app/templates/cobranca/objeto/_partials/_divida.html.twig app/public/css/cobrancas.css
git commit -m "Redesign objeto_show: revestir divida, acordo e barra de selecao"
```

---

### Task 5: Revestir o extrato "O que já entrou"

**Files:**
- Modify: `app/templates/cobranca/objeto/_partials/_movimentos.html.twig`
- Modify: `app/public/css/cobrancas.css` (`.jp-mov*` — ícone circular, valor mono em accent)

**Interfaces:**
- Consumes: `caso.pagamentos`, `caso.liquidacoes`, `caso.acordos`, `podeMovimentar`, `#modalCorrigirPagamento`.
- Produces: nada novo.

- [ ] **Step 1: CSS do extrato**

```css
.jp-mov { display: grid; grid-template-columns: 34px 96px minmax(0,1fr) 140px auto; align-items: center; gap: .9rem; padding: .7rem .65rem; }
.jp-mov-ico { width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center; background: rgba(var(--jp-accent-rgb), .12); color: var(--jp-accent); }
.jp-mov-ico.is-liq { background: rgba(var(--bs-secondary-rgb), .12); color: var(--bs-secondary-color); }
.jp-mov-valor { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--jp-accent); }
```

- [ ] **Step 2: Marcação** — revestir as linhas de pagamento/liquidação com `.jp-mov`/`.jp-mov-ico`/
`.jp-mov-valor`, mantendo o botão "Corrigir" (`#modalCorrigirPagamento` + `data-acao-url`/`data-previa-url`
intactos) e a seção "Acordos encerrados" (só revestir).

- [ ] **Step 3: Rodar contrato + liquidação/pagamento**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter "ObjetoShowContratoJsTest|LiquidacaoMutacaoControllerTest"'`
Expected: PASS.

- [ ] **Step 4: Smoke** — extrato do mais recente ao mais antigo; "Corrigir pagamento" abre o modal com o
contexto certo; dois temas.

- [ ] **Step 5: Commit**

```bash
git add app/templates/cobranca/objeto/_partials/_movimentos.html.twig app/public/css/cobrancas.css
git commit -m "Redesign objeto_show: revestir extrato de entradas"
```

---

### Task 6: Fechamento — suíte cheia, tema, responsivo e revisão

**Files:**
- Modify (se o smoke pedir): `app/public/css/cobrancas.css`, os 4 templates.

- [ ] **Step 1: Suíte completa dos domínios afetados**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca tests/Pasta'`
Expected: PASS total (Pasta entra porque `pasta-arquivos.js`/aba Documentos é compartilhado).

- [ ] **Step 2: Smoke consolidado nos dois temas** — percorrer a página inteira e a checklist da spec §7:
Receber na linha, gerador de acordo, editar/excluir via `⋯`, upload de documento (restaura aba Documentos),
reabertura de modal com erro de validação (B5), responsivo < 992px. Anotar evidências.

- [ ] **Step 3: `/review`** — rodar o feature-review-agent contra a spec
`docs/specs/cobranca-ajuste11-redesign-visual-objeto-show.md` (read-only). Corrigir furos apontados
(orquestrador), sem novos ganchos removidos.

- [ ] **Step 4: Commit final (se houve ajuste)**

```bash
git add -A
git commit -m "Ajuste 11: polir tema/responsivo do redesign da objeto_show"
```

- [ ] **Step 5: Handoff** — atualizar `docs/gestao-cobrancas/SESSION_HANDOFF.md` e a memória
`project_redesign_objeto_show` com o estado (implementado/revisado; falta humano decidir merge+deploy).
Deploy em prod é decisão do humano (sem migration; nginx revalida CSS/JS no 1º acesso).

## Self-Review (feito na escrita)

- **Cobertura da spec:** §1 dores → Tasks 2–5; §2 contrato → Task 1 (+ verificado em toda task); §3 tokens →
  Tasks 2–5 CSS; §4 layout/responsivo → Tasks 2 e 6; §4.1 mapa → uma task por área; §5 mudanças de fluxo
  (⋯, próxima ação/ações no trilho) → Tasks 3 e 4; §6 arquivos → Files de cada task; §7 verificação → Steps
  de teste + smoke + Task 6/`/review`; §8 mockup→código → Global Constraints + Task 4 (ícones/mono).
- **Placeholders:** o único ponto propositalmente aberto é a "barra recuperado × total do caso" no hero
  (Task 2 Step 2): só desenhar se os campos já existirem no output — **proibido inventar cálculo** (a spec
  não cria dado novo). Se não existirem, omitir a barra.
- **Consistência:** classes `.cob-grid/.cob-main/.cob-rail/.cob-hero/.cob-card` definidas na Task 2 e
  reusadas nas 3/5; nenhum id de modal renomeado; nomes de campos do output vêm do template atual já lido.
