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

Fora do agendador, **no crontab do host, sem mudança**: `certbot renew`, `scripts/backup.sh` e
`scripts/backup-db.sh` (rodam fora do contêiner da aplicação, e o backup não pode depender dela).

## Tarefas

Brasília é UTC-3 sem horário de verão (desde 2019), então cada linha é a mesma hora do crontab antigo
menos 3 horas. Nenhuma conversão cruza a meia-noite para outro dia da semana: só a foto do Dashboard
(02h55 UTC) cai no dia anterior em Brasília, que é justamente o dia que ela fotografa (ver a nota abaixo).

> **Conferir com o crontab real (`crontab -l` do dono).** A coluna "crontab antigo" foi levantada da
> documentação do repositório (DEPLOY.md, `scripts/sync-reconciliar.sh` e as specs citadas), não da VPS.
> Antes da virada, o dono compara linha a linha: tarefa no crontab que não está aqui, ou horário diferente,
> volta para decisão antes de comentar a linha.

| Comando (como o agendador roda) | Crontab antigo (UTC) | Agendador (America/Sao_Paulo) | Fonte do horário antigo |
|---|---|---|---|
| `app:purgar-dados-expirados --force --no-interaction` | `0 3 * * *` | `0 0 * * *` (0h) | DEPLOY.md |
| `app:importar-indices-monetarios --no-interaction` | `0 4 11 * *` | `0 1 11 * *` (dia 11, 1h) | `cobranca-simular-acordo-atualizacao-monetaria.md` |
| `app:djen:sincronizar --no-interaction` | `0 5 * * *` | `0 2 * * *` (2h) | `djen-integracao-comunicacoes.md:71` (linha sem `-w`) |
| `app:perfil:resumo-diario --no-interaction` | `0 11 * * *` | `0 8 * * *` (8h) | DEPLOY.md |
| `app:push:resumo-semanal-cliente --no-interaction` | `0 11 * * 1` | `0 8 * * 1` (segunda, 8h) | DEPLOY.md |
| `app:pasta:cobrar-checklist --no-interaction` | `0 12 * * 1-5` | `0 9 * * 1-5` (seg a sex, 9h) | DEPLOY.md |
| `app:pastas:relatorio-sem-processo --no-interaction` | `5 13 * * *` | `5 10 * * *` (10h05) | DEPLOY.md |
| `app:pastas:relatorio-pastas-paradas --no-interaction` | `15 13 * * *` | `15 10 * * *` (10h15) | DEPLOY.md |
| `app:dashboard:fotografar --no-interaction` | `55 2 * * *` (recomendada, nunca agendada) | `55 23 * * *` (23h55) | `trilha-b-designer.md` (D-CRON) |
| `app:sync:reconciliar --tenant-id=1 --usuario-id=1 --modo=enviar --no-interaction` | `0 * * * *` via `scripts/sync-reconciliar.sh` | `0 * * * *` (de hora em hora) | `scripts/sync-reconciliar.sh`, `sincronizacao-drive-bidirecional.md` |

Notas:

- **Opções**: as mesmas que o crontab passava (`--force` da purga; `--tenant-id=1 --usuario-id=1
  --modo=enviar` do script do Drive). `--no-interaction` em todas: sem TTY o Symfony já desliga a
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
  Drive vai por último para não atrasar as diárias (0h, 2h, 8h, 9h, 10h). Os relatórios das 10h05 e 10h15
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
`tenant_id`). O `down()` remove as duas.

## Aviso de falha

`App\Shared\Scheduler\AvisoDeFalhaDoAgendador`, ouvinte do `Symfony\Component\Scheduler\Event\FailureEvent`.

Cadeia confirmada no código do Symfony 7.4 e num teste de ponta a ponta com `Worker` + `SchedulerTransport`
reais: o `RunCommandMessageHandler` lança `RunCommandFailedException` quando o comando sai com código
diferente de 0 (`throwOnFailure` é `true` por padrão) ou lança exceção; o worker a embrulha numa
`HandlerFailedException`; o `DispatchSchedulerEventListener` dispara o `FailureEvent`.

- Sempre: `logger->error('Tarefa agendada falhou: {comando}: {erro}')` com o comando, a mensagem, o código
  de saída, o fim da saída (até 2000 caracteres) e o horário agendado. Vai para o stderr do contêiner.
- Com `AGENDADOR_ALERTA_EMAIL` preenchida (um ou mais endereços separados por vírgula): e-mail
  "[BlueJus] Tarefa agendada falhou: app:..." pelo `MailerInterface`, remetente `MAILER_FROM` (o mesmo de
  todos os e-mails do sistema). Lida com `%env(string:default::AGENDADOR_ALERTA_EMAIL)%`: ausente no
  `.env.prod` é o mesmo que vazia (o build de produção apaga o `app/.env`).
- Falha no envio (SMTP fora, endereço mal escrito): registrada em `ERROR` e engolida. O aviso nunca derruba
  o consumidor.
- O transport do agendador não tem nova tentativa nem fila de falhas (`failed`): o retry e a fila de falhas
  do Messenger só valem para transports declarados no `messenger.yaml`. A próxima execução é a do horário
  seguinte.

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

`scripts/deploy-prod-tls.sh` (`up -d --remove-orphans`) sobe o agendador sem mudança. O
`scripts/vps/deploy-automatico.sh` guarda a imagem do agendador para a volta automática **quando ele já
existia antes do deploy**; no primeiro deploy com ele, se a volta acontecer, ele é desligado
(`docker rm -f`), porque a versão anterior não o tem. A rotação das imagens de volta passou a contar por
versão (2 deploys), não por contêiner. Casos novos em `scripts/testar-deploy-automatico.sh`.

## Plano de virada (passo do dono)

O deploy da versão que traz o agendador já o liga. Enquanto as linhas antigas seguirem no crontab, cada
tarefa roda duas vezes, uma em cada contêiner (o `flock` de cada comando é por contêiner). Quase todas são
idempotentes no dia (gravam antes de enviar), mas o envio ao Drive de hora em hora em dois contêineres ao
mesmo tempo pode criar pasta duplicada no Drive. Por isso a virada é no mesmo dia do deploy, antes da
próxima hora cheia. Passo a passo em DEPLOY.md, "Virada do crontab para o agendador":

1. `crontab -l > ~/crontab-antes-do-agendador.bak`.
2. Depois do deploy: contêiner `Up` e `debug:scheduler` com as 10 tarefas e próxima execução em `-0300`.
3. **Conferir com o crontab real**: comparar `crontab -l` com a tabela acima e comentar só as linhas das
   tarefas da tabela. Certbot e backups continuam no crontab.
4. Na hora cheia seguinte, `docker logs --since 1h jusprime_scheduler_prod`: o envio ao Drive recebido e
   tratado, sem `ERROR`. No dia seguinte, as diárias.
5. Volta: restaurar o crontab salvo e `docker stop jusprime_scheduler_prod`.

Recomendado: preencher `AGENDADOR_ALERTA_EMAIL` no `.env.prod` antes do deploy.

O `scripts/sync-reconciliar.sh` fica no repositório até a virada estar confirmada (é o caminho de volta);
removê-lo é passo de um PR seguinte.

## Próximos passos (fora deste PR)

- **Cadeias com `DelayStamp`** (push-varredura, google-varredura, `comercial:varredura`,
  `ExpandirCampanhas`): hoje se reagendam sozinhas com `DelayStamp` no worker. Passá-las para o Scheduler é
  o PR seguinte, com spec própria (elas têm estado e vazão, não só horário).
- Remover `scripts/sync-reconciliar.sh` e as menções antigas de crontab nas specs (DJEN, índices, Dashboard,
  sincronização) depois da virada confirmada.
- Avaliar log da saída das execuções bem-sucedidas (hoje só aparece na falha).
