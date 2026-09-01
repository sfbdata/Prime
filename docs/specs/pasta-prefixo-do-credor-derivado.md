# ⛔ DESCARTADA — não implementar

> **Decisão do dono em 2026-09-01, no mesmo dia em que esta spec foi escrita.** Nada daqui foi
> implementado, e nada daqui deve ser implementado. O arquivo fica no repositório porque as medições
> das §3 a §7 são verdadeiras e caras de refazer — mas o **desenho** foi substituído.
>
> **O que a substituiu:** o dono reformulou o problema em termos de responsabilidade, não de
> mecanismo. `pasta.nome_cliente` **é o IDENTIFICADOR da pasta** — um campo com dono e função
> próprios, que continua valendo depois de o cliente ser cadastrado e nunca é sobrescrito por ele. O
> cliente cadastrado (`pasta_cliente`) é outra coisa, com outra função. Os dois convivem.
>
> **Por que isso dispensa toda esta spec:** o prefixo pode continuar **gravado** no identificador. Ele
> não precisa ser derivado da carteira, porque o problema nunca foi "o prefixo está guardado no lugar
> errado" — era "as telas tratavam o campo como um nome provisório e o descartavam quando havia
> cliente cadastrado". Isso foi corrigido em `ef56cad8` (exibição e ordenação) e `22d106c9` (o
> formulário parou de apagar o campo).
>
> 🔑 **A prova de que o modelo novo é o certo:** o campo só é escrito em DOIS lugares —
> `CriarPastaUseCase` e `EditarPastaUseCase`. **Nunca existiu substituição automática no código.** O
> "nome provisório" era uma ficção que vivia só na camada de exibição, e produziu três defeitos com a
> mesma forma (*"havendo cliente cadastrado, ignore o `nome_cliente`"*): o formulário que apagava, a
> coluna que escondia o prefixo e a ordenação que ordenava pelo nome errado.
>
> **Duas consequências que o dono aceitou de olhos abertos:** o rótulo da tela continua dizendo
> "Cliente" (a separação vale no código, não nas palavras), e o identificador **pode envelhecer** —
> corrigir o nome da pessoa no cadastro não atualiza o identificador da pasta, e isso é o desejado,
> porque ele é o título da pasta e não a ficha da pessoa.

---

## (histórico) O desenho descartado: o prefixo do credor montado na exibição

> Escrita em 2026-09-01. Risco BAIXO pela escala do projeto (não toca dinheiro, ponto eletrônico nem
> permissão), mas atravessa **quatro** domínios — Pasta, Cobrança, Expediente e Sync.

## 1. Por que mudar o que acabou de entrar

A entrega de 01/09 (`4943e19e`, spec `cobranca-judicializar-cria-pasta.md` §2.5) **gravava** o texto
composto em `pasta.nome_cliente`:

```
APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ
```

Funcionou, mas expôs uma fragilidade no mesmo dia: **para o sistema aquilo é só texto**. Um usuário
salvou `APLC TOP LIFE 1 -` — prefixo e traço, sem o nome da pessoa — e o sistema gravou, porque não
sabe que aquela frase "deveria" terminar num nome. Duas pastas de produção ficaram assim.

O dono decidiu: **a tela monta `CREDOR - DEVEDOR` na hora de exibir**. O campo gravado passa a
guardar só o devedor, e o prefixo deixa de ser digitável — logo, deixa de ser apagável.

## 2. As três decisões do dono (2026-09-01) — não reabrir

1. **O prefixo vem da carteira a cada exibição**, não de um campo guardado na pasta. Consequência
   aceita: mudar o nome fantasia do cliente corrige **todas** as pastas de uma vez, e nunca há
   divergência entre o cadastro e a tela.
2. **Buscar por `APLC` no Expediente TEM de achar**, e ordenar por Cliente tem de considerar o
   prefixo. Consequência aceita: a consulta da listagem ganha o caminho da cobrança.
3. **O nome da pasta no Google Drive leva o prefixo**, igual ao que aparece na tela. Consequência
   aceita: a mesma resolução também no Sync.

## 3. 🔑 O que torna a migração barata

O texto que a tela vai montar é **idêntico** ao que está gravado hoje. Então, ao mover o prefixo de
"gravado" para "derivado":

| | antes | depois |
|---|---|---|
| `pasta.nome_cliente` | `APLC TOP LIFE 1 - CLAUDIO…` | `CLAUDIO…` |
| o que a tela mostra | `APLC TOP LIFE 1 - CLAUDIO…` | `APLC TOP LIFE 1 - CLAUDIO…` |
| nome da pasta no Drive | `1255 - APLC TOP LIFE 1 - CLAUDIO… - AÇÃO MONITÓRIA` | **igual** |

**Nenhuma pasta do Drive precisa ser renomeada** — o nome esperado não muda. Isso só vale porque a
composição no Sync (decisão 3) entra na mesma entrega que a limpeza do campo. Separar as duas
provocaria uma onda de renomeações no Drive entre uma e outra.

## 4. 🔴 A armadilha do prefixo em dobro

As pastas judicializadas de hoje **já têm o prefixo gravado**. Se a tela passar a prefixar sem que o
campo seja limpo, sai:

```
APLC TOP LIFE 1 - APLC TOP LIFE 1 - LEANDRO DA SILVA E SOUSA
```

Por isso duas mudanças são **inseparáveis** e vão na mesma entrega:

1. a judicialização para de compor e grava só o nome da pessoa cobrada;
2. um comando remove o prefixo já gravado das pastas existentes.

Medido em produção em 01/09: **6 pastas** têm credor a resolver. O comando roda em simulação por
padrão e mostra cada troca antes de gravar (padrão dos comandos de produção deste projeto).

## 5. Onde o prefixo é resolvido

A ligação hoje é de **mão única**: `CasoCobranca.pastaJudicial → Pasta`. A Pasta não conhece o Caso.
A resolução percorre o caminho inverso:

```
pasta ← caso.pastaJudicial → caso.objeto → objeto.carteira → carteira.cliente → ClientePJ.nomeFantasia
```

**Quem resolve é a Cobrança, não a Pasta.** Um método novo no `CasoCobrancaRepository` devolve o mapa
`pastaId → nome fantasia` para uma lista de pastas, em **uma** consulta. A Pasta e o Expediente
apenas perguntam — o conhecimento de cobrança não vaza para o domínio da Pasta.

### 5.1 Como a tela chega ao valor sem N+1 e sem esquecer nenhuma tela

Passar o mapa por parâmetro obrigaria **todas** as telas que renderizam `_tabela`/`_card` a lembrar
de passá-lo; a que esquecesse ficaria sem prefixo em silêncio. Em vez disso:

- um serviço `ResolvedorPrefixoDoCredor` com memória por requisição;
- `primeParaPastas(iterable)` carrega o mapa da página inteira numa consulta — o controller chama;
- uma função Twig `prefixo_credor(pasta)` lê da memória.

Se alguém esquecer de primar, a função ainda devolve o valor certo (consulta avulsa): o custo é
performance, **nunca** prefixo faltando na tela. É a escolha deliberada — degradar em velocidade, não
em correção.

## 6. Onde a composição aparece

| Lugar | Arquivo | O que muda |
|---|---|---|
| Tabela do Expediente | `pasta/_tabela.html.twig` | coluna Cliente monta `prefixo - nome` |
| Cartão (modo lista) | `pasta/_card.html.twig` | idem |
| Resultado de Demandas | `pasta/demandas/_resultado.html.twig` | idem |
| Cabeçalho da pasta | `pasta/_cabecalho.html.twig` | idem, onde exibe o nome da pasta |
| Formulário de edição | `pasta/show.html.twig` | prefixo vira **rótulo fixo** ao lado do campo; o campo guarda só o devedor |
| Nome no Drive | `Sync/Service/ReconciliadorDePasta` e `SincronizacaoPastaDispatcher` | `nomeEsperado` recebe o nome composto |

## 7. Busca e ordenação (decisão 2)

- **Busca livre e filtro de cliente:** um `EXISTS` correlacionado sobre o caminho da cobrança, somado
  aos campos que a busca já cobre. `EXISTS` não multiplica linhas — é por isso que não é `JOIN`.
- **Ordenação por cliente:** aí o `JOIN` é necessário (DQL não aceita subconsulta escalar no
  `ORDER BY`), e ele entra **apenas** no ramo `case 'cliente'` do `aplicarOrdenacao`, que já faz
  `JOIN` hoje. A consulta das outras ordenações não muda.
- Precedência do `COALESCE`, espelhando a tela: `CONCAT(fantasia, ' - ', nomeCliente)` → `nomeCliente`
  → nome do cliente cadastrado.

## 8. O que NÃO muda

- **O cadastro do devedor.** O `ClientePF` criado pela judicialização continua saindo da ficha da
  pessoa cobrada, sem prefixo (ver `cobranca-judicializar-cria-pasta.md` §3).
- **As 1.093 pastas sem credor.** Sem caso de cobrança não há prefixo, e a coluna segue exibindo o
  campo como hoje.
- **A precedência da coluna Cliente** (`ef56cad8`): nome da pasta primeiro, cliente cadastrado como
  reserva. O prefixo entra por cima disso.

## 9. Prova

Cada ponto abaixo precisa de teste **e** de prova por reintrodução — a suíte é cega para aparência, e
nesta frente um teste já ficou verde com o defeito de pé (o fragmento do acervo traz tabela e cartões
juntos, e a asserção no corpo inteiro era satisfeita pelo cartão; ancorar em cada modo).

1. pasta com credor → a tabela mostra `FANTASIA - DEVEDOR`;
2. o mesmo no cartão e em Demandas;
3. pasta sem credor → mostra o campo, sem prefixo e sem traço solto;
4. **prefixo em dobro**: campo já contendo o prefixo não produz `APLC … - APLC …` depois do comando;
5. buscar pela fantasia acha a pasta; buscar pelo devedor continua achando;
6. ordenar por Cliente segue o composto;
7. o nome esperado no Drive leva o prefixo;
8. o formulário de edição **não** deixa o prefixo ser digitado nem apagado;
9. o comando de limpeza simula por padrão e é idempotente (rodar duas vezes não tira nada a mais).

## 10. Ordem de execução

1. resolução (repositório + serviço + função Twig) e seus testes;
2. exibição nas 5 telas;
3. busca e ordenação;
4. Sync (nome no Drive);
5. judicialização para de compor;
6. comando de limpeza — **por último**, porque só depois de 4 e 5 o campo pode perder o prefixo sem
   mudar o nome no Drive;
7. rodar o comando em produção, com simulação antes.
