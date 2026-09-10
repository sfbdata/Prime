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
**antes** de o INSERT gerar o id, então todo `create` de `RegistroPonto` tem `entity_id` nulo. O
casamento é por **`actor_user_id` + `tipo` + `dataHora`**, os três lidos de
`changes->'diff'->'after'`, contra `user_id` + `tipo` + `data_hora` da tabela. As exclusões saem de
`changes->'diff'->'before'` com `entity_class LIKE '%RegistroPonto%'`, que cobre a classe legada e a
atual. Sem esta nota o número não é reproduzível — e a primeira versão desta tabela trazia "2.460
presentes", que não fechava com 105 ausentes.

⚠️ **Limite da chave:** batida cuja `dataHora` foi editada depois deixaria de casar e apareceria
como ausente. Conferido nas 18: nenhuma tem `update` registrado no mesmo dia.

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

### Frente 2 — falha nunca mais passa despercebida

`finally` devolvendo `disabled`/rótulo do botão em todo caminho, e o `alert` (descartável, e
suprimido por navegador em aba não-visível) trocado por aviso que **fica na tela** até a pessoa
resolver. Sem isso, um `fetch` travado ainda deixa o botão preso em "Registrando…".

### Frente 3 — a tela mostra as quatro batidas do dia

Hoje a tela **não mostra nenhuma**. O controller já calcula `pontoHoje` com os quatro horários, mas
ele só alimenta o relógio em JS; nada é renderizado. Depois do `window.location.reload()` a
confirmação verde some e a pessoa não tem como conferir sem rolar até a folha (que no celular fica
**abaixo** do card, porque `col-md-8` empilha sob `col-md-4`).

🪤 Ao mexer nisso, corrigir `parseHMS`: ela monta a data com `new Date().toISOString().slice(0,10)`,
que é a data em **UTC**, e combina com um horário **local**. Entre 21h e meia-noite (BRT = UTC−3) o
UTC já virou o dia seguinte, o relógio calcula diferença negativa e mostra `00:00:00`.

### Frente 4 — tentativa que falhou fica guardada e é reenviada

Guardar a tentativa no próprio navegador e reenviar quando a conexão voltar, e **registrar a recusa
no servidor**. É o que tira o problema da invisibilidade: hoje batida recusada e batida esquecida
ficam idênticas depois do fato, para o colaborador e para o gestor — e foi por isso que a medição
acima não conseguiu separar as duas.

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
