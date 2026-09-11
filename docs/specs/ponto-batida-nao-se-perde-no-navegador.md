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

🪤 **O bloco é renderizado no servidor e congela.** Página esquecida aberta da noite para o dia
seguinte mostraria a entrada de **ontem** sob o título "suas batidas de hoje", e a pessoa concluiria
que já bateu — o defeito desta frente reintroduzido pelo próprio remédio. `conferirViradaDoDia` roda
a cada 30 s, avisa que a tela é de ontem e trava o botão até recarregar.

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

Correções de rumo que isso impõe, e que **não** estão implementadas:

- **O botão precisa bloquear pelo raio na própria tela.** Hoje ele só desabilita quando não há
  posição nenhuma; quem está fora do raio consegue apertar e é o servidor que recusa, depois. A
  checagem da tela tem de ser **mais permissiva** que a do servidor (descontando a margem de
  precisão), porque trabalha com posição possivelmente antiga — bloquear na tela quem o servidor
  aceitaria seria transformar imprecisão em falta.
- **O botão desabilitado precisa dizer por quê e o que fazer.** Hoje fica só cinza.
- **A autorização prévia vira peça central.** Quem estiver bloqueado não registra de jeito nenhum, e
  cai em Esquecimento de Registro. A liberação hoje existe pela metade (`HomeOfficeConfig`, por
  pessoa e por dia) e precisa ser concedível rápido, inclusive no mesmo dia.

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
6. **Frente 2, a falha:** bater com o celular em modo avião. Tem que aparecer aviso vermelho dizendo
   que a batida **não** foi registrada, e o botão tem que voltar a "Bater Ponto" em vez de ficar
   preso em "Registrando…".
7. **Relógio:** abrir a tela **depois das 21h** com entrada batida. O contador tem que mostrar o
   tempo trabalhado, não `00:00:00`.
