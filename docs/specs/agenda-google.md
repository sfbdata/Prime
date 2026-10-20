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

**Modelo de dados (migration Version20261020129700, refeita, porque não chegou ao master):**
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

- **HTTP:** `HttpClientInterface` do Symfony direto na API REST (OAuth 2.0 e Calendar v3). Sem
  biblioteca nova; o `composer.json` não muda. Testes com `MockHttpClient`.
- **OAuth por pessoa**, no molde do Drive (`DriveConexaoController`): `state` aleatório na sessão
  conferido com `hash_equals`, **PKCE S256**, `access_type=offline`, `prompt=select_account consent`,
  escopo `https://www.googleapis.com/auth/calendar.events`. Rotas:
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
- **Ligação com o compromisso:** colunas novas em `evento`: `google_id` e `google_hash` (resumo do que
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
  **Outro escritório:** a mesma conta Google só se liga a um escritório; o segundo recusa com aviso, sem
  revogar a do primeiro (decisão do coordenador, 19/10/2026: o "Só eu" aparece na auditoria de quem
  administra, e a agenda pessoal não pode vazar para um empregador; a correção da auditoria vai num PR
  próprio, antes deste). Por defesa, id nosso de outro escritório (`bjt<outro>e…`) também não entra. **Fora:** aniversários, local de trabalho, ausência (`eventType`); convite de
  outro organizador entra mas fica só do Google (não dá para editar lá).
  **Troca de conta:** reconectar com outra conta revoga a antiga e zera as ligações da pessoa naquele
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
- **O que vai:** compromissos de que a pessoa é **criadora**, de qualquer tipo, inclusive os da tela
  antiga. **Não vão:** datas do sistema (feriados, comemorativas, tribunais), prazos e audiências das
  pastas (não são compromissos) e compromissos de outros em que a pessoa só participa.

## 3. Decisões de produto (registradas; o coordenador confirma)

- **D1 "Só eu" continua particular:** vai ao Google com `visibility: private`; o que vem do Google entra
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

## 5. Testes

- **Unitários** (`CompromissoNoGoogleTest`, `GoogleAgendaUseCasesTest`): hora de parede com fuso; dia
  inteiro com fim exclusivo; RRULE com UNTIL; cancelado nos dois sentidos; "Só eu" vira particular e o
  Google nunca torna público; repetição não suportada fica só do Google; ida e volta não muda o resumo;
  três estados do painel; reconectar troca a conta e recomeça.
- **Integração** (`SincronizacaoComGoogleTest`, Google falso no `MockHttpClient` e banco de teste): traz
  do Google sem devolver; leva ao Google com id nosso e só quando muda; 409 vira atualização (sem
  duplicata); cancelado no Google fica aqui como "cancelado"; excluído aqui apaga lá; só o que a pessoa
  criou; 410 recomeça; autorização revogada volta a "Não conectado"; trava por pessoa; cadeia única de
  5 min.
- **Funcional** (`GoogleAgendaControllerTest`): os três estados na tela; conectar com state, PKCE S256 e
  escopo; volta de outro site vira a Agenda; state errado dá 403; token gravado cifrado; desconectar
  revoga; sem Agenda, 403.
- **Ponta a ponta com o Google real:** depois do cliente OAuth do dono.

## 6. Material do dono

1. Criar o cliente OAuth (seção 8 da spec principal). URI de retorno:
   `https://<domínio de produção>/agenda/google/retorno`.
2. No `.env.prod` do servidor: `GOOGLE_CALENDAR_OAUTH_CLIENT_ID` e `GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET`.
   `SYNC_CRYPTO_KEY` já existe (é a do Drive) e cifra o token.
3. Worker da fila `async` rodando (o mesmo da 5b). Depois do deploy, `php bin/console
   app:agenda:google-varredura` para não esperar a primeira consulta de lembretes.
