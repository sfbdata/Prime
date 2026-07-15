# Ajuste 9 — Bloquear "acordo sobre acordo" na criação

> **Risco:** MÉDIO (dinheiro — o estado hoje alcançável duplica saldo).
> **Origem:** follow-up aberto na spec do ajuste 7 §13, elevado após investigação: o vetor de **rompimento**
> não estava analisado e é pior que o de edição, que a Fatia 4 já cobriu.
> **Migration:** NENHUMA (sem mudança de schema).
> **Decisão de produto (humano, 2026-07-15):** "acordo sobre acordo" **NÃO é um fluxo do negócio.**

## 1. Objetivo

Impedir que um acordo B substitua **parcelas de um acordo A ainda vigente**. O caminho legítimo para
refazer um acordo já existe no domínio e passa a ser o único: **romper/cancelar A** (as dívidas originais
voltam ao saldo por derivação) e então criar B sobre elas.

Fecha um caminho de **duplicação de saldo** hoje alcançável em produção.

## 2. Estado atual (confirmado no código — não repetir a investigação)

- `ObrigacaoRepository::doCasoExigiveis` (`ObrigacaoRepository.php:102-118`) **exige** que a parcela seja
  de acordo vigente para retorná-la: `(aorig.id IS NULL OR aorig.status IN (:vigentes))` (linha 110).
  Não é acidente — é a regra correta **de saldo**: parcela de acordo vigente **é** exigível.
- `CriarAcordoUseCase` (`CriarAcordoUseCase.php:95-99`) só barra `acordoSubstituto` vigente
  (`ObrigacaoJaSubstituidaException`). **Nunca chama `getAcordoOrigem()`** — as únicas ocorrências são
  `setAcordoOrigem` (linhas 115, 128), escrita nas parcelas novas.
- A tela de criação não distingue dívida original de parcela: `AcordoCriarType::opcoesObrigacoes`
  (`AcordoCriarType.php:86-96`) rotula só descrição, vencimento e valor.
- **`CriarAcordoUseCase.php:102` é o ÚNICO ponto do sistema que grava `acordoSubstituto`** (verificado por
  grep em `app/src/`). O import CLI `app:cobranca:importar` não toca em acordos. **A criação é a única porta.**

### 2.1 Consequência não prevista pela spec do ajuste 7 (o motivo real deste ajuste)

`RomperAcordoUseCase.php:33-63` e `CancelarAcordoUseCase.php:36-66` validam apenas tenant + `estaAtivo()`.
**Nenhum** guard sobre parcelas que outro acordo guarda como dívida original.

Cenário: A vigente com 5 parcelas; B (vigente) substitui 3 delas. O gestor rompe A. Na query seguinte:

| Conjunto | Destino | Por quê |
|---|---|---|
| Originais que A substituiu | **voltam ao exigível** | `asub = A ∈ (Rompido, Cancelado)` → linha 109 passa |
| As 5 parcelas de A | saem do exigível | `aorig = A ∉ (Ativo, Cumprido)` → linha 110 barra |
| Parcelas de B | **continuam exigíveis** | `aorig = B` vigente; `asub` null |

Resultado: a dívida original **e** a renegociação de parte dela ficam **ambas** no saldo — a mesma dívida
cobrada duas vezes, inflando saldo, alertas e painel. Derivável de `ObrigacaoRepository.php:109-110` +
`CalculadoraSaldo.php:47`. Nada compensa isso no código.

O guard da Fatia 4 (`EditarAcordoUseCase::recusarSeGeridaPorOutroAcordo`, `:243-248`) **não alcança este
caminho** — ele protege o vetor de edição, não o de rompimento.

### 2.2 Dados existentes

- **dev:** 3 acordos (2 cancelados, 1 rompido), **zero** casos de acordo-sobre-acordo (query §7).
- **prod:** dados são de **teste, nada real** (humano, 2026-07-15). Portanto **não há plano de limpeza de
  dado sujo**: se o estado existir lá, é descartável, e o guard de §5 apenas barrará o romper daquele caso
  de teste — que é o comportamento correto. A query de §7 é **informativa, não bloqueante**.

## 3. Decisões fechadas com o humano

| # | Decisão |
|---|---|
| D1 | "Acordo sobre acordo" **não existe** como fluxo. Bloquear na **criação**. |
| D2 | `doCasoExigiveis` **NÃO muda.** É a fonte do saldo (`CalculadoraSaldo`, `AlertasCobranca`, `AutoAlocadorFifo`); filtrar ali quebraria o saldo do módulo. O bloqueio é no que a **tela de acordo oferece** + guard no servidor. |
| D3 | UX: a parcela **some** da lista de substituíveis + **texto explicando o caminho** ("para refazer um acordo, rompa o atual primeiro"). Lista vazia → o aviso ocupa o lugar da lista. |
| D4 | Guard também em **romper E cancelar** (os dois deixam A não-vigente → mesmo vetor). Justificativa honesta pós-investigação: com a criação bloqueada e sendo ela a única porta (§2), este guard é **código que não dispara com dado novo** — ele é um **alarme** contra uma porta futura (import, carga, ferramenta em lote), não uma correção de legado. |
| D5 | **Pagar** parcela de acordo vigente continua funcionando — é o fluxo normal, intocado. |
| D6 | Detalhe do acordo A listar parcelas substituídas como se valessem = **follow-up, fora de escopo** (§8). |

## 4. Peça 1 — leitura nova (saldo intocado)

Novo método em `ObrigacaoRepository`:

```php
/**
 * Obrigações que um acordo novo PODE substituir: só DÍVIDAS ORIGINAIS (invariável INV-I).
 *
 * Reusa `doCasoExigiveis` de propósito — os critérios de exigibilidade NÃO podem divergir em dois
 * lugares. Dentro do conjunto exigível, toda parcela é necessariamente de acordo VIGENTE (garantia da
 * cláusula `aorig.status IN (:vigentes)`), logo "excluir parcela de acordo vigente" equivale a
 * `acordoOrigem === null`.
 *
 * @return list<Obrigacao>
 */
public function doCasoSubstituiveis(CasoCobranca $caso): array
```

Filtro: `array_values(array_filter($this->doCasoExigiveis($caso), fn (Obrigacao $o) => $o->getAcordoOrigem() === null))`.

**NÃO alterar `doCasoExigiveis`** (D2).

## 5. Peça 2 — guards no servidor

### 5.1 Criação (o portão real)

`CriarAcordoUseCase`, **depois** do guard de `acordoSubstituto` (`:95-99`), dentro do mesmo laço:

```php
// INV-I: acordo só substitui DÍVIDA ORIGINAL. Parcela gerada por outro acordo nunca é substituível —
// substituí-la duplicaria a dívida no saldo quando o acordo de origem fosse rompido/cancelado (§2.1).
if ($obrigacao->getAcordoOrigem() !== null) {
    throw new ObrigacaoNaoEhDividaOriginalException($obrigacaoId);
}
```

> **CORREÇÃO PÓS-REVISÃO (achado MÉDIO).** A spec previa reusar `ObrigacaoDeAcordoException`. **Errado:**
> aquela exception diz *"participa de um acordo **vigente**"* e é usada em 4 pontos com essa semântica,
> mas este guard recusa parcela de acordo em **qualquer** status — para a de acordo rompido a mensagem
> seria uma mentira ao gestor, contradizendo o próprio docblock da classe. Exception nova e específica:
> `ObrigacaoNaoEhDividaOriginalException`, que ainda aponta o caminho ("rompa o acordo atual primeiro").

Cobertura extra de graça: a condição `getAcordoOrigem() !== null` também fecha um furo pré-existente — hoje
é possível passar o id de uma parcela de acordo **rompido** (não exigível) e o UseCase aceita.

> **⚠️ ARMADILHA CONHECIDA (regressão já cometida no item 7):** o `catch` da criação em `AcordoController`
> não captura a exception nova. Sem adicioná-la, o guard vira **500** — **reproduzido de verdade** durante
> a implementação (`'statusCode' => 500`). **Guard novo exige teste FUNCTIONAL do caminho HTTP** — unit não
> vê o `catch`.

### 5.1.1 A assimetria das choices (render ⊂ POST) — CORREÇÃO PÓS-REVISÃO, não "consertar"

A revisão achou um **BLOQUEANTE de prova**: com as choices do POST filtradas por `doCasoSubstituiveis`
(como o §6 previa), o ChoiceType barra a parcela **antes** do UseCase, e:
- o guard e o `catch` ficam **inalcançáveis pelo form** → INV-L deixa de ser verificável;
- o teste functional passava **pelo motivo errado** (form inválido também dá 302 para a mesma URL, sem
  criar acordo) — confirmado por mutação: removendo a exception do catch, o teste **seguia verde**;
- pior, na prática: o `catch` foi escrito **sem o `use`** da exception nova, resolveria para o namespace do
  Controller e **nunca capturaria** — e os 539 testes passaram assim mesmo, porque nada alcançava o catch.
  Prova viva de que seguro inalcançável não é seguro: é código morto que apodrece sem ninguém ver.

**Resolução:** o **render** (`MontadorModaisCaso::deMutacao`) oferece só as substituíveis (D3 — a parcela
não aparece), mas o **POST** (`AcordoController::criar`) valida contra as **exigíveis** (conjunto maior).
Como substituíveis ⊂ exigíveis, tudo que a tela ofereceu segue válido; uma parcela submetida por fora chega
ao guard e recebe a **mensagem de domínio** em vez do "valor inválido" cru do ChoiceType.

**As duas listas NÃO devem ser igualadas.** Igualá-las restaura o bloqueante acima.

### 5.2 Romper e cancelar (o alarme — D4)

`RomperAcordoUseCase` e `CancelarAcordoUseCase`, após o guard `estaAtivo()`: recusar enquanto um acordo
vigente guardar parcelas deste acordo como dívida original.

> **DESVIO DO DESENHO ORIGINAL (decidido na implementação, 2026-07-15).** A spec previa um predicado na
> entidade (`Acordo::parcelaRenegociadaPorAcordoVigente(): ?Acordo`) iterando `getParcelas()`. **Descartado
> na implementação por três motivos verificados no código:** (1) `Obrigacao::setAcordoOrigem` NÃO sincroniza
> o lado inverso e `Acordo` não tem `addParcela` — a coleção sai vazia em unit test, tornando o predicado
> não-testável sem lazy loading; (2) em produção custaria um lazy load da coleção + um do `acordoSubstituto`
> de CADA parcela (N+1); (3) a query explícita é tenant-scoped por construção.

Método de repositório (query única, mockável no unit test):

```php
// ObrigacaoRepository
/**
 * Parcelas DESTE acordo que um OUTRO acordo VIGENTE substituiu como dívida original (§2.1).
 * Vazio = o acordo pode ser rompido/cancelado sem duplicar dívida no saldo.
 *
 * @return list<Obrigacao>
 */
public function parcelasRenegociadasPorAcordoVigente(Acordo $acordo): array
```

Os dois UseCases ganham `ObrigacaoRepository` no construtor e recusam quando o retorno não é vazio.

Nova exception `AcordoComParcelasRenegociadasException` (mensagem aponta o caminho: desfaça o acordo #B
primeiro). Catch no helper `mutarAcordoComMotivo` do `AcordoController` — o ponto único por onde romper e
cancelar passam; hoje captura só `AcordoNaoAtivoException`. **Mesma armadilha do §5.1: sem o catch, 500.**

## 6. Peça 3 — o que a tela oferece (cirúrgico) + UX

`AcordoCriarType::opcoesObrigacoes` é um helper **compartilhado** — alimenta o modal de acordo **e** os de
pagamento. O filtro vai na **lista passada ao form de acordo**, nunca no helper.

| Ponto | Ação | Motivo |
|---|---|---|
| `MontadorModaisCaso::deMutacao` | → `doCasoSubstituiveis` | é o RENDER: a parcela não aparece na tela (D3). `$substituiveis` ali só alimenta `acordoCriar` |
| `AcordoController::criar` | **mantém `doCasoExigiveis`** | é o POST: conjunto MAIOR de propósito, para o guard ser alcançável — ver §5.1.1 |
| `MontadorModaisCaso::financeiros` | **NÃO TOCAR** | pagamento — parcela deve continuar pagável (D5) |
| `PagamentoController` (registrar e corrigir) | **NÃO TOCAR** | idem |

UX (D3), em `templates/cobranca/caso/_acoes_modais.html.twig:238-244`: texto de ajuda explicando que
parcelas de acordos vigentes não são renegociáveis ali e que o caminho é romper o acordo atual primeiro.
Se `opcoesObrigacoes` vier vazio, o aviso ocupa o lugar da lista (não renderizar lista vazia + `Count(min:1)`
insatisfazível sem explicação).

## 7. Query de diagnóstico (informativa — §2.2)

```sql
SELECT a.id AS acordo_b, a.status AS status_b, count(*) AS parcelas_de_outro_acordo_substituidas
FROM cobranca_obrigacao o
JOIN cobranca_acordo a ON a.id = o.acordo_substituto_id
WHERE o.acordo_origem_id IS NOT NULL
GROUP BY a.id, a.status;
```

Zero linhas = o estado não existe. Dev: **0 linhas** (verificado 2026-07-15).

## 8. Fora de escopo (follow-ups registrados)

1. `MontarDetalheAcordoUseCase.php:45-59` — o detalhe do acordo A itera `getParcelas()` e conta **todas**,
   inclusive as que saíram do saldo por substituição; `ParcelaAcordoResumoOutput` não tem flag de
   substituída. O item 8 corrigiu esse inflacionamento na **aba do caso**
   (`MontarDetalheCasoUseCase::agruparPorAcordo`), mas a **tela do acordo** ficou. Depois deste ajuste só
   afeta dado legado. Não ampliar o escopo agora.
2. **Obrigação quitada segue sendo oferecida como substituível** — `doCasoExigiveis` não filtra por
   pagamento, então uma dívida original 100% paga aparece na lista de acordo. **Pré-existente**, não
   introduzido aqui (registrado para não virar folclore).
3. **Dívidas aceitas conscientemente na revisão** (parecer do `feature-review-agent`, 2026-07-15):
   `parcelasRenegociadasPorAcordoVigente` não filtra `asub.tenant` — o lado lido já é escopado por
   `o.tenant` + `o.acordoOrigem = :acordo` (que veio de `findOneByIdDoTenant`), e o efeito de um `asub`
   cross-tenant seria **fail-safe** (recusar romper), nunca vazamento; e o botão de submit continua ativo
   com a lista vazia (`Count(min:1)` dá mensagem clara). Ambos cosméticos/teóricos.

## 9. Autorização, multi-tenancy, CSRF (inegociável)

Nada muda: os guards entram **depois** do `findOneByIdDoTenant` + 404 existentes. Nenhuma rota nova,
nenhuma capacidade nova, nenhum form novo. `doCasoSubstituiveis` herda o filtro de tenant de
`doCasoExigiveis` (cláusula `o.tenant = :tenant`).

## 10. Invariantes (alvo da revisão)

| # | Invariante |
|---|---|
| INV-I | Um acordo só substitui **dívida original**. Obrigação com `acordoOrigem !== null` nunca é substituível. |
| INV-J | `doCasoExigiveis` e a derivação de saldo permanecem **byte-a-byte** inalteradas. Parcela de acordo vigente segue exigível. |
| INV-K | Pagar/corrigir pagamento continua oferecendo **todas** as exigíveis, inclusive parcelas de acordo vigente. |
| INV-L | Nenhum guard novo produz 500: todo `throw` tem `catch` no caminho HTTP correspondente, **provado por teste functional**. |
| INV-M | Nenhuma mudança de schema; nenhuma migration. |

## 11. Testes (contrato protegido) — ENTREGUE

`tests/Cobranca` **539/539** (eram 522); suíte global **1854/1854**.

| Arquivo | O que prova |
|---|---|
| `Functional/ObrigacoesSubstituiveisRepositoryTest` (5) | DB real: `doCasoSubstituiveis` só devolve originais; **INV-J** (`doCasoExigiveis` segue devolvendo as parcelas); substituíveis ⊆ exigíveis; `parcelasRenegociadasPorAcordoVigente` detecta o estado e ignora renegociador não-vigente |
| `Unit/CriarAcordoUseCaseTest` (+2) | **INV-I**: parcela de acordo vigente **e** de acordo rompido → `ObrigacaoNaoEhDividaOriginalException`, sem nenhum efeito |
| `Unit/RomperAcordoUseCaseTest` (+2) e `Unit/CancelarAcordoUseCaseTest` (+2) | guard recusa e o acordo **não muda de status**; sem parcelas renegociadas, rompe/cancela normal |
| `Functional/AcordoSobreAcordoBloqueadoControllerTest` (6) | **INV-L** pelo HTTP real (criar, romper e cancelar): erro amigável, não 500; parcela não é oferecida na tela; **INV-K** (pagar parcela de acordo vigente ainda funciona); **D3** (aviso no lugar da lista vazia) |

**Provas por execução, não por leitura** (o resto é relato — e relato foi exatamente o que a revisão derrubou):
- O 500 do §5.1 **foi reproduzido** (`'statusCode' => 500`) — a armadilha era real.
- **Criar, provado por mutação DUPLA** (só passou a valer depois da correção do §5.1.1): removendo o
  `catch` → 500 → teste falha; desligando o guard → acordo criado e flash some → teste falha. O teste exige
  a **mensagem do guard** no flash; sem isso passaria pelo motivo errado, que foi o bloqueante da revisão.
- **Romper, provado por mutação:** desligando o `if`, o acordo vira `Rompido` e o teste falha.
- **Cancelar:** functional próprio — não presumir que "compartilha o catch do romper".

## 12. Fatiamento

Fatia única — a mudança é pequena e coesa, e separar criação de romper/cancelar deixaria a invariante
meio-aplicada num commit intermediário. Ciclo: TDD → smoke visual → humano aprova → suíte + `/review` →
corrigir → commit atômico.

## 13. Riscos e deploy

- **Sem migration** → o delta de deploy deste ajuste é só código.
- Todo o efeito é **recusar** entrada inválida; nenhum caminho que hoje funciona muda de comportamento
  (D5/INV-K cobrem o único vizinho de risco: pagamento).
- Risco real e único: **guard sem catch = 500** (§5.1). Mitigado por INV-L + teste functional obrigatório.
- Segundo risco: filtrar no helper compartilhado em vez de na lista do form quebraria pagamento. Mitigado
  pela tabela do §6 + INV-K.
- Nada desta rodada é pushado/deployado sem decisão do humano.
