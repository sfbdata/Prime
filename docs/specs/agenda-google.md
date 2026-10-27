# Agenda nova, entrega 6: sincronia com o Google Agenda (dois sentidos)

Risco **MÉDIO**: guarda, por pessoa, a autorização de acesso à agenda Google dela. Parte da spec
`docs/specs/agenda-vs-18-10-26.md` (V8, seções 3 e 8). Base: entrega 5b (#76).

## 0. Modelo novo do dono (20/10/2026): vale sobre tudo o que conflitar abaixo (78a)

Decisões do dono, trazidas pelo coordenador em 20/10/2026:

- **M1 Cancelado no Google:** fica no BlueJus como "cancelado", sem sumir (já era assim).
- **M2 A conta é da pessoa:** cada pessoa conecta a própria conta Google, **qualquer uma**; o BlueJus sugere a
  do e-mail do cadastro (`login_hint`). A mesma conta pode ser ligada em vários escritórios.
- **M3 Agenda secundária por escritório:** ao conectar, o BlueJus cria na conta da pessoa uma agenda com o
  nome do escritório e só trabalha nela. **A agenda principal (pessoal) não é lida nem escrita.** Escopo
  `https://www.googleapis.com/auth/calendar.app.created` ("Make secondary Google calendars, and see, create,
  change, and delete events on them"), mais `openid email` só para saber o e-mail da conta. Sai o
  `calendar.events` (todas as agendas) e sai a regra "uma conta por escritório" (motivo dela era a agenda
  pessoal).
- **M4 Participantes:** o compromisso vai para a agenda do escritório de **cada participante** conectado,
  além da do criador. "Só eu" vai só para a do criador. Só quem criou altera: a cópia do participante é
  **só leitura** (decisão b): mudança feita nela no Google é desfeita na próxima sincronia; apagada lá, fica
  apagada até o compromisso mudar aqui.
- **M5 Criado no Google** (decisão a), na agenda do escritório: entra no BlueJus visível à **equipe**
  (antes: "Só eu"), criado por quem é dono da conta. Participantes = convidados (`attendees`) cujo e-mail é de
  alguém da equipe do escritório (o do cadastro **ou** o da conta Google conectada naquele escritório).
  Convidado de fora é ignorado, e o e-mail dele não é guardado. Sem convidados, é só do criador. Nas
  mudanças seguintes vindas do Google, convidado da equipe novo entra; ninguém sai por isso (o BlueJus não
  manda convidados ao Google, para não disparar e-mail do Google aos colegas).
- **M6 Desconectar** (decisão c): pergunta "Apagar também a agenda “<escritório>” do seu Google?", com "Não,
  manter" marcado. Mantida, a agenda fica lá parada. A autorização só é revogada no Google se a mesma conta
  não estiver ligada em outro escritório (revogar derruba a autorização inteira da conta para o BlueJus).
- **M7 Prazos** (78b, PR próprio): prazos e audiências das pastas vão também à agenda do escritório de quem
  tem a permissão de perfil "Ver prazos na agenda" (nasce ligada nos perfis que veem pastas).
- **M8 Login com o Google** (futuro, não agora): a conexão da Agenda não depende do login; o OAuth da Agenda
  fica no domínio Agenda, sem tocar o `Security`.

**Modelo de dados (migration Version20261020134000, refeita, porque não chegou ao master):**
- `agenda_google_conexao`: + `calendario_id` (a agenda do escritório na conta; nulo até criar). `conta` é o
  e-mail vindo do `id_token`. Sem unicidade de conta entre escritórios.
- `agenda_google_ligacao` (nova): conexão, compromisso (ou prazo, 78b), `google_id`, `hash` do último envio
  (com o prefixo `x:` quando o Google recusou). Única por (conexão, compromisso) e por (conexão, google_id).
  Apaga junto com a conexão ou o compromisso.
- `evento.importado_do_google` (o "Importado do Google Agenda." do detalhe, L237). Saem `evento.google_id`
  e `evento.google_hash`: a ligação passa a ser por pessoa.
- `agenda_google_exclusao` e `agenda_google_varredura`: como antes.

**Sincronia de uma conexão:** garante a agenda do escritório (cria se não houver; 404 numa chamada = sumiu,
zera as ligações e recria na próxima volta); traz as mudanças dela (syncToken); apaga lá as exclusões
pendentes; leva o que mudou dos compromissos de que a pessoa é criadora ou participante (o "Só eu" só ao
criador) e apaga lá as cópias de compromissos em que ela deixou de ser participante. Id no Google continua
`bjt<escritório>e<compromisso>`, o mesmo em todas as agendas (o id é único por agenda).

**Acertos da revisão (20/10/2026):**
- **Atualizar não apaga o que é do Google:** o `events.update` troca o evento inteiro, então o BlueJus lê o
  evento antes e troca só os campos dele (título, descrição, local, situação, visibilidade, início, fim,
  repetição). Convidados, Meet, lembretes e cor postos no Google ficam.
- **Só e-mail confirmado:** a conta só conecta se o `id_token` trouxer `email_verified`; o e-mail da conta casa
  convidados com a equipe (M5).
- **Cópia mexida fora da janela:** a cópia do participante mexida no Google volta como aqui mesmo quando o
  compromisso já passou da janela de envio (a ligação sem resumo entra no próximo envio).
- **Agenda nova ou troca de conta:** as ligações são esquecidas, menos a marca do importado com repetição que
  o BlueJus não representa (não vai à agenda nova achatado).
- **Purga do escritório:** `agenda_google_ligacao` apagada explicitamente, como as outras tabelas do Google.

**Perguntas abertas ao coordenador (não bloqueiam o 78a):**
- Pela regra da casa (`EventoRepository`), "Para todos" com participantes fica visível só ao criador e aos
  participantes. Então o criado no Google com convidado da equipe não fica aberto ao escritório inteiro (sem
  convidados, fica). É o que o dono quis no M5?
- A mesma conta Google ligada por duas pessoas do mesmo escritório: o banco permite (cada uma ganha a sua
  agenda com o mesmo nome na conta), e o casamento de convidados pela conta fica com uma das duas. Tratar?

## 1. O que o desenho pede (09 - Agenda - VS 18.10.26, linhas 52-57 e 232-233)

Painel **GOOGLE AGENDA** na lateral, três estados:

| Situação | Botão | Status | Cor |
|---|---|---|---|
| conectado | `Sincronizado com o Google Agenda` | `Conectado · atualiza automaticamente` | `#1e7b45` |
| servidor liberado, pessoa não conectada | `Sincronizar com o Google Agenda` | `Não conectado` | `#7a8e9b` |
| servidor sem cliente OAuth | `Sincronizar com o Google Agenda` | `Disponível quando o servidor liberar a conexão` | `#7a8e9b` |

Clique: com o servidor liberado, vai para a URL do OAuth com `volta=<página atual>`; sem, mostra o aviso
"A sincronização com o Google Agenda será liberada assim que a conexão for configurada no servidor do
BlueJus." Texto fixo: os compromissos ficam iguais nos dois lados; "Só eu" continua particular; feriados
do sistema não são enviados.

## 2. Decisões técnicas (decisor técnico; as de produto em D1-D5 abaixo)

> Esta seção e a 3 são do modelo de 19/10. O que conflita com o §0 (escopo, uma conta por escritório, troca de
> conta, o que vai ao Google, colunas em `evento`, "Só eu" no que vem do Google) **não vale mais**; está
> marcado com "(substituído pelo §0)".

- **HTTP:** `HttpClientInterface` do Symfony direto na API REST (OAuth 2.0 e Calendar v3). Sem
  biblioteca nova; o `composer.json` não muda. Testes com `MockHttpClient`.
- **OAuth por pessoa**, no molde do Drive (`DriveConexaoController`): `state` aleatório na sessão
  conferido com `hash_equals`, **PKCE S256**, `access_type=offline`, `prompt=select_account consent`,
  escopo `https://www.googleapis.com/auth/calendar.events` (substituído pelo §0: `calendar.app.created` +
  `openid email`). Rotas:
  - `GET /agenda/google/conectar?volta=` (módulo agenda): guarda `volta` (só caminho local que começa
    com `/` e não com `//`) e redireciona ao Google;
  - `GET /agenda/google/retorno`: confere o state, troca o code, grava a conexão e volta;
  - `POST /agenda/google/desconectar` (CSRF): revoga no Google e apaga a conexão.
- **Token:** só o `refresh_token`, cifrado com o `CifradorDeSegredo` do Sync (libsodium secretbox,
  chave `SYNC_CRYPTO_KEY`), coluna `refresh_token_cifrado` (o nome já está fora do audit log). O access
  token vive só na memória da sincronia. Variáveis: `GOOGLE_CALENDAR_OAUTH_CLIENT_ID` e
  `GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET`; sem as duas, o painel fica no terceiro estado.
- **Conexão** `agenda_google_conexao`: tenant, usuario (único por pessoa e escritório), conta (e-mail,
  que é o id da agenda principal), fuso da agenda Google, `sync_token`, `ultima_sincronia`,
  `ocupada_desde` (trava), `erro`. TenantAware; fora da auditoria (técnica, com segredo).
- **Ligação com o compromisso** (substituído pelo §0: tabela `agenda_google_ligacao` por conexão): colunas novas em `evento`: `google_id` e `google_hash` (resumo do que
  foi sincronizado por último). Envio quando o resumo atual difere do guardado: pega qualquer caminho de
  gravação (tela nova, tela antiga, desfazer da auditoria) sem gancho em cada um, e não devolve ao Google
  o que acabou de vir dele.
- **Id determinístico** no envio: `bjt<tenant>e<evento>` (alfabeto base32hex do Google). Inserir duas
  vezes dá 409, que vira atualização: duas sincronias não criam duplicata.
- **Exclusão e mudança:** um ouvinte do Doctrine (`onFlush` anota, `postFlush` age, só depois do banco
  aceitar) grava `agenda_google_exclusao` (pessoa, google_id) quando o compromisso excluído tinha
  `google_id` e pede a sincronia da pessoa (3 s depois). Pega qualquer tela. Com a trava ocupada, a
  sincronia pedida tenta de novo a cada 30 s (até 10 vezes).
- **Sincronia de uma pessoa** (`SincronizarGoogleAgendaUseCase`), com trava atômica por conexão
  (`UPDATE ... SET ocupada_desde = agora WHERE ocupada_desde IS NULL OR ocupada_desde < agora - 5 min`):
  0. (decisões confirmadas pelo coordenador em 19/10/2026: D1, D2 com ajuste, D3, D4, D5)
  1. renova o access token (recusado: `invalid_grant` marca erro e o painel volta a "Não conectado");
  2. **traz do Google** com `syncToken` incremental (`singleEvents=false`, `showDeleted=true`); na
     primeira vez, desde 30 dias atrás; 410 Gone recomeça do zero;
  3. **leva ao Google** as exclusões pendentes e os compromissos da pessoa cujo resumo mudou;
  4. grava o novo `syncToken` e a hora.
  **Eco:** a listagem traz também o que nós mesmos gravamos. Item igual ao último resumo enviado (aplicado
  numa cópia) é eco e não sobrescreve: a pessoa pode ter editado aqui depois do envio. Só mudança de
  verdade no Google vale (conflito: vale o Google, como edição). O mesmo protege o recomeço (410) e a
  reconexão com a mesma conta.
  **Recusa de um evento** (400, ou 403 que não é limite de uso): só ele fica de fora, marcado até mudar
  (`google_hash = "x:" + resumo`); os outros seguem e o syncToken avança.
  **Sem acesso à Agenda** no escritório (saiu, perdeu o papel): a sincronia desfaz a conexão e revoga.
  **Outro escritório** (substituído pelo §0, M2: a mesma conta pode ser ligada em vários escritórios, cada um com
  a sua agenda): a mesma conta Google só se liga a um escritório; o segundo recusa com aviso, sem
  revogar a do primeiro (decisão do coordenador, 19/10/2026: o "Só eu" aparece na auditoria de quem
  administra, e a agenda pessoal não pode vazar para um empregador; a correção da auditoria vai num PR
  próprio, antes deste). Por defesa, id nosso de outro escritório (`bjt<outro>e…`) também não entra. **Fora:** aniversários, local de trabalho, ausência (`eventType`); convite de
  outro organizador entra mas fica só do Google (não dá para editar lá).
  **Troca de conta** (ajustado pelo §0: só revoga a antiga se ela não estiver ligada em outra conexão; a agenda
  nova é criada na conta nova): reconectar com outra conta revoga a antiga e zera as ligações da pessoa naquele
  escritório (vão para a conta nova como novos).
  **Repetição:** BYDAY só em semanal e no dia do início; BYMONTHDAY só em mensal; BYMONTH só em anual.
  UNTIL vai como data em dia inteiro e em UTC às 23:59:59 de Brasília com hora.
- **Quando roda:** logo depois de conectar; a cada 5 min por uma cadeia única do Messenger
  (`VarrerGoogleAgenda`, marca `agenda_google_varredura`, mesmo molde da 5b: reivindica a vez com
  `UPDATE` atômico e reagenda com `DelayStamp(300000)`; religada pela consulta de lembretes); e logo
  depois de salvar, mover, mudar situação ou excluir na tela nova (`SincronizarGoogleDaPessoa`, async).
- **Datas:** a Agenda grava hora de parede de Brasília. Envio com `dateTime` sem deslocamento e
  `timeZone: America/Sao_Paulo`; dia inteiro com `date` e fim exclusivo (+1 dia). O que vem do Google é
  convertido para a hora de Brasília.
- **Recorrência:** diário, semanal, mensal e anual viram `RRULE:FREQ=...` (com `UNTIL` se houver fim).
  Do Google, só entra recorrência que cabe nesses quatro (sem `INTERVAL`, `COUNT`, `BYDAY` múltiplo);
  as outras entram como compromisso único na primeira data, com a observação "Repetição do Google
  Agenda não suportada aqui". Ocorrência alterada de uma série no Google (`recurringEventId`) é ignorada.
- **O que vai** (substituído pelo §0, M4 e M7: também as cópias dos compromissos em que a pessoa participa, e os
  prazos no 78b): compromissos de que a pessoa é **criadora**, de qualquer tipo, inclusive os da tela
  antiga. **Não vão:** datas do sistema (feriados, comemorativas, tribunais), prazos e audiências das
  pastas (não são compromissos) e compromissos de outros em que a pessoa só participa.

## 3. Decisões de produto (registradas; o coordenador confirma)

- **D1 "Só eu" continua particular:** vai ao Google com `visibility: private`. (Substituído pelo §0, M5: o que
  vem do Google entra visível à equipe.) Antes: o que vem do Google entrava
  como **"Só eu"** (a agenda Google é pessoal; não se expõe ao escritório sem a pessoa decidir).
- **D2 Tipo do que vem do Google:** "Meus compromissos", cor padrão, situação "agendado".
  **Cancelado ou apagado no Google não apaga aqui** (ajuste do coordenador, 19/10/2026): o compromisso
  passa à situação "cancelado" (a da entrega 3b), com o registro mantido e a mudança auditada. Um
  compromisso do BlueJus pode ser visto pelo escritório; um clique no celular pessoal não o apaga de vez.
  O mesmo no conflito: o que vem do Google entra como **edição** pelo ORM (auditada), nunca exclusão.
- **D3 Desconectar:** o desenho não mostra, e o acréscimo é **obrigação legal**, não preferência: a LGPD
  garante à pessoa revogar o consentimento a qualquer momento (art. 8º, § 5º). Com a conexão ligada, um
  link "Desconectar" discreto abaixo do status revoga o token no Google e apaga a conexão (os compromissos
  já trazidos ficam).
- **D4 Clique conectado:** como no desenho, o botão leva de novo ao Google (permite trocar de conta) e
  sincroniza ao voltar.
- **D5 Avisos na volta:** "Google Agenda conectado. Os compromissos já estão sendo sincronizados." /
  "A conexão com o Google Agenda foi cancelada." / "Não foi possível conectar ao Google Agenda. Tente
  de novo." (o desenho não tem; toast da Agenda).

## 4. Fora desta entrega

- Teste de ponta a ponta com o Google real: espera o cliente OAuth do dono (passo a passo na seção 8 da
  spec principal). URI de retorno: `https://<domínio>/agenda/google/retorno`.
- Participantes e avisados não vão como convidados do Google (evita e-mail do Google para colegas).

## 5. Testes (modelo do dono de 20/10/2026)

- **Unitários** (`CompromissoNoGoogleTest`, `GoogleAgendaUseCasesTest`, `ListarCompromissosUseCaseTest`): hora de
  parede com fuso; dia inteiro com fim exclusivo; RRULE com UNTIL; cancelado nos dois sentidos; "Só eu" vira
  particular e o Google nunca torna público; repetição não suportada fica só do Google; ida e volta não muda o
  resumo; eco pelo resumo guardado na ligação; a cópia do participante leva o aviso de só leitura e o eco dela é
  reconhecido; id nosso e convidados; três estados do painel; reconectar com outra conta zera a agenda;
  desconectar mantém ou apaga a agenda e não revoga a conta ligada em outro escritório; "Importado do Google".
- **Integração** (`SincronizacaoComGoogleTest`, Google falso com várias agendas no `MockHttpClient`): cria a agenda
  do escritório e nunca toca a principal; criado no Google entra visível à equipe e importado; convidados da equipe
  (cadastro ou conta ligada) viram participantes e o de fora é ignorado; leva ao Google com id nosso e só quando
  muda; ligação perdida não duplica (409 vira atualização; id nosso volta religado, sem cópia); cópia do
  participante, "Só eu" não vai a participante; cópia só leitura (mexida volta, apagada fica até mudar, quem sai
  perde); cancelado no Google fica; excluído aqui apaga no criador e no participante; agenda apagada no Google é
  recriada; 410 recomeça; autorização revogada; trava; cadeia de 5 min; eco não desfaz edição; mesma conta em dois
  escritórios com agendas separadas; recusa não trava; sem acesso desconecta; tipos e convites.
- **Funcional** (`GoogleAgendaControllerTest`): os três estados na tela; conectar com state, PKCE S256, o escopo
  `openid email calendar.app.created` e o e-mail do cadastro como `login_hint`; volta de outro site vira a Agenda;
  state errado dá 403; token gravado cifrado; mesma conta em outro escritório aceita; desconectar revoga, mantém a
  agenda ou apaga quando pedido; sem Agenda, 403.
- **Ponta a ponta com o Google real:** depois do cliente OAuth do dono (seção 6).

## 6. Material do dono

1. Criar o cliente OAuth (seção 8 da spec principal). URI de retorno:
   `https://<domínio de produção>/agenda/google/retorno`. Na "Tela de permissão OAuth", acrescentar os escopos
   `openid`, `.../auth/userinfo.email` e `https://www.googleapis.com/auth/calendar.app.created` (não o
   `calendar.events`). A classificação do `calendar.app.created` para a verificação do app aparece no próprio
   console ao adicionar o escopo.
2. No `.env.prod` do servidor: `GOOGLE_CALENDAR_OAUTH_CLIENT_ID` e `GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET`.
   `SYNC_CRYPTO_KEY` já existe (é a do Drive) e cifra o token.
3. Worker de produção rodando (o mesmo da 5b): ele consome o schedule `cadeias`, que dispara a sincronia a cada
   5 min (desde 23/10/2026; o `app:agenda:google-varredura` saiu, ver `docs/specs/scheduler-tarefas-agendadas.md`, "Cadeias no Scheduler").

## 7. Prazos e audiências no Google (78b-2, decisão M7 do dono)

Risco BAIXO (Agenda; a permissão é a do #180, só lida aqui). Branch `agenda-google-prazos`, base master,
migration `Version20261020134200`.

- **Quem recebe:** cada pessoa conectada que tem a permissão "Ver prazos na agenda" (`PermissoesDaAgenda::verPrazos`)
  recebe na agenda do escritório na conta dela os prazos e audiências **abertos** das pastas do escritório, de 30
  dias atrás a um ano à frente, fora da lixeira e fora das pastas ocultas para ela pelo cadeado. É a mesma regra
  dos lembretes (`abertosDoEscritorioNoPeriodo` + `idsOcultosPara`).
- **Como vai:** título igual ao da grade ("Audiência · Pasta 0001", "Contestação · Pasta 0001"); com hora, uma
  hora a partir dela; sem hora, dia inteiro. Descrição: "Prazo da pasta <NUP> no BlueJus. Para mudar, abra a
  pasta no BlueJus: o que for mudado aqui volta como está lá." ("Audiência da pasta" na audiência) mais o
  responsável, se houver. Id no Google
  `bjt<escritório>p<prazo>` (base32hex, determinístico, como o dos compromissos).
- **Só leitura** (como a cópia do participante, decisão b): mexido no Google, volta como está aqui; apagado lá,
  fica apagado até o prazo mudar aqui. Nunca vira compromisso no BlueJus.
- **Sai do Google** quando deixa de valer para a pessoa: cumprido, excluído, pasta na lixeira, pasta oculta para
  ela (também quando trancada depois do envio), fora da janela, ou ela perdeu a permissão.
- **Apagado lá e mudado aqui na mesma volta:** a marca de "apagado lá" guarda o resumo do último envio, então a
  mudança feita aqui antes da sincronia ainda vai.
- **Custo:** cada sincronia de uma conexão com a permissão lê os prazos abertos do escritório na janela (uma
  consulta, com a pasta, sem N+1) e compara resumos; só o que mudou vai ao Google.
- **Quem sai do escritório ou perde a Agenda:** a conexão é desfeita sem apagar a agenda dela no Google (como os
  compromissos, §0 M6); os eventos de prazo ficam lá parados. Pergunta aberta ao coordenador.
- **Quando:** na sincronia normal da conexão (a cada 5 min e depois de cada mudança na Agenda). Mudança só no
  prazo da pasta chega na próxima varredura de 5 min (sem gancho no domínio Pasta).
- **Dados:** tabela própria `agenda_google_prazo` (tenant, conexão, prazo, `google_id`, `hash`), não a
  `agenda_google_ligacao`. Motivo: a ligação de compromisso tem `evento_id` obrigatório e é lida em todo o
  caminho de trazer e de levar; deixá-la nula espalharia `null` pela sincronia dos compromissos, que acabou de ser
  revisada. Únicos (conexão, prazo) e (conexão, google_id). Apaga junto com a conexão; o prazo excluído deixa
  `prazo_id` nulo (SET NULL) para a próxima sincronia apagar o evento lá antes de esquecer a linha.
- **Agenda nova ou troca de conta:** as linhas da conexão são esquecidas, como as ligações (vão de novo).
- **Eco:** o resumo guardado é o do corpo normalizado (título, descrição, local, início e fim em hora de Brasília);
  item igual a ele é eco.

**Testes** (`SincronizacaoDePrazosComGoogleTest`): vai a quem tem a permissão e não a quem não tem; pasta oculta
não vai; mexido no Google volta; apagado lá fica até mudar; cumprido, excluído e perda da permissão tiram do
Google; agenda nova manda de novo; o id de prazo nunca vira compromisso importado.

## 8. Acertos da revisão da b5 (20/10/2026)

- **Escopos:** `include_granted_scopes=false` (nada herdado de outra autorização do mesmo cliente, como um login
  futuro). Token com escopo além de `openid`, `email` (ou `userinfo.email`) e `calendar.app.created`, ou sem o da
  agenda, é recusado e revogado no Google, a menos que a conta já esteja ligada em outro escritório (revogar
  derrubaria a de lá). Mesma regra no retorno sem como cifrar.
- **id_token:** além do `email_verified`, confere `aud` (o nosso cliente), `iss` (`accounts.google.com`) e `exp`.
- **Chave dos segredos:** sem `SYNC_CRYPTO_KEY` o Google fica desligado (`ligado()` falso, painel "indisponível").
  Se mesmo assim um retorno do OAuth chegar sem como cifrar, a autorização é revogada lá e a tela mostra a falha,
  sem erro 500.
- **Desconectar pega a trava da conexão:** com a sincronia rodando, 409 com "tente de novo em instantes"; no
  worker (perdeu o acesso), a próxima volta desconecta.
- **Limitador** `agenda_google` (20 por pessoa em 15 min) em conectar e desconectar (o retorno não conta: o
  consentimento já foi dado). Estourado, conectar volta à Agenda com o aviso "Muitas tentativas com o Google
  Agenda. Tente de novo em alguns minutos." e desconectar responde 429 com a mesma frase. Bloco próprio em
  `config/packages/agenda_google.yaml`, fora do `framework.yaml`.
- **Auditoria:** sai o `googleHash` morto da lista de campos ignorados.
- **Com o dono (prontos para virar com uma linha):** (1) apagar a agenda do escritório de quem perde a Agenda:
  `SincronizarGoogleAgendaUseCase::APAGAR_AGENDA_DE_QUEM_SAI`; (2) revogar na exclusão de conta, suspensão ou
  purga: `DesconectarGoogleAgendaUseCase::todasDaPessoa()` e `todasDoEscritorio()` (testadas, ainda não chamadas).
  Buscam com o filtro de escritório suspenso (funcionam de dentro de uma sessão de outro escritório), não esperam
  a trava e uma conexão que falha não segura as outras.
- **Ambiente:** `.env.prod.example` documenta `GOOGLE_CALENDAR_OAUTH_CLIENT_ID`, `GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET`
  e `SYNC_CRYPTO_KEY`.

## 9. Feriados e recessos do sistema no Google (decidido 21/10/2026)

Pedido do dono: os feriados e recessos que a Agenda mostra sem ninguém cadastrar vão também à agenda do
escritório no Google. **Muda o desenho:** o painel do Google diz hoje "feriados do sistema não são enviados"
(seção 1) e a seção 2 dizia "Não vão: datas do sistema". Risco BAIXO. Precisa de migration (tabela nova).

**Proposta (recomendações; os pontos [dono] decidem):**

- **O que vai (decidido pelo dono, 21/10/2026): TUDO o que a Agenda mostra sem ninguém cadastrar**, sem restrição:
  feriados nacionais e pontos facultativos, estaduais, recesso forense, datas dos tribunais e eleições, datas
  comemorativas, feriados do escritório do Ponto, datas acrescentadas pelo escritório e os aniversários da equipe.
  Valem os ajustes do escritório (oculta não vai; renomeada vai com o nome novo), inclusive nos aniversários. Mesmas
  regras da grade (`ListarDatasDoSistemaUseCase` e os aniversários do `ListarCompromissosUseCase`), sem cópia.
  **Aniversários e LGPD (decisão do dono, 21/10/2026):** o nome e a data de aniversário dos colegas vão para a conta
  Google de cada pessoa conectada, que pode ser pessoal; o dono está ciente. O escritório que não quiser usa o
  "ocultar" da Agenda (vale para cada aniversário).
- **Para quem:** toda pessoa conectada ao Google naquele escritório, sem permissão própria (são datas públicas).
  As UFs são as da grade dela: a da lotação mais a do estado do escritório (spec agenda-vs-18-10-26, V6).
- **Como vai:** dia inteiro, `transparency: transparent` (não ocupa o horário de ninguém), título igual ao da grade.
  Descrição: "Data do sistema na Agenda do BlueJus. Para mudar, ajuste na Agenda do BlueJus: o que for mudado aqui
  volta como está lá." mais a observação da data (ex.: "Prazos processuais suspensos de 20/12 a 20/01 (CPC,
  art. 220)"). O recesso vai um evento por dia, como na grade (cada dia pode ser ocultado ou renomeado).
- **Só leitura**, como os prazos (§7): mexido lá volta; apagado lá fica apagado até a data mudar aqui. Nunca vira
  compromisso.
- **Sai do Google** quando deixa de valer: oculta pelo escritório, fora da janela, data apagada.
- **Janela:** de 30 dias atrás a um ano à frente (a mesma dos prazos). Cerca de 67 datas por pessoa nessa janela (32 delas do recesso, um evento por dia) mais um aniversário por pessoa da equipe; a primeira sincronia leva todas, com gravação por item (uma falha no meio continua na volta seguinte).
- **Quando:** na sincronia normal (5 min). Ajuste do escritório (ocultar, renomear, acrescentar) chega na varredura
  seguinte.
- **Dados:** tabela própria `agenda_google_data` (tenant, conexão, `chave` da data, `google_id`, `hash`), no molde
  da `agenda_google_prazo`; única (conexão, chave) e (conexão, google_id); apaga com a conexão; esquecida em agenda
  nova ou troca de conta. Id no Google `bjt<escritório>d<sha1 da chave>` (base32hex). Migration:
  `Version20261020149200` (dada pelo coordenador). Código só depois das respostas do dono.
- **Texto do painel (decidido pelo dono):** termina com "Compromissos "Só eu" continuam particulares. Feriados,
  recessos e datas do sistema também vão, como dia inteiro, sem ocupar a sua agenda."
- **Implementação:** `App\Agenda\Contract\DatasParaOGoogle` (fonte das datas; `DatasDaAgendaParaOGoogle` usa
  `ListarDatasDoSistemaUseCase` e `AniversariosDaEquipe`, este extraído do `ListarCompromissosUseCase`),
  `DataParaOGoogle` (tradução), `LevarDatasAoGoogle` (envio, só leitura), `ResumoDoEventoSoLeitura` (resumo
  normalizado, compartilhado com os prazos). Ao conectar, as datas já existentes vão na primeira sincronia.

**Testes:** vão o feriado nacional, o recesso, a comemorativa, a data de tribunal e o aniversário; oculta pelo escritório sai do Google; a
renomeada vai com o nome novo; UF da lotação da pessoa; mexido lá volta; apagado lá fica até mudar; agenda nova manda
de novo; o id de data nunca vira compromisso; `transparency` transparente.
