# Horas pagas — ajuste manual do banco de horas

- **Data:** 2026-07-30
- **Risco:** ALTO (ponto eletrônico — altera saldo de banco de horas, que é verba trabalhista)
- **Domínio:** `App\Ponto`
- **Migration:** sim (uma tabela nova)

---

## 1. Problema

O escritório precisa acertar o banco de horas de um colaborador por fora das batidas. Dois casos reais:

1. **Horas pagas em dinheiro.** O colaborador acumulou 100h de saldo positivo; o escritório paga essas horas
   na folha salarial. O banco precisa ser reduzido em 100h, senão o escritório paga duas vezes — uma em
   dinheiro, outra em folga.
2. **Bonificação.** O escritório presenteia o colaborador com horas de banco (mutirão, reconhecimento).

Hoje **não existe caminho para nenhum dos dois.** O banco de horas nunca é gravado: é recalculado do zero a
cada leitura por `FolhaPontoBuilder`. O único ponto em que minutos entram no saldo é o abono parcial de uma
justificativa (`FolhaPontoBuilder.php:168`), que exige um intervalo hora-início→hora-fim dentro de **um único
dia** e nunca produz crédito além do déficit daquele dia. Os tipos de justificativa com nome sugestivo
(`compensacao_horas`, `hora_extra_autorizada`, `ajuste_manual_autorizado`) são apenas rótulos: no cálculo
todos caem no mesmo ramo genérico, que zera dia negativo e nada mais.

## 2. Decisão

Um lançamento manual de **minutos com sinal**, preso a uma **competência (mês/ano)**, não a um dia.
Rótulo único na folha de ponto: **"Horas pagas"**, exibido com o sinal (`-100h00` ou `+8h00`).

Decisões tomadas pelo dono do sistema e pelo responsável, com o que cada uma descarta:

| Decisão | Alternativa descartada |
|---|---|
| Valor com sinal: negativo desconta, positivo acrescenta | Só crédito |
| Preso à competência (mês), entra no total | Preso a um dia, somando na linha do calendário |
| Rótulo único "Horas pagas" para os dois sentidos | Dois rótulos ("Horas pagas" / "Bonificação") |
| Visível ao colaborador: rótulo e valor | Invisível; ou com o motivo à mostra |
| Motivo visível só para o admin | Motivo na folha do colaborador |
| Sem teto **de negócio** para as horas (ver nota abaixo) | Teto de 24h como rede contra erro de digitação |
| Sem trava e sem aviso se o saldo ficar negativo | Bloquear; ou avisar e confirmar |
| Editar e excluir livremente | Cancelamento com rastro preservado |
| Auto-lançamento **bloqueado** | Permitir, como já ocorre em batidas e abonos |

**Nota sobre o teto de horas.** O dono recusou teto de **negócio** de propósito. Existe, ainda assim, um teto
de **sanidade** de `100.000` horas (`LancamentoHorasPagasInput::HORAS_MAXIMAS`, ~11 anos ininterruptos):
acima dele o total em minutos estoura o `integer` do Postgres e o admin levava um **erro 500** no INSERT em
vez de uma recusa legível. É guarda contra estouro, não regra de negócio — e é inclusivo (100.000h grava).

**Ressalva registrada, não bloqueante.** Editar/excluir sem rastro num valor que altera verba trabalhista é
difícil de defender numa contestação. Mitigação de custo zero adotada: **toda criação, edição e exclusão grava
no `audit_log` que o sistema já possui**, sem tela nova. Isso não restaura o histórico dentro do produto, mas
deixa a prova no banco.

## 3. Modelo de dados

Entidade `App\Ponto\Entity\LancamentoHorasPagas` → tabela `ponto_lancamento_horas_pagas`.

| coluna | tipo | regra |
|---|---|---|
| `id` | `integer` PK auto | PK inteira, conforme a skill `criar-entity` |
| `tenant_id` | FK `Tenant`, NOT NULL | isolamento multi-tenant |
| `user_id` | FK `User`, NOT NULL | colaborador beneficiado/descontado |
| `ano` | `smallint`, NOT NULL | competência |
| `mes` | `smallint`, NOT NULL | competência, 1–12 |
| `minutos` | `integer`, NOT NULL | com sinal; **nunca 0** |
| `motivo` | `text`, NOT NULL | obrigatório, mínimo 3 caracteres após `trim` |
| `criado_por_id` | FK `User`, NOT NULL | quem lançou |
| `criado_em` | `datetime_immutable`, NOT NULL | |
| `atualizado_por_id` | FK `User`, NULL | preenchido na edição |
| `atualizado_em` | `datetime_immutable`, NULL | |

Índice: `(tenant_id, user_id, ano, mes)` — é exatamente o acesso que o cálculo faz, uma vez por mês percorrido.

Vários lançamentos na mesma competência são permitidos e **somam**. Não há registro único por mês: o admin
pode lançar `-100h` (pagamento) e `+8h` (bonificação) no mesmo agosto, e a folha mostra `-92h00`.

## 4. Como entra no cálculo

Este é o ponto delicado da frente. O saldo não é persistido em lugar nenhum — logo o lançamento **não
"atualiza um saldo"**: ele passa a ser mais um ingrediente que o cálculo lê, junto de batidas, feriados e
abonos.

### 4.1 Onde NÃO entra

`FolhaPontoBuilder::buildRows()` **não muda.** Ele produz uma linha por dia; o lançamento é mensal e não
pertence a nenhum dia. Mexer em `buildRows` sujaria o `saldoAcumulado` das linhas e contrariaria a decisão de
não marcar o calendário.

Consequência visual assumida: a **última linha da tabela** do mês mostra o acumulado só das batidas; a linha
**"Horas pagas"** e o **saldo final** vêm abaixo da tabela. São números diferentes de propósito.

### 4.2 Onde entra

`LancamentoHorasPagasRepository` é injetado no construtor de `FolhaPontoBuilder`, ao lado dos repositórios de
batida e justificativa que já estão lá. Os dois agregadores passam a somar os lançamentos:

- `calcularSaldoAnual(...)` — `FolhaPontoBuilder.php:389`
- `calcularSaldoAteMes(...)` — `FolhaPontoBuilder.php:296`

**As duas assinaturas ganham um parâmetro final `Tenant|null|false $tenant = false`** — a mesma sentinela do
`$inicioContagem` (`FolhaPontoBuilder.php:257`), e pelo mesmo motivo. `false` significa **não informado**, é
erro de chamada e **lança `\InvalidArgumentException`**; `null` significa **"não há tenant"** e então os
lançamentos não somam (devolve 0 de horas pagas, sem exceção — filtrar lançamento sem tenant vazaria dado
entre escritórios, o que é pior do que não somar).

> A Tarefa 3 nasceu com `?Tenant $tenant = null` para não quebrar a compilação dos dois chamadores
> existentes. **A Tarefa 4 endureceu a assinatura** depois de ligar os dois: enquanto houvesse default,
> qualquer chamador futuro que esquecesse o tenant perderia os lançamentos **em silêncio** — sem exceção,
> sem log, com a folha aparentemente normal.

**Os dois chamadores passam o tenant** desde a Tarefa 4: `PontoController.php:140` (`calcularSaldoAnual`, do
painel) e `PontoController.php:1006` (`calcularSaldoAteMes`, do "Saldo anterior" do espelho/PDF/XLSX).
(Na Tarefa 6, `TenantController.php:625` também passou a chamar `somarHorasPagasDaCompetencia` — a ficha do
admin precisou da mesma soma para exibir a linha "Horas pagas" na aba de batidas.)

Método público novo, para as telas exibirem a linha:

```php
public function somarHorasPagasDaCompetencia(User $user, ?Tenant $tenant, int $ano, int $mes): int
```

### 4.3 Regra que impede horas de sumirem caladas

Os dois agregadores têm saídas antecipadas: colaborador sem `JornadaColaborador` (`:307` em
`calcularSaldoAteMes`, `:400` em `calcularSaldoAnual`), sem nenhuma batida (`:314`, `:407`), e
`$inicio > $fim` quando a varredura mensal — que só percorre de `max(início da contagem, 01/jan)` até
`min(hoje, fim do período)` — fecha vazia (`:326`, `:418`).

**A soma dos lançamentos (`$horasPagas`) acontece FORA e ANTES dessas condições**, e cada uma delas retorna
`$horasPagas` em vez de `0`. Um lançamento numa competência anterior à primeira batida, ou de um colaborador
sem jornada configurada, **ainda conta**. Perder horas em silêncio é pior do que exibir um saldo que o admin
precise explicar.

**Corolário para os chamadores:** ninguém pode repetir a condição do lado de fora. `montarDadosFolha`
(`PontoController.php:912`) chamava `calcularSaldoAteMes` apenas quando `$jornada !== null` e usava `0` no
resto — exatamente o guard que o método já faz por dentro, só que sem devolver os lançamentos. O efeito era
600 minutos de diferença entre o painel `/ponto` e o "Saldo anterior" do espelho para o mesmo colaborador. A
chamada é **incondicional** (corrigido na Tarefa 4, coberto por
`app/tests/Ponto/Functional/SaldoAnteriorHorasPagasTest.php`).

O formulário recusa competência futura, por higiene — mas o cálculo não depende dessa recusa.

## 5. Autorização

Mesma guarda que protege a ficha do funcionário (`TenantController.php:332-336`):

```php
$isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
$isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.users.manage'))) {
    throw $this->createAccessDeniedException(...);
}
```

Somam-se:

- **`escoparFiltroNoTenant()`** (`TenantController.php:448`) antes de qualquer query, como nas demais rotas de
  ponto administrativo.
- **Token CSRF por intenção**, no padrão dos modais existentes (`ponto_manual_add`,
  `admin_nova_justificativa_<id>`): `horas_pagas_lancar_<userId>`, `horas_pagas_editar_<lancamentoId>`,
  `horas_pagas_excluir_<lancamentoId>`.
- **Colaborador precisa pertencer ao tenant da URL** — guarda explícita contra IDOR. Sem ela, um admin do
  escritório A alcança o funcionário do escritório B pelo id na rota.
- **Auto-lançamento bloqueado**: se `criadoPor->getId() === colaborador->getId()`, o UseCase recusa. Vale para
  lançar, editar e excluir, e **inclusive para `ROLE_SUPER_ADMIN`** — a trava é sobre a identidade, não sobre
  o papel.
- **Lançamento tem de ser do tenant da URL** na edição e na exclusão, além de ser do colaborador da rota.

## 6. Camadas

O domínio `Ponto` **não tem pasta `UseCase/`** — toda a escrita mora hoje nos controllers, contrariando o
fluxo obrigatório do projeto (`Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()`).
Esta frente cria o primeiro `UseCase/` do domínio em vez de engordar `TenantController` (1700+ linhas).

| Camada | Arquivo |
|---|---|
| Entity | `app/src/Ponto/Entity/LancamentoHorasPagas.php` |
| Repository | `app/src/Ponto/Repository/LancamentoHorasPagasRepository.php` |
| DTO | `app/src/Ponto/DTO/LancamentoHorasPagasInput.php` |
| Form | `app/src/Ponto/Form/LancamentoHorasPagasType.php` |
| UseCase | `app/src/Ponto/UseCase/LancarHorasPagasUseCase.php` |
| UseCase | `app/src/Ponto/UseCase/EditarHorasPagasUseCase.php` |
| UseCase | `app/src/Ponto/UseCase/ExcluirHorasPagasUseCase.php` |
| Controller | `app/src/Ponto/Controller/HorasPagasController.php` |

### Repository (filtro de tenant obrigatório)

```php
public function somarPorCompetencia(User $user, Tenant $tenant, int $ano, int $mes): int;                 // linha "Horas pagas" do mês exibido
public function somarPorPeriodo(User $user, Tenant $tenant, int $ano, int $mesInicial, int $mesFinal): int; // agregadores; UMA query, pontas inclusivas
public function listarPorUser(User $user, Tenant $tenant): array;  // ordenado por ano/mês desc, para a ficha do admin
public function buscarDoTenant(int $id, Tenant $tenant): ?LancamentoHorasPagas; // nunca find() por id da URL
```

O `somarPorPeriodo` existe porque `calcularSaldoAnual` roda no painel `/ponto` — caminho quente, aberto
várias vezes por dia por todo funcionário. Somar mês a mês custaria 12 round-trips por load.

### DTO / Form

O admin digita **horas e minutos separados, mais o sentido** — não um número com sinal. Digitar `-100:30`
num campo de texto é fonte de erro.

```
Competência:  [ Agosto ▾ ] [ 2026 ]
Operação:     ( ) Descontar do banco   ( ) Acrescentar ao banco
Quantidade:   [ 100 ] h  [ 30 ] min
Motivo:       [ Horas pagas na folha de agosto/2026                 ]
```

O DTO converte para `minutos` com sinal. Validação: quantidade total `> 0` (o sinal decide o resto) e `horas`
dentro do teto de sanidade de `100.000`, competência não futura, motivo com **ao menos 3 caracteres depois do
`trim`**. Cada regra vale nas **duas** camadas — `Assert\*` no DTO (com `normalizer: 'trim'`, senão `"  x  "`
passa) e `GuardaHorasPagas::validarInput()`, que é o gate real: o formulário não é a única porta.

### Rotas

| Rota | name | método |
|---|---|---|
| `/tenant/{tenantId}/users/{id}/horas-pagas` | `ponto_horas_pagas_lancar` | POST |
| `/tenant/{tenantId}/users/{id}/horas-pagas/{lancamentoId}/editar` | `ponto_horas_pagas_editar` | POST |
| `/tenant/{tenantId}/users/{id}/horas-pagas/{lancamentoId}/excluir` | `ponto_horas_pagas_excluir` | POST |

## 7. Interface

### Admin — ficha do funcionário, aba Ponto

Em `app/templates/tenant/edit_user_role.html.twig`, ao lado dos botões "Adicionar Batida" e "Nova
Justificativa": botão **"Horas pagas"**, abrindo modal com o formulário da §6.

Abaixo, tabela dos lançamentos do colaborador — competência, valor com sinal, motivo, quem lançou, quando —
com ações editar e excluir. **É a única tela em que o motivo aparece.**

### Colaborador — folha de ponto

Abaixo da tabela do mês, em `app/templates/ponto/_folha_table.html.twig`:

```
  Saldo do mês ................  +12h00
  Horas pagas .................  -100h00
  ------------------------------------------
  Total do mês ................  -88h00
```

O bloco **só aparece quando há lançamento na competência** — mês sem lançamento fica exatamente como está
hoje, sem nenhuma das três linhas. Sem motivo, sem quem lançou.

**São três linhas, não quatro.** Uma versão anterior desta spec abria o bloco com "Horas trabalhadas"; ela
nunca foi implementada e foi retirada do desenho — o dado já está na tabela logo acima, e a linha não entra
em nenhuma conta do bloco.

**O total chama-se "Total do mês", e o rótulo é decisão, não estilo.** A re-revisão da onda final mostrou que
chamá-lo de "Saldo do banco de horas" colocava **dois números diferentes com o mesmo nome na mesma página**:
o card do topo (`ponto/index.html.twig:30-35`, vindo de `calcularSaldoAnual`) mostra o banco **acumulado**,
enquanto este bloco soma o saldo **daquele mês** com o lançamento. No caso de uso nº 1 — 100h acumuladas,
pagas em dinheiro, quitadas com `-6000` em agosto, agosto trabalhado na jornada exata — o card exibia
`+0h00m` (certo) e o rodapé `-100h00m`, cobrando de volta o que acabou de ser pago. É a **mesma** cobrança
indevida que o bloco assinado produzia, apenas mudada de superfície. O rótulo está
ancorado por `assertBlocoTotaisPresente()` em `app/tests/Ponto/Functional/HorasPagasFolhaExibicaoTest.php`.

> **Esta tela continua MENSAL, o bloco assinado do PDF/XLSX virou ACUMULADO** (decisão de 2026-07-31, mais
> abaixo). Não é descuido: são superfícies com propósito diferente — a folha na tela mostra o mês que a pessoa
> está olhando, o documento assinado mostra o banco que ela tem. Os rótulos dizem qual é qual ("Total do mês"
> × "Saldo do Banco de Horas Atual"). Se um dia isso incomodar, a mudança é no rótulo ou na base desta tela —
> decisão do dono, não conserto silencioso.

> **Dívida registrada pelo dono (2026-07-30), NÃO corrigida nesta frente — anterior a ela.** O "Saldo do mês"
> desta tela pode **subestimar o déficit**, e o bloco novo tornou isso visível em vez de criá-lo.
> `PontoController::index()` chama `buildRows` com `$includeEmptyDays = false` (`PontoController.php:120`),
> enquanto a ficha do admin, o PDF e o XLSX usam `true` (`TenantController.php:635`, `PontoController.php:802`
> e `:886`). Em `FolhaPontoBuilder.php:176-195` o dia sem batida **entra no acumulado** (`$saldoAcumulado +=
> $saldoDia`) e só **depois** é descartado pelo `continue`; como `saldoAcumuladoFinal()` devolve o acumulado da
> última linha **presente**, faltas no **fim** do mês somem da conta na tela do colaborador e permanecem nas
> demais. Jornada 8h/dia, mês exato até 24/07 e faltas em 27, 28 e 29: `/ponto` mostra "Saldo do mês 0h00m",
> a ficha do admin mostra `-24h00m` — 1440 minutos de divergência, que num desligamento no meio do mês passa
> de 4000. O mesmo desvio já existia na última célula da coluna "Banco de Horas"; corrigi-lo muda o saldo que
> **todo colaborador** lê na tela, então é frente própria, com decisão e deploy próprios.
>
> Fica também registrado que `testFichaDoAdminMostraOMesmoBlocoDeTotais`
> (`HorasPagasFolhaExibicaoTest.php`) **não** prova a paridade que o comentário dele afirma: usa
> `criarJornadaSemDiasDeTrabalho()`, em que `cargaEsperada = 0` e um dia ausente pesa zero — exatamente a
> configuração que exclui a divergência. Ele segue válido para o que de fato pega (variável esquecida em um
> dos dois renders do partial).

O "Saldo do mês" do bloco é o **último `saldoAcumulado` não-nulo** das linhas da tabela (o mesmo número da
última célula preenchida da coluna "Banco de Horas"), calculado por
`FolhaPontoBuilder::saldoAcumuladoFinal()` e passado **pronto** pelos controllers — no Twig viraria laço com
estado. Competência sem batida nenhuma devolve `null` e o bloco trata como zero, para que a soma continue
existindo justamente no mês em que só há o lançamento.

> ⚠️ **Armadilha, custou rodadas nesta frente:** `_folha_table.html.twig` é incluído por **dois** lugares —
> `app/templates/ponto/index.html.twig` (via `PontoController::index()`) e
> `app/templates/tenant/edit_user_role.html.twig` (via `TenantController::editUserRole()`), cada um com
> `only`. Toda variável nova do partial precisa sair dos **dois** renders; o partial se protege com
> `is defined`, então esquecer um deles **não quebra a página** — só mostra número errado em silêncio.

### Bloco assinado do PDF/XLSX — "Saldo do Banco de Horas Atual" passa a ser acumulado

**Decisão do dono, 2026-07-31, depois do smoke.** Ela SUBSTITUI a decisão da onda final de correção (que
mandava o lançamento aparecer só numa linha própria "Horas pagas", sem tocar os campos antigos). O histórico
das duas está preservado abaixo porque a diferença entre elas é exatamente onde o dinheiro erra.

O bloco assinado (`app/templates/ponto/folha_pdf.html.twig` e `app/src/Ponto/Service/FolhaPontoXlsxExporter.php`)
tem **cinco linhas fixas**, sem linha própria de horas pagas:

```
  Detalhe de Horas Trabalhadas no Mês: 141:26
  Total de Horas Extras no Mês: 0:12
  Saldo do Banco de Horas Anterior: -105:27
  Saldo do Banco de Horas Atual: -12:36
  Horas a Compensar: 12:36
```

Em `montarDadosFolha()` (`PontoController.php:912`):

```php
$saldoBancoAtualMinutos = $saldoBancoAnteriorMinutos + ($saldoDoMesMinutos ?? 0) + $horasPagasMinutos;
```

e `horasACompensar` é o módulo desse total quando negativo. A chave `horasPagasMinutos` **não é mais
publicada** no array — nada a consome depois da remoção das duas linhas.

**Por que a base tinha de mudar junto.** Até aqui `saldoBancoAtual` era o `saldoAcumulado` da última linha da
tabela, e `buildRows` faz esse acumulado **nascer em zero no primeiro dia do intervalo pedido** (o PDF chama
`buildRows($inicioMes, $fimMes, …)`): apesar do rótulo, era o saldo **daquele mês** — ao lado de um "Saldo
Anterior" que sempre foi acumulado, via `calcularSaldoAteMes`. Dois rótulos irmãos medindo coisas diferentes.

Era essa base que tornava a soma perigosa. Caso de uso nº 1: colaborador acumula 100h, o escritório paga em
dinheiro e lança `-6000` em agosto, agosto é trabalhado na jornada exata (saldo do mês = 0).

| base do campo | Saldo Atual | Horas a Compensar | veredito |
|---|---|---|---|
| mensal + lançamento (rodada revertida) | −100:00 | **100:00** | cobra o que acabou de ser pago |
| mensal, lançamento em linha à parte | +0:00 | – | não cobra, mas o total ignora o pagamento |
| **acumulado (esta decisão)** | **+0:00** | **–** | fecha em zero, que é o devido |

**Sem contagem dupla:** `calcularSaldoAteMes` soma os lançamentos das competências **anteriores**;
`somarHorasPagasDaCompetencia` soma os **desta**. As faixas não se sobrepõem.

⚠️ **Muda o PDF de TODOS os escritórios, inclusive os que nunca usaram horas pagas** — o campo deixa de ser
mensal e passa a ser acumulado. Consequência aceita e explícita: foi por causa dela que essa correção estava
registrada como follow-up de frente própria, e o dono optou por trazê-la para cá em vez de deployar um
comportamento e desfazê-lo no deploy seguinte. Mudança de exibição secundária: mês **sem saldo apurado**
(nenhuma batida) exibia `–` e passa a exibir `+0:00`, porque o acumulado existe mesmo sem movimento no mês.

Coberto por `app/tests/Ponto/Functional/HorasPagasTotalAssinadoTest.php` (as três parcelas, o caso de uso nº 1
ponta a ponta e a tabela de saldos sem lançamento) e por `SaldoAnteriorHorasPagasTest`, que prova que o
lançamento da competência exibida chega ao campo.

O card de saldo em `app/templates/ponto/index.html.twig:30-35` vem de `calcularSaldoAnual` e passa a refletir
o lançamento **sem alteração de template**.

## 8. Testes

Sem estes, a frente não é considerada pronta.

**Unit — `app/tests/Ponto/Unit/FolhaPontoBuilderHorasPagasTest.php`**

1. Lançamento negativo reduz `calcularSaldoAnual`.
2. Lançamento positivo aumenta `calcularSaldoAnual`.
3. Dois lançamentos na mesma competência somam.
4. Lançamento numa competência **anterior ao início da contagem** ainda conta (§4.3).
5. Colaborador **sem `JornadaColaborador`** com lançamento: retorna o lançamento, não `0` (§4.3).
6. Colaborador **sem nenhuma batida** com lançamento: retorna o lançamento, não `0` (§4.3).
7. `calcularSaldoAteMes` inclui lançamentos até o mês pedido e **exclui** os posteriores.
8. `buildRows` **não** é afetado: `saldoDia` e `saldoAcumulado` das linhas ficam idênticos com e sem
   lançamento na competência.

**Unit — `app/tests/Ponto/Unit/LancarHorasPagasUseCaseTest.php`**

9. Quantidade zero é recusada.
10. Motivo vazio ou só espaços é recusado.
11. Competência futura é recusada.
12. Auto-lançamento é recusado (mesmo `User` como autor e beneficiado).
13. Auto-lançamento é recusado **também para super-admin**.
14. Editar/excluir lançamento de outro tenant é recusado.

**Functional — `app/tests/Ponto/Functional/HorasPagasControllerTest.php`**

15. Colaborador sem `admin.users.manage` recebe 403 ao lançar.
16. Admin com a permissão lança e o registro nasce com `criadoPor` e `criadoEm` corretos.
17. POST sem token CSRF válido é recusado — asserindo a **mensagem**, não só o status.
18. **Cross-tenant:** admin do tenant A lançando para colaborador do tenant B recebe 403 e **nada é gravado**.
19. Excluir remove o registro e o saldo do colaborador volta ao valor anterior.

**Prova do teste (obrigatória nesta frente).** Cada teste do grupo Unit 1–8 deve ser provado por injeção de
defeito: reverter a linha que ele cobre e confirmar que ele **falha**. Um teste verde que não pega o defeito
não vale, e esta frente mexe em dinheiro.

## 9. Migration

Uma tabela nova, nenhuma alteração em tabela existente, nenhum backfill.

Antes de gerar, fotografar a divergência preexistente do schema (procedimento do `CLAUDE.md` raiz):

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:update --dump-sql'
```

Tudo que já aparecer nessa saída sai do arquivo gerado. Atenção especial a `DROP INDEX` de índice funcional,
que o Doctrine propõe apagar por não saber representá-lo.

Aplicação em produção é do humano.

## 10. Fora de escopo

- Aprovação em duas etapas do lançamento.
- Relatório consolidado de horas pagas por período ou por escritório.
- Exibição do motivo ou do autor na folha do colaborador.
- Notificação ao colaborador quando um lançamento é feito.
- Vínculo entre o lançamento e o valor pago em folha salarial.
