# Judicializar passa a CRIAR a pasta, em vez de pedir uma existente

> Decisão do dono em 2026-08-27. Risco BAIXO (não toca dinheiro, ponto eletrônico nem permissão),
> mas atravessa três domínios — Cobrança, Pasta e Cliente —, e por isso a spec existe.

## 1. O que muda

Hoje o botão **Judicializar** ([`show.html.twig:197`](../../app/templates/cobranca/objeto/show.html.twig))
abre um modal com um `<select>` das pastas do escritório: o gestor tem de ter aberto a pasta antes,
à mão, e escolhê-la na lista.

Passa a ser: **um clique abre o modal já preenchido**, e o botão principal **cria a pasta**.

| campo da pasta nova | de onde vem |
|---|---|
| número (NUP) | gerado pelo sistema (`GerarNumeroDePasta`), como em qualquer pasta |
| **nome do cliente** | nome do **responsável principal** — a pessoa cobrada atual do caso |
| **ação** | **`AÇÃO MONITÓRIA`**, fixo: é a ação de todos os casos de cobrança |
| **cliente principal** | cadastrado a partir da ficha do responsável, quando ela tem CPF (§3) |

Os dois campos de texto ficam **editáveis** no modal — decisão do dono: ele vê antes de criar e pode
corrigir. Vincular uma pasta **já existente** continua possível, como caminho secundário (§4).

## 2. As quatro decisões do dono (2026-08-27) — não reabrir

1. **O nome do cliente da pasta é o DEVEDOR**, não o condomínio credor. Foi perguntado com as três
   opções na mesa (condomínio da carteira / devedor / os dois combinados) e ele escolheu o devedor.
   ⚠️ Consequência aceita: o campo `pasta.nome_cliente` passa a guardar, nestas pastas, a parte
   **contrária** — não o cliente do escritório. Quem for calcular indicador por cliente precisa
   saber disso.
2. **O responsável principal vira o `clientePrincipal` cadastrado da pasta** (registro `ClientePF`),
   não só texto.
3. **O clique abre modal de confirmação**, com os campos preenchidos e editáveis — não cria direto.
4. **Vincular pasta existente continua existindo**, como opção secundária.

## 3. 🔴 O RG em branco — decisão consciente, NÃO é bug

`cliente_pf.rg` e `cliente_pf.rg_orgao_expedidor` são **`NOT NULL`** no banco e obrigatórios no
formulário de cliente (`ClientePFType`, e a validação à mão em `PastaController::novoCliente`).

**Medido no dev (dataset real de produção), em 2026-08-27:**

| | |
|---|---:|
| casos de cobrança, todos com responsável | **248** |
| responsáveis com CPF | **46** (18,5%) |
| responsáveis com CNPJ | **0** — todos PF |
| com endereço atual | 46 |
| com CPF + endereço + e-mail | 43 |
| **pessoas cobradas com RG e órgão expedidor** | **0 de 260** |

Ou seja: cadastrar o `ClientePF` do jeito que a entidade pede **falharia em 100% dos casos** — o
`INSERT` bate no `NOT NULL` do RG.

✅ **Decisão do dono: gravar RG e órgão expedidor em BRANCO (`''`).** Sem migration, sem tornar o
campo opcional para o resto do sistema. O cadastro nasce incompleto **de propósito**.

⚠️ **A consequência, aceita:** ao abrir esse cliente na tela de edição, o formulário vai exigir RG e
órgão antes de salvar qualquer outra coisa. Isso é esperado — não "consertar" apagando o cadastro
nem afrouxando a validação da tela sem decisão nova.

📌 **O conserto honesto (fatia futura, se o dono quiser):** migration tornando RG/órgão anuláveis no
`cliente_pf`. Ficou fora daqui porque mexe no domínio Cliente inteiro e a regra da casa é uma frente
com migration por vez.

### 3.1 Quando o cliente É cadastrado

Só quando o responsável principal tem **CPF**. Sem CPF não há identidade para cadastrar, e a pasta
nasce só com nome + ação — que é o caso de 202 dos 248 hoje. **Isso não é falha: é o dado que falta.**

Mapeamento `Pessoa` (cobrança) → `ClientePF`:

| campo do cliente | origem | quando falta |
|---|---|---|
| `nomeCompleto` | `pessoa.nome` | nunca falta (`NOT NULL`) |
| `cpf` | `pessoa.cpf`, **formatado** `000.000.000-00` | não cadastra |
| `rg`, `rgOrgaoExpedidor` | — | `''` (§3) |
| `email` | `pessoa.getEmail()` (já resolve o "atual") | `''` |
| `cep`, `endereco`, `cidade`, `estado`, `complemento` | endereço **atual** da ficha | `''` |
| `telefoneCelular` | `pessoa.getTelefone()` (já resolve o "atual") | fica nulo |
| `dataNascimento`, `estadoCivil`, `profissao` | ficha | ficam nulos |

🔑 **O CPF é gravado FORMATADO** porque `cliente_pf.cpf` é `varchar(14)` — o tamanho exato da máscara —
e a tela de cadastro usa `000.000.000-00`. A cobrança guarda **11 dígitos**, sem máscara: a conversão
é aqui.

🔑 **Nome maior que 50 caracteres NÃO cadastra.** `cliente_pf.nome_completo` é `varchar(50)` e
`cobranca_pessoa.nome` é `varchar(255)`. Truncar o nome de uma parte processual seria inventar dado;
o maior hoje tem 38 caracteres, então isto não dispara — mas se disparar, a pasta nasce sem o
cadastro em vez de nascer com meio nome.

### 3.2 CPF repetido REUSA o cliente, não recusa

`PastaController::novoCliente` **recusa** com "Já existe um cliente cadastrado com o CPF X". Aqui
recusar quebraria a judicialização por um motivo que não é do gestor. Então: se já existe um
`ClientePF` com aquele CPF **no mesmo escritório**, a pasta **vincula o que existe**.

A busca normaliza o CPF da cobrança com `NormalizadorDocumento::apenasDigitos` e procura pelas **duas
convenções que convivem no banco** — só dígitos e mascarado (`ClientePFRepository::findOneByCpfDoTenant`).
Em dev há 3 clientes com máscara e 1 sem; comparar a string crua criaria o duplicado que a regra
existe para evitar.

🔑 Não é `REPLACE` em DQL de propósito: Doctrine ORM 3 não tem essa função de string, e comparar as
duas formas conhecidas resolve o caso real sem SQL nativo nem varredura da tabela.

## 4. Vincular pasta existente

Continua no mesmo modal, num bloco abaixo do de criar, separado por um `<hr>`. O modo é um `radio`
de verdade (`ChoiceType` `expanded`), então o caminho secundário **funciona sem JavaScript**.

⚠️ Dois detalhes do Symfony que já custaram uma rodada:

- o grupo do modo é renderizado **inteiro**, com `form_row`. Renderizar só um dos rádios deixa o
  `modo` como campo não-renderizado, e o `form_end` reimprime o grupo completo no rodapé — os dois
  rádios apareceriam **duas vezes**, e o segundo par (sem `checked`) venceria o primeiro. Travado por
  teste (`testModalAbrePreenchido` conta 2 rádios, não 4);
- **não passar `required: false`** no `ChoiceType` `expanded`: isso acrescenta um **terceiro** rádio,
  o vazio, e o modal passa a oferecer uma opção que não existe.

Um único formulário, uma única rota (`cobranca_caso_judicializar`), com validação condicional por
`#[Assert\Callback]` no Input — o padrão que 7 DTOs da Cobrança já usam.

🔑 `$modo` é **anulável** na entidade de entrada: o `ChoiceType` escreve `null` quando o rádio não vem
no payload, e uma propriedade `string` estouraria com `TypeError` antes de qualquer validação. Quem
decide é `ehModoCriar()`, que trata ausente como **criar**.

## 5. Contrato

**`JudicializarCasoInput`**

```php
public ?int $casoId = null;            // da rota
public string $modo = 'criar';         // 'criar' | 'vincular'
public ?string $nomeCliente = null;    // modo criar — obrigatório
public ?string $nomeAcao = null;       // modo criar — obrigatório
public ?int $pastaId = null;           // modo vincular — obrigatório
```

**`JudicializarCasoUseCase::executar()`** — as guardas de hoje continuam **na mesma ordem** (caso do
tenant → não encerrado → não judicializado) e só então o modo decide:

- `vincular`: resolve a pasta por `id + tenant` (guarda anti-IDOR de hoje, intacta);
- `criar`: `CriarPastaUseCase` com NUP automático; se o responsável tem CPF, resolve o cliente
  (§3.1/§3.2) e `pasta->addCliente()` — que já grava o principal no primeiro vínculo.

O resto é igual: `setPastaJudicial`, status `judicializado`, e os **dois** eventos de histórico
(`Judicializacao` + `VinculoPasta`), com flush único no último. A mensagem do segundo evento diz se
a pasta foi **criada** ou **vinculada** — o histórico tem de distinguir os dois.

**Sincronização com o Drive:** o `SincronizacaoPastaDispatcher` mora na camada de **controller** de
propósito (o reconciliador chama os mesmos UseCases e um dispatch lá dispararia durante a própria
reconciliação). Então quem despacha é o `CasoController`, depois do sucesso — e **só no modo criar**.

⚠️ **Limite conhecido, medido e aceito:** o `CriarPastaUseCase` abre e **commita a própria transação**
(é o que dá validade à trava por escritório do `GerarNumeroDePasta`). Por isso a criação da pasta não
volta atrás se algo estourar depois dela — no flush dos eventos, por exemplo. As três guardas de
negócio rodam **antes** da criação, que é o caminho realista de recusa e está travado por teste; o que
sobra é falha de infraestrutura, e aí a pasta fica no acervo sem caso ligado. Resolver isso exigiria
uma transação abrangendo os dois UseCases, e essa é a transação que a trava de numeração não aceita.

## 6. Multi-tenancy

- O caso é resolvido por `id + tenant` (já era).
- A pasta criada nasce com **o tenant do caso**, não com o da sessão.
- A busca de cliente por CPF é escopada por `tenant` **explicitamente** no critério — não apenas
  pelo `TenantFilter` de sessão, que fica desligado em CLI/super-admin.
- O gate do módulo `pastas` continua valendo para a rota inteira.

## 7. O que os testes têm de provar

1. modo `criar` sem CPF no responsável: pasta nasce com nome + `AÇÃO MONITÓRIA`, **sem** cliente;
2. modo `criar` com CPF: cliente cadastrado, CPF **formatado**, RG e órgão `''`, virou o principal;
3. CPF já cadastrado no escritório: **reusa**, não duplica — inclusive quando as máscaras diferem;
4. nome com mais de 50 caracteres: pasta nasce, cliente **não**;
5. modo `vincular`: continua idêntico ao de hoje, com a guarda anti-IDOR (pasta de outro tenant);
6. as três guardas de hoje (caso de outro tenant, encerrado, já judicializado) nos **dois** modos;
7. o histórico distingue pasta criada de pasta vinculada.

### 7.1 Estado — entregue e provado em 2026-08-27

Suíte completa **4.086/4.086**. Os testes acima estão em `JudicializarCasoUseCaseTest` (unit) e
`JudicializarMutacaoControllerTest` (functional), e **quatro deles foram provados por reintrodução do
defeito** — apagou-se a correção, viu-se vermelho, restaurou-se:

| defeito reintroduzido | teste que morreu |
|---|---|
| gravar o CPF cru, sem máscara | `testJudicializarCadastraOResponsavelComoClientePrincipal` |
| nunca reusar o cliente do mesmo CPF | `testJudicializarReusaClienteDoMesmoCpf` (criou cliente duplicado) |
| modal voltar a abrir vazio | `testModalAbrePreenchido` |
| criar a pasta ANTES das guardas | `testCriarEmCasoJaJudicializadoNaoDeixaPastaOrfa` **e** `noModoCriarAsGuardasRodamAntesDeAbrirAPasta` |

⚠️ **O que a suíte NÃO prova, e por isso precisa do smoke do dono:** aparência do modal e o fluxo no
navegador. Teste de PHPUnit lê HTML — não vê posição, tamanho nem se o rádio está visível.
