# Ponto — a folha escolhe uma batida por tipo antes de calcular (INVESTIGAÇÃO)

**Risco:** ALTO (ponto eletrônico, saldo e banco de horas).
**Frente:** `ponto-folha-uma-batida-por-tipo`, base `origin/master` @ `563460a9`.
**Estado:** implementado e validado na frente em 21/09/2026 (§10.5). **Não integrado, não publicado,
sem migration.** Para no portão humano (§10.6). Decisões: §7.1 e §9.9. Plano: §9 e §10.
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

### 7.1 Decisão do dono (21/09/2026)

Aprovado para planejamento, **sem implementar ainda**.

1. **Caminho A, conceitualmente.** Uma **única decisão** diz quais batidas do dia valem. A folha, as
   células exibidas, o intervalo e o saldo usam **exatamente essas** batidas.
2. **19/06 (+62 min) é ambíguo.** Não alterar automaticamente: preservar o resultado atual até uma
   decisão humana específica sobre ele.
3. **Meses fechados não mudam sozinhos.** A correção da lógica não pode recalcular em silêncio o
   histórico fechado. O comportamento futuro e um eventual saneamento do histórico ficam separados.
4. **Ordem planejada das frentes:**
   1. `ponto-folha-uma-batida-por-tipo`;
   2. `ponto-batida-duplicada`;
   3. `ponto-batida-duplicada-reaprovacao`.
5. **Documentação dos "64h30" corrigida** nesta branch (`docs/specs/ponto-batida-que-responde-e-conta-certa.md`),
   com registro explícito de que a conclusão foi **refutada**:
   - os 3.870 minutos vieram da comparação direta entre calculadoras, cada uma recebendo todas as
     batidas;
   - a folha real já reduzia o dia a uma batida por tipo;
   - **não havia 64h30 refletidas na folha a devolver.**

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

## 9. Plano técnico da solução A (não implementado)

### 9.1 O que já existe para preservar o histórico

**Não existe no Ponto um conceito confiável de competência fechada.**

Fontes:

- levantamento de um investigador só de leitura, sobre o código de `563460a9`;
- consultas somente leitura à produção;
- conferência própria dos pontos marcados com ✓.

| Candidato | O que é de fato | Serve para preservar o histórico? |
|---|---|---|
| Tabela ou coluna de fechamento, folha emitida ou folha assinada | **Não existe**: nem nas migrations nem no `information_schema` da produção | — |
| `ponto_lancamento_horas_pagas` (ano/mês/minutos) | Ajuste manual do banco, editável e excluível à vontade (spec de horas pagas, "Editar e excluir livremente"). **0 linhas em produção** ✓ | Não: não guarda saldo e não trava nada |
| "Bloco assinado" do PDF/XLSX (`folha_pdf.html.twig:330-351`) | Linhas para assinar **à caneta**. Nada é gravado ao exportar. `tenant.responsavel_assinatura` é só o nome impresso | Não. A folha assinada só existe no papel |
| `InicioContagemResolver` | Data da 1ª batida, calculada a cada leitura; muda com batida ou abono retroativo | Não: é móvel |
| Zeramento do banco em 1º/jan | Único corte fixo do cálculo | Não é fechamento de mês |
| `/ponto` do colaborador mostrando só o mês atual e o anterior (`PontoController.php:94-99`) ✓ | Filtro de tela. Admin, PDF e XLSX alcançam qualquer mês | Não |
| Recusa de alterar período passado | **Não existe.** Batida, justificativa, reversão e jornada: tudo edita qualquer data. A C2-01 trava a justificativa **por estado**, não por período | Não |
| Vigência de regra de cálculo | **Não existe.** A única vigência do Ponto é `home_office_config.vigencia_inicio/fim`, que decide a batida do dia e não o saldo | — |
| Mudanças anteriores de cálculo (05/08, 31/08, início da contagem, 14/09) | **Todas retroativas, sem corte** ✓ (`ponto-abono-nao-perdoa-jornada.md:120`, `ponto-registro-incompleto-entrada-saida.md:3`) | — |

**Precedentes de desenho fora do Ponto:**

- **Versão vigente por data como constante no código:** `TermoVigente::VERSAO = '2026-06-23'` ✓
  (`app/src/Termo/TermoVigente.php:16`). A vigência do §9.4 segue esse modelo.
- **Congelamento de resultado em estado fechado:** `Obrigacao.encargosCongeladosEm`/`liquidadaEm` na
  Cobrança, que guardam um retrato e não recalculam. É o modelo para um **fechamento verdadeiro** no
  futuro, se o dono quiser imutabilidade. Não é proposto aqui.

**Consequência para o plano:** "competência fechada" não é estado do sistema, só calendário. Por isso
a proteção **não pode depender** de saber se um mês está fechado. A vigência por data congela a regra
para **todo** dia anterior a ela, fechado ou não, sem precisar definir fechamento.

### 9.2 Os 7 dias (além do 19/06), por competência

É o resultado da A "pura" (par adjacente sobre todas as batidas do dia), a mesma do §5.

- Metas de hoje.
- Nenhum feriado, nenhum abono de saldo, nenhum dia dentro da tolerância, nenhum dia incompleto.
- Hoje é 21/09, então setembro/2026 é a competência corrente.

| Dia | Usuário | Competência | Situação (só calendário: não há fechamento no sistema, §9.1) | Repousos | Classe (frente de duplicatas) | Folha hoje → A pura |
|---|---|---|---|---|---|---|
| 09/04 | 3 | 04/2026 | encerrada pelo calendário | 12:43:00 e 12:43:24 | C1 (+24 s) | 399 → 400 (+1) |
| 04/05 | 9 | 05/2026 | encerrada pelo calendário | 4 entre 12:14:35 e 12:16:49 | C1 | 540 → 542 (+2) |
| 07/08 | 5 | 08/2026 | encerrada pelo calendário | 12:03:04 e 12:03:34 | C1 (+30 s) | 542 → 543 (+1) |
| 14/08 | 9 | 08/2026 | encerrada pelo calendário | 12:49:41 e 12:50:02 | C1 (+21 s) | 530 → 531 (+1) |
| 28/08 | 12 | 08/2026 | encerrada pelo calendário | 12:00:00 (aprovação) e 12:05:04 (GPS) | **C3** (+304 s, origens diferentes) | 476 → 481 (+5) |
| 02/09 | 5 | 09/2026 | **corrente (aberta)** | 12:04:43 e 12:05:02 | C1 (+19 s) | 537 → 538 (+1) |
| 03/09 | 9 | 09/2026 | **corrente (aberta)** | 3 entre 13:18:31 e 13:19:03 | C1 | 587 → 588 (+1) |

Mais o **19/06**, usuário 1, competência 06/2026, encerrada pelo calendário: repousos às 12:28 e às
13:30, 500 → 562 (+62). É ambíguo.

**Resumo:**

- 5 dias em competências passadas (abril, maio e três em agosto);
- 2 na competência corrente (setembro);
- mais o 19/06;
- "encerrada" aqui é só calendário: ver §9.1.

### 9.3 A regra única: `EscolhaDasBatidasDoDia`

**Uma única função decide quais batidas do dia valem.** Tudo usa essa escolha:

- as células da folha e os ids dos links de editar e excluir;
- `minutosIntervalo`;
- `calcularMinutosTrabalhados`, `calcularSaldoDia` e `registroIncompleto`;
- a tela, a ficha do admin, o PDF, o XLSX e os saldos do mês e do ano.

A calculadora deixa de escolher por conta própria: recebe a escolha pronta.

**Entrada:** todas as batidas do dia, em ordem de `dataHora` e depois de `id` (hoje falta o desempate
por id).

1. **Eventos.** Batidas do mesmo tipo, **consecutivas**, com até **5 min** entre si e **as duas feitas
   pelo próprio colaborador** formam um só evento. É a janela da D-1, já aprovada na frente de
   duplicatas; não é limiar novo. Aprovação de esquecimento (observação fixa) e lançamento ou edição do
   admin (snapshot `Lançamento manual`) nunca se juntam. A D1 também pede GPS presente; aqui a batida
   de home office, que não tem GPS, conta como do colaborador (zero delas em produção, §10.5).

   O evento vale **a batida que a folha usa hoje**: a primeira do grupo, e a última quando o tipo é
   saída. É a mesma que a D-2 manda preservar na limpeza. As outras são "repetições" e não entram na
   conta.
2. **Entrada** = o primeiro evento de entrada. **Saída** = o último evento de saída.
3. **Intervalo.** O retorno usado é o primeiro evento de retorno que tenha algum repouso antes dele.
   - Um só evento de repouso antes dele: esse é o repouso.
   - **Dois ou mais eventos de repouso antes dele:** o dia é **ambíguo**. A regra **não** escolhe
     sozinha o almoço menor. Mantém o **primeiro** repouso (a escolha atual) e marca o dia (§9.5).
   - Nenhum par: não há intervalo, e o dia vale o span inteiro (regra de 31/08, como hoje).
4. **Marcas "a conferir"**, que não mudam número nenhum:
   - dois eventos distintos de entrada;
   - dois de retorno;
   - dois de saída;
   - dois de repouso;
   - retorno antes do repouso.

   São batidas que o admin deveria corrigir. Nos dados de hoje, **38 dias** ficam marcados (medido na
   execução, §10.5; o "33" que estava aqui contava só os repousos antes do retorno).

**Efeito medido sobre os 64 dias reais** (simulação em Python validada contra o PHP real nos 64 dias):

- **nenhum dia muda**;
- os outros 819 pessoa-dias são iguais pela construção (no máximo uma batida por tipo, em ordem).

A regra só dá resultado diferente em **padrões que hoje não existem**. Exemplo: retorno batido por
engano de manhã e depois o par real repouso 12:00 → retorno 13:00. A folha de hoje dá 600 min (span);
a regra única dá 540.

⚠️ **O que a regra desfaz do 14/09.** O caso "repouso cedo por engano" (09:00 e 12:00) continua valendo
360 min, agora **marcado**. Os testes unitários da calculadora de 14/09
(`testRepousoPrecoceDeTipoErradoNaoApagaAManha`, que espera 540) teriam de mudar. Isso é coerente com a
decisão 2 (repousos distintos são ambíguos e dependem de decisão humana), mas **contradiz o que a
spec de 14/09 aprovou para esse caso**. Aquela regra nunca chegou à folha: está no §9.8 como decisão.

### 9.4 Como aplicar daqui para frente sem recalcular o passado

A folha é recalculada a cada exibição a partir de quatro coisas:

- as batidas;
- a regra;
- a jornada **atual** (blocos sem vigência, §9.1);
- feriados e justificativas.

**Uma mudança de regra só recalcula o passado se ela der número diferente para dados que já existem.**
A proposta tem duas camadas.

1. **Regra desenhada para coincidir com a de hoje em todo dado existente (§9.3). É a proteção
   principal.** Medido: zero dias mudam. A prova fica **permanente** num teste de regressão com os 64
   padrões reais (§9.6, T8), sem `user_id`: só tipos e horários. Se alguém mexer na regra e mudar o
   passado, esse teste cai.
2. **Vigência por competência, como salvaguarda.** Uma **constante datada no código**, no modelo do
   `TermoVigente::VERSAO` (por exemplo, `EscolhaDasBatidasDoDia::VIGENCIA = '2026-10-01'`), injetável
   nos testes. Vale a partir do **1º dia da competência seguinte ao deploy**, então uma competência nunca
   é calculada com duas regras. É constante e não configuração para que mudá-la passe por commit e
   revisão.
   - Para dia < vigência, `EscolhaDasBatidasDoDia` delega à **escolha legada**. É o código de hoje
     (`FolhaPontoBuilder.php:49-70` mais o par adjacente sobre a lista reduzida), **congelado** numa
     classe própria.
   - Garante que um padrão divergente criado **depois** num dia passado (por exemplo, o admin editando
     uma batida antiga até formar o caso do retorno errado cedo) continue sendo calculado pela regra de
     quando aquele dia aconteceu.
   - **Regras da vigência:**
     - ela só anda para a frente;
     - mudá-la exige revisão e a medição do §5 refeita;
     - **não é por usuário nem por dia.**

**O que isso NÃO garante, e continua como hoje** (fora do escopo, registrado):

- **Alterações humanas.** Batida editada, apagada ou lançada num mês passado muda o resultado desse
  mês, como sempre mudou (com `audit_log`).
- **Jornada e feriados.** Mudar a jornada de alguém recalcula o histórico dessa pessoa inteiro, porque
  `bloco_jornada_colaborador` e `jornada_colaborador` não têm vigência. Um feriado cadastrado depois
  também recalcula o histórico.
- **Imutabilidade de mês fechado de verdade** exigiria um fechamento persistido (foto da folha
  assinada). **Não existe hoje** (§9.1) e seria mecanismo novo, que não proponho nesta frente.

**Saneamento histórico, separado do comportamento futuro:** sempre por **correção explícita de
batidas**, feita por um humano, com lista fechada, e nunca por mudança de regra ou de vigência.

- É o mecanismo que já existe. Com as batidas limpas (uma por tipo, em ordem), a regra legada e a
  nova dão o mesmo número, então o saneamento de um dia não depende de qual regra está em vigor.
- A limpeza C1 da frente de duplicatas (D-2) é um desses saneamentos, e com esta regra continua com Δ
  zero.

### 9.5 O 19/06 (e o que for igual a ele), sem exceção por data ou usuário

O 19/06 fica fora da mudança automática por **três camadas gerais, nenhuma nomeando o dia**:

1. **Pela própria regra:** dois repousos distintos antes do retorno (12:28 e 13:30, a 62 min um do
   outro, fora da janela de 5 min) tornam o dia ambíguo. A regra mantém a escolha atual (500 min) e
   marca `repousos_distintos`. O mesmo acontece com o 28/08 (aprovação 12:00 + GPS 12:05:04, a 304 s,
   com origens diferentes), que também não muda.
2. **Pela vigência:** o dia é anterior a ela e usa a escolha legada.
3. **Pelo teste T8:** ele está entre os 64 padrões congelados.

**A decisão humana específica sobre o 19/06 continua pelo caminho que já existe:**

- se o repouso das 12:28 foi engano, o admin apaga ou edita a batida;
- se não foi, nada muda.

A marca "a conferir" é o que torna a pendência visível. Um registro de "conferido, manter" que apague
a marca exigiria persistência nova. Fica como opção (§9.8), não no escopo mínimo.

### 9.6 Mudanças por arquivo (escopo previsto, sem migration)

| Arquivo | Mudança |
|---|---|
| `src/Ponto/Service/EscolhaDasBatidasDoDia.php` (novo) | A regra do §9.3. Devolve um objeto de valor com entrada, repouso, retorno, saída (as entidades escolhidas), as repetições e as marcas. Escolhe a legada ou a nova pela vigência. |
| `src/Ponto/Service/EscolhaLegadaDasBatidas.php` (novo) | O código de hoje congelado (primeira de cada tipo, última saída, par adjacente sobre a lista reduzida). |
| `src/Ponto/Service/CalculadoraJornada.php` | `calcularMinutosTrabalhados`, `calcularSaldoDia` e `registroIncompleto` passam a receber a escolha pronta. O par adjacente sai daqui e vai para a regra única. |
| `src/Ponto/Service/FolhaPontoBuilder.php` | `:49-70` e `:141-167` usam a escolha para células, ids, intervalo, minutos, saldo e marcas. `calcularSaldoAteMes`/`Anual` herdam. |
| `src/Ponto/Repository/RegistroPontoRepository.php` | `findByUserAndCompetencia`: desempate `ORDER BY dataHora, id`. |
| `templates/ponto/_folha_table.html.twig` (usado pela tela e pela ficha do admin) | Selo "a conferir" com o motivo. Na ficha do admin, acesso às repetições (hoje invisíveis, sem link de editar nem de excluir). |
| (constante em `EscolhaDasBatidasDoDia`) | `VIGENCIA` datada, no modelo do `TermoVigente`. Não entra em `services.yaml`. |
| PDF e XLSX | Números iguais. Selo não entra no documento assinado (a decidir, §9.8). |
| `PontoController::index` (card "suas batidas de hoje") | Hoje mostra a **última** de cada tipo. Passa a usar a escolha (opcional, §9.8). |

### 9.7 Testes (obrigatórios, passando pelo caminho real)

| # | Nível | O que prova |
|---|---|---|
| T1 | Unit, `EscolhaDasBatidasDoDia` | A matriz inteira: grupo de 5 min (primeira; última na saída), borda de 300 × 301 s, entrada e saída distintas, par adjacente com um repouso, dois repousos distintos (ambíguo, fica o primeiro), retorno antes do repouso, retorno errado cedo mais o par real, dia só com entrada e saída, cada marca. |
| T2 | Unit, vigência | O mesmo padrão divergente na véspera da vigência (legada) e no dia dela (nova). Nenhuma competência mistura as duas. |
| T3 | Unit, `FolhaPontoBuilder` | Células, ids, `minutosIntervalo`, minutos e saldo saem **da mesma escolha**: a batida da célula é a batida da conta. Também com justificativa abonada e com dia incompleto. |
| T4 | Funcional, tela `/ponto/` do colaborador | No molde do §2.1: saldo do mês, células do dia, selo "a conferir". Um dia antes e um depois da vigência. |
| T5 | Funcional, ficha do admin (`/tenant/{t}/user/{u}/edit-role`) | O mesmo número do T4 (o parcial é compartilhado) e os links de editar e excluir apontando para a batida escolhida e para as repetições. |
| T6 | Funcional, XLSX (`ponto_exportar_xlsx`) | Lê a planilha gerada (PhpSpreadsheet) e confere horas trabalhadas, saldo e banco do dia e do mês. |
| T7 | Funcional, PDF (`ponto_exportar_pdf`) | O Dompdf comprime o texto e codifica por glifo, então não dá para ler o PDF. O teste confere o **HTML** que alimenta o PDF, montado pelo mesmo caminho (exige extrair `montarDadosFolha` para um serviço), e confere que a rota devolve 200 com `application/pdf`. |
| T8 | Regressão do histórico | Os **64 padrões reais** (só tipos e horários, sem `user_id`): minutos idênticos aos de hoje, tanto pela regra legada quanto pela nova. É a prova permanente da decisão 3. |
| T9 | Saldo acumulado | `calcularSaldoAnual`/`AteMes` atravessando a vigência: o mês anterior pela legada, o seguinte pela nova, e o total igual à soma. Também o "saldo anterior" da exportação. |
| T10 | Isolamento | Batidas iguais noutro escritório não entram na escolha (o `TenantFilter` do repositório), provado com o recurso irmão. |

**Continuam verdes:** `FolhaPontoRegressaoFolhasReaisTest`, `FolhaPontoBuilderTest`, os funcionais de
horas pagas e `BatidasDeHojeNaTelaTest`. Os testes de `CalculadoraJornadaTest` que chamam a
calculadora direto são reescritos contra a escolha. O de repouso precoce muda de expectativa (§9.3).

**Provas por reintrodução, com diário por mutação:**

| Mutação | Teste que precisa cair |
|---|---|
| Tirar a checagem da vigência | T2 e T8 |
| Grupo de 5 min valendo a primeira também na saída | T8 |
| Escolher o menor almoço nos repousos distintos | T1 e T8 (19/06 e 28/08) |
| A célula lendo uma batida diferente da usada na conta | T3 e T4 |
| Tirar o desempate por id | T1 |

### 9.8 Riscos e decisões antes de implementar

1. **Repousos distintos: preservar e marcar** (recomendado, coerente com a decisão 2), ou aplicar o
   almoço menor a partir da vigência (o que a spec de 14/09 aprovou). A primeira muda a expectativa do
   teste de 14/09 do repouso precoce.
2. **Vigência como salvaguarda:** adotar (recomendado, custo de ~20 linhas congeladas), ou confiar só no
   T8. Data: 1º dia da competência seguinte ao deploy.
3. **Marcas "a conferir":** só na tela e na ficha do admin (recomendado), ou também no PDF/XLSX
   assinados. Também um registro de "conferido, manter", que exige persistência nova e fica fora.
4. **Card "suas batidas de hoje"** passando a usar a escolha única (recomendado junto).
5. **Ordem já decidida:** esta frente antes da de duplicatas. Com a regra do §9.3, a D-2 continua
   preservando "a que a folha usa", com o mesmo sentido e a mesma lista.

### 9.9 Decisão do dono sobre o §9.8 (21/09/2026)

1. **Dois repousos distintos:** preservar o cálculo atual e marcar o dia "a conferir". A regra não
   escolhe sozinha outro repouso. A spec de 14/09 foi atualizada: a regra que ela aprovou para o
   "repouso cedo por engano" nunca chegou à folha e foi substituída por esta decisão.
2. **Vigência como salvaguarda,** a partir do 1º dia da competência seguinte ao deploy.
3. **A marca "a conferir" aparece só na tela do colaborador e na ficha do admin.** O PDF e o XLSX não
   mudam por enquanto.
4. **O quadro "suas batidas de hoje"** usa a mesma `EscolhaDasBatidasDoDia`.

## 10. Plano de execução (testes primeiro, commits pequenos)

### 10.1 Divergências do §9, achadas ao detalhar o plano antes de codificar

- **A regra única coincide com a de hoje em todo dia em ordem de horário.** Com a decisão 1 do §9.9
  e o requisito "dia ambíguo é marcado, não reinterpretado", a nova regra escolhe:
  - o **primeiro** evento de entrada, de repouso e de retorno;
  - o **último** de saída.

  Com as batidas em ordem de horário, é exatamente o que a folha escolhe hoje. **O exemplo do §9.3
  ("retorno errado cedo: 600 → 540") não vale mais:** esse dia é ambíguo, fica em 600 e é marcado.
  O que a frente entrega:
  - a decisão única (células, conta, intervalo e quadro de hoje leem a mesma escolha);
  - a remoção do par adjacente, que era código morto na folha;
  - as marcas "a conferir";
  - o acesso do admin às batidas desconsideradas;
  - a vigência.
- **O efeito da vigência hoje:** a regra legada preserva a **ordem de entrada** das batidas, como o
  código antigo; a nova ordena por horário e depois por id. Com a lista vinda do repositório (ordenada
  por horário), as duas coincidem. O valor da vigência é **estrutural**: dá um ponto de início para
  qualquer mudança futura da regra sem tocar o passado. Uma mudança futura exige **nova** vigência,
  nunca editar esta.
- **`minutosIntervalo` mantém a fórmula de hoje** (diferença entre o repouso e o retorno escolhidos)
  nas duas regras. É o que alimenta o indicador "intrajornada conforme" do PDF e do XLSX, que por isso
  não muda (decisão 3). Ele passa a sair das **mesmas** batidas das células e da conta.
- **O desempate por id no repositório (§9.6) saiu do escopo.** A nova regra ordena sozinha, e a
  legada precisa preservar a ordem de entrada. Sem mudança em repositório.
- **O teste T10 (isolamento) saiu.** Nenhuma consulta nova é criada; a superfície de tenant não muda.
  Os testes de isolamento que já existem continuam na suíte completa.
- **A data da vigência é `2026-10-01`, supondo deploy em setembro.** Se o deploy for em outubro ou
  depois, **a constante tem de mudar antes** (portão do deploy). Um teste garante que ela é sempre um
  1º dia de mês.

### 10.2 Sequência

| # | Commit | Teste que vem primeiro (e falha) | Código |
|---|---|---|---|
| 1 | docs: §9.9, spec de 14/09, este §10 | — | — |
| 2 | Congelar os 64 padrões reais | `FolhaPontoPadroesHistoricosTest`: os 64 padrões (só tipos e horários) pelo `buildRows` real, com os minutos de hoje. **Caracterização:** passa no código de hoje de propósito, para a refatoração não mudar nada. | — |
| 3 | A escolha das batidas | `EscolhaDasBatidasDoDiaTest` (falha: a classe não existe). Cobre: grupo de 5 min (primeira, e a última na saída); borda de 300 × 301 s; eventos distintos de cada tipo marcados; retorno antes do repouso; dia vazio; batidas desconsideradas; vigência (véspera legada, dia nova, VIGENCIA sempre dia 1º); ordem de entrada na legada × ordenação na nova. | `BatidasEscolhidas` (objeto de valor) e `EscolhaDasBatidasDoDia` |
| 4 | A calculadora conta a escolha | Em `CalculadoraJornadaTest`, o repouso precoce passa a esperar **360** e a marca `repousos_distintos` (falha: hoje dá 540). Novos testes de `calcularMinutosDaEscolha`/`calcularSaldoDiaDaEscolha`. | A `CalculadoraJornada` recebe a escolha; o par adjacente sai; os métodos com array delegam à escolha |
| 5 | A folha lê a escolha | Em `FolhaPontoBuilderTest`: células, ids, `minutosIntervalo`, minutos e saldo saem da mesma batida escolhida; linha com `aConferir` e `batidasDesconsideradas` (falha: as chaves não existem). O T8 ganha os 64 padrões **depois** da vigência (mesmos minutos) e as marcas do 19/06 e do 28/08. | `FolhaPontoBuilder` sem escolha própria |
| 6 | Marca na tela e na ficha do admin | Funcionais: `/ponto/` mostra o selo e não mostra links; a ficha do admin mostra o selo e os links de editar e excluir das desconsideradas (falha: não existem). | `_folha_table.html.twig` |
| 7 | Quadro de hoje | Em `BatidasDeHojeNaTelaTest`, duas entradas → o quadro mostra a **primeira**, a da folha, e continua dizendo "2 registros" (falha: hoje mostra a última). | `PontoController::index` |
| 8 | Exportação e saldos | Funcional do XLSX (lê a planilha: horas do dia, saldo e banco); `montarDadosFolha` por reflexão, como no `HorasPagasTotalAssinadoTest` (o que o PDF imprime); `calcularSaldoAteMes`/`Anual` atravessando a vigência (diário, mensal e anual). São guardas de regressão. | — |
| 9 | docs: registro da execução, das mutações e das revisões | — | — |

### 10.3 Provas por reintrodução (diário por mutação, reverter e conferir `git diff` vazio)

| Mutação | Teste que precisa cair |
|---|---|
| M1: a vigência ignorada (sempre legada, ou sempre nova) | teste da vigência (commit 3) |
| M2: o grupo de 5 min vale a **primeira** também na saída | T8 depois da vigência (padrões de 20/05 e 10/09) |
| M3: dois repousos distintos → o **último** antes do retorno (almoço menor) | na regra única: T1, T8 e o teste de saldo; na legada: o repouso precoce e as telas (§10.5) |
| M4: a célula lê uma batida diferente da usada na conta | teste da folha (commit 5) |
| M5: as marcas deixam de ser calculadas | teste da escolha e funcional do selo |
| M6: o quadro de hoje volta a usar a última | teste do quadro (commit 7) |
| M7: a janela de 5 min vira 10 min | teste da borda (commit 3) |

### 10.4 Validação final

- Suíte completa da frente (`scripts/frente-testar.sh`).
- `lint:twig`, `lint:container` e `doctrine:schema:validate --skip-sync` (este sem migration).
- Revisão por dois `feature-review-agent` independentes (regra e histórico; telas e testes). Depois,
  correções e uma nova revisão, porque o risco é ALTO.

### 10.5 Registro da execução (21/09/2026)

**Commits desta frente** (sobre `563460a9`, sem migration, nada publicado):

| Commit | O quê |
|---|---|
| `5a4f4eaa` | docs: decisões do §9.8 (§9.9), spec de 14/09 e este plano |
| `8b3655f9` | teste de caracterização dos 64 padrões reais, verde no código ANTIGO |
| `96cc2e76` | `BatidasEscolhidas` e `EscolhaDasBatidasDoDia` (regra única, marcas, vigência) |
| `af2048c4` | a calculadora conta a escolha pronta; o par adjacente sai |
| `5850d0a8` | a folha lê células, ids, intervalo, minutos, saldo e marcas da escolha |
| `a4e44035` | selo "a conferir" (tela e ficha) e as batidas desconsideradas para o admin |
| `1d4f7dd0` | o quadro "suas batidas de hoje" usa a escolha |
| `7a41da3b` | guardas de saldo diário, mensal e anual, e de exportação |
| `43647fd0` | o teste de exportação deixa de cair no dia 1º do mês |
| `5c40d5e3` | o aviso de jornada (`VerificadorAlertaPonto`) usa a escolha — achado F1 da revisão |
| `232a982c` | repetição só entre batidas do colaborador (a D1); empate de saída igual ao da legada |
| `938ea858` | lista solta da calculadora posta em ordem; dia só com tipo desconhecido continua incompleto |
| `73f1f286` | exclusão da desconsiderada provada pelo formulário; texto e HTML do selo corrigidos |
| `c13ef78f` | testes de PDF, XLSX e da troca de regra na vigência fortalecidos |
| `9295d87b` | o aviso de jornada conta o span quando não há intervalo válido — achado da re-revisão |
| `ea7ce9d9` | `RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL` liga quem grava e quem lê; aprovação depois da real coberta |
| `786ae0df` | comentários da calculadora antiga precisos; saldo da lista solta coberto |

**Divergências achadas depois da revisão (somam-se ao §10.1):**

- **O aviso de jornada entrou no escopo.** O `VerificadorAlertaPonto` ficava com a ÚLTIMA batida de
  cada tipo para "6 h sem repouso", "intervalo concluído" e "jornada concluída". Depois do commit do
  quadro de hoje, relógio e aviso discordavam na mesma tela. O requisito "uma única fonte de decisão"
  é explícito, então o aviso passou a ler a escolha. Efeito: com dois repousos, o aviso de intervalo
  conta do primeiro (o mesmo que `findRepousoDoDia`, a validação do servidor, já usava) e o de jornada
  concluída conta como a folha. Ficam fora da escolha só as validações da batida nova no servidor
  (`findRepousoDoDia`, que coincide, e `findUltimaSaida`, entre dias).
- **A repetição de 5 min passou a exigir as duas batidas do colaborador**, como a D1 aprovada:
  aprovação de esquecimento e lançamento manual nunca se juntam. Nenhum número muda. Mudam as marcas:
  **38 dias** ficam "a conferir" nos dados de hoje (35 sem olhar a origem). Os 3 a mais são uma
  aprovação sobreposta a uma batida real a 24 s (a C3) e dois lançamentos manuais repetidos a 13–15 s
  (a C4). O "33" do §9.3 estava errado: contava só os repousos antes do retorno.
- **Definição da marca de repouso:** mais de um registro distinto de repouso em QUALQUER posição (o
  item 4 do §9.3), não só antes do retorno (o item 3). Dois dias reais têm um repouso batido 2–3 min
  depois do retorno e ficam marcados. É o que o código faz; se o dono preferir o item 3, muda a marca e
  nenhum número.
- **A legada é um método privado, não uma classe**, e a conta de minutos é UMA para as duas regras.
  Uma mudança futura na conta muda o passado; o T8 cai e o docblock de `calcularMinutosDaEscolha`
  avisa que ela exige vigência própria.
- **Empate no mesmo segundo:** a regra única escolhe o mesmo registro que a legada (o gravado
  primeiro), inclusive na saída, e não só o mesmo horário.
- **Métodos de lista solta da calculadora** (sem uso em produção) põem a lista em ordem antes de
  escolher. Dia só com batida de tipo desconhecido continua incompleto, como antes (0 casos em produção:
  só os quatro tipos existem).
- **Escopo de teste reduzido:** não há funcional do "saldo do mês" na tela antes e depois da vigência (a
  vigência real é futura; a folha não apura dia futuro). A troca de regra é provada na folha pelo teste
  de lista fora de ordem, e os saldos diário, mensal e anual pelos unitários. O PDF é provado pelo HTML
  que o Dompdf recebe, montado com os mesmos passos da rota; `montarDadosFolha` não foi extraído para
  serviço.
- **Efeito na tela, não testado (JavaScript):** o `pontoHoje` alimenta o relógio, o contador de repouso e
  a previsão de saída. Com dois registros do mesmo tipo, eles passam a usar o primeiro (o da folha), não
  o último.
- **Selo no celular:** o motivo da marca está no `title` (passar o mouse). O `data-bs-toggle` não é
  inicializado nessas telas, como já acontece no selo "Registro incompleto"; no celular aparece só
  "A conferir".
- **Fora do escopo, registrados:** batidas fora de ordem continuam sem marca e com o `abs()` de
  `diffMinutos` creditando (pré-existente); a data da vigência é portão manual do deploy.

**Provas por reintrodução — 24 mutações, 24 derrubadas.** Cada uma foi aplicada sozinha, rodou os testes
do alvo e foi desfeita pela cópia do arquivo, com `git diff` vazio conferido antes da próxima (diário
completo: `scratchpad/diario-mutacoes.md` da sessão; duas rodadas, a segunda depois das correções da
revisão).

| # | Mutação | Caiu |
|---|---|---|
| M1a / M1b | vigência ignorada (sempre a única / sempre a legada) | teste da escolha e da calculadora |
| M2 | saída vale a primeira na regra única | escolha, T8 (regra única) e saldos |
| M3a | dois repousos distintos → o último (almoço menor), regra única | escolha, T8 (19/06 e 28/08) e saldos |
| M3b | o mesmo na regra legada | T8, calculadora, folha, selo e quadro de hoje |
| M4 | a célula do repouso mostra outra batida que não a da conta | folha, T8, selo, PDF e XLSX |
| M5 | as marcas deixam de ser calculadas | escolha, folha, T8 e selo |
| M6 | o quadro de hoje volta à última de cada tipo | quadro de hoje |
| M7 | janela de repetição de 10 min | escolha e T8 |
| M8 | `minutosIntervalo` só com intervalo válido (mudaria o PDF/XLSX) | T8 |
| M9 | registro incompleto deixa de olhar metade do intervalo | T8 e calculadora |
| M10 | a marca some da tela do colaborador | selo |
| M11 | o admin perde as batidas desconsideradas | selo |
| M12 | a marca vaza para o XLSX | exportação |
| M13 | a repetição ignora a origem (aprovação/manual se juntam) | escolha |
| M14 | saída no mesmo segundo fica com a gravada depois | escolha |
| M15 | o aviso de jornada volta à última de cada tipo | aviso |
| M16 | a lista solta da calculadora deixa de ser posta em ordem | calculadora |
| M17 | dia só com tipo desconhecido deixa de ser incompleto | calculadora |
| M18 | a folha apura cada dia pela regra de HOJE | teste da troca de regra na folha |
| M19 | o formulário de excluir a desconsiderada usa outro token | selo (exclusão de fato) |
| M20 | o PDF imprime o repouso que ficou fora da conta | exportação (PDF) |
| M13b | a origem só é conferida na batida anterior (sobrevivia na re-revisão) | escolha |
| M21 | o aviso de jornada soma o par mesmo sem intervalo válido | aviso |

🪤 **Achado do próprio roteiro:** a restauração copiava o arquivo preservando a data antiga, e o cache
do Twig continuou servindo os templates compilados das mutações M19 e M20. Uma suíte completa deu 2
falhas falsas por isso. Cache limpo, roteiro corrigido (a data do arquivo é atualizada ao restaurar) e
as mutações de template refeitas: todas derrubadas de novo.

**Revisões independentes** (dois `feature-review-agent`, só leitura, sobre `7a41da3b`):

- **Regra, histórico e vigência:** nada bloqueante. A legada é transcrição fiel; nenhum número anterior
  à vigência muda com dado válido (fuzz de 300 mil dias pelo revisor: 0 diferenças de número).
  Achados tratados: F1 (aviso de jornada) → corrigido; F2 (33 × 35) → recontado, 38 com a origem; F3
  (empate de saída) → corrigido; F4 (legada só escolhe, a conta é compartilhada) → documentado; F5
  (lista solta) → corrigido; F6 (tipo desconhecido; 0 em produção) → corrigido; F7 (fora de ordem,
  pré-existente) → registrado fora; F8 (origem na repetição) → corrigido, alinhado à D1; F9 (vigência
  manual) → portão do deploy.
- **Telas e testes:** código de produção coerente, sem XSS, CSRF e posse corretos, HTML balanceado.
  Reprovou pelos testes: dia 1º do mês (já corrigido em `43647fd0`), asserção vazia no PDF ("09:00"
  também é o horário contratual padrão), troca de regra não provada na folha, XLSX sem saldo e banco,
  teste tautológico, exclusão nunca enviada, texto do selo mandando o colaborador corrigir e formulário
  dentro de `<span>`. Todos corrigidos (`73f1f286`, `c13ef78f`, `938ea858`); as mutações M18–M20 provam
  os testes novos.

**Re-revisão (sobre `7a41da3b..c13ef78f`), sem bloqueante.** Todos os achados anteriores foram
confirmados como resolvidos, alguns parcialmente, com estas pendências:

- **Tratadas depois:** o aviso de jornada ignorava o intervalo inválido (`9295d87b`); metade da mutação
  de origem sobrevivia (`ea7ce9d9`); o texto `Lançamento manual` estava acoplado por literal
  (`ea7ce9d9`); dois comentários imprecisos (`786ae0df`); o saldo da lista solta estava sem teste
  (`786ae0df`); a documentação não estava commitada (este commit).
- **Ficam registradas:** as linhas do PDF são montadas por uma réplica fiel da rota, e o binário não é
  lido; a batida de home office conta como do colaborador na repetição, embora a D1 peça GPS (zero em
  produção); alguns commits reúnem mais de um ajuste.

**Suíte completa:**

- 1ª rodada, depois do commit 8: 5.553/5.553.
- 2ª: 2 falhas **falsas**, do cache do Twig com as mutações.
- 3ª: com o cache apagado de propósito, 15 falhas em Mcp, Shared (compressor) e Cobrança, domínios que
  esta frente não toca. As quatro classes passam isoladas; é tempo e cache frio.
- **4ª e final, sobre `786ae0df`: 5.567/5.567, 20.691 asserções.** `lint:twig`, `lint:container` e
  `doctrine:schema:validate --skip-sync` limpos; sem migration.

### 10.6 Portão humano

1. **Data da vigência** (`EscolhaDasBatidasDoDia::VIGENCIA = '2026-10-01'`): vale se o deploy sair em
   setembro. Deploy em outubro ou depois → avançar a constante antes.
2. **O que muda na tela com o deploy, sem mudar número nenhum:**
   - 38 dias do histórico ganham o selo "a conferir";
   - a ficha do admin passa a mostrar e excluir as batidas fora da conta;
   - o quadro de hoje e o aviso de jornada passam a usar a primeira batida de cada tipo (e a última
     saída), como a folha.
3. **Marca de repouso:** qualquer posição (hoje) ou só antes do retorno (tira a marca de 2 dias).
4. **Smoke do dono:** o selo na tela do colaborador e na ficha (desktop e celular), os links "Fora da
   conta" e a exclusão por eles, e o aviso de jornada num dia com dois repousos.
5. **Integração:** esta frente vai antes de `ponto-batida-duplicada` (decisão 4 do §7.1). A lista da
   limpeza D-2 continua valendo, porque o grupo de repetição preserva a mesma batida que a folha usava.
