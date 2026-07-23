# Spec — Início da contagem da folha de ponto

**Risco:** ALTO (ponto eletrônico) · **Deploy:** humano · **Base:** `origin/master`
**Substitui:** `ponto-folha-ignora-dias-pre-admissao.md` (regra anterior, revertida — ver Histórico)

## Regra

A folha de ponto conta **a partir do registro de ponto mais antigo do colaborador naquele
escritório** — seja uma **batida** real, seja uma **justificativa já abonada** —, e daí em diante.
Um abono deferido também é registro de ponto: se o colaborador esqueceu de bater os primeiros dias
e o admin deferiu abono retroativo, a contagem abre ali.

`data_admissao` e `created_at` **não participam do cálculo**. Continuam existindo como
**registro** (a admissão aparece no cabeçalho da folha/XLSX), mas não decidem o que entra
no banco de horas.

### Invariantes

1. **Antes do primeiro registro → não conta.** Nenhuma meta, nenhum saldo, não soma no banco.
2. **Sem nenhuma batida → não conta nada.** Colaborador que nunca registrou ponto tem saldo 0,
   e não um débito fantasma de todos os dias úteis.
3. **Depois do primeiro registro, buraco é falta.** Dia sem batida posterior ao início da
   contagem continua gerando débito — é ausência real. Só o "antes do primeiro registro" é
   ignorado.

## Histórico — por que a regra anterior falhou

A primeira versão limitava por `data_admissao ?? created_at`. Ela consertava o caso do
recém-contratado, mas **quebrou os veteranos**: quem foi admitido anos antes do sistema existir
e só foi cadastrado recentemente passou a contar desde **01/01 do ano corrente** (a admissão
antiga perdia para o início do ano), acumulando **centenas de horas negativas** em meses nos
quais a pessoa sequer estava no sistema.

A lição: `data_admissao` responde *"desde quando a pessoa é funcionária"*; `created_at`,
*"desde quando o registro existe"*. Nenhuma das duas responde *"desde quando há controle de
ponto para esta pessoa"* — e é essa a pergunta que a folha precisa fazer. A primeira batida
responde exatamente isso, com dado real em vez de heurística.

## Contrato

### Repositórios (ambos com filtro de tenant explícito, além do TenantFilter)

```php
RegistroPontoRepository::findDataPrimeiraBatida(User $user, Tenant $tenant): ?\DateTimeImmutable
JustificativaPontoRepository::findDataPrimeiraAbonada(User $user, Tenant $tenant): ?\DateTimeImmutable
```
`MIN` da respectiva data no tenant informado; `null` quando não há nenhum registro daquele tipo.
A segunda considera apenas justificativas com `status = 'abonado'`.

### `InicioContagemResolver` (novo)

```php
resolver(User $user, Tenant $tenant): ?\DateTimeImmutable
```
Devolve a **mais antiga** entre a primeira batida e o primeiro abono; `null` se não houver nenhum
dos dois. Centraliza a regra para os 6 chamadores não a reimplementarem cada um.

### `FolhaPontoBuilder::buildRows`

- Parâmetro ao final: `?\DateTimeInterface $inicioContagem = null`.
- `inicioContagem === null` → **nenhum dia conta** (invariante 2): `minutosTrabalhadosDia`,
  `saldoDia` e `saldoAcumulado` ficam `null` e nada entra no acumulador.
- Caso contrário, dias `< inicioContagem` (normalizado a 00:00) recebem o mesmo tratamento de
  dia futuro — `null` e fora do acumulador.
- Nova chave na linha: `antesDoPrimeiroRegistro: bool`.
- **Não** consulta `createdAt` nem `dataAdmissao`.

### `calcularSaldoAteMes` / `calcularSaldoAnual`

- Parâmetro ao final: `?\DateTimeInterface $inicioContagem = null`.
- `inicioContagem === null` → retorna `0`.
- `inicio = max(inicioContagem, 01/01/ano)`; repassa `inicioContagem` ao `buildRows`.
- **Removem** o uso de `createdAt` (e o guard associado) e de `dataAdmissao`.

### Chamadores (6)

Resolvem `inicioContagem` via `InicioContagemResolver::resolver($user, $tenant)` e passam adiante:

| Arquivo | Uso |
|---|---|
| `PontoController` | folha mensal · `calcularSaldoAnual` · export PDF · export XLSX · `calcularSaldoAteMes` |
| `TenantController` | folha do colaborador na visão do escritório |

O `UserTenant` continua sendo lido onde já era (cargo, lotação, admissão do cabeçalho) — só
deixa de alimentar o cálculo.

## Testes

1. `buildRows` com `inicioContagem` no meio do mês: dias antes → `saldoDia null` e
   `antesDoPrimeiroRegistro true`; dias a partir dele contam.
2. `buildRows` não soma os dias anteriores no `saldoAcumulado`.
3. **`buildRows` com `inicioContagem = null` → nenhum dia conta** (invariante 2).
4. `inicioContagem` anterior ao mês → nenhum efeito; posterior ao mês → nada conta.
5. **Cenário do veterano** (o que quebrou): início da contagem no meio do ano → meses
   anteriores não entram no `calcularSaldoAnual`.
6. `calcularSaldoAteMes`/`Anual` com `inicioContagem = null` → 0.
7. Dia sem batida **posterior** ao início da contagem continua negativo (invariante 3).

## Fora de escopo

- Não altera o cálculo de saldo do dia (`CalculadoraJornada`) nem a régua de jornada.
- Não altera `data_admissao`/`created_at` de ninguém (seguem como registro).
- Não redesenha template (só disponibiliza a chave `antesDoPrimeiroRegistro`).
