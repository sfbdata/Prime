# Spec — Início da contagem da folha de ponto

**Risco:** ALTO (ponto eletrônico) · **Deploy:** humano · **Base:** `origin/master`
**Substitui:** `ponto-folha-ignora-dias-pre-admissao.md` (regra anterior, revertida — ver Histórico)

## Regra

A folha conta **a partir do registro de ponto mais antigo do colaborador naquele escritório**, e daí
em diante. Registro é a **batida** real ou uma **justificativa já abonada** — abono deferido também
é registro.

`data_admissao` e `created_at` **não participam do cálculo**. Seguem existindo como registro (a
admissão aparece no cabeçalho da folha/XLSX), mas não decidem o que entra no banco de horas.

**Janela do abono:** um abono só puxa o início para trás se cair **até 30 dias antes da primeira
batida** (`InicioContagemResolver::JANELA_ABONO_DIAS`). Sem esse limite, um abono retroativo antigo
— o sistema aceita qualquer data passada — abriria dezenas de dias úteis sem batida como débito,
recriando o mesmo fantasma que esta regra existe para matar. A janela cobre o caso real: esqueceu
de bater os primeiros dias e o admin deferiu abono depois. Quando **não há batida alguma**, o abono
mais antigo abre a contagem (não há âncora para janela).

### Invariantes

1. **Antes do primeiro registro → não conta.** Sem meta, sem saldo, não soma no banco.
2. **Sem nenhum registro → não conta nada.** Colaborador que nunca registrou ponto tem saldo 0, não
   um débito fantasma de todos os dias úteis.
3. **Depois do primeiro registro, buraco é falta.** Dia sem batida posterior ao início segue gerando
   débito — é ausência real. Só o "antes do primeiro registro" é ignorado.

## Histórico — por que a regra anterior falhou

A primeira versão limitava por `data_admissao ?? created_at`. Consertava o recém-contratado, mas
**quebrou os veteranos**: quem foi admitido antes de o sistema existir e só foi cadastrado
recentemente passou a contar desde 01/01 do ano corrente, acumulando centenas de horas negativas em
meses nos quais sequer estava no sistema. Medido em produção: **7 colaboradores**, de 37 a 90 dias
de fantasma (o pior ≈ 512 h).

A lição: `data_admissao` responde *"desde quando a pessoa é funcionária"*; `created_at`, *"desde
quando o cadastro existe"*. Nenhuma responde *"desde quando há controle de ponto para esta pessoa"* —
e é essa a pergunta da folha. O primeiro registro responde, com dado real em vez de heurística.

## Contrato

### Repositórios (filtro de tenant explícito, além do TenantFilter)

```php
RegistroPontoRepository::findDataPrimeiraBatida(User $user, Tenant $tenant): ?\DateTimeImmutable
JustificativaPontoRepository::findDataPrimeiraAbonada(User $user, Tenant $tenant, ?\DateTimeInterface $aPartirDe = null): ?\DateTimeImmutable
```
`MIN` da respectiva data; `null` se não houver registro no recorte. A segunda considera apenas
`status = 'abonado'`; `$aPartirDe` limita por baixo (é o piso da janela — sem ele, o `MIN` pegaria
um abono antigo e ignoraria outro, mais recente, que está dentro da janela).

### `InicioContagemResolver`

```php
resolver(User $user, Tenant $tenant): ?\DateTimeImmutable
```
Sem batida → abono mais antigo (sem janela). Com batida → a mais antiga entre a batida e o abono
**dentro da janela**. `null` se não houver nenhum registro.

### `FolhaPontoBuilder`

```php
buildRows(..., ?JornadaTenant $jornadaTenant = null, \DateTimeInterface|null|false $inicioContagem = false): array
calcularSaldoAteMes(User, int $ano, int $mes, array $feriados, ?JornadaTenant = null, \DateTimeInterface|null|false $inicioContagem = false): int
calcularSaldoAnual(User, int $ano, array $feriados, ?JornadaTenant = null, \DateTimeInterface|null|false $inicioContagem = false): int
```
- **Omitir o parâmetro lança `InvalidArgumentException`.** O default é a sentinela `false`, não
  `null`: `null` significa "sem registro de ponto" e a semântica do valor omitido se inverteu (antes
  "conta tudo", agora "não conta nada"), então uma chamada antiga sobrevivendo a um merge daria
  saldo errado **em silêncio**. A sentinela transforma isso em falha imediata.
- `buildRows`: dias `< inicioContagem` (normalizado a 00:00) recebem o tratamento de dia futuro —
  `null` em minutos/saldo/acumulado e fora do acumulador. Nova chave na linha:
  `antesDoPrimeiroRegistro: bool`. Não consulta `createdAt` nem `dataAdmissao`.
- Acumulados: `inicioContagem === null` → `0`; senão `inicio = max(inicioContagem, 01/01/ano)`,
  repassando ao `buildRows`.

### Chamadores (6)

Resolvem via `InicioContagemResolver::resolver($user, $tenant)` (injetado no construtor dos dois
controllers) e repassam:

| Arquivo | Uso |
|---|---|
| `PontoController` | folha mensal · `calcularSaldoAnual` · export PDF · export XLSX · `calcularSaldoAteMes` (via `montarDadosFolha`) |
| `TenantController` | folha do colaborador na visão do escritório |

O `UserTenant` segue lido onde já era (cargo, lotação, admissão do cabeçalho) — só deixou de
alimentar o cálculo.

## Testes

**Unit — `FolhaPontoBuilderTest`:** dias antes do início não contam e marcam
`antesDoPrimeiroRegistro`; não entram no `saldoAcumulado`; `null` → nenhum dia conta; início antes
do mês não tem efeito; início depois do mês zera tudo; buraco posterior ao início segue falta;
**omitir o parâmetro lança**; acumulados com `null` → 0 (com `createdAt` fixado em 2020, para
falharem na regra que quebrou a produção).

**Unit — `InicioContagemResolverTest`:** só batida; só abono; abono dentro da janela puxa; **abono
fora da janela não puxa**; batida anterior ao abono manda; nenhum registro → `null`.

**Functional — `PontoIsolamentoRepositoryTest`** (SQL real): isolamento por tenant das duas
consultas; `null` sem registro; "a mais antiga"; abono **não-abonado** (pendente/rejeitado) ignorado;
piso da janela respeitado.

## Impacto no dado existente

A regra **muda saldo retroativo**: é o objetivo. Antes de publicar, rodar a comparação por
(usuário, escritório) entre o início antigo e o novo e conferir a lista. Medição de produção em
2026-07: 7 colaboradores deixam de acumular fantasma (37–90 dias cada); 4 cadastros sem batida
alguma param de contar; 1 caso (YLKA) tem a contagem **antecipada em 3 dias** — conferir esses dias.

## Fora de escopo

- Não altera o cálculo do saldo do dia (`CalculadoraJornada`) nem a régua de jornada.
- Não altera `data_admissao`/`created_at` de ninguém.
- Não redesenha template (só disponibiliza `antesDoPrimeiroRegistro`).
