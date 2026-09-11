# Ponto — a batida não pode se perder dentro do navegador

**Risco:** ALTO (ponto eletrônico)
**Data:** 2026-09-10
**Origem:** relato do dono — *"vários usuários estão reclamando que estão batendo o ponto, mas
depois quando vai olhar não foi registrado"*, refinado por ele para: *"estão batendo normalmente e
parece que registrou, mas quando vai conferir fica registro incompleto"*.
**Frente:** `ponto-batida-nao-trava`

## O que o dono descartou antes de eu medir

Ele corrigiu duas hipóteses minhas, e as duas correções se sustentaram:

1. **Não é a trava de 60 minutos de intervalo.** Ela avisa. A tela mostra o contador
   *"Retorno disponível em N min"* enquanto o repouso corre, e a recusa devolve mensagem.
2. **Não é o raio da sede.** Acontece em `entrada`, `repouso` e `saida`, todas dentro do raio.

Uma ressalva dele merece registro porque **está incorreta e importa**: o botão *não* bloqueia quem
está fora do raio. Ele só bloqueia quando o navegador não entrega posição **nenhuma**
(`erroGps` → `btnPonto.disabled = true`). Ter posição e estar dentro do raio são coisas diferentes,
e só o servidor sabe a segunda. Batida fora do raio **chega** ao servidor e é recusada com aviso.

## A medição que localizou o defeito

O `audit_log` registra toda batida efetivamente gravada (`route = 'ponto_batida'`, `action = create`),
com autor, horário e `user_agent`. Ele responde onde a batida se perde.

**O servidor não perde batida.**

| | |
|---|---|
| batidas confirmadas pelo servidor (desde abril) | 2.478 |
| ainda presentes na tabela | 2.373 |
| **ausentes** | **105** |
| — dessas, **com** exclusão registrada (admin, com autoria e horário) | 87 |
| — dessas, **sem** exclusão registrada | 18 |

🔑 **A chave do cruzamento não é o `entity_id`.** O `AuditLogSubscriber` monta o log no `onFlush`,
**antes** de o INSERT gerar o id, então todo `create` de `RegistroPonto` tem `entity_id` nulo.

A consulta vai inteira aqui porque prosa não basta: duas escolhas silenciosas viram números
completamente diferentes. `data_hora` é `timestamp without time zone` e o JSON grava com offset
(`2026-04-07T20:11:52-03:00`), então o `left(..., 19)` **descarta o offset de propósito** — com
`::timestamptz` a sessão converte para UTC e *nada* casa. E do lado da exclusão o usuário é o
`before.user_id` (dono da batida), **não** o `actor_user_id`, que é quem apagou: 43% das exclusões
foram feitas por outra pessoa.

```sql
WITH criadas AS (
  SELECT a.actor_user_id AS uid,
         (left(a.changes->'diff'->'after'->>'dataHora', 19))::timestamp AS dh,
         a.changes->'diff'->'after'->>'tipo' AS tipo
  FROM audit_log a WHERE a.route = 'ponto_batida' AND a.action = 'create'
), apagadas AS (
  SELECT (a.changes->'diff'->'before'->>'user_id')::int AS uid,
         (left(a.changes->'diff'->'before'->>'dataHora', 19))::timestamp AS dh,
         a.changes->'diff'->'before'->>'tipo' AS tipo
  FROM audit_log a WHERE a.action = 'delete' AND a.entity_class LIKE '%RegistroPonto%'
)
SELECT count(*) AS confirmadas,
       count(*) FILTER (WHERE r.id IS NOT NULL) AS ainda_presentes,
       count(*) FILTER (WHERE r.id IS NULL) AS ausentes,
       count(*) FILTER (WHERE r.id IS NULL AND p.uid IS NOT NULL) AS ausentes_com_exclusao,
       count(*) FILTER (WHERE r.id IS NULL AND p.uid IS NULL) AS ausentes_sem_exclusao
FROM criadas c
LEFT JOIN registro_ponto r ON r.user_id = c.uid AND r.tipo = c.tipo AND r.data_hora = c.dh
LEFT JOIN apagadas   p ON p.uid   = c.uid AND p.tipo   = c.tipo AND p.dh        = c.dh;
```

O `entity_class LIKE '%RegistroPonto%'` do lado da exclusão cobre a classe legada
(`App\Entity\Ponto\RegistroPonto`) e a atual (`App\Ponto\Entity\RegistroPonto`).

⚠️ **Limite da chave, fechado por medição e não por amostra:** batida cuja `dataHora` fosse editada
depois deixaria de casar e apareceria como ausente. Como o `diff` só grava campo alterado, basta
uma consulta para saber que isso nunca aconteceu — **76 edições de `RegistroPonto` em produção,
`0` tocaram `dataHora`**:

```sql
SELECT count(*) AS updates,
       count(*) FILTER (WHERE (changes->'diff'->'before'->>'dataHora') IS NOT NULL) AS mexeram_na_data
FROM audit_log WHERE action = 'update' AND entity_class LIKE '%RegistroPonto%';
```

As 18 estão espalhadas por 5 meses e 7 pessoas, com concentração em abril (mês de implantação).
Não sustentam um relato de "vários usuários".

**A batida não chega a ser gravada.**

| | |
|---|---|
| pedidos de *Esquecimento de Registro* desde 01/06 | 177 |
| **sem nenhuma batida salva daquela pessoa em ±30 min do horário alegado** | **168** |
| com alguma batida salva na janela | 9 |

⚠️ **Limite honesto desta medição:** o `audit_log` só registra batida **gravada**. Ele não distingue
"a requisição nunca chegou" de "chegou e foi recusada" — recusa não deixa rastro nenhum (é a
Frente 4). O que ele prova é que **nada foi gravado**; foi o relato do dono (sem aviso na tela,
dentro do raio, nos três tipos sem regra de recusa) que fechou o caminho para o lado do navegador.

**Metade das batidas vem de celular** — e é no celular que o defeito abaixo morde:

| Origem | Total | Desde agosto |
|---|---|---|
| Android | 1.120 | 320 |
| Computador | 1.057 | 276 |
| iPhone | 301 | 25 |

## O defeito

`app/templates/ponto/index.html.twig`, no `click` do `#btn-bater-ponto`.

A ordem das operações é o problema. O botão **primeiro** trava e vira "Registrando…", e **só
depois** o código pede o GPS de novo e espera:

```js
btnPonto.disabled = true;
btnPonto.innerHTML = '<i class="bi bi-hourglass-split"></i> Registrando...';
// ...
const position = await new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 5000 });
});
```

Essa promessa **só se resolve pelos callbacks do navegador**. Quando o navegador não chama nenhum
dos dois, ela nunca se resolve, o `await` trava, **o `fetch` nunca é executado** e o botão fica em
"Registrando…" para sempre. Sem erro, sem aviso, sem linha no banco.

É exatamente o que um celular faz: o Chrome no Android suspende a geolocalização quando o documento
fica oculto — tela apagada, troca de aplicativo, celular no bolso. Nesse estado **o `timeout: 5000`
do próprio navegador também não corre**, porque o relógio dele é do pedido de posição, não nosso.

Vale para os quatro tipos, dentro do raio, sem passar por regra nenhuma. Casa com o relato: a
pessoa aperta, vê "Registrando…", guarda o celular e vai trabalhar.

**Três proteções não existem em lugar nenhum do arquivo:** prazo próprio para a leitura do GPS,
`finally` devolvendo o botão, e qualquer forma de reenvio quando o envio falha.

## O que NÃO muda

Nenhuma regra trabalhista. Não se mexe em `minimo_minutos_repouso`, em interjornada, no raio das
sedes, nem em `CalculadoraJornada`. Nenhuma migration. Nenhum saldo retroativo é recalculado.

## As quatro frentes

### Frente 1 — o envio nunca depende do GPS travar ✅ (esta entrega)

A leitura de posição passa a ter **prazo próprio**, e a promessa **sempre** se resolve dentro dele.
Estourou o prazo ou deu erro, usa a posição que já está na mão — o botão só liga quando existe uma
(`updateButtonState`), então sempre há.

Invariante: **`lerPosicaoComPrazo` nunca rejeita e, com a página rodando, nunca demora mais que o
prazo.** É o que garante que o `fetch` sempre acontece.

🔑 **O ganho é sobre a suspensão, não sobre o relógio.** `setTimeout` também é do navegador: aba
oculta o estrangula, página congelada nem roda JS. A diferença que importa é que **timer
estrangulado volta a disparar quando a página volta**, enquanto a geolocalização suspensa pode não
chamar callback nenhum, nunca. Escrever o invariante como absoluto seria mentira — e faria o smoke
#1 parecer conclusivo quando ele pode falhar com o código certo.

**Por isso a frente traz uma segunda guarda, `LIMITE_ATRASO_ENVIO_MS` (60 s).** O servidor carimba a
batida com a hora de **chegada** da requisição (`PontoController::batida` → `setDataHora(new
\DateTime())`); o payload não leva o instante do toque. Se a aba congelar e o envio só sair minutos
depois, a batida entraria com **hora errada e cara de certa**. Trocar ausência visível por dado
errado silencioso num módulo de risco ALTO seria piorar o problema, então o envio atrasado **não
acontece**: o botão volta e a pessoa repete — o comportamento que ela já conhecia.

⏳ **Decisão do dono, para uma frente futura:** mandar o instante do toque no payload e o servidor
carimbar com ele (ou marcar o registro como "enviado com atraso"). Isso salvaria a batida em vez de
descartá-la, mas muda o significado de `data_hora` no ponto — é decisão de regra, não minha.

A precisão enviada continua sendo a real da posição usada; nada é inventado. Usar a posição da
carga da página é o mesmo comportamento que o código já tinha no ramo `catch` de erro de GPS.

### Frente 2 — falha nunca mais passa despercebida ✅ (entregue)

O `fetch` não tinha prazo nenhum. Conexão pendurada deixava o botão em "Registrando…" para sempre,
que é **o mesmo sintoma da Frente 1 por outro caminho**: `fetch` só desiste quando o sistema
operacional decide, e isso pode não acontecer. Entrou `ENVIO_PRAZO_MS` (15 s) com `AbortController`.

O `finally` devolve o botão em **todo** caminho de falha, por `updateButtonState()` e não por
`disabled = false`, para respeitar o estado do GPS. A flag `sucesso` existe porque no caminho bom o
botão fica travado de propósito até a página recarregar — sem ela ele voltaria a clicável por 1,5 s
e aceitaria uma segunda batida do mesmo tipo.

O `alert` saiu. Ele some quando a pessoa toca em OK, não deixa rastro na tela, e navegador nenhum o
mostra em aba que não está visível. No lugar, `#batida-aviso`, que **fica** até a próxima tentativa.

🔑 **Resposta que não é JSON deixou de virar "erro de conexão".** Sessão expirada devolve a tela de
login e erro do servidor devolve HTML; os dois quebravam no `.json()` e caíam no `catch` como falha
de rede. Diagnóstico errado, e mandava a pessoa tentar de novo sem sair do lugar.

⚠️ **Nada disto IMPEDE batida duplicada no servidor.** `PontoController::batida` continua sem
guarda: duas `entrada`, dois `repouso` ou duas `saida` entram sem recusa. O que a frente faz é parar
de *induzir* a duplicata e passar a *mostrá-la* quando acontece. Fechar de verdade é decisão de
regra — recusar a segunda batida do mesmo tipo no mesmo dia, ou aceitar e sinalizar — e não está
feito.

🔴 **O aviso não pode afirmar que a batida não entrou.** A primeira versão dizia "a batida NÃO foi
registrada, tente de novo" no aborto e na falha de rede. O aborto cancela **só o lado do
navegador**: a requisição já saiu e o servidor pode ter gravado antes de a resposta se perder. Como
`PontoController::batida` **não tem guarda de duplicata** (as únicas travas são repouso e
interjornada, e nenhuma delas pega uma segunda `entrada` ou `saida`), o texto produzia batida
dobrada. Agora esses casos usam `AVISO_SEM_CONFIRMACAO`, que manda **conferir o bloco de hoje antes
de repetir**. A certeza só aparece quando o servidor respondeu recusando.

🔴 **A flag `sucesso` não fechava o duplo envio sozinha.** `tipoRegistro` tem
`addEventListener('change', updateButtonState)`: trocar o tipo durante o 1,5 s até o recarregamento
reabilitava o botão, e trocar de tipo é o gesto natural de quem já pensa na próxima batida. Entrou
`envioEmAndamento`, respeitado dentro do próprio `updateButtonState` — durante o envio o botão é do
envio e de mais ninguém.

### Frente 3 — a tela mostra as quatro batidas do dia ✅ (entregue)

A tela **não mostrava nenhuma**. O controller já calculava `pontoHoje` com os quatro horários, mas
ele só alimentava o relógio em JS; nada era renderizado. Depois do `window.location.reload()` a
confirmação verde sumia e a pessoa não tinha como conferir sem rolar até a folha, que no celular
fica **abaixo** do card porque `col-md-8` empilha sob `col-md-4`. Era o que sustentava o
"parece que registrou".

Entrou `#batidas-de-hoje` dentro do card do botão: os quatro tipos, com o horário de quem já bateu e
**"ainda não registrada"** escrito para quem falta. O estado também vai em `data-registrada`, para o
teste asserir invariante e não rótulo.

🔑 **O bloco mostra a CONTAGEM por tipo, não só o horário.** `pontoHoje` guarda um registro por tipo
(o último vence), então duas entradas no mesmo dia apareceriam como uma. Isso esconderia justamente
a duplicata que o aviso de "sem confirmação" pode provocar, no lugar para onde esse aviso manda a
pessoa olhar. `quantasHoje` vem do controller e o card marca `N registros` quando passa de um.

🪤 **O bloco é renderizado no servidor e congela.** Isso mordeu duas vezes, de jeitos diferentes, e
as duas viraram regra:

**(a) Página esquecida aberta da noite para o dia seguinte** mostraria a entrada de **ontem** sob o
título "suas batidas de hoje". `conferirViradaDoDia` roda a cada 30 s e avisa.

🔴 Ele **avisa e não bloqueia**, e compara o aparelho **contra ele mesmo**
(`new Date().toDateString()` da carga contra o de agora). A primeira versão comparava com a data do
servidor e travava o botão: celular com fuso errado (roaming, hora automática desligada) via data
diferente às 21h, o botão travava, e **recarregar não resolvia** porque a causa é o aparelho. Ficava
impossível bater a saída — o oposto do objetivo da frente. Detectar só a *mudança* é imune a fuso
errado. E quem carimba a hora é o servidor: bater com a página velha grava certo, então impedir a
batida trocaria um engano de leitura por uma falta.

⚠️ Tirar o veto não é o mesmo que ter pontaria: relógio errado **anda** e cruza a própria
meia-noite na hora errada. Aparelho em UTC avisa às 21h BRT (falso) e depois fica calado na virada
real, porque `diaVirou` já saiu. Como aqui só se avisa, o custo é um alarme fora de hora que o
próprio recarregamento conserta.

**(b) O aviso de "sem confirmação" não pode mandar conferir a lista sem atualizá-la.** A primeira
versão dizia "confira aqui embaixo, só aperte de novo se não aparecer". A lista é da carga da
página: ela ainda diria "ainda não registrada" e **confirmaria a conclusão errada**, levando à
segunda batida. Falso com cara de verificado, que é pior que falso sozinho. Agora o texto diz que a
lista é de antes e manda **atualizar primeiro**, com o botão `#btn-atualizar-pagina` junto do aviso.

🪤 `parseHMS` corrigida junto. Montava a data com `new Date().toISOString().slice(0,10)`, que é a
data em **UTC**, e combinava com horário **local**. Entre 21h e meia-noite (BRT = UTC−3) o UTC já
virou o dia seguinte, a diferença contra `agora` dava negativa e o relógio mostrava `00:00:00` para
quem estava trabalhando. Agora a data vem do servidor (`dataHoje`).

⚠️ **A correção é PARCIAL e é honesto dizer.** `new Date(ano, mes, dia, ...)` constrói na timezone
**do aparelho**, e o servidor roda em `America/Sao_Paulo`. Celular com fuso divergente (roaming,
config errada) continua contando errado — só que agora com número plausível em vez do `00:00:00`
óbvio, que é mais difícil de notar. Fechar de vez exige o servidor mandar o próprio deslocamento.
**Não está feito.**

### Frente 4 — tentativa que falhou fica guardada e é reenviada

🪤 **Aviso para quem pegar esta frente.** O teste da Frente 1 proíbe **promessa embrulhando
`getCurrentPosition`** — a forma do defeito, não a classe dele. A classe é *"`await` que pode não
assentar antes do `fetch`"*, e a escrita mais natural do reenvio cai nela sem disparar teste nenhum:

```js
await new Promise(r => window.addEventListener('online', r, { once: true }));
```

Mesmo handler, mesmo travamento, quatro testes verdes. Toda espera nova nesse fluxo precisa de
prazo próprio, como `lerPosicaoComPrazo`.


Guardar a tentativa no próprio navegador e reenviar quando a conexão voltar, e **registrar a recusa
no servidor**. É o que tira o problema da invisibilidade: hoje batida recusada e batida esquecida
ficam idênticas depois do fato, para o colaborador e para o gestor — e foi por isso que a medição
acima não conseguiu separar as duas.

## 🔑 Decisão do dono em 11/09/2026: a cerca continua bloqueando

Medi que a coordenada do QNF 03 está 62 m fora e que a precisão do GPS passa de 100 m com
frequência, e propus trocar bloqueio por sinalização, citando o art. 74 da Portaria 671 e a Súmula
338 do TST. **O dono manteve o bloqueio**, com o argumento de que o raio de 100 m já foi escolhido
contando com ~60 m de imprecisão e de que nunca houve problema de localização ruim na prática.
Também decidiu **não** corrigir a coordenada do QNF 03 e **manter** exclusão e alteração de batida
pelo administrador, para ajustar depois.

Correções de rumo que isso impõe:

### ✅ O botão bloqueia pelo raio na própria tela (entregue)

Antes ele só desabilitava quando não havia posição **nenhuma**; quem estava fora do raio apertava e
levava o erro depois. `index()` passa as sedes do escritório ativo (`sedesDaTela`) e `avaliarPosicao`
decide na tela.

🔑 **A avaliação da tela é deliberadamente MAIS PERMISSIVA que a do servidor, e o sentido do desvio é
o invariante.** Três situações, não duas: `dentro`, `fora` e **`indeterminado`**. Só bloqueia no
`fora`, que exige `(menor excedente − precisão) > 0`. Com 120 m de erro e 70 m de distância o
aparelho não sabe dizer se está dentro, e decidir contra a pessoa aí seria transformar imprecisão de
GPS em **falta**. Divergir para o permissivo é inofensivo: o servidor recusa e a mensagem aparece.

O número que torna a margem obrigatória, medido em prod em 11/09 sobre 1.683 batidas desde 01/06:

| | |
|---|---|
| mediana da precisão | 27,1 m |
| **p90** | **99,0 m** |
| acima de 100 m | 7,5% |

Uma em cada dez leituras carrega incerteza do tamanho do raio inteiro. ⚠️ E a amostra é **otimista**,
porque só contém batidas aceitas: as recusadas por posição não estão nela.

🔑 **O excedente é o MENOR entre as sedes, não o da mais próxima.** Com raios diferentes, a sede mais
perto pode ter raio pequeno enquanto outra, mais longe, tem raio grande o bastante. Olhar só a mais
próxima bloquearia quem o servidor aceita. Hoje as 4 sedes têm raio 100 e os dois critérios
coincidem; a diferença aparece no dia em que cadastrarem raios diferentes.

🔴 **A posição não pode congelar na carga.** Esta foi a lição mais cara desta fatia. Com o botão
bloqueando por raio, decidir com a leitura da carga vira armadilha: quem abre a tela no caminho, a
300 m do escritório, ficava travado e **continuava travado depois de chegar**, sem nada na tela que
sugerisse recarregar. Era o defeito desta frente de volta por outra porta. `watchPosition` resolve na
raiz, atualizando a posição enquanto a pessoa se desloca, e o botão destrava quando ela chega.

⚠️ **A liberação do dia (`homeOfficeHoje`) continua sendo um retrato da carga.** Se o gestor conceder
com a página aberta, o servidor passa a aceitar e a tela seguiria cinza. Por isso quem está fora da
área recebe o botão **Atualizar e conferir** junto da mensagem.

🔴 **O botão "Atualizar e conferir" tem dois donos, e quando eles discordam o aviso de envio vence.**
A razão é assimétrica: existe um caso em que **mostrar** o botão é dano, e nenhum em que escondê-lo
seja. Sem rede, recarregar entrega a página de erro do navegador e leva junto o aviso e a lista de
batidas de hoje — a única prova de que a batida pode ter entrado. Por isso a cerca só mexe no botão
quando não há aviso de envio ativo **nem** envio em andamento (`cercaMandaNoBotaoAtualizar`).

Sem esse guarda havia o caminho: bater no subsolo sem sinal → `AVISO_SEM_REDE` esconde o botão →
andar até o carro e sair do raio → a cerca reexibe o botão proibido ao lado do aviso que manda **não**
atualizar. Não dependia de modo avião.

⚠️ **Custo aceito conscientemente: `watchPosition` com `enableHighAccuracy` fica ligado enquanto a
tela estiver aberta**, e a tela é feita para ficar aberta o dia todo. Gasta bateria. A alternativa,
que é parar o watch quando a aba sai de foco, não foi feita nesta fatia.

A tela espelha as duas exclusões do servidor (sede sem coordenada, raio não positivo) para não
bloquear por uma cerca que a regra não aplica. 🪤 Sede **sem coordenada** não é testável: a coluna é
`NOT NULL` no banco. O guard existe nos dois lados como defesa em profundidade.

⚠️ Isto **não** substitui a regra: uma verificação no navegador é contornável em segundos, e
`batida()` continua sendo quem decide.

⚠️ **Efeito colateral registrado para o dono decidir:** a coordenada exata da sede e o raio passam a
ir para o navegador de **todo colaborador**. Antes esse dado só aparecia na tela de sedes, atrás da
permissão de gerenciá-las. Não muda a natureza do risco (falsear GPS já era possível), mas troca
"descobrir a coordenada" por "copiar do HTML" — inclusive o raio, que era desconhecido.

### ✅ O botão desabilitado diz por quê e o que fazer (entregue)

Fora da área, a faixa de GPS passa a mostrar a distância, o nome da sede mais próxima, e a instrução:
pedir ao gestor a liberação do dia se estiver em trabalho externo ou home office. Antes ficava só
cinza.

### ✅ A autorização prévia já existe — o que faltava era saber dela

`HomeOfficeConfig` tem `datasAvulsas`, e a aba **Home Office** em `tenant/edit_user_role` já deixa o
gestor incluir um dia solto por pessoa. Não foi preciso construir nada: o buraco era a pessoa
bloqueada não saber que esse caminho existe, e é isso que a mensagem acima resolve.

⚠️ **O registro de tentativa recusada (Frente 4) ficou menor do que eu vendi.** Com o botão
bloqueando por raio, a recusa por localização deixa de chegar ao servidor. O log passa a valer para
as outras: trava de repouso e de interjornada (a maior fonte real — 58 pedidos de esquecimento de
`retorno`), divergência entre tela e servidor na borda, sessão expirada, e quem reabilitar o botão
no navegador.

## Como conferir a Frente 1

Suíte é cega para comportamento de JS. O que dá para asserir é **estrutura**: que o leitor com prazo
existe e que a espera sem prazo não voltou.

🪤 **O primeiro teste escrito aqui era frouxo e a revisão pegou.** Ele recortava do
`addEventListener` até o fim do arquivo e proibia `getCurrentPosition` ali dentro — e ficava verde
com o defeito de volta, bastando extrair a espera crua para uma função declarada **acima** do
recorte, que é o refactor mais natural que existe. O teste atual assere sobre o **arquivo inteiro**:
nenhuma promessa do template pode embrulhar `getCurrentPosition`, exceto a do leitor com prazo.
Onde ela é declarada não importa.

Prova por reintrodução feita nas **duas** formas — a literal (voltar o `await new Promise` no lugar)
e a extraída (helper acima do recorte). As duas derrubam o teste; o arquivo foi restaurado e
conferido com `diff -q` depois de cada uma. Ver `feedback_provar_teste_reintroduzindo_defeito`.

**Smoke do dono, na tela** (é dele, não meu):

1. `/ponto` no celular, escolher o tipo, apertar **Bater Ponto** e **apagar a tela na hora**.
   Voltar depois de uns segundos: a batida tem que estar gravada.
2. O mesmo, mas voltando **depois de mais de um minuto**: a batida **não** deve entrar, e a tela
   deve pedir para repetir com a tela ligada. É a guarda de hora errada — sem ela a batida entraria
   com a hora da retomada.
3. Bater normalmente com a tela ligada: continua gravando com a precisão de GPS de sempre.
4. Negar a permissão de localização: o botão continua desabilitado, como antes.
5. **Frentes 2 e 3:** depois de bater, as quatro batidas do dia têm que aparecer logo abaixo do
   botão, com horário em quem já bateu e "ainda não registrada" em quem falta. O aviso do resultado
   tem que **ficar** na tela, sem caixa de alerta para fechar.
6. **Frente 2, a falha sem rede:** bater com o celular em **modo avião**. O aviso deve dizer que
   você está **sem internet** e que **não dá para saber** se a batida saiu, pedindo para não fechar
   a tela. O botão deve voltar a "Bater Ponto" em vez de ficar preso em "Registrando…".
   🔴 **O botão "Atualizar e conferir" NÃO pode aparecer aqui.** Atualizar sem rede entrega a página
   de erro do navegador e leva junto o aviso e a lista, que é a única pista que sobrou. Tire o modo
   avião sem recarregar: o aviso deve mudar sozinho, dizendo que a internet voltou, e **aí** o botão
   de atualizar aparece.
7. **Frente 2, a falha com rede:** o aviso **não** pode afirmar que a batida não foi registrada. Ele
   tem que dizer que ela **pode** ter entrado e mandar atualizar e conferir antes de repetir. Se ele
   afirmar, é regressão: o servidor pode ter gravado e só a resposta ter se perdido, e repetir
   nessas condições cria batida duplicada.
8. **Relógio:** abrir a tela **depois das 21h** com entrada batida. O contador tem que mostrar o
   tempo trabalhado, não `00:00:00`.
