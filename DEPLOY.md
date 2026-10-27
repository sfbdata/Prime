# Deploy e ambientes

> **Domínio de produção do JusPrime/BlueJus: `https://bluejus.com.br`** (app na raiz `/`).
> O `grupojusprime.tech` usado nos exemplos abaixo (certbot, trusted hosts, go-live-check)
> é **infra compartilhada** — o mesmo container/VPS também hospeda **outra** aplicação
> (gestão de condomínio, em `grupojusprime.tech/app/`). Para diagnosticar/validar a prod
> do JusPrime, use **`bluejus.com.br`**, não `grupojusprime.tech`. Confira os nomes de
> domínio nos comandos abaixo contra a config real da VPS antes de usar.

## Desenvolvimento

```bash
docker-compose up -d --build
```

- Usa bind mount do código para iteração rápida.
- Porta web: `http://localhost:8080`.

## Produção

1. Crie o arquivo de ambiente:

```bash
cp .env.prod.example .env.prod
```

2. Preencha os valores reais em `.env.prod`.

Campos obrigatórios:
- `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `DATABASE_URL`
- `APP_SECRET`
- `MAILER_DSN`
- `DATAJUD_API_KEY`
- `DEFAULT_URI` (URL base pública, ex: `https://grupojusprime.tech`)
- `SYMFONY_TRUSTED_PROXIES=127.0.0.1` (exatamente assim. O nginx fala com o PHP por FastCGI e já entrega o IP real
  em `REMOTE_ADDR`. **Não use `REMOTE_ADDR` nem `private_ranges`**: em FastCGI o `REMOTE_ADDR` é o próprio cliente, e
  confiar nele deixa qualquer um forjar o IP no `X-Forwarded-For` e furar os limitadores por IP.)
- `SYMFONY_TRUSTED_HOSTS` (regex do host, ex: `^grupojusprime\\.tech$`)

3. Execute o deploy:

```bash
chmod +x scripts/deploy-prod.sh
./scripts/deploy-prod.sh
```

Esse script:
- derruba containers anteriores (`down --remove-orphans`);
- faz build da imagem `prod` e sobe a stack;
- aguarda o PostgreSQL ficar pronto;
- executa as migrations.

> A stack de produção (`docker-compose.prod.yml`) já expõe as portas `80` e `443` e monta `/etc/letsencrypt` para TLS — não há arquivo de override separado.

## Produção com TLS (HTTPS via Let's Encrypt)

O script `deploy-prod-tls.sh` realiza o deploy completo com validação de certificados:

Pré-requisitos:
- DNS do domínio (`grupojusprime.tech`) apontando para o servidor;
- portas `80` e `443` abertas no firewall;
- `certbot` instalado no host;
- certificados já emitidos em `/etc/letsencrypt/live/grupojusprime.tech/`.

### Emitir certificado (primeira vez)

```bash
sudo certbot certonly --standalone \
  -d grupojusprime.tech \
  -d www.grupojusprime.tech \
  --email admin@grupojusprime.tech \
  --agree-tos \
  --non-interactive
```

> **Atenção:** use `--standalone` apenas antes de subir os containers. Com containers em execução, use `--webroot` conforme descrito na seção [Let's Encrypt (renovação)](#lets-encrypt-renovação).

### Executar deploy com TLS

```bash
chmod +x scripts/deploy-prod-tls.sh
./scripts/deploy-prod-tls.sh
```

Esse script:
- valida a presença dos certificados em `/etc/letsencrypt/live/grupojusprime.tech/`;
- faz `git pull`;
- derruba e sobe a stack com build;
- aguarda o banco de dados;
- executa migrations;
- verifica a config do Nginx;
- exibe as portas ativas.

Uploads em produção:
- arquivos em `public/uploads` são persistidos no volume Docker `uploads_prod`, compartilhado entre PHP e Nginx.

## Let's Encrypt (emissão e renovação via webroot)

A validação ACME usa o método **webroot**: o nginx serve o desafio HTTP-01 a partir
de `./certbot/www` (montado em `/var/www/certbot`), e os dois server blocks `:80`
expõem `location ^~ /.well-known/acme-challenge/`. Não é preciso parar o nginx —
zero downtime nas renovações.

> **Pré-requisito de infra:** o nginx precisa já estar servindo o acme-challenge.
> Alterar `nginx/conf.d/nginx.prod.conf` já basta — desde 19/08/2026 o compose monta a
> **pasta** `./nginx/conf.d` (e não mais o arquivo solto), então o container enxerga o
> arquivo novo, e o `deploy-prod*.sh` recarrega o nginx no fim. Só mudança no
> `docker-compose.prod.yml` exige **recreate**
> (`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d nginx`).
> Valide com `curl http://<dominio>/.well-known/acme-challenge/teste` (deve dar 200,
> não 301) **antes** de qualquer emissão real.
>
> ⚠️ **Por que a pasta:** bind-mount de arquivo único amarra o **inode**, e `git pull`
> grava arquivo novo em vez de editar no lugar. O container ficava preso no inode antigo
> **sem erro e sem log** — produção rodou de 27/06 a 19/08/2026 com config velha, servindo
> CSS/JS com cache `immutable` de 30 dias que já tinha sido corrigido em 17/07. `reload`
> não resolvia (relia o arquivo errado); só recriar o container resolvia.

### Emissão manual (`letsencrypt.sh issue`)

O `scripts/letsencrypt.sh` é a ferramenta de **emissão manual** (um domínio por vez,
apex). Para o domínio com `www`, prefira `certbot certonly --webroot` repetindo os `-d`
(ver abaixo). O script recarrega o nginx ao final (lê os certs direto de `/etc/letsencrypt`).

```bash
export LETSENCRYPT_DOMAIN=grupojusprime.tech
export LETSENCRYPT_EMAIL=admin@grupojusprime.tech
chmod +x scripts/letsencrypt.sh
./scripts/letsencrypt.sh issue
```

Emissão/reemissão com apex + www (rescreve o renewal config para webroot):

```bash
sudo certbot certonly --webroot -w /opt/jusprime/certbot/www \
  -d grupojusprime.tech -d www.grupojusprime.tech
```

### Renovação automática (cron com `certbot renew`)

A renovação recorrente **não** usa o `letsencrypt.sh` — usa `certbot renew` puro, que
renova todos os certs configurados e recarrega o nginx via `--deploy-hook`:

```bash
# 03h UTC = 00h de Brasília
0 3 * * * certbot renew --quiet --deploy-hook "docker exec jusprime_nginx_prod nginx -s reload"
```

> Os renewal configs em `/etc/letsencrypt/renewal/*.conf` precisam estar em
> `authenticator = webroot` (reemita com `certbot certonly --webroot` para que o
> certbot reescreva o `.conf`). Valide com `sudo certbot renew --dry-run`.

## Backup e Restauração

### Configuração inicial (na VPS, apenas uma vez)

```bash
# Cria o diretório de backups
sudo mkdir -p /var/backups/jusprime
sudo chown $USER:$USER /var/backups/jusprime

# Garante que o script é executável
chmod +x scripts/backup.sh scripts/restore.sh
```

### Executar backup manual

```bash
./scripts/backup.sh
```

O script:
- Faz dump comprimido do PostgreSQL via container Docker
- Copia os arquivos do volume `uploads_prod`
- Gera um `.tar.gz` em `/var/backups/jusprime/`
- Aplica rotação automática (padrão: 7 backups)

### Agendamento automático (crontab na VPS)

```bash
crontab -e
```

Adicione a linha (executa todo dia às 02:00):

```
# 02h UTC = 23h de Brasília (do dia anterior)
0 2 * * * /caminho/para/jusprime/scripts/backup.sh >> /var/log/jusprime-backup.log 2>&1
```

> Substitua `/caminho/para/jusprime` pelo caminho real do projeto na VPS.

### Comandos do console no container de produção

O container `jusprime_php_prod` começa em `/var/www` (estágio de produção do `Dockerfile`), e o
`bin/console` mora em `/var/www/app`. Por isso toda chamada manual do console (simulações, comandos de
uma vez) leva `-w /var/www/app`; sem ele, o `docker exec` responde "Could not open input file: bin/console".

### Contribua: cartão pelo Asaas (variáveis e webhook)

> **Variáveis (`.env.prod`, nunca no repositório):** `CONTRIBUA_ASAAS_URL_API`, `CONTRIBUA_ASAAS_API_KEY`,
> `CONTRIBUA_ASAAS_TOKEN_WEBHOOK` e as da nota fiscal (`CONTRIBUA_ASAAS_NFSE_*`); a lista completa e vazia
> está no `app/.env`. Sem elas o gateway não chama a API e o checkout responde "o gateway de pagamento não
> respondeu". **Webhook** no painel do Asaas: `https://bluejus.com.br/api/webhooks/asaas`, com o mesmo token
> (passo a passo em `docs/runbooks/contribua-asaas-passo-a-passo.md`). O Master escolhe o gateway na página
> Contribua, botão "Pagamento por cartão".

A conciliação das assinaturas (`app:contribua:conciliar-assinaturas`, de hora em hora aos 15 minutos) é tarefa do
Scheduler (`app/src/Shared/Scheduler/AgendaDeTarefas.php`), não do crontab do host. Reprocessa avisos pendentes,
reconsulta no gateway as assinaturas que ainda podem mudar e devolve ao Free quem passou do fim do mês pago (ou da
carência). Sai com erro só quando nenhuma reconsulta deu certo.

### Tarefas agendadas (Symfony Scheduler, contêiner `jusprime_scheduler_prod`)

As tarefas do sistema não moram mais no crontab do host: quem as roda é o serviço `scheduler` do
`docker-compose.prod.yml` (`messenger:consume scheduler_default`), que o deploy sobe e recria junto com o
php e o worker. A lista e os horários estão em `app/src/Shared/Scheduler/AgendaDeTarefas.php` e na spec
`docs/specs/scheduler-tarefas-agendadas.md`. **Horários em Brasília** (o gatilho recebe o fuso
`America/Sao_Paulo`; não depende do relógio da VPS, que está em UTC).

| Tarefa | Quando (Brasília) | O que faz |
|---|---|---|
| `app:purgar-dados-expirados --force` | todo dia, 0h | apaga cadastros pendentes expirados e escritórios em quarentena vencida (RN09); depois dos backups das 23h e 23h30 |
| `app:importar-indices-monetarios` | dia 11, 1h | importa os índices do BCB (INPC, IPCA e taxa legal) |
| `app:djen:sincronizar --dias=2` | todo dia, 3h | captação das comunicações do DJEN dos últimos 2 dias |
| `app:push:resumo-semanal-cliente` | segunda, 8h | resumo semanal das movimentações aos clientes |
| `app:pastas:relatorio-sem-processo` | todo dia, 10h05 | relatório mensal de pastas sem processo (só envia na primeira segunda-feira do mês, uma vez por escritório) |
| `app:pastas:relatorio-pastas-paradas` | todo dia, 10h15 | relatório mensal de pastas paradas (mesma regra de envio) |
| `app:dashboard:fotografar` | todo dia, 23h55 | foto diária do estoque do Dashboard (tendências de Vencidas e Prazos) |
| `app:sync:reconciliar --usuario-id=1 --modo=enviar` | de hora em hora, no minuto 0 | envia ao Drive o que falta (só sistema para Drive), em todos os escritórios com Drive conectado; o escritório que desconecta para de enviar |

Todas levam `--no-interaction`. Tabela conferida com o crontab real da VPS em 23/10/2026. A foto do
Dashboard e os índices monetários (ligados pelo dono em 23/10) são novos (não estavam no crontab). Duas
tarefas documentadas antes (`app:perfil:resumo-diario` e `app:pasta:cobrar-checklist`) **não estavam no
crontab real** e ficam desligadas até o dono decidir (spec, seção "Desligadas, aguardando o dono"). O consumidor é um só: duas tarefas no mesmo minuto rodam uma depois da
outra, na ordem da lista (o envio ao Drive por último).

**Ver o que está agendado e a próxima execução:**

```
docker exec -w /var/www/app jusprime_scheduler_prod php bin/console debug:scheduler
```

**Logs** (início, fim e falha de cada tarefa, no stderr do contêiner; a saída do comando em si só aparece
quando ele falha):

```
docker logs --since 24h jusprime_scheduler_prod
docker logs -f jusprime_scheduler_prod
```

**Aviso de falha.** Uma tarefa que termina com código diferente de 0 (ou lança exceção) gera sempre um
`ERROR` no log ("Tarefa agendada falhou: app:..."). Com `AGENDADOR_ALERTA_EMAIL` no `.env.prod` (um
endereço, ou vários separados por vírgula), vai também um e-mail "[BlueJus] Tarefa agendada falhou: app:..."
pelo remetente `MAILER_FROM`. O e-mail não traz dado de cliente: só o comando, o horário, a classe do erro e o
código de saída; a mensagem e a saída do comando ficam no `docker logs jusprime_scheduler_prod`. No máximo um
e-mail por comando a cada 60 min (falhas seguidas do mesmo comando só aparecem no log, na tela e na Central). Vazia ou
ausente: só o log. Mudou a variável: recrie o contêiner
(`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d scheduler`). Não há nova tentativa
automática: a próxima execução é a do horário seguinte. Toda falha também é gravada (tabela `falha_agendada`, só comando, hora, classe do erro e código) e aparece
num aviso vermelho no topo de toda tela para o Master e para quem tem o e-mail de `AGENDADOR_ALERTA_EMAIL`,
com o botão "Dispensar" (tira o aviso de todos), e na Central do Master (origem "Tarefa agendada falhou"). Para rodar uma tarefa na hora, use o `docker exec`
de sempre no `jusprime_php_prod`.

**Trava e estado.** A trava (tabela `lock_keys`) impede que dois consumidores gerem a mesma tarefa; o
estado (tabela `cache_agendador`) guarda a última execução de cada uma, então uma tarefa que passou da
hora durante um deploy ou uma parada roda quando o contêiner volta (só a última execução perdida). As duas
tabelas nascem da migration `Version20261023150800`, junto com a `falha_agendada`.

**Simular antes** continua igual, no `jusprime_php_prod` e sem tocar no agendador:
`app:purgar-dados-expirados --dry-run`, `app:pasta:cobrar-checklist --escritorio=ID --simular`,
`app:push:resumo-semanal-cliente --dry-run`, `app:pastas:relatorio-sem-processo --dry-run`,
`app:pastas:relatorio-pastas-paradas --dry-run`, `app:perfil:resumo-diario --dry-run` (os de escritório
aceitam `--tenant=ID`). A carência da quarentena (padrão 365 dias) segue em `TENANT_CARENCIA_PURGA_DIAS`.

**Espelho dos arquivos no R2** (spec `docs/specs/p2-gravacao-dupla.md`, seção "P2.5 e P2.7 preparadas"). O
agendador tem três tarefas do espelho, todas com `--se-ligado`: `armazenamento:replicar-espelho --limite=50` a
cada minuto, `armazenamento:reconciliar-espelho --fatia=auto` todo dia às 3h30 (presença e tamanho de tudo, SHA de
1/7 dos arquivos por noite) e `armazenamento:limpar-replicacao` (retenção de 90 dias da fila) todo dia às 4h30. Com
`ARMAZENAMENTO_ESPELHO_CATEGORIAS` vazia ou sem as `ESPELHO_R2_*`, elas terminam com sucesso sem fazer nada (a
limpeza da fila roda mesmo assim). Ligado, a reconciliação falha, e o aviso do agendador chega, quando há arquivo
ausente no R2 sem estar na fila, tamanho ou SHA diferente, erro de consulta ao R2 ou envio em `falhou`; o log
traz só contagens por classe e por categoria. Ao ligar uma categoria, rode
`armazenamento:carregar-acervo --categoria=X` (primeiro com `--simular`) e espere a fila esvaziar; envios em
`falhou` voltam à fila com `armazenamento:reenfileirar-falhas [--categoria=X]`.
Situação: `docker exec -w /var/www/app jusprime_php_prod php bin/console armazenamento:espelho-situacao` (mostra se
está configurado, sem valor nenhum). **Desligar:** esvazie `ARMAZENAMENTO_ESPELHO_CATEGORIAS` no `.env.prod` e
recrie php, worker e scheduler (`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d php worker
scheduler`), depois recarregue o nginx (`docker exec jusprime_nginx_prod nginx -s reload`): sem o reload, o nginx
segue apontando para o php antigo e responde 502. Os objetos já enviados ficam no R2, sem efeito.

#### Varreduras periódicas (no worker, não no agendador)

O push dos lembretes (a cada 60 s), a sincronia do Google Agenda (5 min), as automações do Comercial (15 min) e o
expansor de campanhas (60 s) são o schedule `cadeias` (`app/src/Shared/Scheduler/AgendaDasCadeias.php`). Quem o
consome é o **worker** (`jusprime_worker_prod`, `messenger:consume scheduler_cadeias comercial async`), que sobe em
todo deploy: as varreduras seguem mesmo com o agendador desligado pela trava do crontab. Cada volta só põe a mensagem
na fila de sempre (`async` ou `comercial`), com a nova tentativa e a fila `failed` de sempre. Não há comando para
"semear" nem para religar: os antigos `app:agenda:push-varredura`, `app:agenda:google-varredura` e
`app:comercial:varredura` saíram. Spec: `docs/specs/scheduler-tarefas-agendadas.md`, "Cadeias no Scheduler".

**Conferir** (o `debug:scheduler` lista os dois schedules, `cadeias` e `default`, com a próxima volta de cada um):

```
docker exec -w /var/www/app jusprime_worker_prod php bin/console debug:scheduler cadeias
docker logs --since 1h jusprime_worker_prod 2>&1 | grep -E "RedispatchMessage|Varrer|ExpandirCampanhas"
```

Com o worker no ar, o log mostra a cada minuto a volta recebida do `scheduler_cadeias` e, logo depois, a mensagem
tratada na fila. Depois de um deploy, a primeira volta pode levar até cerca de 2 min (a trava do worker antigo,
morto sem soltá-la, expira em 60 s mais o intervalo). Na virada não há passo manual: as mensagens antigas que se
reagendavam rodam uma última vez, sem continuação, e a trava no banco barra a volta em dobro.

**Se a publicação voltar para a versão anterior** (a volta automática do deploy), o código antigo depende da semente:
push e Google se religam sozinhos na primeira tela aberta; as do Comercial precisam de
`docker exec -w /var/www/app jusprime_worker_prod php bin/console app:comercial:varredura`. A própria volta automática
lembra disso num `::warning::` no log do deploy.

#### Virada do crontab para o agendador (passo do dono, uma vez)

O deploy da versão que traz o agendador já o liga, e o deploy só acontece com o merge do PR de release
("Lança a vX.Y.Z"). Por isso a hora da virada é a do merge, e o crontab sai **antes** dele: assim nenhuma
tarefa roda em dobro (uma em cada contêiner, que o `flock` de cada comando não enxerga). O custo é no
máximo uma hora cheia sem o envio ao Drive entre o comentar e o agendador subir, o que é aceitável.

1. Guarde o crontab: `crontab -l > ~/crontab-antes-do-agendador.bak`.
2. O crontab real já foi conferido em 23/10/2026; a tabela acima veio dele.
3. Comente no crontab (`crontab -e`) **só** estas linhas: `app:djen:sincronizar` (`0 6 * * *`),
   `scripts/sync-reconciliar.sh` (`0 * * * *`), `app:purgar-dados-expirados` (`0 3 * * *`),
   `app:push:resumo-semanal-cliente` (`0 11 * * 1`), `app:pastas:relatorio-sem-processo` (`5 13 * * *`) e
   `app:pastas:relatorio-pastas-paradas` (`15 13 * * *`). **Continuam no crontab: certbot,
   `scripts/backup-db.sh` e os backups dos outros sistemas** (a linha do `scripts/backup.sh` já está
   comentada).
4. Só então mergeie o PR de release.
5. Depois do deploy: `docker ps --filter name=jusprime_scheduler_prod` (deve estar `Up`, sem reiniciar
   sem parar), o `debug:scheduler` acima (7 tarefas, próxima execução em `-0300`) e
   `docker logs jusprime_scheduler_prod`. Na hora cheia seguinte, o `app:sync:reconciliar` aparece recebido
   e tratado, sem `ERROR`. No dia seguinte, confira as diárias.
6. Se o deploy falhar e voltar para a versão anterior (que não tem o agendador), descomente o crontab:
   `crontab ~/crontab-antes-do-agendador.bak`. O mesmo se o agendador não ficar de pé: restaure o crontab e
   `docker stop jusprime_scheduler_prod` (com o crontab restaurado, o próximo deploy também não o liga).

**Se esquecer o crontab, o deploy não liga o agendador e avisa.** Antes de subir os contêineres, o
`deploy-automatico.sh` lê o `crontab -l` do root. Com alguma linha ativa (não comentada) com
`bin/console app:` ou `sync-reconciliar.sh`, a publicação segue normalmente, mas o agendador não nasce
(`--scale scheduler=0` no `deploy-prod-tls.sh`), e o log do deploy (e o GitHub Actions) mostra um
`::warning::` com as linhas encontradas. As tarefas continuam no cron e nada roda duas vezes. Para ligar
depois: comente essas linhas (`crontab -e`) e rode, em `/opt/jusprime`,
`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d scheduler`. Certbot e backups não
contam; sem crontab nenhum, o agendador sobe. A volta automática só religa o agendador se ele estava
rodando antes do deploy e o crontab não tem as tarefas.

Os arquivos de log antigos (`/var/log/jusprime-*.log` das tarefas) param de crescer; o histórico passa a
ser o `docker logs` do agendador.

### Encargos de cobrança (sem cron)

Não há cron de encargos. Os juros, a multa, a correção e os honorários das obrigações são calculados
ao vivo, na leitura; o antigo `app:cobranca:atualizar-encargos` foi removido (commit a5583f7f, "F4:
remover o cron de encargos") e não existe mais no console. Se o crontab da VPS ainda tiver essa linha,
apague-a.

### Número das metas antigas (uma vez, depois da publicação)

As metas ganham um número no escritório ("Nº 0001") ao serem criadas. As que já existiam nascem sem
número (e a tela não mostra o selo) até este comando rodar. Rode **uma vez depois da publicação** da
versão com a migration `Version20261020148700`:

```
docker exec -w /var/www/app jusprime_php_prod php bin/console app:tarefa:numerar-metas --dry-run
docker exec -w /var/www/app jusprime_php_prod php bin/console app:tarefa:numerar-metas
```

O `--dry-run` lista quantas metas cada escritório teria numeradas e não grava nada. A numeração é por
escritório, em ordem de criação, a partir do próximo número livre; rodar de novo não muda nada. Para um
escritório só: `--escritorio=ID`. Não vai para o cron.

Rode logo depois da publicação: as metas criadas entre a publicação e o comando já nascem com 1, 2, 3... e as
antigas ficam com os números seguintes (não há colisão, só a ordem fica trocada). O comando grava por SQL direto,
então a numeração das antigas não aparece na trilha de auditoria (só o número muda; nada mais na meta).

### Prioridade das metas antigas (uma vez, depois da publicação)

As metas ganharam prioridade própria (Normal, Alta, Urgente), que é o que o filtro "Prioridade" de Minhas
Metas usa. A migration `Version20261020149300` cria a coluna com `normal` para todas; a meta nova já nasce com
a prioridade da pasta. Para as que já existiam herdarem a da pasta, rode **uma vez depois da publicação**:

```
docker exec -w /var/www/app jusprime_php_prod php bin/console app:tarefa:herdar-prioridade-da-pasta --dry-run
docker exec -w /var/www/app jusprime_php_prod php bin/console app:tarefa:herdar-prioridade-da-pasta
```

Pasta `urgente` vira meta Urgente, pasta `prioridade` vira meta Alta, o resto fica Normal. O `--dry-run` lista
quantas metas cada escritório teria atualizadas e não grava nada. Só mexe em meta ainda `normal`, então rodar
de novo não muda nada. Para um escritório só: `--escritorio=ID`. Não vai para o cron. Enquanto o comando não
roda, o filtro "Prioridade" só acha as metas criadas depois da publicação. O comando grava por SQL direto,
então a mudança não aparece na trilha de auditoria da meta.

### Selo "Assinado" dos documentos antigos (uma vez, depois da publicação)

Os documentos de pasta ganharam o selo "Assinado" (aba Financeiro). O upload já marca sozinho os arquivos com
assinatura digital (`.p7s`, `.p7m` e PDF assinado: PAdES, ICP-Brasil, gov.br). Os que já existiam nascem sem o
selo até este comando rodar. Rode **uma vez depois da publicação** da versão com a migration
`Version20261020151000`, primeiro a simulação e depois a gravação:

```
docker exec -w /var/www/app jusprime_php_prod php bin/console app:pasta:detectar-assinatura-antigos
docker exec -w /var/www/app jusprime_php_prod php bin/console app:pasta:detectar-assinatura-antigos --aplicar
```

Sem `--aplicar` o comando lê os arquivos, lista por escritório quantos têm assinatura e não grava nada. Com
`--aplicar` marca esses com a origem `digital`. Só olha documento ainda sem selo (PDF, `.p7s`, `.p7m`): o já
marcado, digital ou à mão, é contado como pulado e nunca é sobrescrito, então rodar de novo não muda nada. Lê
cada arquivo pelo armazenamento (só o começo e o fim do PDF); arquivo ausente ou ilegível conta como erro, vai
para o log e o comando segue (o documento volta à fila na próxima execução). No fim mostra lidos, assinados,
pulados e erros. Para um escritório só: `--escritorio=ID`; para fatiar: `--limite=N`. Não vai para o cron.

**Atenção com o armazenamento remoto (R2).** Lá o arquivo chega por um stream sem seek, e aí o detector não
consegue pular para o fim: **baixa o arquivo inteiro** para ler o começo e o fim (a memória continua limitada
às pontas, mas o tráfego e o tempo são os do acervo todo). Com acervo grande, rode em partes: um escritório
por vez (`--escritorio=ID`) e/ou fatias com `--limite=N` (cada execução retoma de onde a anterior parou,
porque a fila é "quem ainda está sem selo"), primeiro na simulação para medir o tempo.

### Personalizar configuração

Variáveis de ambiente que sobrescrevem os padrões do script:

| Variável        | Padrão                   | Descrição                         |
|-----------------|--------------------------|-----------------------------------|
| `BACKUP_DIR`    | `/var/backups/jusprime`  | Onde salvar os backups            |
| `KEEP_BACKUPS`  | `7`                      | Quantos backups manter            |

Exemplo:
```bash
BACKUP_DIR=/mnt/backup-externo KEEP_BACKUPS=14 ./scripts/backup.sh
```

### Restaurar um backup

```bash
./scripts/restore.sh /var/backups/jusprime/jusprime_20240101_020000.tar.gz
```

> **Atenção:** a restauração é destrutiva. O script pede confirmação explícita antes de prosseguir.

---

## Comandos úteis (produção)

```bash
docker-compose -f docker-compose.prod.yml --env-file .env.prod ps
docker-compose -f docker-compose.prod.yml --env-file .env.prod logs -f
docker-compose -f docker-compose.prod.yml --env-file .env.prod down
```

## Checklist automatizado pós-deploy

Após o deploy, rode um smoke check automatizado:

```bash
chmod +x scripts/go-live-check.sh
./scripts/go-live-check.sh grupojusprime.tech
```

Também é possível usar variável de ambiente:

```bash
LETSENCRYPT_DOMAIN=grupojusprime.tech ./scripts/go-live-check.sh
```

O script valida:
- variáveis críticas do `.env.prod`;
- serviços `php`, `nginx`, `db` em execução;
- saúde do PostgreSQL;
- configuração do Nginx e presença/validade dos certificados em `/etc/letsencrypt/live/<dominio>/`;
- DNS, redirecionamento HTTP -> HTTPS e resposta do endpoint HTTPS;
- status de migrations;
- status do firewall (ufw).

## Checklist de release (pré, durante e pós-deploy)

### Pré-deploy

- [ ] Branch da release está limpa (sem mudanças locais pendentes).
- [ ] Fluxos críticos validados (login, cadastro, telas principais).
- [ ] Mudanças de banco com migration criada e revisada.
- [ ] Variáveis de produção conferidas em `.env.prod`:
	- `DEFAULT_URI`
	- `SYMFONY_TRUSTED_PROXIES`
	- `SYMFONY_TRUSTED_HOSTS`
- [ ] Build da aplicação concluído sem erro.
- [ ] Backup recente do banco confirmado.
- [ ] Se o deploy leva mudança de produto: versão lançada pelo workflow Release e conferida contra o master (`docs/versionamento.md`, seção 5). Só CI, testes ou documentação não geram versão.
- [ ] Plano de rollback definido (imagem/tag anterior + comando de retorno — `docs/versionamento.md`, seção 7).
- [ ] Janela de deploy alinhada (evitar horário de pico).

### Durante o deploy

- [ ] Executar script de deploy:
	- sem TLS: `./scripts/deploy-prod.sh`
	- com TLS: `./scripts/deploy-prod-tls.sh`
- [ ] Confirmar serviços em execução:
	```bash
	docker-compose -f docker-compose.prod.yml --env-file .env.prod ps
	```
- [ ] Verificar migrations aplicadas sem erro.
- [ ] Acompanhar logs iniciais:
	```bash
	docker-compose -f docker-compose.prod.yml --env-file .env.prod logs -f
	```

### Pós-deploy (go-live)

- [ ] Rodar smoke check: `./scripts/go-live-check.sh grupojusprime.tech`
- [ ] Validar domínio público (HTTP/HTTPS, redirecionamento, rota principal e login).
- [ ] Testar upload e persistência em `public/uploads`.
- [ ] Monitorar logs e erros por 15-30 minutos após publicação.
- [ ] Registrar versão publicada (tag/commit, data/hora e responsável).
