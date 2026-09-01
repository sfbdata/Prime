# O identificador da pasta judicializada é derivado do credor e do devedor

> **Decisão do dono em 2026-09-01.** Risco BAIXO pela escala do projeto (não toca dinheiro, ponto
> eletrônico nem permissão), mas atravessa quatro domínios — Pasta, Cobrança, Expediente e Sync.

## 0. Histórico da decisão — ela mudou duas vezes no mesmo dia, e isso importa

Quem ler isto daqui a seis meses precisa saber que o caminho não foi reto:

1. **manhã** — o prefixo passou a ser **gravado** no identificador pela judicialização (`4943e19e`).
   Funcionou; foi para produção;
2. **tarde** — apareceu a fragilidade (alguém salvou `APLC TOP LIFE 1 -`, sem o nome). Escrevi esta
   spec propondo derivar o prefixo. O dono então reformulou o problema em termos de
   **responsabilidade** — o campo é o identificador da pasta, e ponto — e a spec foi **descartada**
   (`a44a3b1c`), porque com o campo respeitado pelas telas o prefixo podia continuar gravado;
3. **noite** — pensando em otimização, o dono concluiu que, **para as pastas judicializadas pela
   cobrança**, o identificador deve ficar **preso** ao credor e ao responsável da unidade cobrada.
   Mudar o nome passa a ser mudar o **cadastro**, não a pasta. Esta spec foi revivida com escopo
   estreito.

O que sobrevive do passo 2 e **não** se reabre: `pasta.nome_cliente` continua sendo o IDENTIFICADOR
da pasta — não um nome provisório do cliente — para **todas** as outras pastas. As correções
`22d106c9` e `ef56cad8` continuam valendo e são pré-requisito desta.

## 1. Escopo — e por que ele é estreito de propósito

Vale **apenas** para a pasta ligada a um caso de cobrança judicializado (`CasoCobranca.pastaJudicial`).

As outras **1.093 pastas** de produção seguem exatamente como hoje: identificador de texto livre,
editável. Não há mudança nenhuma para elas.

⚠️ **Consequência estrutural:** passam a existir dois tipos de pasta na mesma tela — a de nome
derivado e a de nome livre. **A tela de edição precisa se comportar diferente conforme a origem**, e
é a parte mais delicada do trabalho. Um campo que às vezes edita e às vezes não, sem dizer por quê, é
pior que qualquer um dos dois extremos: a pasta derivada mostra o nome montado como **texto fixo, com
a explicação de onde ele vem e do que fazer para mudá-lo**.

## 2. A regra

```
identificador = <nome fantasia do cliente da carteira> - <nome da pessoa cobrada atual do caso>
                APLC TOP LIFE 1                        - CLAUDIO SILVA DA CRUZ
```

**Os dois pedaços são vivos.** Nada é gravado; ambos são lidos na hora, pelo caminho
`caso.pastaJudicial ← caso → objeto → carteira → cliente(PJ).nomeFantasia` e
`caso.pessoaCobradaAtual.nome`.

Quedas, sem inventar nome (mesmas de `ComporNomeDaPastaJudicial`, que passa a servir a EXIBIÇÃO):

- credor PF, ou PJ com fantasia em branco → só o nome da pessoa cobrada;
- caso sem pessoa cobrada → cai para o `nome_cliente` gravado;
- a razão social **nunca** substitui a fantasia ausente (93 caracteres no maior caso de produção).

## 3. 🔑 O campo gravado NÃO é migrado

Como o nome derivado **substitui** o valor gravado (não se concatena com ele), não existe risco de
prefixo em dobro e **não há comando de migração de dados**. O `pasta.nome_cliente` das pastas de
cobrança fica como está, sem uso — registro histórico, e a volta atrás continua possível.

**Ganho colateral medido:** as pastas **1255** e **1259**, hoje com o texto quebrado
(`APLC TOP LIFE 1 -`, sem o nome), passam a exibir o nome correto **sem intervenção**. Some a
correção manual das duas. (A pasta **1025** não é de cobrança e continua precisando de correção à
mão; a ação errada da **1260** é outro campo.)

## 4. As três consequências aceitas pelo dono — não são defeito

1. **Trocar a pessoa cobrada RENOMEIA a pasta**, inclusive a pasta no Google Drive. É a consequência
   direta de "preso ao nome do responsável", e o dono a escolheu de olhos abertos, com a alternativa
   (congelar o devedor) na mesa.
2. **Editar o cadastro do cliente deixa de ser inofensivo.** Hoje o nome fantasia só aparece em duas
   telas do próprio cliente; passa a renomear pastas e pastas do Drive de uma vez. É o objetivo.
3. **O identificador dessas pastas deixa de ser editável.** Para mudar, muda-se o cadastro.

## 5. Onde a composição aparece

| Lugar | Arquivo | O que muda |
|---|---|---|
| Tabela do Expediente | `pasta/_tabela.html.twig` | coluna Cliente mostra o derivado |
| Cartão (modo lista) | `pasta/_card.html.twig` | idem |
| Resultado de Demandas | `pasta/demandas/_resultado.html.twig` | idem |
| Cabeçalho da pasta | `pasta/_cabecalho.html.twig` | idem |
| Formulário de edição | `pasta/show.html.twig` | campo vira **texto fixo + explicação** |
| Nome no Drive | `Sync/Service/ReconciliadorDePasta`, `SincronizacaoPastaDispatcher` | `nomeEsperado` usa o derivado |
| Modal de judicializar | `Cobranca/Service/MontadorModaisCaso` | para de pré-preencher o campo editável |

### 5.1 Como a tela chega ao valor sem N+1 e sem esquecer nenhuma tela

Passar o valor por parâmetro obrigaria toda tela que renderiza `_tabela`/`_card` a lembrar de
passá-lo; a que esquecesse ficaria sem prefixo **em silêncio**. Em vez disso:

- **quem resolve é a Cobrança**, num método de repositório que devolve o mapa `pastaId → identificador`
  para uma lista de pastas, em UMA consulta — o conhecimento de cobrança não vaza para o domínio Pasta;
- um serviço com memória por requisição, primado pelo controller com as pastas da página;
- uma função Twig lê da memória.

Se alguém esquecer de primar, a função ainda devolve o valor certo (consulta avulsa). Degradar em
velocidade, **nunca** em correção.

## 6. Busca e ordenação (decisão do dono, mantida)

Buscar por `APLC` no Expediente **tem** de achar, e ordenar por Cliente tem de considerar o derivado.

- **Busca:** `EXISTS` correlacionado sobre o caminho da cobrança, somado ao que a busca já cobre.
  `EXISTS` não multiplica linhas — por isso não é `JOIN`.
- **Ordenação:** aí o `JOIN` é necessário (DQL não aceita subconsulta escalar no `ORDER BY`), e entra
  **apenas** no ramo `case 'cliente'` do `aplicarOrdenacao`, que já faz `JOIN` hoje.
- Precedência, espelhando a tela: derivado → `nomeCliente` → nome do cliente cadastrado.

## 7. Prova

Teste **e** prova por reintrodução em cada ponto. Nesta frente um teste já ficou verde com o defeito
de pé (o fragmento do acervo traz tabela **e** cartões, e a asserção no corpo inteiro era satisfeita
pelo cartão) — ancorar em cada modo.

1. pasta de cobrança → as 3 listagens e o cabeçalho mostram `FANTASIA - DEVEDOR`;
2. pasta **sem** cobrança → mostra o `nome_cliente` gravado, intocada;
3. mudar o nome fantasia do cliente → o nome exibido da pasta muda junto;
4. **trocar a pessoa cobrada** → o nome exibido muda junto, e o nome esperado no Drive também;
5. pasta com `nome_cliente` quebrado (`APLC TOP LIFE 1 -`) → exibe o derivado, correto;
6. buscar pela fantasia acha; buscar pelo devedor continua achando;
7. ordenar por Cliente segue o derivado;
8. o formulário **não** oferece o campo editável numa pasta de cobrança, e diz onde mudar;
9. sem fantasia cadastrada, não inventa prefixo nem deixa traço solto.

## 8. Ordem de execução

1. resolução (repositório da Cobrança + serviço + função Twig) e seus testes;
2. exibição nas 4 telas;
3. formulário de edição (campo fixo + explicação);
4. busca e ordenação;
5. Sync (nome esperado no Drive);
6. o modal de judicializar para de pré-preencher.

⚠️ **Pendência ligada, fora desta spec:** o leitor de nomes vindos do Drive
(`AcervoNomesParser::extrairCampos`) assume que o pedaço do meio não tem traço, e o identificador tem.
Hoje não morde porque a sincronização está parada desde 21/08 (403 sem credencial). Ver a memória do
incidente antes de religar o Drive.
