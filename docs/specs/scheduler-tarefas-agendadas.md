# Tarefas agendadas no Symfony Scheduler

Risco: **MÉDIO** (infraestrutura de produção: tarefas que apagam dado, mandam e-mail e falam com o Drive).
Branch: `scheduler-tarefas-agendadas`. Migration: `Version20261023150800`.

## Objetivo

Tirar do crontab do host da VPS as tarefas do próprio sistema (comandos `bin/console`) e passá-las ao
Symfony Scheduler, num contêiner próprio (`jusprime_scheduler_prod`) que o deploy sobe junto com o php e o
worker. Ganhos:

- a lista de tarefas e os horários passam a ser código versionado, revisado e testado
  (`app/src/Shared/Scheduler/AgendaDeTarefas.php` + `AgendaDeTarefasTest`), não uma linha no crontab que só
  existe na VPS;
- horários em **Brasília**: o gatilho cron recebe o fuso `America/Sao_Paulo`, então "9h" é 9h de Brasília,
  sem a conta de UTC e sem depender do relógio da VPS;
- some a classe de erro do `docker exec` sem `-w` (a linha do DJEN documentada na spec do DJEN não tinha o
  `-w`, e esse foi o mesmo motivo de a foto do Dashboard nunca ter sido agendada);
- falha de tarefa vira `ERROR` no log e, se configurado, e-mail.

Fora do agendador, **no crontab do host, sem mudança**: `certbot renew`, `scripts/backup-db.sh` e os
backups dos outros sistemas da VPS (rodam fora do contêiner da aplicação, e o backup não pode depender dela).
A linha do `scripts/backup.sh` já está comentada no crontab.

## Tarefas

Brasília é UTC-3 sem horário de verão (desde 2019), então cada linha é a mesma hora do crontab antigo
menos 3 horas. Nenhuma conversão cruza a meia-noite para outro dia da semana: só a foto do Dashboard
(02h55 UTC) cai no dia anterior em Brasília, que é justamente o dia que ela fotografa (ver a nota abaixo).

A coluna "crontab antigo" foi **conferida com o crontab real da VPS em 23/10/2026** (`crontab -l` do dono).

| Comando (como o agendador roda) | Crontab antigo (UTC) | Agendador (America/Sao_Paulo) |
|---|---|---|
| `app:purgar-dados-expirados --force --no-interaction` | `0 3 * * *` | `0 0 * * *` (0h) |
| `app:desempenho:purgar --no-interaction` | não estava no crontab (nova, docs/specs/master-desempenho.md) | `40 0 * * *` (0h40) |
| `app:djen:sincronizar --dias=2 --no-interaction` | `0 6 * * *` (com `--dias=2`) | `0 3 * * *` (3h) |
| `app:push:resumo-semanal-cliente --no-interaction` | `0 11 * * 1` | `0 8 * * 1` (segunda, 8h) |
| `app:pastas:relatorio-sem-processo --no-interaction` | `5 13 * * *` | `5 10 * * *` (10h05) |
| `app:pastas:relatorio-pastas-paradas --no-interaction` | `15 13 * * *` | `15 10 * * *` (10h15) |
| `app:dashboard:fotografar --no-interaction` | não estava no crontab (recomendada em `trilha-b-designer.md`, D-CRON: `55 2 * * *`) | `55 23 * * *` (23h55) |
| `app:sync:reconciliar --usuario-id=1 --modo=enviar --no-interaction` | `0 * * * *` via `scripts/sync-reconciliar.sh` (com `--tenant-id=1`) | `0 * * * *` (de hora em hora) |
| `armazenamento:reconciliar-espelho --se-ligado --fatia=auto --no-interaction` | não estava no crontab (espelho R2, P2.7) | `30 3 * * *` (3h30; SHA de 1/7 por noite) |
| `armazenamento:limpar-replicacao --se-ligado --no-interaction` | não estava no crontab (retenção da outbox, I4) | `30 4 * * *` (4h30) |
| `armazenamento:replicar-espelho --se-ligado --limite=50 --no-interaction` | não estava no crontab (espelho R2, P2.5) | `* * * * *` (a cada minuto, antes do Drive na ordem) |

As três do espelho R2 (r2-p25) só agem com o espelho ligado: com `ARMAZENAMENTO_ESPELHO_CATEGORIAS` vazia
ou sem `ESPELHO_R2_*`, terminam com sucesso ("espelho desligado"). Detalhes em `docs/specs/p2-gravacao-dupla.md`,
seção "P2.5 e P2.7 preparadas (r2-p25)".

A foto do Dashboard é comportamento **novo** em produção (nunca rodou); as outras seis só mudam de lugar.

### Desligadas, aguardando o dono

Três tarefas estavam documentadas no DEPLOY.md, mas **não estão no crontab real**: nunca rodaram em
produção. Ligá-las seria comportamento novo (e-mails a clientes e escritórios, importação de índices),
então ficaram fora da agenda até o dono decidir. Em 23/10/2026 o dono ligou `app:importar-indices-monetarios`
(dia 11, 1h de Brasília, como no DEPLOY.md); resumo diário e cobrança do checklist seguem desligados até
a simulação em produção. Para ligar, basta voltar a linha em `AgendaDeTarefas::TAREFAS`
e no `AgendaDeTarefasTest` (o teste `testTarefasQueNuncaRodaramEmProducaoFicamDesligadas` trava a lista).

| Comando | Horário que teria (Brasília) | Equivalente em UTC |
|---|---|---|
| `app:perfil:resumo-diario --no-interaction` | `0 8 * * *` (todo dia, 8h) | `0 11 * * *` |
| `app:pasta:cobrar-checklist --no-interaction` | `0 9 * * 1-5` (seg a sex, 9h) | `0 12 * * 1-5` |
| ~~`app:importar-indices-monetarios --no-interaction`~~ (ligada em 23/10/2026) | `0 1 11 * *` (dia 11, 1h) | `0 4 11 * *` |

Notas:

- **Opções**: as mesmas que o crontab passava (`--force` da purga; `--usuario-id=1 --modo=enviar` do
  script do Drive), menos o `--tenant-id=1` do Drive: sem ele, o comando percorre todos os escritórios com
  Drive conectado; o escritório que desconecta para de enviar (decisão do dono, 24/10/2026). Sem nenhum
  conectado, termina com sucesso ("Nenhum tenant com Drive conectado"), sem aviso de falha. O
  `--usuario-id` segue porque o comando o exige (só o modo importar o usa). `--no-interaction` em todas: sem TTY o Symfony já desliga a
  interação, a opção só deixa isso explícito. A spec do DJEN cita `--force` num parágrafo, mas o comando não
  tem essa opção (a F4 da mesma spec registra "Sem `--force`") e a linha de crontab documentada também não.
- **Foto do Dashboard**: o comando fotografa "hoje" no fuso do PHP (`date.timezone = America/Sao_Paulo`
  no Dockerfile; padrão de `--data` = hoje) e o painel compara com a foto de `data_de - 1`. Então a foto
  precisa sair no fim do dia de Brasília: 23h55, que é exatamente a recomendação antiga (02h55 UTC). Às
  23h55 de Brasília "hoje" ainda é o dia que acabou. Risco residual: se o envio ao Drive das 23h passar das
  0h, a foto (que espera na fila do mesmo consumidor) sai depois da meia-noite e fotografa o dia seguinte.
  Hoje o envio leva minutos; se isso mudar, mover a foto para antes do envio ou separar consumidores.
- **Envio ao Drive**: o script antigo exportava `GOOGLE_DRIVE_OAUTH_*` do `/opt/jusprime/.sync-oauth.env`.
  O comando atual obtém o token de cada escritório do banco (`TenantDriveConexao`) e só lê do ambiente o
  `GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET`, como o worker. O contêiner do agendador usa o mesmo `.env.prod` do
  worker. **Premissa a conferir na virada**: o `.env.prod` tem `GOOGLE_DRIVE_OAUTH_CLIENT_ID` e
  `GOOGLE_DRIVE_OAUTH_CLIENT_SECRET` (o worker e o "Conectar meu Drive" já dependem deles). Se faltarem, a
  primeira rodada falha com aviso ("OAuth do Drive incompleto").
- **Ordem no mesmo minuto**: o consumidor é um só e roda uma tarefa por vez, na ordem da lista. O envio ao
  Drive vai por último para não atrasar as diárias (0h, 3h, 8h, 10h). Os relatórios das 10h05 e 10h15
  exigem "a partir das 10h": um atraso só os empurra para mais tarde no mesmo dia.
- **Saída dos comandos**: o `RunCommandMessage` guarda a saída em memória. Ela aparece no log (e no e-mail)
  quando a tarefa falha; numa execução bem-sucedida, o log tem só "recebida" e "tratada" (`-vv`). Os
  arquivos `/var/log/jusprime-*.log` das tarefas param de crescer depois da virada.

O contrato da lista é o `AgendaDeTarefasTest`: para cada tarefa, o comando exato, a expressão em Brasília e
a prova de que ela dispara nos **mesmos instantes** que a linha antiga em UTC (120 pontos de partida ao longo
de 5 semanas). Mudar horário, comando ou fuso quebra o teste e exige mudar esta tabela junto.

## Trava

`Schedule::lock()` com uma trava do Symfony Lock, para que dois consumidores do `scheduler_default` (dois
contêineres por engano, ou o antigo ainda vivo durante um `up -d`) nunca gerem a mesma tarefa.

- Loja: `DoctrineDbalStore` na conexão Doctrine padrão (PostgreSQL), tabela `lock_keys` (nome padrão do
  Symfony). Vale entre contêineres, o que o `flock` em `/tmp` de cada comando não faz.
- Configuração: `app/config/packages/lock.yaml`, recurso **com nome** `agendador` (serviço
  `lock.agendador.factory`), não o `default`. Motivo: com um `default`, o Symfony passaria a usar a trava em
  todos os rate limiters (`lock_factory: auto`), e cada tentativa de login viraria escrita no banco. Com o
  nome, os limitadores ficam como sempre foram.
- Nome da trava: `agendador-de-tarefas-default` (constante `AgendaDeTarefas::TRAVA`).

## Estado

`Schedule::stateful()` com o pool `cache.agendador` (adaptador Doctrine DBAL, tabela própria
`cache_agendador`, `app/config/packages/scheduler.yaml`), mais `processOnlyLastMissedRun(true)`.

- Guarda, por schedule, o instante da última tarefa gerada. Depois de uma parada (o deploy recria o
  contêiner; o `--time-limit=3600` o reinicia de hora em hora), a tarefa que passou da hora roda ao voltar,
  só a última execução perdida de cada uma, em vez de se perder.
- Precisa estar no banco porque o contêiner é recriado a cada deploy (cache em arquivo se perderia).
- Primeira subida: sem estado, o agendador começa do agora (não roda nada "atrasado").

## Tabelas (migration `Version20261023150800`)

Criadas pela migration, nunca pela auto-criação do Symfony: `lock_keys` (`key_id`, `key_token`,
`key_expiration`) e `cache_agendador` (`item_id`, `item_data`, `item_lifetime`, `item_time`), no formato
exato que o Symfony gera. O Doctrine as enxerga pelos schema listeners da trava e do cache
(`LockStoreSchemaListener`, `DoctrineDbalCacheAdapterSchemaListener`), então o `make:migration` e o
`schema:validate` não propõem apagá-las e nenhum `schema_filter` foi preciso. Sem dado de escritório (sem
`tenant_id`).

A mesma migration cria `falha_agendada` (entidade `App\Shared\Scheduler\Entity\FalhaAgendada`, mapeamento
`AppSharedScheduler` no `doctrine.yaml`): `id`, `comando`, `ocorrida_em`, `classe_erro`, `codigo_saida`
(nulo), `dispensada_em` (nulo) e `dispensada_por_id` (nulo, FK para `user` com `ON DELETE SET NULL`, para
não travar a purga). Índice `(dispensada_em, ocorrida_em)` para a consulta das pendentes. Entidade global:
fora do `TenantAware` (teto do `TenantAwareCoberturaTest` de 67 para 68) e da auditoria
(`AuditavelCoberturaTest`), com a justificativa ao lado. O `down()` remove as três.

## Aviso de falha

`App\Shared\Scheduler\AvisoDeFalhaDoAgendador`, ouvinte do `Symfony\Component\Scheduler\Event\FailureEvent`.

Cadeia confirmada no código do Symfony 7.4 e num teste de ponta a ponta com `Worker` + `SchedulerTransport`
reais: o `RunCommandMessageHandler` lança `RunCommandFailedException` quando o comando sai com código
diferente de 0 (`throwOnFailure` é `true` por padrão) ou lança exceção; o worker a embrulha numa
`HandlerFailedException`; o `DispatchSchedulerEventListener` dispara o `FailureEvent`.

- Sempre: `logger->error('Tarefa agendada falhou: {comando}: {erro}')` com o comando, a mensagem, o código
  de saída, o fim da saída (até 2000 caracteres) e o horário agendado. Vai para o stderr do contêiner.
- Com `AGENDADOR_ALERTA_EMAIL` preenchida (um ou mais endereços separados por vírgula): e-mail
  "[BlueJus] Tarefa agendada falhou: app:..." pelo `MailerInterface`, com a identidade padrão dos e-mails
  do sistema (`IdentidadeDeEmail::aplicar`: remetente `MAILER_FROM`, `Reply-To` e prefixo do assunto).
  **Sem dado de cliente**: o e-mail leva só o comando, o horário, a classe da exceção de origem e o código
  de saída, e aponta para `docker logs jusprime_scheduler_prod`. A mensagem crua e a saída do comando (que
  podem citar escritório, pessoa, CPF ou e-mail) ficam só no log. Lida com `%env(string:default::AGENDADOR_ALERTA_EMAIL)%`: ausente no
  `.env.prod` é o mesmo que vazia (o build de produção apaga o `app/.env`).
- Falha no envio (SMTP fora, endereço mal escrito): registrada em `ERROR` e engolida. O aviso nunca derruba
  o consumidor.
- Sempre, depois do log: grava uma `FalhaAgendada` só com metadados (comando, hora agendada em Brasília,
  classe do erro e código de saída; nunca a mensagem nem a saída). Grava por SQL direto
  (`FalhaAgendadaRepository::registrar`), sem depender do EntityManager que a tarefa pode ter fechado. Uma
  falha na gravação é registrada em `ERROR` e engolida, e o e-mail sai do mesmo jeito.
- O transport do agendador não tem nova tentativa nem fila de falhas (`failed`): o retry e a fila de falhas
  do Messenger só valem para transports declarados no `messenger.yaml`. A próxima execução é a do horário
  seguinte.

### Aviso na tela e na Central do Master

- **Quem vê**: o Master (`ROLE_SUPER_ADMIN`) ou a pessoa cujo e-mail é um dos endereços de
  `AGENDADOR_ALERTA_EMAIL`, sem diferença de maiúsculas (variável vazia: só o Master). Regra única em
  `App\Shared\Scheduler\QuemVeAvisoDoAgendador`, usada pela moldura e pela rota de dispensar.
- **Aviso**: parcial `layout/_globais/aviso_falha_agendada.html.twig` (uma linha no `_globais`, sem tocar no
  `base`), com o componente do aviso do desenho 08 (`#bjp-bar` em `bj-plano.js`, já implementado como
  `.ae-aviso`) no visual cheio: borda vermelha e ⛔. Lista até 5 falhas pendentes, as mais recentes
  ("app:djen:sincronizar às 03:00 de 23/10."), avisa se há outras mais antigas e diz "Detalhe no log do
  agendador." Sem JS e sem modal: o botão "Dispensar" é um formulário POST. CSS próprio
  (`css/aviso-falha-agendada.css`) só para o botão, que repete o azul do `a.ae-aumentar` do desenho.
  Textos no catálogo (`moldura.aviso_falha_agendada.*`).
- **Junto com o aviso de espaço**: os dois ficam num contêiner fixo único do `_globais` (`.ae-avisos`,
  `css/avisos-do-topo.css`), no mesmo lugar do `.ae-aviso` (top 70px, centrado), empilhados com 8px de
  intervalo, o de espaço primeiro. Cada aviso só perde o posicionamento próprio; o visual não muda.
- **Voltar do Dispensar**: a própria página só quando ela veio de um GET; numa página montada por POST, o
  início.
- **Custo por página**: a função Twig `aviso_de_falha_agendada()` só consulta para quem vê o aviso, uma vez
  por requisição, `dispensada_em IS NULL ORDER BY ocorrida_em DESC LIMIT 6` no índice acima. Uma falha na
  consulta some com o aviso, nunca com a página.
- **Dispensar**: `POST /agendador/falhas/dispensar` (`agendador_falhas_dispensar`), CSRF
  `dispensar_falhas_agendadas`, 403 para quem não vê o aviso. Marca **todas** as pendentes de uma vez
  (`dispensada_em`, `dispensada_por_id`): o aviso some para todos os que o veem. Volta para a página de
  origem só se for caminho local (sem `//`, barra invertida nem caractere de controle, também na forma
  decodificada); senão, para o início.
- **Central do Master**: origem `falha_agendada` (`App\Master\Notificacoes\LeitorDeFalhasAgendadas`), do
  "Sistema BlueJus", tipo Alerta, sem resposta. Lista as recentes, dispensadas ou não: dispensar tira o
  aviso da moldura, não a mensagem da Central. Texto: "Tarefa agendada falhou: app:djen:sincronizar às 03:00
  de 23/10. Detalhe no log do agendador."

## Contêiner

Serviço `scheduler` no `docker-compose.prod.yml` (`container_name: jusprime_scheduler_prod`), espelho do
`worker`: mesma imagem (build `prod`), `.env.prod`, `TZ`, `APP_ENV=prod`, volume de uploads,
`entrypoint: []` (as migrations são do php), `depends_on` db e php saudáveis, `restart: unless-stopped`.
Comando:

```
php -d memory_limit=256M bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=192M -vv
```

O `-d memory_limit=256M` é o mesmo do worker: sem ele o limite do PHP da imagem (128M) cai antes do
`--memory-limit=192M`. O worker não tem healthcheck nem `logging` próprio; o agendador também não.
Consumidor separado do worker de propósito: uma tarefa longa não atrasa a fila do Drive nem a dos e-mails.
As varreduras periódicas (schedule `cadeias`) não rodam aqui, e sim no worker: ver "Cadeias no Scheduler".

`scripts/deploy-prod-tls.sh` (`up -d --remove-orphans`) sobe o agendador sem mudança. O
`scripts/vps/deploy-automatico.sh` guarda a imagem do agendador para a volta automática **quando ele já
existia antes do deploy**; no primeiro deploy com ele, se a volta acontecer, ele é desligado
(`docker rm -f`), porque a versão anterior não o tem. A rotação das imagens de volta passou a contar por
versão (2 deploys), não por contêiner. Casos novos em `scripts/testar-deploy-automatico.sh`.

## Plano de virada (passo do dono)

O deploy da versão que traz o agendador já o liga, e o deploy só acontece com o merge do PR de release.
Então o merge controla a hora, e o crontab sai **antes** dele: não há janela em que a mesma tarefa roda em
dois contêineres (o `flock` de cada comando é por contêiner; o envio ao Drive em dobro poderia duplicar
pasta no Drive). O custo é no máximo uma hora cheia sem o envio ao Drive, entre comentar o crontab e o
agendador subir, o que é aceitável (o envio é aditivo e a rodada seguinte põe em dia). Passo a passo em
DEPLOY.md, "Virada do crontab para o agendador":

1. `crontab -l > ~/crontab-antes-do-agendador.bak`.
2. O crontab real já foi conferido em 23/10/2026 (a tabela acima veio dele).
3. Comentar só estas linhas: `app:djen:sincronizar` (`0 6 * * *`), `scripts/sync-reconciliar.sh`
   (`0 * * * *`), `app:purgar-dados-expirados` (`0 3 * * *`), `app:push:resumo-semanal-cliente`
   (`0 11 * * 1`) e os dois relatórios de pastas (`5 13 * * *` e `15 13 * * *`). Continuam no crontab:
   `certbot`, `scripts/backup-db.sh` e os backups dos outros sistemas (a do `scripts/backup.sh` já está
   comentada).
4. Mergear o PR de release.
5. Depois do deploy: `docker logs jusprime_scheduler_prod`, `debug:scheduler` (7 tarefas, próxima
   execução em `-0300`) e, na hora cheia seguinte, o envio ao Drive recebido e tratado, sem `ERROR`.
6. Se o deploy falhar e voltar para a versão anterior (sem agendador; a volta automática o desliga),
   descomentar o crontab (`crontab ~/crontab-antes-do-agendador.bak`).

**Se esquecer o crontab, o deploy não liga o agendador e avisa.** O `deploy-automatico.sh` (roda como
root) lê o `crontab -l` antes de subir os contêineres. Linha ativa com `bin/console app:` ou
`sync-reconciliar.sh`: a publicação segue, mas com `SEM_AGENDADOR=1`, e o `deploy-prod-tls.sh` sobe com
`--scale scheduler=0` (o agendador nem nasce). Sai um `::warning::` com as linhas e o passo para ligar
depois (comentar o crontab e `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
scheduler`). Comentário, certbot e backups não contam; sem crontab (`no crontab for root`), o agendador
sobe. Na volta automática, o agendador só é religado se rodava antes do deploy e o crontab não tem as
tarefas. Provado em `scripts/testar-deploy-automatico.sh` e `scripts/testar-deploy-guardas.sh`. O
`deploy-prod-tls.sh` rodado à mão, sem o `deploy-automatico.sh`, não faz essa conferência.

Recomendado: preencher `AGENDADOR_ALERTA_EMAIL` no `.env.prod` antes do merge.

O `scripts/sync-reconciliar.sh` fica no repositório até a virada estar confirmada (é o caminho de volta);
removê-lo é passo de um PR seguinte.

## Limitações conhecidas

- **Parada sem sinal tratado.** A imagem não tem a extensão `pcntl`, então o consumidor não trata o
  SIGTERM do `docker stop` (deploy, restart): o Docker espera o prazo e mata com SIGKILL. A trava não é
  liberada e fica presa até expirar (cerca de `nextTime + 300 s`, pela renovação do `Checkpoint`). Até lá o
  contêiner novo não gera tarefa nenhuma, o que pode atrasar uma tarefa em até ~65 min (o estado garante
  que ela roda, só que tarde). O worker antigo tem o mesmo problema. Um `stop_grace_period` não ajuda (sem
  `pcntl` o processo nunca sai sozinho; só alongaria o deploy) e não foi posto.
- **Estouro de memória** é erro fatal do PHP: o processo morre sem passar pelo `FailureEvent`, então não há
  log de "Tarefa agendada falhou" nem e-mail. O `restart: unless-stopped` traz o contêiner de volta e o
  estado roda a tarefa de novo, que pode estourar de novo.
- **Reinício em laço não é percebido pelo deploy.** O `deploy-automatico.sh` confere a saúde do php e do
  site, não a do agendador; um agendador reiniciando sem parar passa despercebido até alguém olhar o
  `docker ps` (por isso o passo 5 da virada).

## Cadeias no Scheduler

Branch `scheduler-cadeias-delaystamp` (empilhada sobre esta). Risco **BAIXO/MÉDIO**: não muda o que cada varredura
faz, só quem a dispara. Sem migration.

As quatro varreduras periódicas se reagendavam sozinhas: cada volta, no fim, despachava a próxima com `DelayStamp`,
e a primeira era semeada por comando, pela tela da Agenda ou pela confirmação de campanha. Uma cadeia que morria
(mensagem em `failed`, perdida num deploy) só voltava com a semente. Agora quem as dispara é um **segundo schedule**,
`cadeias` (`App\Shared\Scheduler\AgendaDasCadeias`, transport `scheduler_cadeias`):

| Mensagem | Intervalo | Transport do trabalho | Janela da vez no banco |
|---|---|---|---|
| `VarrerLembretesDePush` (push dos lembretes) | 60 s | `async` | 30 s (`agenda_push_varredura`) |
| `VarrerGoogleAgenda` (Google Agenda) | 300 s | `async` | 150 s (`agenda_google_varredura`) |
| `VarrerAutomacoes` (automações e recuperação do Comercial) | 900 s | `comercial` | 450 s (`comercial_varredura`) |
| `ExpandirCampanhas` (expansor de campanhas) | 60 s | `comercial` | 30 s (`comercial_expansor`) |

- **Cada domínio declara as suas** numa classe que implementa `App\Shared\Scheduler\CadeiaRecorrente` (tag
  automática): `App\Agenda\Scheduler\CadeiasDaAgenda` (push e Google) e `App\Comercial\Scheduler\CadeiasDoComercial`
  (varredura e expansor). A `AgendaDasCadeias` as recebe por `#[AutowireIterator]` e não importa nada de domínio
  (o Shared não depende de domínios).
- **`RecurringMessage::every`** com o intervalo de cada uma (as constantes `INTERVALO_SEGUNDOS` ao lado de cada
  trava). Cada volta vai embrulhada em `RedispatchMessage(new X(), '<transport>')`: o gerador só põe a mensagem na
  fila de sempre, e o trabalho continua com a nova tentativa e a fila `failed` de hoje. Contratos:
  `AgendaDasCadeiasTest` (montagem, com dublês), `CadeiasDaAgendaTest` e `CadeiasDoComercialTest` (intervalo e
  transport) e `AgendadorNoContainerTest` (o schedule real tem as quatro).
- **Relógio fixo**: cada `every` leva `from` = `2026-01-01 00:00:00` de Brasília (`VoltaRecorrente::aCada`). Sem
  isso, o `PeriodicalTrigger` conta o intervalo a partir da subida do processo, e o worker reinicia de hora em hora
  (`--time-limit=3600`) ou antes (memória): a volta de 15 min recomeçaria a contagem a cada reinício e poderia nunca
  rodar. Com a âncora no passado, o próximo disparo é o próximo múltiplo do intervalo depois de agora (conta direta, sem
  percorrer as voltas passadas, então sem enxurrada): 900 s em :00, :15, :30 e :45; 60 s no segundo 0 de cada minuto.
  Provado com o `MessageGenerator` do Symfony em `VoltaRecorrenteTest`.
- **Sem `stateful`**: o que passou da hora numa parada não roda ao voltar; a próxima volta vem no intervalo seguinte.
  O estado e a trava do schedule `default` não são compartilhados.
- **`processOnlyLastMissedRun(true)`**: com o worker ocupado numa mensagem longa (envio ao Drive, análise da IA), as
  voltas perdidas nesse meio tempo viram **uma** por varredura, em vez de uma fila delas (todas seriam barradas pela
  trava do banco, mas não há por que gerá-las).
- **Trava própria**: `agendador-de-tarefas-cadeias` (`AgendaDasCadeias::TRAVA`), na mesma loja do `default`
  (`lock.agendador.factory`, tabela `lock_keys`). Nome diferente porque o Checkpoint segura a trava enquanto o
  consumidor vive: com o mesmo nome, o `scheduler` e o `worker` se bloqueariam. **Vida de 60 s**
  (`VIDA_DA_TRAVA`): sem `pcntl`, o `docker stop` mata o worker sem soltar a trava (ver "Limitações conhecidas"), e
  o worker novo espera ela expirar. Com 60 s, isso dá no máximo cerca de 2 min sem varredura depois de um deploy
  (com os 300 s padrão do Symfony seriam até 6 min). O push recupera o atraso: manda lembretes vencidos há menos
  de 2 h.
- **Quem consome: o `worker`** (`jusprime_worker_prod`), não o contêiner `scheduler`. O worker sobe em todo deploy;
  o `scheduler` fica desligado quando o crontab do host ainda tem as tarefas (`SEM_AGENDADOR=1`, `--scale
  scheduler=0`) e as varreduras não podem parar por isso. Comando do worker: `messenger:consume scheduler_cadeias
  comercial async`. O `scheduler_cadeias` vem primeiro porque é leve (só põe uma mensagem na fila, no máximo uma por
  minuto) e uma fila longa de e-mails não pode atrasar o relógio das varreduras. O `scheduler` continua só com o
  `scheduler_default`. O worker de DEV (`docker-compose.yml`, profile `worker`) consome o mesmo.
- **Os handlers não se reagendam.** A trava no banco (`UPDATE ... WHERE ultima_em < agora - janela` no push e no
  Google; `proxima_em <= agora`, gravando `agora + janela`, no Comercial) fica como **segunda proteção**, com a janela
  reduzida para **metade do intervalo**: barra a volta repetida (mensagem antiga, dois workers), que chega segundos
  depois, e não barra a volta legítima seguinte quando o worker a pega atrasada ou o relógio do Scheduler e o do
  redispatch se desencontram em alguns segundos. Antes eram 45 s, 240 s, 840 s e 55 s (quase o intervalo inteiro:
  qualquer adiantamento perderia a vez).
- **Expansor**: sem nenhuma campanha ativa, sai antes de reivindicar (não escreve na linha de controle a cada minuto).
  Uma campanha confirmada sai na volta do minuto seguinte (antes a confirmação a "acordava" na hora).
- **Saíram**: `GarantirVarreduraDePush`, `GarantirVarreduraDoGoogle` (e as chamadas no `AgendaNovaController`, no
  `InscreverPushUseCase` e no `ConectarGoogleAgendaUseCase`), o `SementeDoExpansor` (o "acordar" da confirmação e das
  datas comemorativas e o "socorro" da varredura de 15 min), os métodos `estaParada` das marcas e os comandos
  `app:agenda:push-varredura`, `app:agenda:google-varredura` e `app:comercial:varredura`. Os outros `DelayStamp`
  (vazão do `EnviarEmailHandler`, `MudancaNaAgendaParaOGoogleListener`, `SincronizarGoogleDaPessoaHandler`) não são
  cadeia e ficaram.
- **Falha do gerador**: o `AvisoDeFalhaDoAgendador` ouve o `FailureEvent` de todos os schedules. Uma volta das
  cadeias só falha ali se não conseguir pôr a mensagem na fila (banco fora); o aviso, o e-mail e a Central mostram o
  nome curto da mensagem de dentro (ex.: `VarrerAutomacoes`), nunca o conteúdo. A falha do trabalho em si fica no
  retry e no `failed` do transport, como antes.
- **Limite de e-mails** (vale para os dois schedules): no máximo um e-mail por comando a cada 60 min. Antes de gravar,
  o aviso consulta a falha anterior do mesmo comando (`FalhaAgendadaRepository::ultimaDoComando`); se ela é de menos
  de 60 min antes da hora agendada desta, não manda e-mail. O log e a gravação (aviso na tela e Central) continuam
  sempre. Consequência: enquanto um comando falha sem parar com menos de 60 min entre uma falha e outra (uma volta das
  cadeias com o banco fora, a cada minuto), só a primeira falha da série vira e-mail; a tela e a Central mostram as
  outras. Uma tarefa de hora em hora que falha toda hora avisa toda hora (a anterior está a 60 min, não a menos). Se
  a consulta falhar, o e-mail sai.

### Virada sem rodar dobrado

Não há passo manual. No deploy, as mensagens antigas com `DelayStamp` que ainda estão em `messenger_messages` (uma por
cadeia viva) rodam uma última vez com o código novo: disputam a trava no banco com as voltas do Scheduler, quem chega
dentro da janela não faz nada, e nenhuma deixa continuação (o handler novo não se reagenda). Pior caso: uma volta a
mais logo depois do deploy, barrada pela trava ou inofensiva (as varreduras são idempotentes: o push reserva cada
lembrete, a sincronia do Google tem trava por conexão, o expansor trava as linhas do lote).

**Volta para a versão anterior**: o código antigo não consome o `scheduler_cadeias` e depende da semente. Push e
Google voltam sozinhos (a semente antiga roda a cada consulta de lembretes de qualquer tela aberta); as do Comercial
precisam de `docker exec -w /var/www/app jusprime_worker_prod php bin/console app:comercial:varredura` (o comando
existe na versão anterior). A volta automática do `deploy-automatico.sh` imprime esse comando num `::warning::`
(caso no `testar-deploy-automatico.sh`). Ver DEPLOY.md.

## Próximos passos (fora deste PR)

- Remover `scripts/sync-reconciliar.sh` e as menções antigas de crontab nas specs (DJEN, índices, Dashboard,
  sincronização) depois da virada confirmada.
- Avaliar log da saída das execuções bem-sucedidas (hoje só aparece na falha).
- Limitações acima: instalar `pcntl` na imagem (parada limpa, trava liberada, vale também para o worker);
  aviso de morte por memória (ex.: contar reinícios do contêiner); conferir o agendador no deploy
  (`docker inspect` do estado e do contador de reinício).
