# Cliente principal da pasta

> **Estado: IMPLEMENTADA em 2026-08-18** na frente `pasta-cliente-principal`. Suíte 3874/3874.

## O problema, com o número que ele move

A aba Financeiro da pasta mostra **"Média por CPF"**: a média do valor da causa de todas as
pastas de um mesmo cliente. Uma pasta pode ter **vários** clientes, e a média é de **um** só.
Quem escolhia era `Pasta::getPrimeiroCliente()`, pelo **id do cliente** — a ordem em que ele entrou
no cadastro do escritório. O docblock do próprio método já admitia: *"vincular depois um cliente
cadastrado há mais tempo **troca** o número mostrado na tela. É determinístico, não é estável."*

Um ato sem relação nenhuma com dinheiro (vincular mais um cliente) mexia num indicador financeiro,
sem avisar. É esse acoplamento que a fatia corta.

Havia ainda um **segundo critério divergente** para a mesma pergunta: `PeticionarController:60`
usava `getClientes()->first()`, a ordem arbitrária do banco. Duas telas respondiam diferente.

## A decisão de modelagem — e por que ela mudou

A primeira versão desta spec mandava **seguir o padrão do `PastaProcesso`**: promover
`pasta_cliente` a entidade de vínculo com uma flag `principal`. Ao levantar a superfície real de
uso, isso se mostrou caro demais para o que entrega:

| | Promover a junção a entidade | **Coluna em `pasta`** (escolhido) |
|---|---|---|
| Migration | troca a **PK** de tabela populada | `ADD cliente_principal_id`, aditiva e anulável |
| Mapeamento ManyToMany | **sai** | intacto |
| Templates Twig com `pasta.clientes` | 4 arquivos quebram (`[0]`, `|map`) | 0 |
| Joins DQL `p.clientes` | 4 reescritos | 0 |
| `PastaType` (campo mapeado) | quebra | intacto |
| Arquivos de teste com `addCliente()` | 4 | 0 |
| Unicidade "só um principal" | em memória, **sem trava no banco** | **garantida pelo banco** (é uma coluna) |

**O dono decidiu pela coluna em 18/08.** Vale registrar que ela não é o caminho "fraco": o
precedente dos processos mantém o invariante só em memória e não tem constraint nenhuma; uma coluna
não consegue apontar para dois clientes.

## O que foi construído

### Migration `Version20260818150000`

`ALTER TABLE pasta ADD cliente_principal_id INT DEFAULT NULL` + FK para `cliente` com
**`ON DELETE SET NULL`** + índice `idx_pasta_cliente_principal` (declarado também no mapeamento,
senão o Doctrine propõe renomeá-lo em todo `schema:update`).

**Sem backfill, de propósito.** `getClientePrincipal()` cai no critério antigo quando a coluna é
nula — o estado de 100% das pastas no instante do deploy. Logo **nenhum número muda de valor no dia
em que isto sobe**; a tela só muda quando alguém marcar. O `down()` é seguro: a coluna guarda uma
*preferência de exibição*, não um fato, e apagá-la devolve tudo ao critério automático.

### `Pasta` (a regra mora aqui, não no UseCase)

- `getClientePrincipal()` — o marcado **se ainda estiver vinculado**; senão, o de cadastro mais
  antigo. É a única resposta para "de quem é a média".
- `getClientePrincipalMarcado()` — a marcação crua, sem fallback. Sem ela a tela não distingue
  "escolha de alguém" de "padrão automático", porque a primeira nunca devolve nulo havendo cliente.
- `definirClientePrincipal()` — `\DomainException` se o cliente não estiver vinculado.
- `limparClientePrincipal()` — volta ao automático.
- `removeCliente()` — desvincular o principal **limpa a coluna**.
- `getPrimeiroCliente()` **saiu da API pública** e virou `clienteMaisAntigo()`, privado: era ele a
  única resposta, e é isso que fazia o número trocar sozinho.

🔑 **A guarda de "ainda vinculado" não é zelo.** `PastaType` mapeia `clientes` e o Symfony Form
mexe na coleção **direto** (`by_reference` padrão), sem passar por `removeCliente()` — então a
coluna *pode* ficar órfã. A guarda é o que impede a tela de mostrar a média de quem já saiu.

### Rota e tela

`POST /pasta/{id}/cliente/{cliente}/principal` (`pasta_cliente_principal`), CSRF
`pasta_cliente_principal_<pastaId>_<clienteId>` — mesma forma dos vizinhos `pasta_cliente_*`.
Sem permissão devolve **JSON 403 quando XHR** e o redirect do fluxo "pedir acesso" quando form
normal (devolver HTML a uma chamada AJAX faria o JS tentar interpretá-lo como JSON).

Na lista de clientes da aba **Dados**: estrela cheia = estado, estrela vazia = ação — igual ao
bloco de processos. A resposta XHR devolve a **média já recalculada**, porque a marcação existe
para mudar esse número e deixá-lo defasado esconderia o efeito da própria ação. O token CSRF vai
num `data-` da linha: quando a estrela cheia precisa virar botão de novo, o JS não teria de onde
tirá-lo.

`PeticionarController` passou a usar `getClientePrincipal()` — os dois critérios divergentes viraram um.

## O que os testes provam (e como isso foi verificado)

21 testes novos. Os três defeitos foram **reintroduzidos** para conferir que a suíte os pega:

| Defeito reintroduzido | Testes que ficaram vermelhos |
|---|---|
| ignorar a marcação (voltar ao critério antigo) | **7** |
| aceitar cliente não vinculado | **2** |
| não limpar a coluna ao desvincular | **1** |

⚠️ **O terceiro só passou a ser pego depois de um conserto no próprio teste.** Na primeira versão
ele não quebrava nada: os dois getters têm a guarda de "ainda vinculado", então a limpeza da coluna
é **invisível pelo comportamento**. Foi preciso um teste que lê `cliente_principal_id`
**direto no SQL**. Sem isso a asserção parecia forte e não era.

O teste decisivo é `testTelaMostraAMediaDoClienteMarcado`: abre a tela, confere o número **antes**
(critério automático), marca o outro cliente e confere que a tela passou a mostrar a média **dele**.
E `testVincularClienteMaisAntigoNaoTrocaONumeroNaTela` reproduz a regressão que a fatia existe para
matar.

## Achados de investigação (medidos, não herdados)

- **`PastaController::syncClientes()` é código morto** — declarado `private`, chamado em lugar
  nenhum em todo o `app/`. Uma revisão anterior o apontou como "apaga a marcação a cada edição de
  pasta"; ele apagaria **se fosse chamado**, e não é. Não foi tocado (fora do escopo), mas fica o
  registro: quem o ligar tem de torná-lo diferencial antes.
- **`ClienteRepository::findAll()`/`find()` sem filtro de tenant nos vizinhos não é vazamento**:
  `Cliente implements TenantAware` e o `TenantFilter` cobre a consulta. Conferido antes de reportar.
- **Um teste cross-tenant sem `$em->clear()` mente.** O meu falhou por isso e não por defeito de
  código: a pasta fica no cache de objetos do Doctrine, o `find` não chega ao banco e o filtro não
  tem o que filtrar. O teste vizinho de valor-causa já documentava isso.

## O que ficou de fora

- **A lista de clientes continua sendo montada por DOM em JS**, não por parcial Twig. Como a
  ManyToMany não mudou, extrair `_clientes_vinculados.html.twig` deixou de ser pré-requisito — e
  virou dívida opcional, não bloqueio.
- **Smoke no navegador**: é do dono. A suíte lê HTML e não vê posição, tamanho nem cor da estrela.
