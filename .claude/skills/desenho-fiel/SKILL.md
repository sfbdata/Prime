---
name: desenho-fiel
description: "Leva à tela, fielmente, um desenho do Claude Design (.dc.html em docs/design/ ou Artefato do claude.ai): inventário item a item do visível e do invisível (CSS, estados sc-if, textos do JS, hover, responsivo), componentes do desenho no lugar dos do sistema, valores tirados do CSS, teste de arranjo por filho direto e conferência final ✓/✗. Carregue SEMPRE que a tarefa implementar, ajustar, auditar ou revisar uma tela, modal, cartão ou componente que tenha desenho, mesmo que o pedido só diga \"igual ao desenho\", \"conforme o dc\", \"redesign\", \"faltou X do desenho\" ou cite um arquivo de docs/design/, e também ao delegar uma tela a um subagente."
---

# Desenho fiel: do `.dc.html` à tela, sem nada passar

O dono aprova o **desenho**, não a descrição dele. Toda vez que a tela saiu "parecida", houve
retrabalho: a Minhas Metas voltou com ~100 diferenças, o Vincular processo com 19, e numa sessão
3.459 testes passaram com o layout visivelmente quebrado. A causa nunca foi falta de capacidade;
foi ler o print em vez do arquivo, reaproveitar o componente que já existia e "melhorar" o desenho
por convicção. Esta skill é o método para não repetir isso.

## A regra de decisão (CLAUDE.md, "o desenho manda")

> Onde o desenho **mostra algo diferente**, o desenho manda.
> Onde o desenho é **omisso**, a convenção do sistema manda.
> **Desviar do desenho aprovado exige perguntar antes**, nunca decidir e entregar.

Duas consequências que costumam ser esquecidas:

- **Tudo que está no desenho entra.** Não pergunte "quer essa parte?". Se é grande, abra frente nova
  ou atribua a quem é dono da área; ficar de fora em silêncio não é opção.
- **O que o sistema tem e o desenho não tem sai da tela.** Migalha, traço vermelho do H1, menu ⋮,
  selo, contador, bloco extra: se o desenho não mostra, remova. Conferir o que **já existe** na tela
  é tão importante quanto o que é novo.

## O fluxo

### 1. Achar o desenho certo

- `ls docs/design/<frente>/`: pode haver várias versões (`vs-18-10-26/`, `vs-22-10-26/`). Vale a
  mais recente que o pedido citar; na dúvida entre duas, pergunte.
- Confira que o arquivo está **inteiro** (termina em `</x-dc>`/`</html>`; o script avisa) e que
  as dependências `bj-*.js` estão na pasta.
- Leia o `canvas.json` da pasta: cada prancheta (aba, modo lista, celular) é um estado obrigatório.
  Pasta de versão sem `canvas.json` (ex.: `vs-22-10-26/`): o da pasta-mãe é de um desenho anterior;
  não o use como lista de pranchetas da versão nova.
- **Leia a spec da frente** (`grep -ril <tela> docs/specs/`) e as decisões já tomadas pelo dono
  (Dn, Cn, "aprovado"): desvio já aprovado entra no inventário como ⚠ aprovado, não vira pergunta
  de novo.
- Artefato do claude.ai: `Artifact` com `action: "read"`, salve a cópia em `docs/design/<frente>/`.
- Desenho com dado sem lastro (KPI que o domínio não calcula, status que o enum não tem): isso é
  pergunta ao dono **antes** de implementar, não descoberta no meio.

### 2. Inventário: ler o arquivo, não o print

Rode o script, que extrai tudo o que é mecânico de achar:

```bash
python3 -I .claude/skills/desenho-fiel/scripts/inventario_dc.py "docs/design/<frente>/<arquivo>.dc.html" \
  > /tmp/inventario.md   # use o scratchpad da sessão
```

Ele lista dependências (e avisa quando `bj-*.js` falta na pasta), regras do `<style>` com
`@media`/`:hover`, seções `data-screen-label`, cada `sc-if` (estado escondido), cada `sc-for`,
eventos, cores e medidas por frequência, textos da marcação, de atributo (`title`, `placeholder`)
e textos que só existem no JavaScript, e marca travessões.

Também lista imagens e links para outros `.dc.html` (avisa os que faltam), avisa arquivo truncado
e, quando não acha o estado inicial pelo padrão, diz isso em vez de "nenhum". Limites: nos textos
do JS entram também nomes do dado de exemplo e um ou outro valor de CSS; o estado inicial vem só
como trecho.

O script é ponto de partida, não o inventário inteiro. Depois dele, **leia o arquivo** com
`references/formato-dc.md` ao lado e complete à mão, por região da tela (de cima para baixo, da
esquerda para a direita):

| Região | Item | Valor do desenho (CSS/JS) | Componente do desenho | Estado(s) | Onde vai no código |
|---|---|---|---|---|---|
| Cabeçalho | H1 "Minhas Metas" | `36px bold #12242f`, `letter-spacing:-.5px`, `text-shadow:0 2px 6px rgba(10,111,160,.12)` | título do dc | sempre | template da tela |
| KPIs | cartão ATRASADAS | número `#a3232b` se > 0; halo `rgba(163,35,43,.14)`; ícone `bi-exclamation-octagon` | KPI do dc | 0 / > 0 / ativo (filtro) | partial dos KPIs |

Uma linha por elemento; os estados do mesmo elemento ficam na coluna "Estado(s)", e só ganham
linha própria quando mudam valor (cor, texto, ícone). A coluna "Onde vai no código" é para quando
você já vai implementar; numa auditoria ou preparação, deixe em branco. O inventário inclui: hover, foco, ativo/inativo,
vazio, carregando, erro, sem permissão, filtro ativo, responsivo (cada `@media`), tooltips,
placeholders, toasts, confirmações, modais fechados no estado inicial, para onde cada clique leva.
Salve o inventário: ele é a lista da conferência final e o que você entrega a um subagente.

### 3. Mapear para componentes do desenho, nunca do sistema

Para cada linha do inventário, o componente é o **do desenho**, reproduzido com os valores dele:

| Em vez de (sistema) | Use (desenho) |
|---|---|
| `.card .card-outline` do AdminLTE, `col-lg-*` como moldura | a caixa do dc, com a borda, raio e sombra dele |
| `.small-box`/`.info-box` como KPI | o KPI do dc (ícone, halo, número, legenda) |
| `.modal` + backdrop do Bootstrap | o modal/painel do dc, com o fundo e a animação dele |
| `alert-success` como toast | o toast do dc |
| Select2, `form-select` | o campo do dc (busca, chips, menu) |
| `btn btn-primary` | o botão do dc (cor, altura, raio, ícone) |
| cor de status do sistema | a cor do selo no JS do dc |

Por quê: reaproveitar parece economia, mas cada componente do sistema traz margem, fonte, borda e
comportamento próprios, e o resultado fica "quase". "Quase" foi exatamente o que voltou com 100 ✗.
Se o mesmo componente do desenho aparece em várias telas, crie-o uma vez (partial Twig + classes
CSS com prefixo da tela ou do componente) e reaproveite **esse**.

Escopo do CSS: classes com prefixo próprio, escopadas sob a raiz da tela, para não vazar para o
resto do sistema e para não herdar o que o AdminLTE pendura em `.card`, `.row`, `.btn`.

### 4. Tamanhos, cores e espaçamentos saem do CSS

- Copie o valor: `padding:10px 14px`, `border-radius:3px`, `#0a6fa0`. Nada de "aproximadamente",
  nada de trocar pelo token do sistema que "é quase igual".
- Cor que muda com o dado está no JS (ex.: mapa `VIS`): leve a regra inteira, não só a cor do print.
- Ícone: mesmo nome `bi-*`. Fonte: a do `body` do desenho.
- Cores repetidas viram variáveis CSS da tela (`--mm-azul: #0a6fa0`), com o valor do desenho.

### 5. Implementar

Siga o fluxo do projeto (UseCase/testes primeiro, skills `criar-*`). Ao delegar a tela a um
subagente, entregue o **inventário** e escreva literalmente na tarefa: "componentes do desenho,
não os do sistema; valores do CSS do dc; o que o sistema tem e o desenho não tem sai".

### 6. Testar o que dá para testar

PHPUnit lê HTML, não posição. Mesmo assim, três coisas são testáveis e pegam regressão real:

- **Arranjo por filho direto.** `.row > .col-lg-4 .trilho` distingue "está ao lado" de "existe em
  algum lugar da página" (que é verdade até com o layout quebrado). Modelo:
  `app/tests/Cobranca/Functional/CarteiraArranjoTelaTest.php`:

  ```php
  self::assertSame(1, $crawler->filter('.cobrancas-page .cs-grade > .cs-trilho')->count(),
      'O trilho tem que ser filho direto da grade, ao lado da lista');
  ```
- **O que saiu continua fora.** `assertCount(0, $crawler->filter('.minhas-metas .card-outline'))`,
  migalha, menu ⋮: o teste impede que o componente do sistema volte num merge.
- **Textos e estados.** Texto literal do desenho (rótulos, vazio, tooltips), e cada `sc-if`
  relevante como cenário (lista vazia, filtro ativo, sem permissão).

Estilo (cor, borda, fonte) segue invisível para o teste: por isso existe o passo 7.

### 7. Conferência final ✓/✗ (obrigatória, antes de entregar)

Percorra o inventário **linha por linha** contra o código entregue (Twig + CSS + JS), não contra a
memória do que você fez. Para cada linha: ✓, ✗ (com o que falta) ou ⚠ (desvio, com o motivo e a
aprovação). Entregue a tabela inteira, mesmo longa:

```
| # | Item | Desenho | Tela | ✓/✗ |
| 1 | H1 Minhas Metas | 36px bold #12242f, letter-spacing -.5px, sombra | idem | ✓ |
| 2 | KPI ATRASADAS > 0 | número #a3232b | número #12242f | ✗ falta a regra de cor |
| 3 | Travessão em "Prazo — hoje" | travessão | "Prazo: hoje" | ⚠ regra do projeto |
```

Para uma auditoria imparcial, delegue a conferência a um subagente read-only com o `.dc.html`, o
inventário e os arquivos da tela, **sem** contar o que você acha que ficou bom. Corrija os ✗ e
repita até não sobrar ✗ sem justificativa. Suíte verde não substitui esta etapa.

Na mensagem final, diga ao dono **o que olhar na tela** (o smoke no navegador é dele): os estados
que só aparecem com interação, o responsivo, o hover.

## Quando perguntar ao dono

Pergunte **antes** de implementar, nunca entregue o desvio decidido:

- você quer desviar do desenho por qualquer motivo (mais destaque, "fica melhor", padrão do sistema);
- o desenho promete dado ou recurso que o sistema não tem (KPI sem lastro, integração inexistente);
- defeito do próprio desenho: texto ilegível, contraste ruim, sobreposição, conflito com lei;
- duas versões do desenho discordam, ou um `bj-*.js` falta e as cópias divergem;
- omissão com consequência (o desenho não mostra o estado de erro de uma ação que grava).

Não pergunte: se um item do desenho entra (entra), nem detalhe que a convenção do sistema resolve
quando o desenho é omisso. Travessão no texto do desenho: reescreva e cite na conferência.

Nunca escreva no código, no artefato ou na entrega que o desenho "é só um wireframe" ou "é
ilustrativo": foi essa frase que licenciou todos os desvios da primeira vez.

## Armadilhas conhecidas

Leia `references/armadilhas.md` (12 grupos, cada um com o PR em que voltou) antes de escrever o CSS
e de novo antes da conferência. As que mais custaram:

- **O que só existe no JS do desenho** (#85 #156 #222): dicas, vazios, "Limpar não zera a
  Situação", "clicar com menu aberto só fecha o menu", botão que só aparece em certo caso.
- **Componente do sistema traz comportamento do sistema** (#307 #340 #346): o modal Bootstrap
  travava a rolagem que o desenho não trava; o X automático entrou num menu que não tem X.
- **Manter o que já existia** (#206 → #252 → #310): filtro, legenda, modo lista, ⋮, migalha.
- **Desenho truncado** (#250): o `.dc.html` chegou cortado em 256 KiB e duas telas foram refeitas.
  O script avisa quando o arquivo não termina em `</x-dc>`/`</html>`.
- **Clearfix do AdminLTE**: `.card-header::after { display:block; clear:both; content:"" }` vira um
  terceiro item num cabeçalho `d-flex` e empurra o botão para o meio com `justify-content-between`.
  Antes de culpar o próprio HTML, confira o CSS de terceiros que a classe herda.

## Mapa

| Arquivo | Quando ler |
|---|---|
| `references/formato-dc.md` | ao abrir qualquer `.dc.html` ou Artefato: onde mora cada valor, estado e texto |
| `references/armadilhas.md` | antes do CSS e antes da conferência: o que já deu retrabalho, com PR |
| `scripts/inventario_dc.py` | passo 2, sempre |
| `docs/guias/desenho-para-tela.md` | a origem da regra e o caso do "saldo com destaque" |
