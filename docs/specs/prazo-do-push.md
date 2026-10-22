# Spec: prazo calculado a partir da publicação do Push

## Estado da frente (21/10/2026)

- Branch `prazo-do-push`, a partir do master. Risco **MÉDIO**: grava uma data de prazo processual em pasta
  real, e um prazo errado pode levar a perder o prazo de verdade.
- **Origem da decisão:** pergunta 13 da 0d, decidida à noite pelo coordenador (bluejus-72) com base no
  desenho 03 (Expediente, L626). **O dono revê na manhã de 22/10.** Até lá, nada desta spec é decisão do dono.
- **Sem migration:** `pasta_prazo.origem` é `varchar(20)` e `registro_ref` é `varchar(40)`. A 149800 fica
  reservada caso a revisão do dono peça um campo novo.

## 1. Antes

- **Filtros do trilho do Expediente** (desenho 03 L626): "Expediente com prazo", "Prazo findou nos últimos 10 dias
  sem resposta" e "Audiência".
  - Contam só as metas e o `pasta_prazo` (origem `manual` ou `registro`).
  - O comentário em `PastaRepository::aplicarFiltroDoExpediente` diz: "O prazo calculado do Push ainda não existe."
  - O desenho, porém, diz: "Pastas com prazo em aberto: manual, **Push processual** ou Metas".
- **A publicação do DJEN** (`publicacao_djen`) tem `dataDisponibilizacao` e o teor (`texto`). Não guarda prazo,
  data limite nem audiência.
- **O que já existe e é reaproveitado:**
  - `DeterminacoesDoJuizo` lê o teor e acha as determinações. O prazo só é lido quando está explícito no texto
    ("no prazo de 15 (quinze) dias úteis"); nunca é presumido. O extrator também reconhece "Audiência" pela regex.
  - `DiasUteisNacionais::publicacaoDe()` devolve o 1º dia útil depois da disponibilização: a publicação no DJEN
    (Res. CNJ 455/2022).
  - `somarUteis()` conta a partir do dia útil seguinte à publicação (CPC art. 224, §§ 2º e 3º).
  - `DiasUteisDoEscritorio` faz o mesmo com os feriados estaduais da UF do escritório (spec
    `prazo-feriado-calendario.md`).
  - `PrazosDosRegistros` sincroniza os prazos lidos dos registros com o `pasta_prazo`, de forma idempotente
    (`origem = registro`, `estimado`).

## 2. Regra

**Gera prazo** cada publicação do Push cujo processo esteja vinculado a uma pasta do escritório, desde que o teor
tenha uma determinação com prazo explícito em **dias**.

- **Uma linha por determinação com prazo**, em cada pasta que tem o processo:
  `pasta_prazo` com `origem = push`, `registro_ref = push:<id da publicação>`, `tipo = prazo` e `estimado = true`.
- **Termo inicial:** `publicacaoDe(dataDisponibilizacao)`, isto é, o 1º dia útil depois da disponibilização.
  A contagem começa no dia útil seguinte.
- **Dias úteis:** quando o teor diz "úteis" ou não diz nada (CPC art. 219). A contagem usa o calendário da UF do
  escritório (`DiasUteisDoEscritorio`): fim de semana, feriados nacionais e estaduais e recesso de 20/12 a 20/01.
- **Dias corridos:** só quando o teor diz "corridos". Se o fim cair em dia não útil, passa para o próximo dia útil
  (CPC art. 224, § 1º).
- **Prazo em horas:** não gera linha nesta entrega. É raro, e a contagem é outra.
- **Título:** "Prazo do Push: {ato} ({destinatário})" quando o extrator acha o destinatário. Sem ele, fica só
  "Prazo do Push", porque o ato sempre existe (o extrator cai em "Manifestação"). O título é dado gravado, em
  português.
- **A audiência** se chama "Audiência do Push" e é gravada com `estimado = false`, porque a data está escrita no
  teor e não é calculada. Mesmo assim, o selo aparece em toda linha que vem do Push.
- **Dica do selo:** um tooltip explica o que o cálculo não considera. Ele não está no desenho; o dono decide se fica.
- **Audiência:** gera `tipo = audiencia` só quando o teor traz a **data** da audiência por extenso ou em dd/mm/aaaa
  (o `LeitorDeDatas` já lê data e hora em texto livre). Sem data no teor, não gera nada.
- **Na tela**, todo prazo do Push aparece com o selo **"prazo calculado, confira"** e com a origem
  "Push processual" (desenho, inventário da pasta, L238 e L626).
- **Idempotência:** a sincronização por pasta compara o desejado com o que existe (`origem = push`), como no
  `PrazosDosRegistros`.
  - Linha marcada como cumprida continua cumprida.
  - Linha cuja publicação deixou de gerar prazo é removida, salvo se estiver cumprida.
  - Prazo manual nunca é tocado.

**O que o cálculo NÃO faz, e por isso o selo diz "confira":**

- feriado municipal ou forense da comarca, e suspensão de expediente do tribunal (portarias);
- prazo em dobro: Fazenda Pública, MP, Defensoria (CPC arts. 180, 183 e 186) e litisconsortes com procuradores
  diferentes em autos físicos (art. 229);
- intimação pessoal ou por portal, quando a ciência não vem do DJEN;
- prazo presumido pela lei quando o teor não diz o número de dias (fica de fora de propósito: "nada presumido").

## 3. Quando roda

- Depois de cada sincronização do DJEN, para as pastas cujo processo recebeu publicação nova (no
  `SincronizarPublicacoesDjenUseCase` ou num listener do evento que ele já dispara).
- Ao vincular um processo a uma pasta.
- Comando `app:pastas:prazos-do-push`, para o histórico. Ele simula por padrão e só grava com `--aplicar`.
  **Rodar em produção é do dono.**

## 4. Filtros do Expediente

- Os filtros passam a contar o `pasta_prazo` com origem `push` pelo mesmo caminho dos outros.
- "Prazo findou nos últimos 10 dias sem resposta" usa a regra que já existe: sem ação na pasta depois da data.
- **Única mudança na consulta**, decidida na implementação: a linha com origem `push` não conta como "atividade" da
  pasta. Isso vale para o "findou" e para o "Respondidos 10 dias". Uma publicação do juízo não é resposta do
  escritório. Sem essa exceção, uma publicação nova tiraria o prazo anterior do "findou".
- O comentário do repositório e as notas do catálogo (`expediente.*`) passam a citar o Push.

## 5. Testes

- **Unit do cálculo**, com datas fixas:
  - disponibilizado na sexta: a publicação cai na segunda e o prazo de 5 dias úteis vence na segunda seguinte;
  - feriado nacional e feriado estadual no meio da contagem;
  - recesso;
  - dias corridos que terminam no sábado;
  - teor sem prazo;
  - prazo em horas;
  - teor com dois prazos.
- **Integração da sincronização:** cria, não duplica, mantém o cumprido, remove o que deixou de valer e não toca no
  manual.
- **Funcional:** o filtro "Expediente com prazo" conta a pasta com prazo do Push, e o selo "prazo calculado,
  confira" aparece na pasta.

## 6. Para o dono revisar (manhã de 22/10)

1. Gerar o prazo automático, mesmo marcado "confira", ou só sugerir e deixar a pessoa confirmar?
2. O título "Prazo do Push: {ato} ({destinatário})" serve?
3. Rodar o comando no histórico (prazos passados entram no "Prazo findou 10 dias") ou só daqui para a frente?
   Atenção: a sincronização é feita por pasta. Quando chega a primeira publicação nova, ou quando um processo é
   vinculado, ela já calcula o histórico daquela pasta, mesmo sem o comando. Se a resposta for "só daqui para a
   frente", é preciso acrescentar um corte por data.
5. O prazo do Push ainda não pode ser marcado como cumprido pela tela: o `CumprirPrazoDaPastaUseCase` só aceita o
   manual. Liberar o "cumprido" para o Push? Hoje ele sai do "com prazo" só quando vence e do "findou" depois de
   10 dias ou quando há ação na pasta.
6. A Agenda, o Google Agenda e o resumo diário recebem o prazo do Push pelo mesmo caminho dos prazos lidos dos
   registros. Fica assim?
4. Prazo em dobro: aplicar quando a parte do escritório for Fazenda, MP ou Defensoria (exige saber o polo do
   cliente)?
