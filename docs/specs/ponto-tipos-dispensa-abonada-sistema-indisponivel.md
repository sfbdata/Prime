# Spec — Novos tipos de justificativa de ponto: Dispensa Abonada e Sistema Indisponível

## Motivo
Ampliar os tipos de justificativa de falta/ausência do **ponto eletrônico** (risco ALTO)
com dois novos motivos pedidos pelo usuário:
- **Dispensa Abonada** — dispensa concedida/autorizada pelo escritório.
- **Sistema Indisponível** — o sistema de ponto ficou fora do ar e o colaborador não pôde registrar.

Cada um pode ser **dia inteiro** ou **abono parcial** (só algumas horas do dia).

## Invariante-chave (por que a mudança é mínima)
A distinção **dia inteiro vs. abono parcial já existe e é genérica** — não depende do tipo:
- `JustificativaPonto` tem `abonoParcial: bool` + `horaInicioAbono`/`horaFimAbono`, e
  `getMinutosAbonados()` calcula a diferença.
- `FolhaPontoBuilder::buildRows` (`src/Ponto/Service/FolhaPontoBuilder.php`, ~linhas 145-157)
  trata **qualquer** tipo abonável (`status = 'abonado'`) no ramo genérico:
  - **abono parcial** → `saldoDia += getMinutosAbonados()`;
  - **dia inteiro** → se `saldoDia < 0`, zera (abona a falta do dia).
  Só `falta_nao_justificada` e `esquecimento_registro` têm tratamento especial.

Logo, **os dois novos tipos usam o ramo genérico** e funcionam com dia inteiro ou parcial sem nova
lógica. O `<select>` (`TipoJustificativa::asGroupedChoices()`) e o JS de `_justificativa_campos.html.twig`
já exibem o checkbox "Abono parcial" para todo tipo que não seja `falta_nao_justificada`/`esquecimento_registro`.

## Decisões
| Tipo | Slug | Label | Categoria |
|---|---|---|---|
| Dispensa Abonada | `dispensa_abonada` | `Dispensa Abonada` | **Operacionais** (`CategoriaJustificativa::Operacional`) |
| Sistema Indisponível | `sistema_indisponivel` | `Sistema Indisponível` | **Intercorrências** (`CategoriaJustificativa::Intercorrencia`) |

- `status` inicial `pendente` (exige aprovação do gestor, como os demais tipos abonáveis).
- Anexo/comprovante opcional. Dia inteiro **ou** abono parcial (regras de abono parcial inalteradas:
  1 data só, `horaInicio < horaFim`).

## O que muda / o que não muda
- **Muda:** `src/Ponto/Enum/TipoJustificativa.php` — 2 cases + entradas em `label()` e `categoria()`.
- **Não muda:** cálculo (`FolhaPontoBuilder`), controller (`PontoController`), form
  (`JustificativaPontoType`), entity, repository, templates, migration — o schema (`tipo VARCHAR(80)`)
  e o `ChoiceType` (choices = `asGroupedChoices()`) já comportam os novos slugs.

## Testes (travam o comportamento — risco ALTO)
- **Enum** (`tests/Ponto/Unit/TipoJustificativaTest.php`): para cada novo tipo, `label()`, `categoria()`,
  presença em `valores()` e no grupo correto de `asGroupedChoices()`; e que `label()`/`categoria()` não
  lançam para **nenhum** case (`self::cases()`) — guarda a exaustividade dos `match`.
- **Cálculo** (`tests/Ponto/Unit/FolhaPontoBuilderTest.php`):
  - `dispensa_abonada` **dia inteiro** (`abonoParcial=false`, `abonado`): dia sem batidas → `saldoDia = 0`,
    `justificadoDia = true`.
  - `sistema_indisponivel` **abono parcial** (`abonoParcial=true`, horas setadas, `abonado`):
    `saldoDia += getMinutosAbonados()`, `justificadoDia = true`.

## Não-objetivos
- Não altera o fluxo de aprovação/recusa, nem a lógica de abono existente.
- Não cria migration (coluna `tipo` já é `VARCHAR(80)`).
