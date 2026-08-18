# Cliente principal da pasta

> **Estado: PROJETADA, NÃO IMPLEMENTADA — bloqueada pela regra de uma migration por vez.**
> Ver "Por que está parada", no fim.

## O problema, com o número que ele move

A aba Financeiro da pasta mostra **"Média por CPF"**: a média do valor da causa de todas as
pastas de um mesmo cliente. Uma pasta pode ter **vários** clientes vinculados, e a média é de
**um** só. Quem escolhe esse um, hoje, é `Pasta::getPrimeiroCliente()`
([Pasta.php:431-442](../../app/src/Pasta/Entity/Pasta.php#L431-L442)):

```php
usort($clientes, static fn (Cliente $a, Cliente $b): int => $a->getId() <=> $b->getId());
return $clientes[0];
```

O critério é o **id do cliente** — a ordem em que ele entrou no cadastro do escritório. O
docblock do próprio método já admite a consequência: *"vincular depois um cliente cadastrado há
mais tempo **troca** o número mostrado na tela. É determinístico, não é estável."*

Ou seja: um ato que nada tem a ver com dinheiro (vincular mais um cliente à pasta) muda um
número exibido como indicador financeiro, sem ninguém ter pedido. É esse acoplamento que a
feature corta.

Há ainda um **segundo critério, divergente**, para a mesma pergunta "qual é o cliente desta
pasta": [PeticionarController.php:60](../../app/src/Pasta/Controller/PeticionarController.php#L60)
usa `getClientes()->first()`, que é a ordem arbitrária do banco. Dois lugares respondendo
diferente à mesma pergunta.

## O que se quer

O dono marca explicitamente qual cliente é o principal da pasta, e a média segue essa marcação.

## O padrão da casa — seguir, não inventar

O domínio `Pasta` **já tem** "marcar algo como principal": processos. O desenho abaixo é o
mesmo, peça por peça.

| Peça | Processos (existe) | Clientes (a fazer) |
|---|---|---|
| Tabela de vínculo | `pasta_processo` — **entidade** `PastaProcesso` | `pasta_cliente` — hoje ManyToMany **pura** |
| Flag | `bool $principal` na entidade de vínculo | idem |
| Unicidade | em memória, `foreach { setPrincipal($v === $alvo) }` | idem |
| Leitura | `getVinculoPrincipal()` com fallback `->first()` | `getVinculoClientePrincipal()` |
| Ordenação | `#[ORM\OrderBy(['principal' => 'DESC', 'vinculadoEm' => 'ASC', 'id' => 'ASC'])]` | idem |
| UseCase | `DefinirProcessoPrincipalUseCase` (casca de 2 linhas) | `DefinirClientePrincipalUseCase` |
| Rota | `POST /{id}/processo/principal` | `POST /{id}/cliente/principal` |
| CSRF | `pasta_definir_principal_processo_<pastaId>` | `pasta_definir_principal_cliente_<pastaId>` |
| Tela | estrela cheia = estado, estrela vazia = ação | idem |
| Resposta XHR | `{sucesso, html}` do parcial re-renderizado | idem |

**A assimetria que dá o trabalho:** `pasta_processo` já é entidade; `pasta_cliente` é tabela
crua de ManyToMany (`pasta_id`, `cliente_id`, PK composta, nenhuma coluna extra —
[Version20260324124920.php:28](../../app/migrations/Version20260324124920.php#L28)). Não há onde
guardar a flag. Promover a junção a entidade é o núcleo desta fatia, e é o que exige migration.

## Desenho

### 1. Entidade de vínculo `PastaCliente`

Espelha `PastaProcesso`: `id`, `pasta` (ManyToOne, `onDelete: CASCADE`), `cliente` (idem),
`bool $principal = false`, `vinculadoEm`, `vinculadoPor`. **Não é `TenantAware`** — o isolamento
vem da `Pasta` dona do vínculo, como em `PastaProcesso`.

### 2. `Pasta`

- `$pastaClientes` OneToMany com `OrderBy(['principal' => 'DESC', 'vinculadoEm' => 'ASC', 'id' => 'ASC'])`;
- `getVinculoClientePrincipal()` — varre procurando `principal`, **com fallback para `->first()`**
  (o fallback é o que impede a tela de ficar sem número se a flag ficar inconsistente);
- `getClientePrincipal(): ?Cliente` — **substitui `getPrimeiroCliente()`**;
- `vincularCliente()` (o primeiro vínculo já nasce principal), `desvincularCliente()` (remover o
  principal **promove o próximo**), `definirClientePrincipal()` (lança `\DomainException` se o
  cliente não estiver vinculado).
- `getClientes()` continua existindo, **derivada** dos vínculos (`map` sobre `$pastaClientes`),
  como conveniência de leitura em PHP.

⚠️ **A colisão de mapeamento tem de ser decidida aqui, não descoberta no flush.** Se a
ManyToMany `$clientes` continuar **mapeada** ao lado da nova OneToMany, duas associações Doctrine
escrevem na mesma tabela `pasta_cliente` e o flush duplica chave. Se ela **sumir** do mapeamento,
quebram os 4 joins DQL em `p.clientes` do
[PastaRepository](../../app/src/Pasta/Repository/PastaRepository.php) — linhas 180, 484, 585
(a própria `mediaValorCausaPorCliente`) e 634.

**Decisão: a ManyToMany sai do mapeamento e os 4 joins passam a atravessar o vínculo**
(`p.pastaClientes pc INNER JOIN pc.cliente cli`). É mais trabalho, mas é o único caminho sem duas
associações gravando na mesma tabela. Note que isso **contradiz** a afirmação anterior de que
`mediaValorCausaPorCliente()` "não muda": a assinatura e o resultado não mudam, **o DQL muda**.

### 3. Migration — **aditiva e com backfill que preserva o número em tela**

Sobre a tabela `pasta_cliente` existente:

```sql
ALTER TABLE pasta_cliente ADD id INT GENERATED BY DEFAULT AS IDENTITY;
ALTER TABLE pasta_cliente ADD principal BOOLEAN DEFAULT false NOT NULL;
-- Sem DEFAULT permanente: o precedente (pasta_processo) não tem, e um default que o
-- mapeamento Doctrine desconhece vira drift — o próximo make:migration de outra frente
-- proporia "DROP DEFAULT" como lixo no diff. Preenche e só então exige NOT NULL.
ALTER TABLE pasta_cliente ADD vinculado_em TIMESTAMP(0) WITHOUT TIME ZONE;
UPDATE pasta_cliente SET vinculado_em = NOW() WHERE vinculado_em IS NULL;
ALTER TABLE pasta_cliente ALTER vinculado_em SET NOT NULL;
ALTER TABLE pasta_cliente ADD vinculado_por_id INT DEFAULT NULL;
-- FK + índice de vinculado_por_id, como no precedente (IDX_15973D81EDD250C1).
-- Sem eles: doctrine:schema:validate vermelho e id de usuário órfão possível.
ALTER TABLE pasta_cliente ADD CONSTRAINT fk_pasta_cliente_vinculado_por
  FOREIGN KEY (vinculado_por_id) REFERENCES "user" (id) ON DELETE SET NULL;
CREATE INDEX idx_pasta_cliente_vinculado_por ON pasta_cliente (vinculado_por_id);
-- troca a PK composta por id, mantendo a unicidade como índice
ALTER TABLE pasta_cliente DROP CONSTRAINT pasta_cliente_pkey;
ALTER TABLE pasta_cliente ADD PRIMARY KEY (id);
CREATE UNIQUE INDEX uniq_pasta_cliente ON pasta_cliente (pasta_id, cliente_id);
-- BACKFILL: marca como principal exatamente quem a tela JÁ mostra hoje (menor cliente_id).
UPDATE pasta_cliente pc SET principal = true
 WHERE pc.cliente_id = (SELECT MIN(x.cliente_id) FROM pasta_cliente x WHERE x.pasta_id = pc.pasta_id);
```

**Conferido no banco antes de escrever isto:** `SELECT ... FROM pg_constraint WHERE
confrelid='pasta_cliente'::regclass` devolve **zero linhas** — nenhuma tabela referencia
`pasta_cliente`, então trocar a PK composta por `id` não quebra FK alguma. O nome
`pasta_cliente_pkey` também confere.

**Falta escrever o `down()`** — numa migration que troca PK e adiciona coluna identity em tabela
populada, a regra da casa manda escrever e revisar o caminho de volta. E **backup do banco antes
de aplicar**, por ser mudança de chave primária em tabela com dado de produção.

**O backfill é a decisão de projeto mais importante da migration.** Ele reproduz o critério
antigo (menor `cliente_id`), então **nenhum número muda no dia do deploy** — a tela continua
exatamente como está, e só muda quando alguém marcar outro cliente de propósito. Uma feature que
mexe em indicador financeiro não pode alterar valores exibidos por efeito colateral da subida.

### 4. Onde a média passa a ler

`PastaController` [:301](../../app/src/Controller/PastaController.php#L301) e
[:1542](../../app/src/Controller/PastaController.php#L1542) trocam `getPrimeiroCliente()` por
`getClientePrincipal()`. `PastaRepository::mediaValorCausaPorCliente()` mantém assinatura e resultado — ela já recebe o
`Cliente` pronto e é agnóstica ao critério de escolha —, mas **o DQL dela muda**: o
`innerJoin('p.clientes', ...)` passa a atravessar o vínculo (ver o aviso de colisão acima). O
mesmo vale para os outros 3 joins em `p.clientes` do repositório.

`PeticionarController:60` (`getClientes()->first()`) passa a usar `getClientePrincipal()` — é o
que unifica os dois critérios divergentes.

### 4b. Os dois call sites que MATAM a feature se forem esquecidos

Estes não estão na lista de "trocar `getPrimeiroCliente()`", e são os que revertem a marcação sem
ninguém perceber. Ambos verificados no código.

**(a) `PastaController::syncClientes()`
([:2235-2246](../../app/src/Controller/PastaController.php#L2235-L2246))** — a cada edição da
pasta ele **remove todos** os clientes e **re-adiciona**:

```php
foreach ($pasta->getClientes()->toArray() as $clienteExistente) {
    $pasta->removeCliente($clienteExistente);
}
foreach ($clienteIds as $clienteId) { ... $pasta->addCliente($cliente); }
```

Com `pasta_cliente` promovida a entidade de vínculo, isso **apaga e recria as linhas**, zerando
`principal` e `vinculadoEm` em **toda** edição de pasta. É exatamente a regressão que a feature
existe para matar, reintroduzida por outra porta. `syncClientes()` tem de virar diferencial:
remover só quem saiu, adicionar só quem entrou, **preservar os vínculos que permaneceram**.

**(b) `PastaType`
([:32](../../app/src/Form/PastaType.php#L32))** — `->add('clientes', EntityType::class,
['multiple' => true])` é campo **mapeado** na ManyToMany. Uma coleção derivada não é gravável pelo
binding do form: se `getClientes()` virar derivada sem mexer no form, **editar pasta para de
salvar cliente, sem erro visível**. O campo precisa passar a operar sobre os vínculos (ou virar
`mapped: false` com o `syncClientes()` diferencial acima cuidando da escrita).

### 5. Tela

A lista de clientes **não** fica na aba Financeiro; fica na aba **Dados**
([show.html.twig:429-488](../../app/templates/pasta/show.html.twig#L429-L488)), num bloco inline.
A estrela entra nas "ações rápidas" por cliente (`show.html.twig:473-488`), ao lado de "abrir
ficha" e "desvincular" — mesmo lugar estrutural da estrela do processo.

⚠️ **Diferença de mecânica que vai dar trabalho:** a lista de processos é atualizada por *swap de
parcial* (`mpSwapProcessos`), mas a de clientes é **construída no browser por DOM em JS**
(`show.html.twig:1751`) e **não existe** `templates/pasta/_clientes.html.twig`. Para seguir o
padrão da casa é preciso **extrair o parcial `_clientes_vinculados.html.twig` primeiro** e passar
as três rotas de cliente a devolver `{sucesso, html}`. Isso é metade do custo da fatia e precisa
estar no plano desde o começo — não é detalhe de acabamento.

### 6. Testes que a fatia deve trazer

Espelhando os do precedente:

- **Unit** `DefinirClientePrincipalUseCaseTest` — marcar novo principal zera o anterior; cliente
  não vinculado lança `\DomainException`.
- **Unit** `VincularClienteUseCaseTest` — primeiro vínculo vira principal; segundo não rouba.
- **Unit** `DesvincularClienteUseCaseTest` — desvincular o principal **promove outro**;
  desvincular o último esvazia sem principal.
- **Functional** — CSRF inválido, cliente de outro tenant negado, sem permissão 403.
- **Functional, o que prova a feature de verdade:** com dois clientes vinculados e o **de id
  maior** marcado como principal, a tela mostra a média **dele** — o cenário que hoje é
  impossível. E o irmão: marcar o principal e depois vincular um cliente **mais antigo** não
  muda o número (é a regressão exata que a feature existe para matar).
- **Migration** — teste de backfill: pasta com dois clientes fica com exatamente um `principal`,
  e é o de menor `cliente_id`.

### 7. O teste existente que vai colidir

[PastaFinanceiroArranjoTelaTest.php:207](../../app/tests/Pasta/Functional/PastaFinanceiroArranjoTelaTest.php#L207)
— `testVariosClientesUsaODeCadastroMaisAntigo()` **trava o critério antigo** de propósito
(adiciona o cliente de id maior primeiro para provar que a ordem de vínculo não conta). Com a
feature ele passa a descrever o **comportamento de fallback**, não a regra: precisa ser reescrito
para "sem marcação explícita, manda o de cadastro mais antigo", e ganhar um irmão para o caso
marcado. Reescrever este teste é parte da fatia, não um dano colateral.

## Por que está parada

`docs/frentes-ativas.md` fixa: **uma frente com migration por vez**. Hoje a vaga está ocupada por
`cobranca-data-acordo-espelho`, que tem `Version20260817180000.php` commitada
(`data_acordo` → anulável) e está viva, aguardando revisão e smoke.

Esta fatia **exige** migration — a flag não tem onde morar sem promover `pasta_cliente` a
entidade. Então ela espera, por decisão de regra, não por dificuldade técnica.

**Para retomar:** quando `cobranca-data-acordo-espelho` for integrada, abrir
`scripts/frente-abrir.sh pasta-cliente-principal`, registrar em `docs/frentes-ativas.md` como
frente com migration, e executar desta spec. A ordem sugerida é: parcial `_clientes_vinculados`
extraído e sob teste → entidade `PastaCliente` + migration com backfill → UseCases → controller →
tela.
