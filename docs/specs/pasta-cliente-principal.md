# Cliente principal da pasta

> **Estado: IMPLEMENTADA em 2026-08-18** na frente `pasta-cliente-principal`.
>
> ⚠️ **O critério automático foi TROCADO por decisão do dono depois da primeira entrega.** A versão
> integrada em `b6d25e5b` escolhia o cliente de cadastro mais antigo quando ninguém tinha marcado,
> e oferecia um botão de desmarcar. Isso saiu. Vale o que está escrito abaixo.

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

Nasceu **sem backfill**, porque no desenho original "coluna nula" queria dizer "use o critério
automático". Com a troca de regra isso deixou de valer — ver a migration seguinte.

### Migration `Version20260818190000` (backfill)

Preenche `cliente_principal_id` nas pastas que já têm cliente, com **`MIN(cliente_id)`** — que é
**exatamente quem a tela já mostrava**. Logo **nenhum número muda de valor** no dia em que sobe: o
que muda é a resposta parar de ser recalculada e passar a ficar gravada.

🔑 **Por que `MIN(cliente_id)` e não "o primeiro vinculado", que é a regra nova:** `pasta_cliente`
tem só `pasta_id` e `cliente_id` — **nenhuma coluna de data ou sequência**. A ordem de vínculo do
passado nunca foi registrada e **não é recuperável**. Inventar uma ordem para decidir de quem é a
média seria dado chutado movendo número na tela — o defeito que este projeto já pagou caro na data
dos acordos. Então o passado fica com o critério que a tela já usava, e "primeiro vinculado" vale do
primeiro vínculo **novo** em diante, onde é fato e não suposição.

Escopo real, medido na PROD antes de escrever: **1.070 pastas e 1 vínculo** pasta↔cliente no total,
numa única pasta de um único cliente — onde `MIN()` é a resposta certa sem ambiguidade. O dev bate.

O `down()` é **deliberadamente inerte**: não há como distinguir o que esta migration escreveu de uma
marcação feita à mão depois, e reverter mexeria em escolha de usuário.

### A regra: o primeiro vínculo grava, e nada é recalculado depois

🔑 **O INVARIANTE:** pasta com cliente **nunca** fica sem principal, e ele é o **primeiro cliente
vinculado à pasta** ou **quem o dono marcou** pela estrela.

O que isso corrige, e é a fatia inteira: antes a resposta era **recalculada a cada leitura** pelo
cliente de cadastro mais antigo. Por isso vincular depois alguém cadastrado há mais tempo trocava o
número na tela. Agora a resposta é **gravada uma vez** e só muda por ato explícito.

| Método | O que faz |
|---|---|
| `addCliente()` | grava o principal **se o campo estiver vazio**; do segundo vínculo em diante não mexe |
| `definirClientePrincipal()` | a troca pela estrela; `\DomainException` se o cliente não estiver vinculado |
| `removeCliente()` | se sair o principal, **promove** outro (não limpa) — a pasta não pode ficar sem |
| `getClientePrincipal()` | devolve o gravado, se ainda vinculado; senão o fallback determinístico |
| `clienteMaisAntigo()` | privado. **Não é mais critério** — é só o desempate usado ao promover e no fallback |

**A guarda `=== null` do `addCliente()` é o "e depois nunca mais automático".** Sem ela, cada
vínculo novo roubaria o principal — outra forma exata do mesmo defeito. É a mutação B da tabela de
provas, e ela derruba 6 testes.

**O fallback do `getClientePrincipal()` não é o critério antigo voltando pela janela.** Ele cobre
dois casos em que a coluna fica nula com clientes na pasta, e só eles:

- o cliente marcado ser **excluído do sistema** — a FK é `ON DELETE SET NULL`, o banco zera a coluna
  sem passar por método nenhum da entidade;
- pasta gravada antes desta regra, por caminho que não use `addCliente()`.

Sem o fallback, nesses casos a média sumiria da tela. `getClientePrincipalMarcado()` e
`limparClientePrincipal()` **foram removidos**: com principal sempre presente, "escolha × padrão"
deixou de ser distinção que a tela precise fazer.

### Rota e tela

`POST /pasta/{id}/cliente/{cliente}/principal` (`pasta_cliente_principal`), CSRF
`pasta_cliente_principal_<pastaId>_<clienteId>` — mesma forma dos vizinhos `pasta_cliente_*`.
Sem permissão devolve **JSON 403 quando XHR** e o redirect do fluxo "pedir acesso" quando form
normal (devolver HTML a uma chamada AJAX faria o JS tentar interpretá-lo como JSON).

**Uma rota só.** A de desmarcar (`pasta_cliente_principal_limpar`) existiu entre `b6d25e5b` e esta
versão e foi removida: "desmarcar" queria dizer *voltar ao automático*, e não há mais automático
para onde voltar. O que existe é **trocar**.

Na lista de clientes da aba **Dados**, a estrela tem **dois** estados: **cheia** = é o principal
desta pasta; **vazia** = clique para que passe a ser. A resposta XHR devolve a **média já
recalculada**, porque a marcação existe para mudar esse número e deixá-lo defasado esconderia o
efeito da própria ação.

**Vincular um cliente também mexe no principal** — se for o primeiro, ele nasce principal. Por isso
o JSON de vincular devolve o estado do principal junto, e a tela se atualiza na hora.

`PeticionarController` usa `getClientePrincipal()` — os dois critérios divergentes viraram um.

## O que os testes provam (medido, não estimado)

Os defeitos foram **reintroduzidos e medidos** contra `tests/Pasta` (451 testes). Cada linha diz
qual método foi mutado — sem isso a conta não é refazível por ninguém, nem por quem a escreveu:

| Defeito reintroduzido | Testes vermelhos |
|---|---|
| `addCliente()` **grava sempre** (cada vínculo novo rouba o principal) | **6** |
| `addCliente()` **não grava** no primeiro vínculo (volta ao recalculado) | **3** |
| `definirClientePrincipal()` aceita cliente não vinculado | **2** |
| `removeCliente()` **limpa** em vez de promover | **1** |

O teste que carrega a regra é
`PastaFinanceiroArranjoTelaTest::testVariosClientesUsaOPrimeiroVinculado`, montado **contra** o
critério antigo: o cliente vinculado primeiro é o de **id maior**, então a tela só mostra o número
certo se a regra nova estiver valendo. Pelo critério anterior ele mostraria o outro cliente.

⚠️ **Histórico que vale manter:** a versão anterior desta tabela afirmava "7 testes" para um caso
que a revisão não conseguiu reproduzir (ela contou 8 por inspeção; a medição deu 9). Foi o que
motivou passar a registrar **qual mutação** e **contra qual conjunto**.

⚠️ **A asserção que lê o SQL direto continua sendo a decisiva, e mudou de sentido.** Antes provava
que desvincular *limpava* a coluna; agora prova que *grava o promovido*. A diferença importa: como
`getClientePrincipal()` tem fallback, se a promoção não gravasse o getter ainda responderia certo e
o teste passaria por acidente. Quem impede o número de mudar na próxima leitura é o **banco**.

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
- **O UseCase não valida tenant** (`DefinirClientePrincipalUseCase`).
  A proteção inteira mora no `EntityValueResolver` + `TenantFilter`, que o listener liga **por
  request**: chamado de CLI, nada impede marcação cross-tenant. **Não é regressão** — o precedente
  `DefinirProcessoPrincipalUseCase` é idêntico —, mas fica registrado para não passar como coberto.
- **Smoke no navegador**: é do dono. A suíte lê HTML e não vê posição, tamanho nem cor da estrela.

## O que precisa ser olhado na tela (roteiro do smoke)

⚠️ **As duas migrations têm de estar aplicadas nos DOIS bancos** (`saas_ux` para a tela, `saas_test`
para a suíte). Sem a primeira a tela da pasta nem abre.

1. Pasta com **dois clientes ou mais**, aba **Dados**: cada cliente tem estrela, "abrir ficha" e
   "desvincular", nessa ordem.
2. **Um cliente da lista já está com a estrela cheia** — não existe pasta com cliente e sem
   principal. Não há botão de desmarcar em lugar nenhum.
3. Clicar na estrela **vazia** de outro cliente: ela fica cheia, a anterior esvazia, e a **Média por
   CPF muda** para a do escolhido, sem recarregar a página.
4. **Vincular um cliente novo pelo modal**: a linha nasce **com estrela clicável** (não precisa F5),
   e a estrela cheia **continua onde estava** — vincular não rouba o principal.
5. **Numa pasta sem cliente nenhum**, vincular o primeiro: ele já nasce com a estrela **cheia**.
6. **Desvincular quem está com a estrela cheia**: a estrela passa para outro cliente que sobrou; a
   pasta não fica sem principal.
7. Sem permissão de edição na pasta: a estrela não grava e aparece aviso, não uma tela quebrada.
