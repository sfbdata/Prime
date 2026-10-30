# Cálculo: aba de atualização monetária (desenho 14, 30/10/2026)

> **Pedido:** Samuel, 30/10/2026, já aprovado pelo dono. Desenho:
> `docs/design/redesign-30-10-26/14 - Calculo - VS 30.10.26.dc.html` e PDF de exemplo
> `14 - Calculo - exemplo de PDF 30-10-2026.pdf` (mesma pasta).
>
> **Risco:** MÉDIO. Não toca ponto, User/Tenant nem perfis além de uma permissão nova de módulo, mas
> produz número de dinheiro que vai para peça judicial. Spec antes do código; teste antes do motor.

## 1. O que é

Aba **Cálculo** no menu lateral, página única `Atualização monetária`: o advogado informa valores
devidos (data e valor), escolhe índice, juros, incidência, multa, honorários e abatimentos, clica em
**Calcular** e recebe o demonstrativo na tela e o PDF. Sem gravar nada no banco (o formulário fica
no navegador, como no desenho).

## 2. Decisões

| # | Decisão | Escolha | Motivo |
|---|---|---|---|
| 1 | Onde calcula | **Servidor, em PHP** (`app/src/Calculo/`), nunca no JS | dinheiro precisa de teste de unidade; o JS do desenho é a referência da regra, não do código |
| 2 | Aritmética | `float` (IEEE 754 de 64 bits), arredondando **só na exibição** (2 casas; fator 6 casas; % de juros 6 casas) | é o que o desenho faz; com isso os números do PDF de exemplo saem idênticos. `bcmath` mudaria centavos em relação ao exemplo |
| 3 | Índices | tabela global existente `indice_monetario` (`app/src/AtualizacaoMonetaria/`), séries **INPC** (SGS 188), **IPCA** (SGS 433) e nova **SELIC** (SGS 4390, mensal acumulada) | reaproveita entidade, repositório, cliente SGS e importador mensal (cron dia 11). `TAXA_LEGAL` (SGS 29543) **não** é usada: o desenho e o PDF calculam a taxa legal como Selic menos IPCA |
| 4 | Série nova | `SerieIndice::SELIC` (`varchar(20)`, **sem mudança de esquema**) | o importador já percorre `SerieIndice::cases()` |
| 5 | "Atualizar índices" | botão chama o mesmo importador, extraído do comando para um serviço (`ImportadorDeIndices`); o comando passa a usá-lo, comportamento idêntico (testes existentes garantem) | evita duas lógicas de importação. Guardas mantidas: série vazia alarma e não apaga; revisão de índice é registrada |
| 6 | Atualização automática | ao abrir a página, se a última importação tem mais de 3 dias (ou falta série), o JS dispara a atualização em segundo plano (equivale ao cache de 3 dias do desenho) | a página nunca bloqueia no BCB |
| 7 | PDF | **dompdf** (já no projeto, `GeradorDePdf`), gerado no servidor, mesma estrutura do exemplo: cabeçalho com logo e escritório, 3 cartões (Moeda, Atualização monetária, Juros), tabela "Demonstrativo dos valores principais", tabela de abatimentos (se houver), "Agrupamento dos valores apurados", Nota, rodapé com código de verificação | o desenho gera com html2pdf no navegador (rasteriza); o servidor gera texto selecionável. Layout por tabelas (dompdf não tem grid/flex) |
| 8 | Código de verificação | `BJC-` + 8 caracteres base 36 maiúsculos (tempo + aleatório), impresso no rodapé do PDF | igual ao exemplo. **Não há tela de verificação no desenho**: o código não é gravado nem consultável. Pendência de produto, não implementada |
| 9 | Permissão | módulo `calculo`, permissão `modules.calculo.view` (padrão dos módulos, `PermissionChecker::canAccessModule`) | `docs/AUTORIZACAO.md`. Migration idempotente insere a permissão; perfis de sistema passam por bypass; demais perfis ganham no painel de Perfis |
| 10 | Menu | um item **Cálculo** em `_sidebar.html.twig`, ícone `bi-calculator`, ativo em rotas `calculo_*`. Nenhuma outra área muda de visual | pedido do Coordenador |
| 11 | Relógio | o servidor usa `ClockInterface` (fuso `America/Sao_Paulo`) para "hoje" (parcela futura) e para `Atualizar até` padrão | testável |

## 3. Regra de cálculo (idêntica ao JS do desenho, `calc()`)

Entradas: `ate` (data final), `idx` (`oficial` | `inpc` | `nenhum`), `proj` (`media` | `nao`),
`jur` (`legal` | `1` | `05` | `nenhum`), `ini` (`valores` | `citacao` | `fixa`), `dJur` (data),
`multa` e `hon` (%), `m523` e `h523` (bool), `vals[]` e `abats[]` (data, valor, descrição).
Valor monetário em `pt-BR` (`1.234,56`) vira float.

1. **Competência** `mk(d)` = `AAAA-MM` de `d`. **Taxa do mês k:** `idx=nenhum` → 0; `idx=inpc` ou
   `k < 2024-09` → INPC(k); senão IPCA(k). Mês sem índice publicado: com `proj=media` usa a
   **média aritmética dos últimos 12 meses publicados da própria série** (e marca o valor como
   projetado); com `proj=nao` fica sem taxa (conta como "mês sem índice").
2. **Fator** `F(d0,d1)`: produto de `1 + taxa(k)/100` para cada `k` de `mk(d0)` **até antes** de
   `mk(d1)` (o mês de `d1` não entra; `d0 > d1` dá fator 1).
3. **Juros acumulados em %** `J(d0,d1)`, com `dias(a,b)` = diferença em dias corridos:
   - `jur=nenhum` ou `d0 >= d1` → 0.
   - `jur=1` ou `05`: `dias(d0,d1)/30 × (1 | 0,5)`.
   - `jur=legal`, corte `2024-08-30`: antes do corte, `dias(d0, min(d1,corte))/30` (1% ao mês).
     A partir do corte, mês a mês: `max(0, Selic(k) − IPCA(k)) × dias(trecho)/diasDoMês(k)`, com
     Selic/IPCA do mês faltante projetados pela média de 12 meses quando `proj=media` (sem
     projeção, faltante vale 0).
4. **Por valor** (ordenado por data): `corrigido = valor × F(data, ate)`; início dos juros `ji` =
   data do valor (`valores`), `dJur` (`fixa`) ou o maior entre data do valor e `dJur`
   (`citacao`); `juros = corrigido × J(ji, ate) / 100`; `total = corrigido + juros`.
   `projecao` (selo PROJEÇÃO) = `data > hoje`. O PDF e a tela usam o selo quando `data > ate`
   e `data > hoje` (parcela que ainda vai vencer).
5. **Agrupamento:** `subtotal = Σ total`; `multa = subtotal × multa%`; `base = subtotal + multa`;
   `honorários = base × hon%`; **abatimentos** corrigidos por `F(data, ate)` (sem juros);
   `débito = base + honorários − Σ abatimentos corrigidos`; `multa 523 = débito × 10%` e
   `honorários 523 = débito × 10%` se marcados; `total = débito + multa 523 + honorários 523`.
6. **Avisos:** meses sem índice (`proj=nao`) → "Atenção: N mês(es) do período ainda não têm
   índice publicado e foram considerados sem correção." (só valores, não abatimentos). Parcelas com
   valor projetado ou a vencer → "N parcela(s) com valores projetados: …".

### Validações (mensagens e botão "Ir para…" do desenho)

Sem valor (`nvV`), valor sem data (`nvD`), data sem valor (`nvV`), `ate` vazio, índices não
carregados (quando `idx≠nenhum` ou `jur=legal`), `ini≠valores` sem `dJur`. Textos literais no
catálogo de tradução do domínio.

## 4. Prova (testes que reproduzem o PDF de exemplo)

Fixture: `tests/Calculo/Fixtures/series-bcb-30-10-2026.json` (séries reais do BCB consultadas em
30/10/2026, de 01/2023). Caso do exemplo: valor R$ 100,00 + 12 parcelas de R$ 1.000,00 (25/08/2026
a 25/07/2027), `ate` 30/10/2026, `oficial`, `legal`, `valores`, `media`, multa 20%, honorários
15%, 523 multa e honorários. Esperado (linha a linha e agrupamento): fator `1,004974`, `1,008200`,
`1,000000`; juros % `1,182554`, `0,656167`, `0,104167`; subtotal **12.134,40**; multa 2.426,88;
honorários 2.184,19; débito 16.745,47; multa 523 1.674,55; honorários 523 1.674,55; **total
20.094,57**. Os valores de referência dos demais cenários (INPC puro, juros 1% e 0,5%, data fixa,
citação, abatimento, sem projeção) foram gerados por porte independente do JS do desenho e
congelados em `tests/Calculo/Fixtures/casos-referencia.json`.

## 5. Estrutura

`app/src/Calculo/`: `Controller/CalculoController` (GET `/calculo`; POST `/calculo/calcular`,
`/calculo/indices/atualizar`, `/calculo/pdf`; rotas `calculo_*`), `UseCase/` (`CalcularAtualizacao`,
`AtualizarIndices`, `GerarPdfDoCalculo`), `Service/CalculadoraDeAtualizacao` (puro),
`DTO/CalculoInput`, `ResultadoDoCalculo`, `LinhaDoCalculo`. Tela por `templates/calculo/` e
`public/css/calculo.css`, `public/js/calculo.js` (estado em `localStorage`, como no desenho).
Migration: `Version20261030110000` só cria `modules.calculo.view`.

## 6. Fora de escopo

Tela de verificação do código do PDF; gravar cálculos por escritório; cálculos fazendários (o
próprio rodapé do desenho avisa que a rotina não os atende); mudar o visual de qualquer outra área.
