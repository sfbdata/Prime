# Spec — Folha de ponto ignora dias anteriores à admissão

> ⚠️ **SUPERADA por [`ponto-folha-inicio-da-contagem.md`](ponto-folha-inicio-da-contagem.md).**
> A regra descrita aqui (limitar por `data_admissao ?? created_at`) foi ao ar e **causou
> regressão**: colaboradores admitidos anos antes de o sistema existir passaram a acumular
> centenas de horas negativas. Mantida como registro do que foi tentado e por que falhou.

**Risco:** ALTO (ponto eletrônico) · **Deploy:** humano · **Base:** `origin/master`

## Problema

A folha de ponto conta jornada esperada (meta diária) para **todos** os dias do
período, inclusive dias **anteriores à entrada do colaborador na empresa**. Como
esses dias não têm batidas, cada dia útil pré-admissão vira `0 − metaDia` = saldo
fortemente negativo, inflando um débito **fantasma** no banco de horas.

Caso real: colaboradora admitida em **18/05/2026**; a folha de maio contava
04–15/05 como −8h48/dia (~−88h que ela nunca deveu).

### Mecanismo (por que acontece mesmo com dias ocultos)

Em `FolhaPontoBuilder::buildRows`, o acumulador soma o saldo do dia
(`$saldoAcumulado += $saldoDia`, linha ~160) **antes** do filtro de dias vazios
(`$includeEmptyDays`, linha ~171). Logo, mesmo quando a **linha** do dia
pré-admissão é ocultada (`includeEmptyDays=false`), o **−8h48 já foi absorvido**
pelo `saldoAcumulado` e contamina o banco exibido. Nos exports (`includeEmptyDays=true`),
o dia ainda aparece com saldo negativo.

### O que existe hoje

- `buildRows` (mensal): **não** limita por nenhuma data de início.
- `calcularSaldoAteMes` / `calcularSaldoAnual`: limitam por `user.getCreatedAt()`
  — mas `createdAt` é a data de **criação do registro** (p.ex. 20/05), não a de
  **admissão** (18/05), então exclui indevidamente os primeiros dias trabalhados.
- O sistema **já tem** a data de admissão correta: `UserTenant::getDataAdmissao()`
  (usada no cabeçalho do export XLSX).

## Objetivo

A folha (visão mensal, saldo anterior e anual) deve **desconsiderar dias
anteriores ao início do vínculo**, tratando-os como dias sem meta — sem saldo e
**sem somar no banco** —, exatamente como já faz com dias futuros.

**Início do vínculo** = `dataAdmissao ?? createdAt` (fallback), normalizado a 00:00.
Preferir sempre a data de admissão; cair em `createdAt` só quando a admissão não
estiver preenchida.

## Contrato

### `FolhaPontoBuilder::buildRows`

- Novo parâmetro **opcional** ao final: `?\DateTimeInterface $inicioVinculo = null`
  — a data de início **já resolvida pelo chamador** (`dataAdmissao ?? createdAt`).
  Assinatura atual preservada; `null` = comportamento atual (nenhum bound novo).
  **Sem fallback interno**: `buildRows` não olha `createdAt` sozinho, senão os
  chamadores atuais (cujo `User` tem `createdAt = now()` por construção) teriam o
  período todo tratado como pré-admissão. O fallback mora no chamador.
- Normaliza `inicioVinculo` a 00:00.
- Para cada dia com `dia < inicioVinculo`: mesma mecânica de dia futuro —
  `minutosTrabalhadosDia`, `saldoDia`, `saldoAcumulado` = `null` e **não** entra
  no `$saldoAcumulado`. Condição vira `if ($diaFuturo || $diaAntesVinculo)`.
- Nova chave na linha: `'antesAdmissao' => bool` (default `false`), para o template
  poder distinguir depois. Não exige mudança de template neste escopo (saldo `null`
  já renderiza em branco).

### `calcularSaldoAteMes` / `calcularSaldoAnual`

- Novo parâmetro **opcional** ao final: `?\DateTimeInterface $dataAdmissao = null`.
- `inicioVinculo = dataAdmissao ?? createdAt`; `inicio` do range passa a ser
  limitado por `inicioVinculo` (em vez de só `createdAt`). O guard "retorna 0"
  vale quando **ambos** forem `null`.
- Repassam `inicioVinculo` ao `buildRows` interno (consistência do bound).

### Chamadores (passam `userTenant?->getDataAdmissao()`)

| Arquivo | Linha | Método/uso |
|---|---|---|
| `PontoController` | ~117 | folha mensal (index) |
| `PontoController` | ~137 | `calcularSaldoAnual` (banco do ano) |
| `PontoController` | ~762 | export PDF |
| `PontoController` | ~845 | export XLSX |
| `PontoController` | ~961 | `calcularSaldoAteMes` (saldo anterior) |
| `TenantController` | ~563 | folha ponto (visão admin do tenant) |

O `UserTenant` do (colaborador alvo, tenant atual) já é resolvido nesses fluxos
via `UserTenantRepository`; onde ainda não estiver, resolver antes da chamada.

## Testes (TDD, unit — `FolhaPontoBuilderTest`, TestCase puro)

1. `buildRows` com `inicioVinculo` no meio do mês: dias antes → `saldoDia === null`
   e `antesAdmissao === true`; dias a partir do vínculo contam normalmente.
2. `buildRows` com `inicioVinculo` no meio do mês **não soma** os dias anteriores no
   `saldoAcumulado` (o banco do primeiro dia válido não carrega o débito fantasma).
3. `buildRows` sem `inicioVinculo` (null) → comportamento atual: todos os dias
   contam (garantia de não-quebra dos chamadores existentes).
4. `buildRows` `inicioVinculo` anterior ao início do mês → nenhum efeito (todos contam).
5. `buildRows` `inicioVinculo` posterior ao fim do mês → todos os dias `null` /
   `antesAdmissao`.

## Fora de escopo

- **Não** altera o cálculo de saldo por dia (`CalculadoraJornada`) nem a régua de
  meta/jornada.
- **Não** altera `createdAt` de ninguém.
- **Não** redesenha o template (só disponibiliza o flag `antesAdmissao`).
- Correção dos dados da colaboradora (setar `data_admissao = 18/05` e abonos já
  aplicados) é operação de **dado em produção**, à parte deste código.

## Rollback

Mudança isolada no `FolhaPontoBuilder` + passagem de um argumento nos chamadores.
Parâmetros opcionais com default `null` tornam a mudança não-quebrante: reverter =
remover o argumento nas chamadas e o bound no builder.
