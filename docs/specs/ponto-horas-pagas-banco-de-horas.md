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
justificativa (`FolhaPontoBuilder.php:165`), que exige um intervalo hora-início→hora-fim dentro de **um único
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
| Sem teto de horas por lançamento | Teto de 24h como rede contra erro de digitação |
| Sem trava e sem aviso se o saldo ficar negativo | Bloquear; ou avisar e confirmar |
| Editar e excluir livremente | Cancelamento com rastro preservado |
| Auto-lançamento **bloqueado** | Permitir, como já ocorre em batidas e abonos |

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

- `calcularSaldoAnual(...)` — `FolhaPontoBuilder.php:336`
- `calcularSaldoAteMes(...)` — `FolhaPontoBuilder.php:247`

**As duas assinaturas ganham um parâmetro final `?Tenant $tenant = null`.** Diferente do sentinela de
`$inicioContagem` (`FolhaPontoBuilder.php:230`, valor obrigatório sem default), aqui o default existe **de
propósito**: sem tenant não há como filtrar com segurança — filtrar lançamento sem tenant vazaria dado entre
escritórios, o que é pior do que simplesmente não somar. Por isso **sem tenant o método devolve 0 de
lançamentos** (soma continua 0, sem lançar exceção), e o parâmetro é opcional só para não quebrar a
compilação dos dois chamadores existentes — não porque seja dispensável.

**Os chamadores existentes precisam ser alterados para passar o tenant** (Tarefa 4 desta frente) — são
**dois**, ambos em `PontoController.php:140` e `PontoController.php:998`. (`TenantController.php:566` chama
só `buildRows`, que não muda — não é chamador dos agregadores.) Até a Tarefa 4 rodar, esses dois pontos
continuam sem tenant e por isso não refletem lançamento nenhum — comportamento idêntico ao que existia antes
desta tarefa (regressão zero, recurso ainda não plugado).

Método público novo, para as telas exibirem a linha:

```php
public function somarHorasPagasDaCompetencia(User $user, ?Tenant $tenant, int $ano, int $mes): int
```

### 4.3 Regra que impede horas de sumirem caladas

Os dois agregadores têm saídas antecipadas: colaborador sem `JornadaColaborador` (`:256` em
`calcularSaldoAteMes`, `:345` em `calcularSaldoAnual`), sem nenhuma batida (`:263`, `:352`), e
`$inicio > $fim` quando a varredura mensal — que só percorre de `max(início da contagem, 01/jan)` até
`min(hoje, fim do período)` — fecha vazia (`:275`, `:363`).

**A soma dos lançamentos (`$horasPagas`) acontece FORA e ANTES dessas condições**, e cada uma delas retorna
`$horasPagas` em vez de `0`. Um lançamento numa competência anterior à primeira batida, ou de um colaborador
sem jornada configurada, **ainda conta**. Perder horas em silêncio é pior do que exibir um saldo que o admin
precise explicar.

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
public function somarPorCompetencia(User $user, int $ano, int $mes): int;
public function listarPorUser(User $user): array;   // ordenado por ano/mês desc, para a ficha do admin
```

### DTO / Form

O admin digita **horas e minutos separados, mais o sentido** — não um número com sinal. Digitar `-100:30`
num campo de texto é fonte de erro.

```
Competência:  [ Agosto ▾ ] [ 2026 ]
Operação:     ( ) Descontar do banco   ( ) Acrescentar ao banco
Quantidade:   [ 100 ] h  [ 30 ] min
Motivo:       [ Horas pagas na folha de agosto/2026                 ]
```

O DTO converte para `minutos` com sinal. Validação: quantidade total `> 0` (o sinal decide o resto),
competência não futura, motivo não vazio.

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
  Horas trabalhadas ...........  176h00
  Saldo do mês ................  +12h00
  Horas pagas .................  -100h00
  ------------------------------------------
  Saldo do banco de horas .....  -88h00
```

A linha **só aparece quando há lançamento na competência** — mês sem lançamento fica exatamente como está
hoje. Sem motivo, sem quem lançou.

Mesma linha em `app/templates/ponto/folha_pdf.html.twig` e em
`app/src/Ponto/Service/FolhaPontoXlsxExporter.php`. Em `montarDadosFolha()` (`PontoController.php:907`) entram
as chaves `horasPagasMinutos` (competência exibida) e `horasPagasAnterioresMinutos` (embutido no
`saldoBancoAnteriorMinutos`, que já vem de `calcularSaldoAteMes` e portanto já inclui os lançamentos
anteriores).

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
