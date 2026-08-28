# Handoff — redesenho da `cobranca_carteira_show`

Data: 27/08/2026. Direção **1b aprovada pelo dono** ("Lista + trilho lateral").
Repo: `sfbdata/Prime`, branch `master`.

## O que abrir

| Arquivo | O que é |
|---|---|
| `Carteira 1B.dc.html` | O desenho aprovado, interativo. Abre direto no navegador (precisa do `support.js` ao lado). Ordenação, busca, filtro, paginação, modais e estados vazios funcionam de verdade. |
| `support.js` | Runtime do arquivo acima. Não faz parte da implementação — só serve para você VER o desenho. |
| `assets/bluejus-white-transparent-nav.png` | Logo real da nav, tirada do repo (`bluejus-favicons/logo nav/`). |

A implementação é **Twig + CSS do sistema**, não este HTML. Use o arquivo como referência
visual e de comportamento.

## Contexto: o que mudou desde o `docs/design/carteira-show/README.md` (10/08)

Os wireframes daquela pasta tratavam três coisas como **proposta**. Elas **já são dado real**
hoje — foi conferido no código em 27/08:

- `CarteiraController::show()` **pagina**: `POR_PAGINA = 20`, e devolve `pagina`, `total_paginas`,
  `por_pagina`, `total`.
- `MontarVisaoCarteiraUseCase::ORDENACOES = ['saldo', 'objeto', 'pessoa']`, com direção
  `asc|desc` e desempate por `id`. Padrão: `saldo desc`.
- `CarteiraDetalheOutput` expõe `saldoVencido` e `totalComAtraso` — o KPI "Vencido" do desenho
  é agregado real, não invenção.

Então o desenho **não pede back-end novo**, com uma exceção só (ver "Pendências").

## Estrutura da tela

```
header teal (chrome existente)
nav: EXPEDIENTE (ativo) · DEMANDAS · PROCESSOS      ← nav real do sistema, não mexer
┌ card de cabeçalho ────────────────────────────────────────────────┐
│ voltar + breadcrumb              [Novo objeto] [Importar] [⋮]     │
│ selos: modo · honorários                                          │
│ H1 nome da carteira                                               │
│ Credor: <a> + selo "Dados atualizados até …" (abre detalhamento)  │
├ 4 KPIs em grid, separados por hairline ───────────────────────────┤
│ Saldo consolidado │ Vencido (vermelho) │ Objetos │ Casos          │
└───────────────────────────────────────────────────────────────────┘
grid: minmax(0,1fr) 340px, gap 16
┌ card da lista ─────────────────────┐ ┌ trilho 340px ─────────────┐
│ busca · filtro Estado · contagem   │ │ Configuração   [Editar]   │
│ cabeçalho ordenável                │ │ (7 linhas rótulo→valor)   │
│ 20 linhas                          │ ├───────────────────────────┤
│ rodapé: faixa + paginação          │ │ Documentos 3        [+]   │
└────────────────────────────────────┘ └───────────────────────────┘
```

## Tabela: colunas e contrato

Grid da linha **e** do cabeçalho (têm de ser idênticos):

```css
grid-template-columns: minmax(0,1.3fr) minmax(0,1.2fr) 118px 172px 30px;
padding: 9px 14px;
min-height: 60px;
```

| Coluna | Fonte | Ordenável |
|---|---|---|
| Unidade (`objetoIdentificacao`) + descrição (`objetoDescricao`) na 2ª linha | `CasoResumoOutput` | **sim** — `ordenar=objeto` |
| Pessoa cobrada (`pessoaCobradaNome`) | idem | **sim** — `ordenar=pessoa` |
| Estado (`statusLabel`) + "Pronto p/ encerrar" quando `prontoParaEncerrar` | idem | **não** |
| Saldo (`saldoExigivel`) + "R$ X vencido" abaixo quando `temVencido` | idem | **sim** — `ordenar=saldo` (padrão, desc) |
| 📎 quando `temDocumentos` | idem | — |

Regras que o desenho assume:

- **Só essas três colunas mostram ícone de ordenação.** Estado não ordena porque o UseCase
  não aceita — não coloque a seta lá.
- A identificação é `<a href>` real (link para `cobranca_objeto_show`), não `<span>` com JS.
  Já existe teste garantindo isso (`ListaCarteiraAcessivelTest`).
- Linha com atraso ganha `box-shadow: inset 3px 0 0 #e5b4ae` — realce lateral, **não** fundo
  colorido, para o número não perder legibilidade.
- Dinheiro sempre com `.jp-money` / `font-variant-numeric: tabular-nums`.
- Saldo zero fica em `#8a9aa6` (cinza), não em preto.
- "Pronto p/ encerrar" é **indicador derivado**, não um 4º estado — texto pequeno abaixo do
  selo, nunca um selo do mesmo peso.

## Cabeçalho: os 4 KPIs

Grid `1.15fr 1.15fr .8fr .8fr`, hairline `#eef2f5` entre eles, padding `16px 22px`.
Valor em 29px/600/`-0.02em`; rótulo em 10.5px/700/uppercase/`0.1em`/`#7b93a2`;
sub-linha em 12px/`#8a9aa6`.

| KPI | Valor | Sub-linha |
|---|---|---|
| Saldo consolidado | `saldoConsolidado` | "carteira inteira, não a página" |
| Vencido | `saldoVencido` em `#a3232b` | "`totalComAtraso` casos com atraso · N% do saldo" |
| Objetos cobrados | `totalObjetos` | "rótulo: `rotuloObjeto`" |
| Casos abertos | `totalCasos` | "tolerância de atraso: `toleranciaAtrasoDias` dias" |

A sub-linha do primeiro KPI existe porque a busca filtra a lista e **não** os agregados —
é a frase que evita o chamado "o total não bate com a lista".

## Selo "Dados atualizados até"

- Texto: `dadosAtualizadosAte` + " · há N dias" em `#a3232b` quando a defasagem incomoda.
- Clicável: abre popover com `emissaoPorTipo` (Inadimplência, Acordos detalhados, Receitas,
  Dados cadastrais), **destacando em vermelho a emissão mais antiga** — é ela que define a data
  do selo. Rodapé do popover explica isso em uma frase.
- Continua **dentro de `.content-header`**: é onde o `CarteiraDadosAtualizadosNaTelaTest` procura.

## Trilho lateral (340px)

**Configuração** — 7 linhas `rótulo … valor` com linha pontilhada entre eles, valor à direita
(máx. 186px, `text-wrap: pretty`): Modo, Honorários (forma + %), Tolerância de atraso, Juros,
Multa, Vínculo preferido, Rótulo do objeto. Botão "Editar" abre o modal.

**Documentos** — contagem em pill, `+` abre o modal de upload, cada linha tem ícone por tipo,
nome como link de download e lixeira. Estado vazio com uma frase dizendo o que vai ali.

## Modais

| Modal | Rota / form existente |
|---|---|
| Configuração da carteira (3 seções: Operação, Honorários, Encargos por atraso) | `cobranca_carteira_configurar` + `EditarConfiguracaoCarteiraType`. Todos os campos do desenho existem no `EditarConfiguracaoCarteiraInput`. Rodapé cita `resources.carteira.gerenciar`. |
| Importar relatório (4 tipos + arquivo + **Simular → Confirmar**) | os comandos `Importar*Command` já têm `prever()`/`confirmar()`. "Confirmar importação" fica **desabilitado até simular** — é o dry-run virando UI. A projeção mostra lidas / já existentes / novas / variação do saldo. |
| Adicionar documento (categoria + observação) | `cobranca_carteira_documento_upload`, categorias do `CategoriaDocumentoCarteira`. |
| Nova unidade (identificação + descrição) | `cobranca_objeto_criar`. Botão diz "Criar e abrir cobrança" porque o controller já redireciona para `cobranca_objeto_show`. |

Nenhum modal usa `confirm()` nativo. **Mas não troque os 63 `confirm()` do resto do sistema
nesta frente** — a lixeira de documento pode continuar com `confirm()` até existir um
componente de confirmação; é frente própria (já anotado no README de 10/08).

## Estados vazios

- **Busca sem resultado**: ícone, "Nenhum caso casa “termo”", uma frase lembrando que a busca
  cobre unidade e pessoa e que os agregados são da carteira inteira, e botão "Limpar busca".
  O rodapé de paginação **desaparece** (não mostrar "0 de 0" com pager).
- **Carteira sem documento**: frase curta dizendo que atas e contrato de honorários ficam ali.

## Tokens

```
chrome teal            #0c7a9c
fundo da página        #f4f7f9
card                   #fff, borda #dde5eb, raio 14px, sombra 0 1px 2px rgba(16,42,58,.04)
hairline interno       #eef2f5     divisor de linha da tabela  #f2f6f8
texto                  #12242f  ·  secundário #455c6b  ·  apoio #6b8494  ·  rótulo #7b93a2
link                   #0f6fc4  (hover #0b5596)
verde de cobrança      #1f7a4d   (borda #1a6b43, fundo suave #e8f3ed, borda suave #cfe6da)
vermelho               #a3232b   (fundo suave #fdf1f0, realce de linha #e5b4ae)
selo Ativo             texto #0c5f7c · fundo #e4f1f6 · borda #c9e3ec
selo Judicializado     texto #8a5a00 · fundo #fdf3e0 · borda #f0dfbd
selo Encerrado         texto #5f7684 · fundo #f1f5f7 · borda #e2eaef
raios                  card 14 · controle 8–9 · selo 6 · pill 999
alturas                botão 34 · campo 34–36 · linha da tabela 60 · cabeçalho da tabela 40
fonte                  Source Sans 3 (o `--jp-font-sans` do app.css)
```

## Pendências

1. **Filtro por Estado** é a **única** peça do desenho que exige back-end. No arquivo ele está
   com **borda tracejada** de propósito, para você reconhecer. Precisa de um parâmetro novo no
   `show()` e no `MontarVisaoCarteiraUseCase` (e o filtro tem de continuar **não** afetando os
   agregados do cabeçalho, igual à busca). Se ficar para depois, **suma com o botão** — não
   deixe desabilitado.
2. Continua valendo o contrato do `filtro-tabela.js`: no XHR o `show()` devolve **só** o
   fragmento `_resultado_casos.html.twig`. Busca, ordenação, filtro e paginação passam todos
   por ele — o cabeçalho e o trilho **não** recarregam.
3. Ordem sugerida de implementação: (a) cabeçalho + 4 KPIs + selo de frescor; (b) card da lista
   com colunas, realce de atraso e rodapé; (c) trilho; (d) modais; (e) estados vazios.
