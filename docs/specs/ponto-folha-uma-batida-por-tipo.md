# Ponto — a folha escolhe uma batida por tipo antes de calcular (INVESTIGAÇÃO)

**Risco:** ALTO (ponto eletrônico, saldo e banco de horas).
**Frente:** `ponto-folha-uma-batida-por-tipo`, base `origin/master` @ `563460a9`.
**Estado:** investigação concluída em 21/09/2026, **zero código**. Para no portão humano (§7).
**Produção:** lida só com `SELECT` pelo MCP somente leitura. Usuários aparecem pelo `user_id`.
**Origem:** o §10 de `docs/specs/ponto-batida-duplicada.md` (branch `ponto-batida-duplicada`).
**Revê:** a medição "64h30 a devolver" de `docs/specs/ponto-batida-que-responde-e-conta-certa.md`
(`c823a617`, 14/09).

## 1. Fluxo real: batidas → folha → calculadora → saldo

1. **Repositório.** `RegistroPontoRepository::findByUserAndCompetencia` (`:25-39`) traz todas as
   batidas do mês, em ordem `dataHora ASC`.
2. **Escolha na folha.** `FolhaPontoBuilder::buildRows` (`:49-70`) monta `registrosPorDia[dia][tipo]`
   com **uma** batida por tipo:
   - entrada, repouso e retorno: a **primeira**;
   - saída: a **última**.
3. **A folha repassa só essa lista.** `$batidasDoDia = array_values($registrosPorDia[$chaveDia])`
   (`:141-143`) vai para:
   - `calcularMinutosTrabalhados` (`:159`, `:164`);
   - `calcularSaldoDia` (`:165`);
   - `registroIncompleto` (`:167`).

   `minutosIntervalo` (`:149-155`) usa o primeiro repouso e o primeiro retorno. As células da tela
   mostram as mesmas batidas escolhidas (`:101-108`).
4. **Calculadora.** `CalculadoraJornada::calcularSaldoDia` (`:31-80`):
   - dia incompleto dá 0;
   - meta pelo `JornadaResolver::resolverMetaDia`: bloco do colaborador, senão bloco do escritório,
     senão os campos antigos;
   - minutos − meta, com tolerância de 5 min para atraso.
5. **Justificativa abonada** (`FolhaPontoBuilder.php:178-195`):
   - esquecimento e categorias técnicas não mudam o saldo;
   - abono parcial soma minutos;
   - abono total zera saldo negativo.
6. **Acumulados.** `calcularSaldoAteMes` (`:321`) e `calcularSaldoAnual` (`:414`) chamam `buildRows`
   mês a mês. O mesmo `buildRows` alimenta:
   - `/ponto` (`PontoController.php:133`);
   - PDF (`:904`) e XLSX (`:988`);
   - a ficha do admin (`TenantController.php:637`);
   - as horas pagas.
7. **Não existe outro caminho.** Em toda a história do repositório, `calcularSaldoDia` e
   `calcularMinutosTrabalhados` só foram chamados pelo `FolhaPontoBuilder` e por eles mesmos
   (`git log -S`). A escolha de uma batida por tipo existe **desde 04/04/2026** (`3f17f87c`) e ficou
   igual até hoje (`d12fb769`, `c0623bb6` só moveram o arquivo).

**Consequência:** com duas batidas do mesmo tipo no dia, a calculadora **nunca** as vê juntas. O par
adjacente de `c823a617` (o último repouso antes do primeiro retorno) só age quando a calculadora é
chamada direto, como nos testes unitários dela.

## 2. Prova reproduzível

Dois testes **temporários**, rodados com `scripts/frente-testar.sh ponto-folha-uma-batida-por-tipo`
e **apagados** depois. Nenhum foi commitado.

### 2.1 Ponta a ponta, pela tela do colaborador

O molde é `HorasPagasFolhaExibicaoTest`:

- colaborador comum;
- jornada com `diasSemana = []`, então meta 0 e saldo do dia = minutos;
- lançamento de horas pagas de −600, para o bloco de totais aparecer;
- `GET /ponto/`.

No dia 1º do mês corrente, as batidas são entrada 08:00, **repouso 09:00 (tipo errado)**, repouso
12:00, retorno 13:00 e saída 18:00.

| | Resultado |
|---|---|
| `CalculadoraJornada` **do container**, com as 5 batidas | **540 min** |
| Tela `/ponto/`, "Saldo do mês" | **+6h00m = 360 min** |

A folha usou o repouso das 09:00: 60 min de manhã e 300 à tarde. O par adjacente daria 240 + 300.

**Para reproduzir:**

1. Copiar a montagem de `tests/Ponto/Functional/HorasPagasFolhaExibicaoTest.php`
   (`criarColaboradorComum`, `criarJornadaSemDiasDeTrabalho`, `criarRegistro`, `criarLancamento`).
2. Criar as 5 batidas acima.
3. Asseverar `calcularMinutosTrabalhados(...) === 540` e o texto `Saldo do mês` → `+6h00m`.

### 2.2 Os dias reais de produção, pelo `buildRows` real

**Quais dias entram.** Nos dias com no máximo uma batida por tipo e com repouso antes do retorno, as
quatro variantes abaixo coincidem pelo próprio código: a escolha da folha não muda nada, e as duas
calculadoras dão os mesmos minutos. **Só podem divergir os dias com tipo repetido ou com o retorno
antes ou junto do repouso:** **64** de 883 pessoa-dias (01/04 a 21/09, escritório 1). A consulta está
no §8.

**Como o teste roda.** Com as batidas desses 64 dias (só tipo e horário), o teste passa cada dia
pelo `FolhaPontoBuilder` real duas vezes:

- uma com a `CalculadoraJornada` atual;
- outra com uma subclasse que devolve o corpo de `calcularMinutosTrabalhados` **anterior** a
  `c823a617`, copiado de `git show c823a617^`.

`c823a617` só mudou esse método.

**As quatro variantes:**

- **A** — calculadora antiga, batidas cruas. **Nunca foi exibida.**
- **B** — calculadora nova, batidas cruas. É o que os testes da calculadora e a medição de 14/09
  enxergam.
- **F_antiga** — a folha até 14/09.
- **F_nova** — a folha de hoje.

## 3. Calculadora isolada × o que a folha usa

| Comparação | Dias diferentes | Diferença total |
|---|---|---|
| F_antiga × A (a folha antiga contra a calculadora antiga isolada) | 42 | a folha já mostrava **+4.876 min** a mais |
| F_nova × B (a folha de hoje contra a calculadora nova isolada) | **8** | a folha mostra **−74 min** |
| **F_nova × F_antiga** (o que o deploy de 14/09 mudou na folha) | **1** | **−538 min** |

O "último retorno sobrescreve", corrigido em 14/09, nunca apareceu na folha: ela já usava o primeiro
retorno. Nos 8 dias de F_nova × B, a diferença vem do repouso. A folha usa o **primeiro**; o par
adjacente usaria o **último antes do retorno**.

## 4. Reavaliação das "64h30"

**Como o número foi calculado.** Na spec de 14/09 ("O número que o dono viu antes de decidir"), é a
diferença **B − A** sobre as batidas cruas. Reproduzido aqui com os dados de hoje:

| | Spec de 14/09 | Reprodução (dias < 14/09) |
|---|---|---|
| Total | +3.867 min (64,5 h) | **+3.870 min (64,5 h)** |
| Dias que tiram horas | 4 (−681) | 4 (−681) |
| Por pessoa (usuários 5 / 9 / 2 / 6 / 11 / 1) | 20,1 / 17,0 / 11,3 / 7,7 / 7,2 / 1,3 h | 20,1 / 17,0 / 11,2 / 7,7 / 7,2 / 1,3 h |

A diferença de 3 min vem de batidas editadas ou apagadas desde 14/09.

**Por que o número não vale para a folha.** Ele compara duas calculadoras sobre batidas que a folha
nunca entrega a elas. A própria spec diz que o `buildRows` alimenta PDF, XLSX, tela e ficha do
admin, mas não viu que ele escolhe uma batida por tipo antes de calcular.

**Números corretos:**

- **A devolver: 0 min.** A folha nunca descontou esses 64h30. Ela já mostrava o primeiro retorno.
  Exemplo, o 11/08 do usuário 9:
  - na folha, o dia vale **551 min** antes e depois de 14/09;
  - o "349 min" (e "agosto −67 min") saiu da calculadora isolada.
- **O que o deploy de 14/09 mudou de fato: 1 dia, contra o colaborador.** Usuário 1, 21/08. As batidas
  são `retorno 04:02 · entrada 09:32 · repouso 13:00 · saída 19:20`:
  - trabalhado: **1.125 → 587 min**;
  - saldo do dia (meta 440): **+685 → +147 min**, ou seja **−538 min (−8h58)** no mês de agosto dele.

  É o caso que a spec de 14/09 descreve como correção certa (o retorno das 04:02 é lixo). É também a
  única mudança retroativa real daquele deploy.
- A ação sugerida na "Frente C" daquela spec **não muda nada na folha**: apagar o `retorno 17:12` de
  11/08 não "devolve +202 min", porque a folha já usa o das 13:50. A batida continua lá; não executar
  por essa razão.

## 5. Impacto atual e histórico

**Histórico, de 04/04 a 21/09:**

- A folha exibida só mudou com o deploy de 14/09 **num dia** (−538 min, usuário 1, agosto).
- Nenhuma folha de outro colaborador mudou por causa daquele commit.
- Esta análise não cobre as mudanças de 05/08 e 31/08 no cálculo (dia incompleto, dia sem almoço).
  Aqui a comparação é só antes × depois de `c823a617`.

**Atual:** a folha diverge da regra do par adjacente em **8 dias, −74 min no total**. É o que uma
correção devolveria aos colaboradores.

- Metas atuais: usuário 1 = 440 (seg–sáb); usuário 3 = 540 (seg–qui) e 480 (sex); usuários 5, 9
  e 12 = 528.
- Nenhum desses dias é feriado, cai na tolerância de 5 min ou tem abono de saldo. Só há esquecimento
  abonado, que não mexe no saldo. Por isso o Δ do saldo = Δ dos minutos.

| Dia | Usuário | Repousos | Folha (min) | Par adjacente (min) | Δ saldo |
|---|---|---|---|---|---|
| 09/04 | 3 | 12:43:00 e 12:43:24 | 399 | 400 | +1 |
| 04/05 | 9 | 4 entre 12:14 e 12:16 | 540 | 542 | +2 |
| 19/06 | 1 | 12:28 e 13:30 | 500 | 562 | **+62** |
| 07/08 | 5 | 12:03:04 e 12:03:34 | 542 | 543 | +1 |
| 14/08 | 9 | 12:49:41 e 12:50:02 | 530 | 531 | +1 |
| 28/08 | 12 | 12:00 (aprovação) e 12:05 | 476 | 481 | +5 |
| 02/09 | 5 | 12:04:43 e 12:05:02 | 537 | 538 | +1 |
| 03/09 | 9 | 3 entre 13:18 e 13:19 | 587 | 588 | +1 |

- **7 dos 8 são duplicatas de repouso** (a C1 da frente `ponto-batida-duplicada`): +1 a +5 min.
- **19/06 é o caso ambíguo** que a spec de 14/09 deixou para o dono decidir. A decisão (almoço menor
  ou maior) nunca chegou à folha. O dia continua com o almoço **maior** (147 min).
- **O defeito que motivou o par adjacente — um repouso cedo, de tipo errado, que apaga a manhã — tem
  0 ocorrências nos dados de hoje.** Existe no código (prova do §2.1) e pode acontecer amanhã.
- **Divergência de exibição, sem efeito no saldo:** o card "suas batidas de hoje" em `/ponto`
  (`PontoController.php:150-155`) mostra a **última** de cada tipo, e a folha, a **primeira**.

## 6. Proposta de correção (não implementada) e riscos

- **(A) Recomendada — uma decisão só.** A calculadora passa a expor a escolha do dia (por exemplo,
  `escolherBatidasDoDia()`, que devolve entrada, repouso, retorno e saída pelas regras dela). O
  `buildRows` usa essa escolha para:
  - as células;
  - os ids de editar e excluir;
  - o `minutosIntervalo`;
  - o saldo.

  O que a tela mostra passa a ser o que a conta usa.
- **(B) Passar todas as batidas à calculadora e manter as células.** Corrige o saldo, mas a tela
  mostraria um repouso e a conta usaria outro. Não recomendo.
- **(C) Manter a folha e alinhar a calculadora a ela.** Voltar para "primeira de cada tipo" e retirar
  o par adjacente, que hoje é código morto na produção. Zero mudança retroativa, mas o repouso cedo
  por engano continua apagando a manhã.

**Riscos:**

- **Retroativo com (A) ou (B):** 8 dias, −74 min hoje. Inclui meses fechados (abril a setembro) e o
  19/06, **+62 min num dia do usuário 1**, que pede decisão antes (almoço menor ou maior).
- **Interação com `ponto-batida-duplicada`:** a D-2 ("preservar a batida que a folha usa") muda de
  sentido para o repouso. Com (A), a folha passa a usar o **último** repouso antes do retorno, e a lista
  da limpeza tem de ser gerada de novo (a condição já está no §9.1 daquela spec). A ordem das duas
  frentes é decisão do dono.
- **Testes:** os testes da calculadora a chamam direto e por isso não pegaram nada. A correção exige
  teste que passe pelo `buildRows` e pela tela, como o §2.1:
  - repouso cedo por engano;
  - repouso repetido;
  - saída repetida;
  - retorno antes do repouso;
  - PDF e XLSX, porque dividem o `buildRows`;
  - prova por reintrodução.
- **Registro corrigido:** as memórias e specs que dão os 64h30 como "já valendo" estão erradas. A
  memória já foi marcada. A spec de 14/09 continua dizendo isso na branch integrada.

## 7. Decisões do dono (portão)

1. **Caminho:**
   - (A) uma decisão só (recomendado);
   - (B);
   - (C) alinhar a calculadora à folha.
2. **19/06:** +62 min pelo par adjacente (almoço menor), ou manter os 147 min de hoje.
3. **Retroativo:** aplicar a correção a meses fechados, ou só dali em diante.
4. **Ordem:** esta frente antes ou depois de `ponto-batida-duplicada`. Ela muda qual repouso a limpeza
   preserva.
5. **Comunicação:** corrigir o registro dos "64h30" junto a quem recebeu o número.

## 8. Consulta de seleção dos 64 dias

```sql
WITH d AS (
  SELECT tenant_id, user_id, data_hora::date dia, count(*) n, count(DISTINCT tipo) nt,
    min(data_hora) FILTER (WHERE tipo='retorno') ret1, max(data_hora) FILTER (WHERE tipo='repouso') repN
  FROM registro_ponto GROUP BY 1,2,3
)
SELECT d.user_id, d.dia, string_agg(r.tipo||'@'||to_char(r.data_hora,'HH24:MI:SS'), ',' ORDER BY r.data_hora)
FROM d JOIN registro_ponto r ON r.tenant_id=d.tenant_id AND r.user_id=d.user_id AND r.data_hora::date=d.dia
WHERE d.n > d.nt OR d.ret1 <= d.repN
GROUP BY d.user_id, d.dia ORDER BY d.dia, d.user_id;
```
