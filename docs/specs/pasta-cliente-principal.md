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

🔑 **A guarda de "ainda vinculado" é defesa preventiva — e a primeira versão desta spec justificou
ela com um fato falso.** O texto anterior dizia que `PastaType` mapeia `clientes` e que o Symfony
Form mexe na coleção direto (`by_reference`), deixando a coluna órfã. **Medido na revisão: hoje
isso não acontece por caminho nenhum.** `PastaType` aparece uma única vez em todo o `app/` — a
própria declaração da classe; não há `createForm(PastaType::class)`. A rota de editar pasta monta
um `EditarPastaDTO` à mão e não toca em `clientes`. O mesmo vale para `syncClientes()`, que também
é código morto.

Ou seja: **nenhuma pasta tem coluna órfã hoje, e nenhum número na tela está errado por isso.** A
guarda fica porque é barata e porque `getClientePrincipal()` é chamado por quem grave a pasta por
qualquer porta — mas quem reativar `PastaType` ou `syncClientes()` herda a obrigação de mantê-la
verdadeira, e é para esse dia que ela existe.

*Registro do erro, não da correção: o autor rodou exatamente esse grep para `syncClientes()` e
afirmou a alegação irmã sem rodar. Prova por simetria não é prova.*

### Rotas e tela

| Ação | Rota | CSRF |
|---|---|---|
| Marcar / fixar | `POST /pasta/{id}/cliente/{cliente}/principal` (`pasta_cliente_principal`) | `pasta_cliente_principal_<pastaId>_<clienteId>` |
| Desmarcar | `POST /pasta/{id}/cliente/principal/limpar` (`pasta_cliente_principal_limpar`) | `pasta_cliente_principal_limpar_<pastaId>` |

A rota de desmarcar **não leva o cliente na URL** de propósito: só existe uma marcação por pasta,
então "qual limpar" não é pergunta — e exigir o id abriria a chance de limpar a errada mandando
outro. Conferido com `router:match` que os dois caminhos não colidem.

Sem permissão devolve **JSON 403 quando XHR** e o redirect do fluxo "pedir acesso" quando form
normal (devolver HTML a uma chamada AJAX faria o JS tentar interpretá-lo como JSON).

Na lista de clientes da aba **Dados**, a estrela tem **três estados, todos clicáveis**:

| Estado | Aparência | Clique faz |
|---|---|---|
| escolha explícita | cheia, azul sólido | **desmarca** — volta ao automático |
| principal automático | cheia, azul contornado | **fixa** este cliente |
| qualquer outro | vazia | **marca** este cliente |

🔑 **"Fixar o automático" não é enfeite — sem ele a feature não fecha o próprio problema.** O
defeito de origem é o número trocar sozinho quando alguém vincula um cliente de cadastro mais
antigo. Isso só para de acontecer quando existe marcação; se a estrela do principal automático
fosse apenas um estado (como na primeira versão), não haveria como proteger o número **sem antes
escolher outra pessoa**. O bloco de processos tem só os dois últimos estados: lá marcar é via de
mão única. A diferença é deliberada, por decisão do dono em 18/08.

A resposta XHR devolve a **média já recalculada** e o campo `marcado`, que é o que permite à tela
distinguir escolha de padrão. O token CSRF de marcar vai num `data-` da linha; o de desmarcar é um
só por pasta e é renderizado uma vez no JS.

**Vincular um cliente também mexe no principal automático** — se o novo for o de cadastro mais
antigo e ninguém tiver marcado nada, a média passa a ser dele. Por isso o JSON de vincular devolve
o estado do principal junto, e a tela se atualiza na hora em vez de mentir até um F5.

`PeticionarController` passou a usar `getClientePrincipal()` — os dois critérios divergentes viraram um.

## O que os testes provam (e como isso foi verificado)

Os defeitos foram **reintroduzidos e medidos** contra `tests/Pasta` (458 testes). Os números abaixo
são de execução, não de inspeção:

| Defeito reintroduzido | Testes vermelhos |
|---|---|
| `getClientePrincipal()` ignora a marcação (volta ao critério antigo) | **9** |
| `definirClientePrincipal()` aceita cliente não vinculado | **2** |
| `removeCliente()` não limpa a coluna | **1** |
| `limparClientePrincipal()` não faz nada (desmarcar inerte) | **4** |

⚠️ **A versão anterior desta tabela dizia 7 para o primeiro caso, e a revisão não conseguiu
reproduzir o número.** Ela contou 8 por inspeção; a medição dá 9. Duas lições, e a segunda é a que
importa: contagem de prova precisa dizer **qual mutação** e **contra qual conjunto de testes**, ou
ninguém consegue refazê-la — inclusive quem escreveu. As mutações exatas estão registradas acima
pelo nome do método alterado.

⚠️ **O terceiro só passou a ser pego depois de um conserto no próprio teste.** Na primeira versão
ele não quebrava nada: os dois getters têm a guarda de "ainda vinculado", então a limpeza da coluna
é **invisível pelo comportamento**. Foi preciso um teste que lê `cliente_principal_id`
**direto no SQL**. Sem isso a asserção parecia forte e não era.

⚠️ **E o teste do SQL ainda tinha uma brecha.** `fetchOne` devolve `false` para "não há linha" e
`null` para "coluna nula"; o helper achatava os dois em `null`, então a asserção decisiva passaria
também se a **pasta** tivesse sumido do banco. Agora a linha ausente falha o teste explicitamente.

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

- **A lista de clientes continua sendo montada por DOM em JS**, não por parcial Twig. A revisão
  mostrou que isso **não era dívida cosmética**: a linha criada por AJAX nascia sem estrela e com
  um container que o JS de troca nem enxergava, então um cliente recém-vinculado só podia ser
  marcado depois de um F5 — uma ação indisponível no caminho feliz. Foi consertado ligando as duas
  montagens à mesma função (`montaEstrelaPrincipal`) e ao mesmo gancho (`.js-cliente-acoes`), em
  vez de amarrar o JS a classes de layout. Extrair o parcial Twig segue sendo dívida **opcional**.
- **As listagens de pastas ainda mostram `pasta.clientes[0]`** (`_tabela.html.twig`,
  `_card.html.twig`, `demandas/_resultado.html.twig`), não o principal. Quem mover a estrela e for
  à listagem vai estranhar. **Fatia própria** — toca arquivos compartilhados de listagem, e a
  decisão de estendê-la para lá é do dono.
- **O UseCase não valida tenant** (`DefinirClientePrincipalUseCase`, `LimparClientePrincipalUseCase`).
  A proteção inteira mora no `EntityValueResolver` + `TenantFilter`, que o listener liga **por
  request**: chamado de CLI, nada impede marcação cross-tenant. **Não é regressão** — o precedente
  `DefinirProcessoPrincipalUseCase` é idêntico —, mas fica registrado para não passar como coberto.
- **Smoke no navegador**: é do dono. A suíte lê HTML e não vê posição, tamanho nem cor da estrela.

## O que precisa ser olhado na tela (roteiro do smoke)

O dev **não tem a coluna** (`cliente_principal_id` não existe em `saas_ux`): a tela da pasta não
abre até a migration rodar lá. É o mesmo tropeço da frente `pasta-valor-causa`.

1. Pasta com **dois clientes ou mais**, aba **Dados**: cada cliente tem estrela, "abrir ficha" e
   "desvincular", nessa ordem.
2. Sem ninguém marcado: a estrela do cliente de cadastro mais antigo está **cheia e contornada**;
   clicar nela deixa-a **cheia e sólida** (fixou), e a Média por CPF **não muda de valor**.
3. Clicar na estrela vazia de outro cliente: ela fica sólida, a anterior esvazia, e a **Média por
   CPF muda** para a do cliente escolhido, sem recarregar a página.
4. Clicar na estrela sólida: ela volta a contornada e a média volta ao critério automático.
5. **Vincular um cliente novo pelo modal**: a linha nasce **com estrela clicável** (não precisa F5).
6. Sem permissão de edição na pasta: a estrela não grava e aparece aviso, não uma tela quebrada.
