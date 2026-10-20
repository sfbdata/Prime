# Presença on-line e ponto no avatar do cabeçalho

## Estado da frente (atualizado em 19/10/2026; reler antes de continuar se a sessão for compactada)

- **Sessão:** 5d. **Branches:** `presenca-cabecalho` (fase 1, PR #61) e `ponto-no-cabecalho` (fase 2, PR #67, empilhado
  no #61). Em cima do #67 está o #79 (ponto por escritório, spec própria `ponto-por-escritorio.md`).
- **Ordem de merge:** #61 → #67 → #79. Depois de cada merge, trazer o master para a branch de cima, testar e fazer push.
- **Decisões do dono (19/10, pelo coordenador):** P2 (regularizar ontem pelo esquecimento), Q1 ("Em intervalo desde"),
  Q2 (aviso de descanso com o valor configurado). Decididas pelo coordenador por delegação: P1, P3, P4, Q3 a Q7 (seção 5).
  Ainda sem a confirmação explícita do dono: Q3 ("sem atividade há N min", sinal de monitoramento).
- **Fase 3 (seletor "Ausente após", só o primário):** atribuída à 5d pelo coordenador em 19/10 ("tudo do desenho
  entra"); tem migration, entra na fila de migrations do coordenador; começa depois do PR de acertos do Expediente
  (#85), num intervalo da frente Comercial e e-mails. Substitui o desvio temporário P4.
- **Contratos com outras sessões:** o X de fechar do painel segue a regra do #71 (fechar-janelas, 5d), cujo include
  global está no #69 (7d).
- **Migrations:** nenhuma nas fases 1 e 2; uma na fase 3 (posição na fila: a pedir ao coordenador).
- **Subtítulo do colega, fechado (19/10):** o 03 monta o painel com `BJCentral.online()` (`{id, nome, setor, cargo}`,
  dc L.662) e o subtítulo é `[u.setor, u.cargo, u.funcao].join(' · ') || 'on-line'` (L.669; L.676). O "setor" é um
  departamento, que o sistema não tem (a lotação é o local de trabalho, e em produção guarda endereços), e a 7d
  conferiu que em nenhum desenho ele é preenchido (`bj-contas.js` L.23, L.41-43, L.53, L.64: sempre `setor: ''`).
  Na prática o desenho mostra o cargo. Decisão do coordenador: o subtítulo fica como está no master (o cargo, e
  "on-line" sem nada); a branch `presenca-lotacao` foi descartada. Se um dia o setor existir no vínculo (7d), entra
  como "setor · cargo".
- **Pendências:** merges; confirmação do Q3 pelo dono; fase 3.
- **Próximo passo:** depois dos merges, trazer o master para o #79; fase 3 quando houver intervalo.

Desenho: `03 - EXPEDIENTE - VS 18.10.26.dc.html` (projeto claude.ai/design 4f6a5f61), linhas 31, 67 (selo),
299-309 (painel) e 662-685 (lógica). "dc L.n" abaixo são linhas desse arquivo.

Risco: a presença é BAIXO (Central, sem dado novo). O atalho de ponto é **ALTO** (ponto eletrônico): revisão e
re-revisão antes de seguir. Frente: `presenca-cabecalho`. Versão 3 (19/10): v1 não aprovada (B1, B2, I1 a I8, M1
a M6); v2 aprovada com ressalvas (N1 a N10 e as respostas a, b, c, d), todas tratadas abaixo; decisões Q1 a Q7 do
coordenador registradas na seção 5. A fase 2 passa por nova re-revisão antes do código.

Pedido do dono (19/10, via coordenador): "se está no desenho do claude designer, tem que fazer". Restrições:
presença vem da Central (`PresencaRepository`, `ListarEquipeDaCentralUseCase`), sem segunda presença; o ponto usa o
`POST /ponto/batida` e as regras que já existem, **sem regra nova no domínio Ponto**; a nota "ponto e horas on-line
são só seus" vira teste de visibilidade.

Acordo com a frente do cabeçalho (bluejus-7d, PR #53, branch `menu-do-usuario`): ela põe em `base.html.twig`, dentro
de `.navtop-action.dropdown.menu-usuario` e fora do `<a data-mu-toggle>`, a linha
`{% include 'menu_usuario/_presenca.html.twig' ignore missing %}`. Esta frente não toca em `base.html.twig` nem nos
parciais dela. `.menu-usuario` tem `position: relative`; o avatar (38px) é o último filho à direita; o aviso do
desenho é `window.bjAviso(texto)`.

---

## 1. O que o desenho mostra

**Selo no avatar (dc L.67, L.31).** Bolinha verde `#2ecc71` no canto inferior direito do avatar (right -5px, bottom
-4px, altura 18px, raio 9px, texto 10.5px negrito branco, contorno `0 0 0 2px #0a76a8`), pulsando (`bjOnPulse
2.4s`). Mostra **N do selo** = quantos não estão desligados (on-line em qualquer status), você incluído (`OL2.length`).
Dica: "N on-line: Ana, Bruno, …" (primeiros nomes). O clique abre o painel e fecha o menu do avatar (`onTg` faz
`pm:false`); não abre o menu.

**Painel (dc L.299-309).** Caixa fixa (top 66px, right 16px, largura `min(300px, 100vw - 24px)`, altura máxima
`100vh - 80px` com rolagem), fundo branco, borda `#d3dde4`, raio 4px, sombra `0 14px 36px rgba(15,32,43,.2)`; um
véu transparente atrás fecha no clique fora.
- Faixa azul `#0a6fa0`, 14px negrito: ponto verde, resumo "N on-line · M ausente(s)" (aqui N conta só o grupo
  On-line; "· M ausente(s)" some com M = 0), botão de som 28px (bi-volume-up / bi-volume-mute; dica "Som de
  entrada e saída ligado" / "Som desligado"; ao religar, toca o bipe de subida).
- Linha de evento (quando houver): "Ana entrou · 14:02" / "Ana saiu · 14:05" (primeiro nome; ícone
  arrow-left-right; fundo `#f2f8fc`, texto `#0b5f86`, 12px). Compara quem **não está desligado** entre duas
  leituras; a primeira leitura não gera evento. Bipe de subida (entrou) ou descida (saiu) com o som ligado.
- Lista agrupada, cabeçalho de grupo "ON-LINE · 3", "AUSENTE · 1", "NÃO PERTURBE · 1", "DESLIGADO · 4" (10.5px
  negrito, `#5b7383`, fundo `#fafcfd`), nessa ordem; dentro do grupo, você primeiro. Linha: avatar de iniciais 34px
  (`#0b5f86`; desligado `#9aabb7` e opacidade .7) com bolinha 11px da cor do status; nome 13.5px negrito; subtítulo
  12px; à direita o status 11px negrito na cor dele e, **só na sua linha**, "⏱ 1h 05min hoje" (dica "Tempo on-line
  hoje"), ou "iniciando".
  - Cores: On-line `#2ecc71`, Ausente `#f0b429`, Não perturbe `#e74c3c`, Desligado `#b7c4cd`.
  - Subtítulo: "Você" na sua linha; cargo dos outros on-line (o dc junta setor, cargo e função; o sistema só tem
    cargo; sem cargo, "on-line"); "sem atividade há N min" no ausente por inatividade e "ausente" no ausente
    escolhido (Q3); "desligado".
  - Na sua linha, com o atalho de ponto (seção 4), o subtítulo alterna a cada 4 s entre "Você" e o estado do ponto,
    e fica fixo no estado do ponto quando ele tem ação. Com ação: negrito, cursor de mão e dica "Clique para
    registrar a entrada" / "Clique para registrar a saída".
- Nota (11px, `#7a8e9b`, ícone info-circle): "Ponto e horas on-line são só seus: ninguém da equipe vê. Contagem de
  00:00 às 23:59, zera todo dia."
- Só para o usuário primário: "Ausente após sem atividade" com 15 min, 30 min, 1 h ou 2 h (fase 3).
- Equipe só com você: "Só você está na equipe por enquanto."

## 2. Fase 1: presença (BAIXO, sem migration)

**Quem vê.** Só com `central_disponivel()` (vínculo ativo no escritório da sessão). Master sem vínculo no escritório
não vê selo nem painel (a Central é posse, não permissão: regra 1 de `app/src/Central/CLAUDE.md`).

**Dado sem segunda presença e sem relógio novo (I8).** Hoje a lista da equipe (`GET /central/equipe`, ~6 consultas)
só é pedida com a janela da Central aberta. O selo precisa do N sempre, então ele **não** consulta a equipe a cada
20 s. Em vez disso:
- O batimento que já existe (`POST /central/presenca`, a cada 20 s com algo à vista) passa a responder
  `{status, online: [{id, status, semAtividadeMin}]}` de quem está on-line no escritório: só ids, status e o número
  de minutos (regra 12 da Central: só números saem; status já é público para quem tem vínculo, via
  `central_equipe`). A lista sai dos colaboradores com vínculo ativo (`findColaboradoresAtivosPorTenant`: ex-colega
  não aparece, regra 5 da spec da Central) e de `PresencaRepository::doEscritorio`, com o mesmo cálculo de 60 s do
  `ListarEquipeDaCentralUseCase`. O 403 sem vínculo não traz lista. Uma consulta a mais por batimento, nenhuma
  requisição a mais. O contrato novo é escrito na spec da Central, §2, regra 2.
- `central.js` emite `bj-central:presenca` a cada batimento com `{online}` no `document` da página (não na raiz da
  Central: destacada, ela muda de documento e o evento não chegaria ao cabeçalho); o `presenca-cabecalho.js` escuta.
- Nomes, cargos e iniciais vêm de `GET /central/equipe` (sem mudança), guardados no `sessionStorage` por
  `<tenant>:<user>`; o `GET` só sai na primeira página da sessão, ao abrir o painel e quando aparece um id que não
  está no mapa. Nunca em laço.
- Mapeamento: fora da lista → Desligado; `ocupado` → Não perturbe; `ausente` e `inativo` → Ausente; `disponivel` →
  On-line. Você aparece sempre On-line enquanto a página está aberta, como no dc (L.680, `eu ? 'on'`); o evento só
  leva a lista `online`.

**Ausente por inatividade (I6).** Emenda a regra 3 de `docs/specs/central-comunicacao-painel-presenca.md` §2 (status
efetivo) e a regra 12 de `app/src/Central/CLAUDE.md`, que passam a ter três casos, nesta precedência: Não perturbe
→ `ocupado`; sem atividade há mais que o limite → `inativo`; senão o status manual.
- **`inativo` é valor novo de `Presenca::STATUS`** (Q3: o servidor precisa separar ausente escolhido de
  inatividade). A coluna `status` é `string(10)`: cabe sem migration. `status_desde` passa a valer o instante em que
  a inatividade foi detectada (marca + limite), então `semAtividadeMin = floor((agora - statusDesde) / 60) +
  limite`, calculado no servidor, com erro de até um batimento (20 s). A Central (`central.js`) mostra `inativo`
  como o "Ausente" dela. Comportamento conhecido: quem sai do Não perturbe (ou volta de uma aba oculta) já sem
  atividade grava `inativo` naquele batimento, e os minutos contam dali (mostra 60 quando o real é maior).
- A marca de atividade é gravada pelo `central.js` (que roda em toda página com a Central), em chave compartilhada
  por usuário no `localStorage` (`bj-central-ativ:<userId>`), no máximo a cada 15 s, em mousemove, keydown, click,
  scroll e toque, inclusive na janela destacada (`estado.externa`). A chave guarda **um número só** (o instante):
  a última escrita vence sem perda. Nada de objeto JSON lido, alterado e regravado (o `bj-ativ` do dc perde escrita
  entre abas). Todas as abas e janelas leem a mesma marca, então duas janelas visíveis não alternam.
- O evento `storage` da chave de atividade **não** dispara batimento (senão cada aba bateria a cada 15 s de
  atividade).
- Limite: 60 min (padrão do dc) até a fase 3.
- Ao **sair** da inatividade (atividade nova), o status efetivo muda e bate na hora (regra 2 da spec da Central).
  A **entrada** na inatividade não tem evento: é vista no relógio do batimento, em até 20 s.
- O valor enviado continua sempre dentro de `Presenca::STATUS`.

**Horas on-line hoje (só suas).** Contadas no seu navegador como no dc (Q4: também com a aba oculta): um relógio
próprio de 20 s, que segue com a aba oculta (o navegador o espaça para até 1 min, dentro do limite), soma o
intervalo desde o **último instante compartilhado** no armazenamento (o `HR.l` do dc), se for ≤ 90 s, e grava o
instante novo. Assim duas abas ou janelas não contam em dobro. Zera na virada do dia local; chave
`presenca-horas:<tenant>:<user>` com `{d, s, l}`. Não vão ao servidor e nenhum colega recebe. Formato do dc:
"menos de 1 min", "35min", "1h 05min"; sem contagem ainda e on-line: "iniciando".

**Som e evento.** Preferência `presenca-som:<tenant>:<user>`, ligado por padrão; `AudioContext` com as frequências
do dc (subida 660/880 Hz, descida 700/520 Hz). Com duas janelas à vista, o bipe toca uma vez: a aba que tocar grava
`presenca-bipe:<tenant>:<user>` com o evento e o instante, e as outras não tocam o mesmo evento dentro de 30 s (os
relógios de 20 s das abas não andam juntos; com 5 s a segunda janela tocaria de novo).

**Arquivos.** `templates/menu_usuario/_presenca.html.twig` (selo e painel; `data-url-equipe`,
`data-chave="<tenant>:<user>"`), `public/js/presenca-cabecalho.js`, `public/css/presenca-cabecalho.css` (tokens claro
e escuro), `public/js/central.js` (inatividade, evento), `src/Central/...` (resposta do batimento). DOM só com
`textContent` (regra 5). Sem travessão nos textos.

**Testes.**
- Functional: o parcial renderiza com vínculo e não sem; Master sem vínculo não vê; a resposta do batimento traz só
  ids e status do próprio escritório (isolamento, com `IsolamentoDosRepositoriosDaCentralTest` estendido, filtro
  desligado); `central_equipe` e o batimento não trazem ponto nem horas (visibilidade).
- Unit do UseCase da presença: `semAtividadeMin` só para `inativo`; ausente escolhido sem minutos.
- Unit (texto do JS): precedência do status efetivo; marca de atividade de um número só; `storage` da marca não
  bate; batida imediata só na volta da inatividade; horas pelo instante compartilhado; bipe único;
  mapeamento e ordem dos grupos; N do selo diferente do N do resumo; horas só na linha `eu`; evento compara quem não
  está desligado; nenhum `innerHTML`.

## 3. Fase 2: atalho de ponto (ALTO)

**Quem vê.** Só você, na sua linha, e só se `central_disponivel()` **e** `can_access_module('ponto')` **e**
`app.user.jornadaColaborador is not null` (o critério do alerta de ponto do layout, decisão P3). Ninguém vê o ponto
de outro: o estado vem de uma rota que só lê o usuário da sessão. **Fora da própria tela `/ponto` (`ponto_index`,
F3):** lá o bloco "batidas de hoje" e a sugestão de tipo são montados na carga e não releem nada; uma batida pelo
atalho deixaria a tela sugerindo a mesma batida de novo. Na tela do ponto, bate-se pela tela.

**Estado do dia: UseCase de leitura (I5).** `ObterEstadoDoPontoDoDiaUseCase` (domínio Ponto), com teste unitário dos
estados; rota `GET /ponto/estado-do-dia` (`ponto_estado_do_dia`) com as mesmas guardas da batida (401 sem usuário,
403 sem módulo, 403 sem tenant). Devolve:
`{dia: 'AAAA-MM-DD', estado: 'bater_entrada'|'em_intervalo'|'em_jornada'|'jornada_concluida', hoje: {entrada,
repouso, retorno, saida} (H:i ou null), homeOffice: bool, sedes: [{nome, latitude, longitude, raio}] (as chaves da
tela do ponto, que o `avaliarPosicao` lê), ontemSemSaida: ?'AAAA-MM-DD', interjornadaMinimaMin: ?int}`. O `estado` é
derivado no UseCase (`regularizar_ontem` vem antes dos outros, ver P2 na seção 5). (`ontemEntradaSemSaida` só entra com a P2: nenhum
campo morto no contrato.)
- Batidas de `RegistroPontoRepository::batidasDoDiaNoEscritorio(User, Tenant, dia)`, método novo com
  `r.tenant = :tenant` explícito; escolha por `CalculadoraJornada::escolherBatidas` (a da folha, spec
  `ponto-folha-uma-batida-por-tipo.md` §9.9): primeira entrada, primeiro repouso e retorno, última saída.
- `homeOffice` do `HomeOfficeResolver`; `sedes` de um ponto único (`SedesDaCercaDoPonto`, extraído da montagem que
  hoje está em `PontoController::index`, que passa a usá-lo), com as mesmas exclusões (sem coordenada, raio ≤ 0).
- `interjornadaLigada`: `$jornadaTenant?->isValidacaoInterjornadaHabilitada() ?? true`, o mesmo padrão do servidor
  (`PontoController.php:731`; sem configuração de jornada a validação vale), para o texto da confirmação (N6, F7).
- O dia passado a `escolherBatidas` é o de hoje no fuso do escritório, como no `index` (`PontoController.php:152`),
  para a vigência da escolha sair certa (F8).
- Não grava nada e não muda nenhuma regra da batida.

**Estados na sua linha (dc `pontoDe`, com o caso que o dc não tem).**
1. Sem entrada hoje → "Bater ponto" (azul `#0a6fa0`, bi-fingerprint), com ação: registra a entrada. Vale também
   para o dia com saída e sem entrada (o servidor decide; a interjornada pode recusar com a mensagem dele).
2. Entrada, sem saída, e **repouso sem retorno** → "Em intervalo desde HH:MM" (sem ação). O dc não tem esse estado;
   sem ele, quem está no almoço veria "Em jornada desde" e um clique gravaria a saída, travando a entrada seguinte
   por 11 h (interjornada). Ver Q1.
3. Entrada, sem saída, fora do intervalo → "Em jornada desde HH:MM" (verde `#1e7b45`, bi-play-circle), com ação:
   confirma e registra a saída. Confirmação: a frase do dc, "Registrar a saída agora (HH:MM)?", e, só se
   `interjornadaLigada`, a linha "A próxima entrada só será aceita depois da interjornada." (Q2, N6).
4. Entrada e saída → "Jornada HH:MM às HH:MM" (cinza `#5b7383`, bi-check2-circle), sem ação.
O dia ambíguo da folha não ganha estado próprio: vale a escolha da folha. Comportamento conhecido (N10): no dia
com dois repousos e um só retorno, a escolha pega o primeiro par e o segundo intervalo aparece como "Em jornada".

**Estado nunca velho na hora do clique (I1, d).** O estado é pedido ao abrir o painel, a cada batimento com o
painel aberto e **de novo no clique, depois da espera da posição e logo antes do POST** (para a janela ficar
mínima). A releitura tem prazo próprio (F1): `AbortController` com 5 s (`RELEITURA_PRAZO_MS`); estourar conta como
leitura que falhou. Compara o **estado derivado** (qual ação o estado oferece) e o `dia`, não os horários: se mudou (bateu em
outra aba, outro aparelho ou na tela do ponto; página aberta de um dia para o outro), não envia, redesenha e avisa
"O ponto mudou em outra tela; confira antes de bater". Se essa leitura **falhar** (sem rede, 401, resposta que não
é JSON), não envia e avisa; nunca volta ao estado antigo. O resíduo (segundos entre a leitura e o POST, e o
servidor não recusa duplicata) é aceitável porque duas batidas do mesmo tipo do próprio colaborador a até 5 min são
o mesmo registro na escolha da folha (`EscolhaDasBatidasDoDia`, `JANELA_REPETICAO_SEGUNDOS = 300`): não mudam folha
nem saldo.

**Envio, com a lógica da tela do ponto num lugar só (I4, B1, I2, a).** Vão para `public/js/ponto-batida.js` só as
partes puras: `distanciaEmMetros`, `avaliarPosicao(posicao, sedes)`, `lerPosicaoComPrazo(prazo, aoChegar)`, as
constantes (prazos, `LIMITE_ATRASO_ENVIO_MS`, `ENVIO_PRAZO_MS`) e `enviarBatida(url, payload, momentoDoToque)`, que
devolve uma classificação (sucesso, recusa com mensagem, não entrou, sem confirmação, sem rede, e `atrasado`
quando passou do limite de 60 s: a guarda de atraso mora no módulo, antes do `fetch`, na mesma posição de hoje) sem
mexer na tela.
Ficam na tela, porque dependem de IDs dela: `updateButtonState`, `liberarBotaoBatida`, os rótulos do botão,
`mostrarAvisoBatida` e `AVISO_SEM_REDE`, `cercaMandaNoBotaoAtualizar`, `guardarPosicao` e `aplicarPosicao`,
`conferirViradaDoDia` e o relógio, a sugestão de tipo e o recarregamento. **Não é só mover**: as funções passam a
receber por parâmetro o que hoje leem de variáveis da tela (`currentLocation`, `SEDES`, `guardarPosicao`, a URL do
Twig). `aoChegar` é chamado em **toda** chegada da posição, dentro ou fora do prazo, depois de responder, dentro do
mesmo `try/catch` (hoje `guardarEResponder`, `index.html.twig:793-800`, guarda sempre; F4). Por isso é um commit
próprio de **reescrita sem mudança de comportamento** (regra 6 do `app/src/CLAUDE.md`),
antes do atalho, com a suíte do ponto e o `e2e/tests/ponto-batida-cerca.spec.js` como prova. Os três testes de
texto (`BatidaNaoTravaNoGpsTest`, `BotaoDoPontoRespondeAoToqueTest`, `CercaDaTelaNaoEndureceARegraTest`) passam a ler
a tela **e** o módulo; a regra de arquivo inteiro (nenhuma promessa embrulha `getCurrentPosition` fora do leitor com
prazo) cobre a tela, o módulo e o `presenca-ponto.js`. As asserções presas ao texto exato que mudam são listadas, uma
a uma, na mensagem do commit. Regras que o atalho herda por usar o módulo:
- **Cerca no clique (B1).** Decisão do dono de 11/09: a cerca continua bloqueando também na tela. O atalho avalia a
  posição contra `sedes` com a mesma avaliação permissiva (dentro, fora, indeterminado; margem pela precisão;
  excedente menor entre as sedes) e só recusa quando está fora mesmo descontando a precisão. Nunca mais rígido que o
  servidor. Em home office, não avalia. A mensagem vai completa no `window.bjAviso`
  (no cabeçalho não existe o aviso de cima da tela do ponto, N2): "Não registrou: você está a N m da sede <nome>,
  fora da área permitida. Se hoje é trabalho fora da sede, peça a liberação do dia ao gestor."
- **Posição com prazo e sempre nova (N1, N4, N7).** A localização só é pedida **no clique** de um estado com
  ação, fora de home office (abrir o painel para ver quem está on-line não pede localização). O clique sempre pede
  uma leitura nova, só com alta precisão (`enableHighAccuracy: true`, como a tela; sem queda para baixa precisão),
  com prazo de 10 s (o teto de `BatidaNaoTravaNoGpsTest`), e a cerca é avaliada sobre ela. Sem posição ao fim do
  prazo: aviso "Não foi possível obter a sua localização; permita a localização e tente de novo", **nada é
  enviado**.
- **Atraso.** Se entre o clique e o envio passaram mais de 60 s (`LIMITE_ATRASO_ENVIO_MS`), não envia.
- **Envio (N3).** `AbortController` com 15 s; trava contra clique duplo; a classificação da resposta é a da tela:
  401, 403 ou redirecionamento (sessão expirada, CSRF recusado pelo `ValidaCsrfAjaxTrait`) → "não entrou na sessão";
  qualquer outra resposta sem confirmação, inclusive 500 com HTML ou corpo que não é JSON → "sem confirmação" (o
  servidor pode ter gravado antes de falhar): o aviso **nunca** afirma que a batida não entrou e pede para conferir;
  sem rede, não oferece atualizar.
- **Item nunca desabilitado por regra**; o motivo vem no clique, no `window.bjAviso`.
- **Sequência do clique, nesta ordem (F2):** (1) clique liga a trava (`envioEmAndamento`), que cobre posição,
  releitura e POST; (2) só na saída, a confirmação (cancelar destrava); (3) `momentoDoToque = performance.now()`,
  depois da confirmação, para quem demora a responder não perder a batida pelo limite de atraso; (4) posição (10 s),
  fora de home office; (5) cerca; (6) releitura do estado com prazo, comparando estado derivado e dia; (7) limite de
  atraso de 60 s; (8) POST; (9) `finally` destrava, salvo no sucesso, que redesenha.
- **Sucesso:** recarrega o estado (não a página) e avisa "Ponto de entrada registrado às HH:MM" / "Saída registrada
  às HH:MM" com a hora de `data.hora` da resposta (o servidor carimba), não a do aparelho.

**Arquivos.** `src/Ponto/UseCase/ObterEstadoDoPontoDoDiaUseCase.php`, `src/Ponto/Service/SedesDaCercaDoPonto.php`,
`src/Ponto/Controller/PontoController.php` (ação de leitura nova; `index` usa o serviço de sedes),
`src/Ponto/Repository/RegistroPontoRepository.php` (método com tenant), `public/js/ponto-batida.js` (módulo
extraído), `templates/ponto/index.html.twig` (passa a usar o módulo), `public/js/presenca-ponto.js` (atalho).

**Testes.**
- Unit do UseCase: os quatro estados, dia com saída sem entrada, duplicata a 5 min (escolha da folha), home office,
  `interjornadaLigada` sem configuração de jornada (`true`), o dia de hoje passado à escolha.
- Functional: guardas 401/403 iguais às da batida; rota só devolve o usuário da sessão; o parcial não mostra o
  atalho sem módulo, sem jornada ou sem vínculo, nem na rota `ponto_index`; a resposta de `central_equipe` e do
  batimento não traz ponto; `CercaNaTelaDoPontoTest` continua verde com o serviço de sedes (F8).
- Integração com o filtro desligado (M1): `batidasDoDiaNoEscritorio` em `PontoIsolamentoRepositoryTest`.
- Os três testes de texto, lendo a tela e o módulo; teste de texto do atalho (N9): nenhuma promessa crua com
  `getCurrentPosition`; leitura nova de posição no clique, só alta precisão; estado pedido de novo depois da
  posição e antes do POST, com `AbortController` amarrado ao prazo da releitura; leitura do estado que falha não
  envia; a ordem da sequência do clique; classificação da resposta igual à da tela;
  avaliação da cerca pelo módulo; confirmação antes da saída; sem ação no intervalo.
- `e2e/tests/ponto-batida-cerca.spec.js` continua verde (o dono roda).

## 4. Fase 3: limite de ausência escolhido pelo primário (migration, fila do coordenador)

O dc guarda o limite no `localStorage` do primário; lido como regra do escritório (só o primário vê o seletor),
precisa de lugar no servidor por tenant (ver Q5). Não há tabela chave/valor por escritório (`ConfiguracaoCentral` é
global). Proposta: entidade `ConfiguracaoDePresenca` (tenant único, `minutosAusente` em {15, 30, 60, 120}, padrão
60), domínio Central; rota `POST /central/presenca/ausencia` com CSRF, só para quem
`RegraDosPrimarios::ehPrimario` aceita (vínculo ativo, usuário ativo, perfil de sistema); entra na purga do
escritório como as outras tabelas da Central. O parcial lê o valor e o `central.js` usa no lugar dos 60 min. Fila:
depois da C1 da Contribua. O PR #52 (`perfis-masters`) mexe no conceito de Master: conferir antes de abrir.

## 5. Decisões e perguntas

Decididas pelo coordenador (19/10), por delegação do dono para dúvida de produto:
- **P1.** Só entrada e saída no atalho, **sem** link "Abrir o ponto". Repouso e retorno na tela do ponto.
- **P3.** Quem vê o atalho: módulo ponto e jornada cadastrada (convenção do sistema; o dc é omisso).
- **P4.** 60 min fixos até a fase 3; a fase 3 entra (o seletor está no dc). É um desvio temporário do dc.
- **Q1.** Entra "Em intervalo desde HH:MM" sem ação (o dc é omisso sobre repouso sem retorno; vale a tela do ponto).
- **Q2.** Frase do dc mais a linha da interjornada (só com a validação ligada, N6).
- **Q3.** "sem atividade há N min" entra (está no dc), com o dado no servidor (status `inativo`).
- **Q4.** Horas contam também com a aba oculta (como no dc).
- **Q5.** Limite de ausência é regra do escritório no servidor (fase 3).
- **Q6.** O cabeçalho segue o próprio dc ("On-line", "Não perturbe"); a Central fica com "Disponível" e "Ocupado".
  Diferença que resta, registrada.
- **Q7.** Segue; o defeito que já existe (interjornada liga batidas de dois escritórios, jornada sem tenant) vai ao
  dono como informação.

Ao dono, nesta sessão (N5: desvio do dc e sinal de monitoramento são dele; o coordenador decidiu por delegação, e o
dono confirma ou corrige): P4 temporário, Q1, Q2 e Q3. O PR da fase 2 fica em rascunho até o dono confirmar Q1 e Q2
(F5).

Decididas pelo dono (19/10, pelo coordenador), que fecham a fase 2:
- **P2. Regularizar ontem: aprovado pelo fluxo de esquecimento.** Estado `regularizar_ontem` ("Regularizar ponto de
  ontem", laranja `#c76a12`, bi-exclamation-triangle, dica "Clique para informar a saída de ontem"), que vem antes do
  estado de hoje como no dc. Só aparece quando a folha ofereceria o "+" da saída de ontem: o UseCase monta a linha de
  ontem com o próprio `FolhaPontoBuilder` (fim de semana, feriado, início da contagem, escolha das batidas) e o
  esquecimento já pedido ou abonado (`EsquecimentosRegistrados`, extraído do `index`) não volta. O clique leva a
  `ponto_index?competencia=<mês de ontem>&esquecimento=<dia>:saida`, e a tela do ponto clica no MESMO "+" da folha (se
  ele não existir, nada abre): nenhuma batida sai do atalho e a regra de quando o "+" aparece continua só na folha.
  Depois do pedido (pendente), o estado some e volta o de hoje.
  Precedência (decidida pelo coordenador, 19/10, segue o dc): enquanto o pedido não é feito, o atalho mostra só
  "Regularizar ponto de ontem", inclusive com hoje em jornada; o link leva à própria tela do ponto, onde se bate o de
  hoje normalmente. Substitui a ressalva anterior de que o estado não podia esconder "Bater ponto".
- **Q1. "Em intervalo desde HH:MM" sem ação: aprovado.**
- **Q2. Aviso da saída: a interjornada fica em 11 h.** A confirmação é a frase do dc mais "A próxima entrada só será
  aceita depois de <descanso> de descanso.", com o valor configurado do escritório (`minimoMinutosInterjornada`, 660
  sem configuração, como o servidor), e só com a validação de interjornada ligada. O contrato troca
  `interjornadaLigada` por `interjornadaMinimaMin` (null com a validação desligada).

## 6. O que o dono olha na tela (smoke)

Selo e N com colegas on-line; painel com os quatro grupos; evento e som; "Ausente" de um colega depois do limite;
horas on-line; os quatro estados do ponto e o intervalo; bater entrada dentro e fora da sede, em home office e sem
permitir localização; bater pela tela do ponto com o painel aberto e clicar no atalho (estado velho); sem rede.

## 7. Fora do escopo

Menu do avatar, idioma e tema (bluejus-7d). Setor (não existe no sistema). Horas on-line no servidor. Qualquer
mudança nas regras da batida.
