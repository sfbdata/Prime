# Ajuste 7 — Formulário de acordo inteligente + abrir/editar acordo

> Módulo `App\Cobranca` **já em produção**. Rodada de ajustes 2026-07 — item 7.
> Risco **MÉDIO/ALTO** (regra financeira: parcelamento, aritmética de centavos, edição de acordo com pagamentos).
> Base: branch `gestao-cobrancas`, HEAD `8a590f0`. Spec do módulo: `docs/gestao-cobrancas/FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` §12 (Acordos).
> Fonte da rodada: `docs/gestao-cobrancas/AJUSTES_BACKLOG.md` §7 (ideia fechada com o humano).

## 1. Objetivo

Hoje criar um acordo é digitar parcela por parcela num modal, sem soma automática, sem
gerador de parcelamento e sem tela para abrir/editar o acordo depois. Este ajuste entrega:

1. **Gerador de parcelamento** no formulário de criar: seleciona obrigações → total automático;
   total **negociável** (desconto/juros) + **entrada** opcional; escolhe **qtd de parcelas** +
   **data da 1ª** + **periodicidade** (mensal/quinzenal/semanal) → gera as parcelas
   ("Parcela k/n", vencimentos em sequência editáveis).
2. **Recálculo ao vivo (JS):** sobrescrever o valor de uma parcela a **fixa**; as não-fixadas
   redistribuem o restante pra fechar o total. Servidor **revalida** Σ == total no submit.
3. **Abrir acordo:** nova página de **detalhe** (parcelas, status, obrigações substituídas,
   entrada, total, desconto/juros).
4. **Editar acordo:** novo `EditarAcordoUseCase` — editor completo do conjunto de parcelas
   **não pagas** (altera/adiciona/remove + re-negocia total), com guardas e auditoria.

Conecta o **item 8** (parcelas agrupadas na aba Obrigações), que reusa o detalhe deste item.

## 2. Estado atual (confirmado no código — não repetir a investigação)

- **`Acordo`** (`Entity/Acordo.php`): `tenant`, `caso`, `status` (default `Ativo`), `dataAcordo`,
  `motivoRompimento`, `motivoCancelamento`, `obrigacoesSubstituidas` (OneToMany inverso de
  `Obrigacao.acordoSubstituto`), `parcelas` (OneToMany inverso de `Obrigacao.acordoOrigem`),
  auditoria. **NÃO tem colunas de total/entrada/desconto.** Total é implícito (Σ das parcelas).
- **`StatusAcordo`**: `Ativo|Cumprido|Rompido|Cancelado`; `ehVigente()` → `Ativo,Cumprido = true`.
- **`Obrigacao`**: `valorExigivel() = valorOriginal + encargosReconhecidos`; `ehParcela()` =
  `acordoOrigem !== null`; `foiSubstituida()` = `acordoSubstituto !== null`;
  `participaDeAcordoVigente()` trava edição/exclusão direta (o portão legítimo é **este** item).
- **`CriarAcordoUseCase`**: marca substituídas (`acordoSubstituto`), cria parcelas
  (`Obrigacao` com `acordoOrigem`), evento `AcordoCriado`, 1 `flush`. **Não valida soma alguma.**
- **`ObrigacaoRepository::doCasoExigiveis`**: exclui originais substituídas por acordo **vigente**
  e parcelas de acordo **não-vigente** → é aqui que romper/cancelar "restaura originais por
  derivação" (invariável 20). `CalculadoraSaldo` só conhece acordo via essa query.
- **`CalculadoraHonorarios::arredondarFracao`** = `intdiv($n + intdiv($d,2), $d)` (meio-para-cima).
  `ratearPagamento` (forma `AcrescidoDivida`) fecha em centavos (dívida absorve resíduo).
- **`AcordoController`**: rotas POST criar/romper/cancelar/cumprir; gate
  `resources.cobranca.gerenciar`; `findOneByIdDoTenant`→404; redirect p/ `cobranca_objeto_show`.
  **Não existe GET de detalhe de acordo.**
- **`AlocacaoPagamentoRepository::totalAlocadoEmObrigacoes([id], $tenant)`** dá o alocado por parcela.
- Modal atual: `caso/_acoes_modais.html.twig#modalCriarAcordo` (seleção de obrigações + coleção
  de parcelas linha-a-linha, `data-prototype`); montado por `MontadorModaisCaso::deMutacao()`.

## 3. Decisões fechadas com o humano

| # | Decisão | Escolha |
|---|---------|---------|
| D1 | Persistir snapshot da negociação? | **SIM — migração aditiva** (`valor_total_negociado`, `valor_entrada`), snapshot **não-autoritativo p/ saldo**. |
| D2 | Amplitude do editar acordo | **Editor completo** do conjunto de parcelas não-pagas (altera/adiciona/remove + re-negocia total). |
| D3 | Sobra de centavos na divisão | **PRIMEIRA parcela** absorve o resíduo (ex.: 100,00÷3 → 33,34 + 33,33 + 33,33). |

Decisões que o orquestrador fixa (idioma do projeto; não são escolha de produto):

- **D4 Entrada** = a **1ª obrigação-parcela** do acordo (`acordoOrigem` setado, `descricao`
  "Entrada", vencimento = data escolhida, default = `dataAcordo`). Se entrada = 0, nenhuma
  obrigação de entrada é criada. `valor_entrada` guarda o snapshot p/ o detalhe.
- **D5 Total negociado** = `valor_entrada + Σ parcelas.valor` (o que o devedor paga no todo).
  **Desconto/juros = DERIVADO** no detalhe (`Σ substituídas.valorExigivel − valorTotalNegociado`;
  positivo = desconto, negativo = juros). Não vira coluna.
- **D6 Detalhe do acordo** = **página dedicada** `GET /cobrancas/acordos/{id}`
  (`cobranca_acordo_show`), no padrão de `cobranca_objeto_show`.
- **D7 Honorários §18** = o gerador parcela o **total bruto** que o devedor pagará; o rateio
  dívida/honorários continua **no pagamento** (`CalculadoraHonorarios::ratearPagamento`). Base
  automática ao marcar obrigações = `Σ valorExigivel` das selecionadas; o usuário ajusta
  (desconto/juros) livremente. Nenhum tratamento especial de honorários no gerador.

## 4. Modelo de dados (migração aditiva)

`cobranca_acordo` ganha 2 colunas:

```sql
ALTER TABLE cobranca_acordo ADD valor_total_negociado INT DEFAULT NULL;
ALTER TABLE cobranca_acordo ADD valor_entrada INT NOT NULL DEFAULT 0;
```

Entidade `Acordo`:

```php
#[ORM\Column(name: 'valor_total_negociado', type: 'integer', nullable: true)]
private ?int $valorTotalNegociado = null;   // centavos; snapshot da negociação

#[ORM\Column(name: 'valor_entrada', type: 'integer', options: ['default' => 0])]
private int $valorEntrada = 0;              // centavos
```

- **Snapshot descritivo, NÃO-autoritativo:** saldo/honorários/alertas continuam derivados das
  obrigações via `doCasoExigiveis`/`CalculadoraSaldo`. Estas colunas só alimentam o detalhe/edição.
- Acordos **antigos** ficam `valor_total_negociado = NULL`, `valor_entrada = 0` → o detalhe cai no
  derivado (total = Σ parcelas; sem entrada destacada). Não-breaking.
- Migration aplicada em **dev + test** nesta rodada; **prod fica para o humano no deploy**
  (aditiva, sem risco de dados; down remove as colunas).

`AcordoOutput` (readonly) passa a expor: `valorTotalNegociado` (ou derivado se null),
`valorEntrada`, `valorDescontoDerivado`, `valorSubstituidas`, e uma lista de parcelas
(`ParcelaResumoOutput[]`: id, descricao, valor, vencimento, alocado, quitada?). Continua com as
contagens já existentes.

## 5. Gerador de parcelamento (aritmética — 100% centavos inteiros)

Serviço puro **`Service/GeradorParcelamento.php`** (final, sem estado, testável), fonte única da
aritmética. Assinatura:

```php
/** @return LinhaParcelaGerada[] (descricao, valor, vencimento) — NÃO inclui a entrada */
public function gerar(
    int $totalNegociado,        // centavos, o total que o devedor paga (inclui entrada)
    int $valorEntrada,          // centavos, 0 se sem entrada
    int $quantidadeParcelas,    // >= 1
    \DateTimeImmutable $vencimentoPrimeira,
    Periodicidade $periodicidade,
): array;
```

Algoritmo:

1. `totalParcelado = totalNegociado − valorEntrada`. Erros (lançar `ParcelamentoInvalidoException`):
   `valorEntrada < 0`; `totalNegociado <= 0`; `valorEntrada >= totalNegociado` quando
   `quantidadeParcelas >= 1` (entrada não pode consumir todo o total se há parcelas);
   `quantidadeParcelas < 1`.
2. `base = intdiv(totalParcelado, quantidadeParcelas)`;
   `resto = totalParcelado − base * quantidadeParcelas` (∈ [0, n−1] centavos).
3. Parcela `k` (1-based): `valor = base` para todas; **`parcela[1].valor += resto`** (D3 — primeira
   absorve). Garante `Σ parcelas == totalParcelado` por construção.
4. `descricao` = `"Parcela k/n"`. `vencimento[k] = próximo(vencimentoPrimeira, k-1, periodicidade)`.
5. Guard final (defensivo): `assert Σ valores == totalParcelado`.

**`Periodicidade`** (novo enum backed-string): `Mensal|Quinzenal|Semanal` + `label()`.
- Semanal = `+7 dias`; Quinzenal = `+14 dias`; Mensal = mesmo dia nos meses seguintes com **clamp
  ao último dia do mês** (helper próprio; nunca `modify('+1 month')` cru, que estoura 31/01→03/03).
- Vencimentos gerados são **editáveis um a um** depois (a periodicidade só semeia a sequência).

A **entrada** (se `valorEntrada > 0`) é criada à parte pelo UseCase como 1ª obrigação
(`descricao` "Entrada", vencimento = `dataEntrada` do input, default `dataAcordo`). O gerador
**não** a inclui na lista.

## 6. Recálculo ao vivo (contrato JS ↔ servidor)

- **Geração inicial:** ao mudar total/entrada/qtd/data/periodicidade, o JS chama
  **`GET /cobrancas/acordos/previa-parcelamento`** (novo; gate módulo, read-only, **sem CSRF** por
  ser GET; params: total, entrada, qtd, data1, periodicidade) → JSON com as parcelas geradas pelo
  **mesmo `GeradorParcelamento`** (garante paridade PHP↔JS; herda o padrão "fonte única de
  centavos" do item 6). O JS preenche as linhas.
- **Fixar/redistribuir (JS puro):** o usuário pode sobrescrever o valor de uma parcela → ela vira
  **fixa**; as **não-fixadas** dividem `(totalNegociado − entrada − Σ fixadas)` igualmente (primeira
  não-fixada absorve o resíduo). Vencimentos editáveis não afetam valores. Se a soma não fecha
  (impossível por construção, mas guard de UX), o submit é desabilitado com aviso.
- **Revalidação no servidor (obrigatória):** o submit envia `valorTotalNegociado`, `valorEntrada` e
  a lista final de parcelas (valor/descricao/vencimento). O servidor **revalida**
  `valorEntrada + Σ parcelas.valor == valorTotalNegociado` (ver §8, invariante INV-B). O "fixar" é
  só afordância de UI — o servidor só enxerga os valores finais. Divergência → erro de formulário
  (não persiste).

## 7. Abrir acordo — detalhe (D6)

- **`GET /cobrancas/acordos/{id}`** → `cobranca_acordo_show` (novo método no `AcordoController` ou
  controller `AcordoDetalheController` fino — decidir na implementação; preferir estender o
  existente). Gate: **módulo `cobrancas`** (leitura exige só o módulo, como Dashboard/Detalhe);
  `findOneByIdDoTenant`→**404** anti-IDOR.
- Novo `MontarDetalheAcordoUseCase` → `AcordoDetalheOutput` (readonly): cabeçalho (data, status
  badge, motivo se rompido/cancelado), **entrada / total negociado / desconto|juros derivado**,
  tabela de **parcelas** (descrição, valor, vencimento, alocado, quitada?), tabela de **obrigações
  substituídas** (descrição, valorExigível). Saldo/quitação por parcela = derivado
  (`totalAlocadoEmObrigacoes`), nunca coluna.
- Template `cobranca/acordo/show.html.twig`. Se `has_permission('resources.cobranca.gerenciar')`
  **e** acordo `Ativo` **e** caso não encerrado → mostra o botão/painel **Editar** (§8) + as ações
  já existentes (Romper/Cancelar/Cumprir apontando pras rotas atuais). Link "abrir acordo" na aba
  Acordos do objeto passa a apontar aqui.

## 8. Editar acordo (D2 — editor completo)

Novo **`EditarAcordoUseCase`** + `EditarAcordoInput` + `EditarAcordoType` +
**`POST /cobrancas/acordos/{id}/editar`** (`cobranca_acordo_editar`), gate
`resources.cobranca.gerenciar`, CSRF via Form, `findOneByIdDoTenant`→404.

`EditarAcordoInput`: `acordoId`, `valorTotalNegociado`, `valorEntrada`, `dataEntrada?`,
`parcelas[]` (cada uma: `obrigacaoId?` — null = nova —, `descricao`, `valor`, `vencimento`).

Fluxo e **guardas**:

1. Resolve acordo tenant-safe → 404. **Só edita se `status === Ativo`** (Cumprido/Rompido/Cancelado
   → `AcordoNaoAtivoException`, congelado — INV-D). Caso encerrado → `CasoEncerradoException` (INV-H).
2. Monta o diff sobre as parcelas atuais (`acordo.parcelas` + a entrada):
   - **Parcela com pagamento alocado** (`totalAlocadoEmObrigacoes([id]) > 0`) é **CONGELADA**
     (INV-C): não pode ter valor/vencimento/descrição alterados nem ser removida. Se o input tentar
     mudá-la ou omiti-la → `ParcelaComPagamentoException`. (Na UI ela vem travada/read-only e é
     reenviada intacta.)
   - **Parcela sem pagamento presente no input** → atualiza valor/vencimento/descrição.
   - **Parcela sem pagamento ausente do input** → **removida** (hard delete da `Obrigacao`).
   - **Parcela nova** (`obrigacaoId` null) → cria `Obrigacao` com `acordoOrigem` = este acordo.
3. **Conjunto de obrigações substituídas é CONGELADO** (INV-E): a edição não altera `acordoSubstituto`
   de ninguém (decisão de criação; mexer nisso reescreveria saldo/derivação — fora do escopo).
4. Revalida `valorEntrada + Σ parcelas.valor == valorTotalNegociado` (INV-B). A entrada, se existir
   e não estiver paga, pode ser reajustada; se paga, congelada como qualquer parcela.
5. Atualiza o snapshot `valorTotalNegociado`/`valorEntrada` no `Acordo`.
6. Evento de histórico **`AcordoEditado`** (novo caso do enum `TipoEventoHistorico`, code-only — a
   coluna guarda string, sem migração) com resumo antes/depois (qtd parcelas, total). 1 `flush`.
7. Redirect → `cobranca_acordo_show`.

Não há edição de status por aqui (romper/cancelar/cumprir seguem nas rotas próprias).

## 9. Autorização, multi-tenancy, CSRF (inegociável)

- **Gate módulo** `cobrancas` em toda rota (leitura e escrita); **`resources.cobranca.gerenciar`**
  nas mutações (criar/editar). `isSystem`/`ROLE_SUPER_ADMIN` = bypass por design.
- `findOneByIdDoTenant`→**404 ANTES de qualquer efeito** em todas as rotas (acordo e caso).
- Selects de obrigações via `Repository` + `ChoiceType` (nunca `EntityType`) — padrão atual mantido.
- CSRF: mutações via **Symfony Form** (token do form). GET de prévia/detalhe sem CSRF.
- Isolamento multi-tenant testado com caso cross-tenant (404) em criar/editar/detalhe/prévia.

## 10. Invariantes (alvo da revisão)

- **INV-A** Saldo/honorários/alertas **100% derivados** das obrigações; colunas de acordo são
  snapshot descritivo, **nunca lidas para saldo** (invariável 20 intacta).
- **INV-B** `valorEntrada + Σ parcelas.valor == valorTotalNegociado` — garantido na escrita e
  **revalidado no servidor** (criar e editar).
- **INV-C** Parcela com pagamento alocado é **imutável e não-removível**.
- **INV-D** Edita só acordo **Ativo**; Cumprido/Rompido/Cancelado congelados.
- **INV-E** Conjunto de obrigações **substituídas fixado na criação**; edição não altera.
- **INV-F** Aritmética **100% centavos inteiros**; sobra na **primeira** parcela; sem floats;
  arredondamento meio-para-cima onde houver fração (`arredondarFracao`).
- **INV-G** Multi-tenant: `findOneByIdDoTenant`→404 antes de efeito; nada cross-tenant.
- **INV-H** Caso encerrado bloqueia criar e editar acordo.

## 11. Fatiamento (cada fatia: TDD → smoke visual → humano aprova → suíte + /review → corrigir → commit atômico)

- **Fatia 1 — Modelo + snapshot + gerador (backend base).**
  Migração aditiva; campos na `Acordo`; `GeradorParcelamento` + `Periodicidade` +
  `ParcelamentoInvalidoException` (unit tests da divisão/sobra/periodicidade/clamp de mês);
  `CriarAcordoUseCase` passa a **gravar snapshot** (total/entrada) e a **revalidar INV-B** quando o
  input traz total (retrocompatível: total null → deriva de Σ parcelas, sem checar); `CriarAcordoInput`
  ganha `valorTotalNegociado?`, `valorEntrada`, `dataEntrada?` + `Assert\Callback` (INV-B).
  `AcordoOutput` enriquecido. **O modal antigo continua funcionando** (sem total → comportamento atual).
- **Fatia 2 — Gerador na UI de criar.**
  `#modalCriarAcordo` vira formulário inteligente (total auto ao marcar obrigações + negociável +
  entrada + qtd + data 1ª + periodicidade); endpoint `GET previa-parcelamento` (fonte única);
  JS live (gera linhas via endpoint; "fixar"/redistribui; desabilita submit se não fecha). Servidor
  revalida INV-B. Functional tests (happy, revalidação Σ≠total barra, cross-tenant, sem capacidade).
  Reaproveitar o guard de modal reutilizável do item 6 (action nula → não faz POST-405).
- **Fatia 3 — Abrir acordo (detalhe, read-only).**
  `GET cobranca_acordo_show` + `MontarDetalheAcordoUseCase` + `AcordoDetalheOutput` + template;
  link "abrir acordo" na aba Acordos do objeto. Functional tests (render, gate módulo,
  cross-tenant 404, derivação de desconto/total, parcela quitada marcada).
- **Fatia 4 — Editar acordo.**
  `EditarAcordoUseCase` (diff + guardas INV-C/D/E/H, snapshot, evento `AcordoEditado`) +
  `EditarAcordoInput`/`EditarAcordoType` + `POST cobranca_acordo_editar` + UI no detalhe.
  Unit tests (altera/adiciona/remove parcela sem pagamento; congela parcela paga; barra acordo
  não-ativo; barra caso encerrado; revalida INV-B) + functional (happy, CSRF, cross-tenant 404).

**Item 8** (parcelas agrupadas na aba Obrigações + link "abrir acordo") **fica fora deste item** —
depende do detalhe entregue aqui e será tratado depois.

## 12. Testes (contrato protegido)

- Unit: `GeradorParcelamentoTest` (divisão exata; sobra na 1ª; n=1; entrada; periodicidades;
  clamp de fim de mês; erros), `CriarAcordoUseCaseTest` (+ snapshot + INV-B),
  `EditarAcordoUseCaseTest` (todas as guardas + diff). Reusar o estilo DB-backed onde os serviços
  `final` impedem mock (padrão da Etapa 9).
- Functional: `AcordoMutacaoControllerTest` (ampliado: criar com total/entrada/gerador, revalidação),
  `AcordoDetalheControllerTest` (novo), `AcordoEdicaoControllerTest` (novo),
  `AcordoCobrancaIsolamentoTenantTest` (mantém invariável 20; + cross-tenant nas rotas novas).
- Não quebrar: `AcordoMutacaoControllerTest::testCriarHappy` (ajustar p/ enviar total quando aplicável),
  derivação de saldo, re-substituição pós-rompimento.

## 13. Riscos e deploy

- **Migração aditiva** em prod = tarefa do humano no deploy (depois do DJEN); down remove colunas.
- Sem toque em `AlocadorPagamento`/`AutoAlocadorFifo`/`CalculadoraSaldo` (saldo segue derivado).
- Aritmética de centavos coberta por unit tests data-driven (como o rateio de honorários).
- `participaDeAcordoVigente()` continua travando edição **direta** de parcela; o `EditarAcordoUseCase`
  é o único portão que altera parcela de acordo vigente (por design, item 5 já anota isso).
- Nada desta rodada é pushado/deployado sem decisão do humano.
