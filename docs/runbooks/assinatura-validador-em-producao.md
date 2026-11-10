# Runbook: ligar o validador de PDF da Assinatura em produção

O contêiner `validador_pdf` (pasta `validador-pdf/`, spec `docs/specs/assinatura-documentos-etapa-6.md`) confere a
cadeia gov.br e ICP-Brasil dos PDFs assinados e sela o PDF final com o e-CNPJ do BlueJus (etapa 8). Sem ele, o envio
recusa os níveis gov.br e ICP-Brasil (decisão E6-4) e o PDF final sai sem selo. As assinaturas Simples e Reforçada não
dependem dele.

Ele já está no `docker-compose.prod.yml`, mas **desligado**: só existe com o profile `assinatura`. Ligar é este passo a
passo. O php e o worker falam com ele por uma rede interna (`assinatura_net`); ele sai para a internet só pela
`validador_saida`, para baixar do ITI as raízes, as LCR e o OCSP. Não tem porta publicada.

**Quando**: depois de publicada a versão com a etapa 6, antes de liberar gov.br e ICP-Brasil para os escritórios.
Quem faz: o Samuel, na VPS, na pasta `/opt/jusprime`.

Este arquivo **não contém segredo nenhum**. O token só existe no `.env.prod` da VPS.

## 0. Antes de começar: a VPS precisa ser amd64

A imagem do validador só se constrói em amd64: os hashes das dependências Python são das versões para Linux x86_64.

```bash
# Execute manualmente no terminal externo (na VPS)
uname -m
```

**Esperado:** `x86_64`. Se vier `aarch64` (ARM), **pare aqui**: o desenvolvimento precisa refazer os hashes para essa
arquitetura antes. O compose fixa `platform: linux/amd64`, então numa máquina ARM o build falha (e com o profile
ligado o deploy inteiro falha junto).

A imagem ocupa cerca de 250 MB. Confira o disco com `df -h /`.

## 1. Criar os dois arquivos do selo, vazios (obrigatório antes de ligar)

O compose monta no validador a chave do selo (`/opt/jusprime/secrets/selo_pkcs12` e `selo_pkcs12_senha`). **Se esses
arquivos não existirem, o contêiner nem é criado** ("bind source path does not exist"), o `up -d` falha e todo deploy
volta para a versão anterior. Vazios, o validador entende "selo não configurado" e o PDF final sai sem selo, que é o
estado certo até a compra do e-CNPJ (`docs/runbooks/assinatura-selo-ecnpj-em-producao.md`).

Medido em 10/11/2026 com o Docker Compose 2.40.3, os arquivos ausentes:

- **profile desligado** (o estado de hoje): nada muda. O `config` e o `up` dos outros serviços (php, worker, scheduler,
  nginx, db, blog_builder) seguem normais, porque o compose só monta os secrets de quem usa e o validador não sobe;
- **profile ligado**: o compose só avisa (`secret file ... does not exist`), e o Docker recusa criar o contêiner do
  validador (`invalid mount config for type "bind": bind source path does not exist`).

O validador roda como o usuário 10001; os arquivos são dele, só leitura. O compose monta o arquivo do jeito que ele
está no disco (dono e permissão), sem trocar o dono.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && mkdir -p secrets && for f in selo_pkcs12 selo_pkcs12_senha; do [ -e "secrets/$f" ] || install -m 400 -o 10001 -g 10001 /dev/null "secrets/$f"; done
ls -ln secrets/selo_pkcs12 secrets/selo_pkcs12_senha
```

**Esperado:** duas linhas começando com `-r-------- 1 10001 10001`. Arquivo que já existe não é tocado (se o e-CNPJ já
foi gravado, ele fica).

## 2. Gerar o token e a URL no `.env.prod` (uma vez só)

O token é a senha que o php e o worker mandam ao validador; o compose leva o mesmo valor do `.env.prod` aos três
contêineres. A URL é fixa.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && cp -p .env.prod ".env.prod.antes-do-validador.$(date +%Y%m%d%H%M%S)"
grep -q '^ASSINATURA_VALIDADOR_TOKEN=.' .env.prod && echo 'TOKEN JA EXISTE: nao troque sem necessidade' || { sed -i '/^ASSINATURA_VALIDADOR_TOKEN=/d' .env.prod && printf '\nASSINATURA_VALIDADOR_TOKEN=%s\n' "$(openssl rand -hex 32)" >> .env.prod; }
grep -q '^ASSINATURA_VALIDADOR_URL=.' .env.prod || { sed -i '/^ASSINATURA_VALIDADOR_URL=/d' .env.prod && printf 'ASSINATURA_VALIDADOR_URL=http://validador_pdf:8080\n' >> .env.prod; }
grep -o '^ASSINATURA_VALIDADOR_[A-Z]*=' .env.prod
```

**Esperado:** `ASSINATURA_VALIDADOR_TOKEN=` e `ASSINATURA_VALIDADOR_URL=`, uma vez cada (a última linha só mostra os
nomes, nunca o valor).

O token não cifra nada: se o `.env.prod` se perder, gere outro com o mesmo comando. Não precisa de cópia no cofre.

## 3. Ligar o profile `assinatura`

Se o Voto já estiver ligado, a linha `COMPOSE_PROFILES=voto` existe e ganha `,assinatura`; senão, nasce uma linha nova.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && { grep -q '^COMPOSE_PROFILES=' .env.prod && sed -i '/^COMPOSE_PROFILES=/{/assinatura/!s/$/,assinatura/}' .env.prod || printf 'COMPOSE_PROFILES=assinatura\n' >> .env.prod; }
grep '^COMPOSE_PROFILES=' .env.prod
docker compose -f docker-compose.prod.yml --env-file .env.prod config --services
```

**Esperado:** `COMPOSE_PROFILES=assinatura` (ou `COMPOSE_PROFILES=voto,assinatura`) e, na lista de serviços,
`validador_pdf`.

## 4. Subir

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && PULAR_GIT_PULL=1 ./scripts/deploy-prod-tls.sh
```

O script constrói a imagem do validador (a primeira vez leva alguns minutos: baixa as dependências Python), liga a
manutenção por poucos segundos e recria o php e o worker, que precisam ler a URL e o token novos. O `PULAR_GIT_PULL=1`
mantém a versão publicada (sem ele, o script puxa o master). Não use `docker compose restart`: ele não relê o
`.env.prod`. Não use `debug:container --env-vars` nem `docker inspect` para conferir: mostram o token.

## 5. Conferir

Espere uns 2 minutos (na primeira subida o validador baixa as raízes do ITI) e rode, um por vez:

```bash
# Execute manualmente no terminal externo (na VPS)
docker ps --filter name=jusprime_validador_pdf_prod --format '{{.Names}} {{.Status}}'
```

**Esperado:** `jusprime_validador_pdf_prod Up ... (healthy)`. Se aparecer `(unhealthy)`, veja o passo 6.

O `/saude`, chamado de dentro do php (é o caminho que o sistema usa):

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec jusprime_php_prod sh -c 'curl -sS -w "\n%{http_code}\n" "$ASSINATURA_VALIDADOR_URL/saude"'
```

**Esperado:** `{"v":1,"ok":true,"ancoras":{"icp":N,"govbr":N,"atualizadas_em":"..."}}`, com os dois números maiores que
zero, e `200` na linha de baixo. Com `503` e `"ok":false`, veja o passo 6.

O token (sem mostrá-lo; o corpo vazio é recusado de propósito):

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec jusprime_php_prod sh -c 'printf "Authorization: Bearer %s\n" "$ASSINATURA_VALIDADOR_TOKEN" | curl -sS -H @- -H "Content-Type: application/pdf" --data-binary "" -w "\n%{http_code}\n" "$ASSINATURA_VALIDADOR_URL/v1/conferir?ancora=qualquer"'
```

**Esperado:** `{"v":1,"erro":"pdf_ilegivel"}` e `400`: o token foi aceito. Com `nao_autorizado` e `401`, o php e o
validador estão com tokens diferentes (quase sempre um contêiner que não foi recriado): repita o passo 4.

O worker também chega ao validador, e o nginx (rede de fora) não chega:

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec jusprime_worker_prod sh -c 'curl -sS -o /dev/null -w "%{http_code}\n" "$ASSINATURA_VALIDADOR_URL/saude"'
docker exec jusprime_nginx_prod wget -q -O - -T 3 http://validador_pdf:8080/saude
docker port jusprime_validador_pdf_prod
```

**Esperado:** `200` no worker; no nginx, `wget: bad address 'validador_pdf'` (ele nem acha o nome); e o `docker port`
sem nenhuma linha. Se o nginx mostrar o JSON do `/saude`, o validador entrou numa rede errada: pare e fale com o
desenvolvimento.

Por fim, o `app:conferir-segredos` passa a conferir também o `ASSINATURA_VALIDADOR_TOKEN` (sem mostrar o valor):

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T php php /var/www/app/bin/console app:conferir-segredos --modelo=- --no-interaction < .env.prod.example
```

**Esperado:** nenhuma linha com `ASSINATURA_VALIDADOR_TOKEN` na lista de problemas.

## 6. O `/saude` dá 503 (âncoras)

Quer dizer que a ICP-Brasil ou o gov.br está sem raiz carregada: primeira subida sem acesso ao ITI, volume
`validador_ancoras` perdido ou ITI fora do ar. Enquanto isso, quem assina com gov.br ou ICP-Brasil vê "tente de novo em
alguns minutos" (nada é gravado como aceito sem a cadeia), e o selo do PDF final tenta de novo e, esgotadas as
tentativas, o PDF sai sem selo. O php e o worker seguem no ar: ninguém espera o validador ficar saudável.

1. Veja o que o validador registrou:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   docker logs --since 2h jusprime_validador_pdf_prod 2>&1 | grep -i ancoras | tail -20
   ```

   - `ancoras atualizadas: icp=N raizes/...`: já resolveu; rode o `/saude` do passo 5 de novo.
   - `falha ao atualizar ancoras icp: URLError` (ou `TimeoutError`, ou o mesmo com `govbr`): sem acesso ao ITI. Vá ao
     item 2.
   - `hash_nao_confere`, `zip_ilegivel`, `pacote_sem_raiz_fixada`, `cadeia_govbr_ilegivel` ou
     `cadeia_govbr_sem_raiz_fixada`: o ITI mudou o pacote ou trocou de raiz. Fale com o desenvolvimento (a raiz nova
     entra por `ASSINATURA_VALIDADOR_ICP_SHA256` ou `ASSINATURA_VALIDADOR_GOVBR_SHA256` no `.env.prod`, só com a
     impressão digital conferida pelo desenvolvimento).

2. Teste a saída para o ITI de dentro do validador:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   docker exec jusprime_validador_pdf_prod python -c "import urllib.request; print(urllib.request.urlopen('http://acraiz.icpbrasil.gov.br/credenciadas/CertificadosAC-ICP-Brasil/hashsha512.txt', timeout=20).status)"
   docker exec jusprime_validador_pdf_prod python -c "import urllib.request; print(urllib.request.urlopen('http://repo.iti.br/docs/Cadeia_GovBr-der.p7b', timeout=20).status)"
   ```

   - `200` nos dois: a rede está boa e o ITI voltou. Peça a atualização agora (sem esperar a próxima tentativa, que é a
     cada 1 hora depois de uma falha): `docker restart jusprime_validador_pdf_prod`, espere 2 minutos e repita o
     `/saude`.
   - Erro de tempo ou de nome (`timed out`, `Name or service not known`): a VPS não está saindo para a internet na porta
     80, ou o ITI está fora do ar. Confira o firewall da VPS (saída na porta 80 e o DNS) e tente de novo mais tarde.

As âncoras boas ficam guardadas no volume `validador_ancoras` e são conferidas de novo a cada subida: depois da
primeira atualização certa, um ITI fora do ar não derruba mais o validador. **Não apague esse volume.**

## 7. O que muda nos deploys seguintes

- Com o profile ligado, todo deploy também reconstrói e recria o validador. Um build quebrado do validador (PyPI fora
  do ar, por exemplo) **para o deploy inteiro**, com o site no ar na versão anterior.
- **O rollback automático do deploy só cobre `php`, `worker` e `scheduler`.** Se um deploy falhar e voltar, o
  validador fica com a imagem da versão que falhou. Para alinhá-lo com a versão que voltou:

  ```bash
  # Execute manualmente no terminal externo (na VPS)
  cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod build validador_pdf
  docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps validador_pdf
  ```

- Os arquivos do selo (passo 1) precisam continuar existindo enquanto o profile estiver ligado. Para desligar o selo,
  esvazie-os (`docs/runbooks/assinatura-selo-ecnpj-em-producao.md`); nunca os apague.

## 8. Como desligar

1. Tire a URL do `.env.prod` (sem ela, o sistema volta a recusar gov.br e ICP-Brasil com a mensagem de "não
   configurado", em vez de "tente de novo") e tire `assinatura` do `COMPOSE_PROFILES`:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   cd /opt/jusprime && sed -i 's/^ASSINATURA_VALIDADOR_URL=.*/ASSINATURA_VALIDADOR_URL=/' .env.prod
   sed -i -e '/^COMPOSE_PROFILES=/s/,assinatura//' -e '/^COMPOSE_PROFILES=/s/assinatura,\{0,1\}//' -e '/^COMPOSE_PROFILES=$/d' .env.prod
   grep '^COMPOSE_PROFILES=' .env.prod; grep -o '^ASSINATURA_VALIDADOR_URL=.' .env.prod
   ```

   **Esperado:** `COMPOSE_PROFILES=voto` se o Voto estiver ligado (ou nenhuma linha), e nenhuma linha no segundo
   `grep` (a URL ficou vazia).

2. Recrie o php e o worker com o `.env.prod` novo e pare o validador:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   cd /opt/jusprime && PULAR_GIT_PULL=1 ./scripts/deploy-prod-tls.sh
   docker stop jusprime_validador_pdf_prod && docker rm jusprime_validador_pdf_prod
   ```

O token, os arquivos do selo e o volume `validador_ancoras` podem ficar: sem o contêiner, ninguém os usa, e religar
fica mais rápido (as âncoras já estão no volume).

## 9. Dev (máquina do Samuel)

O validador também está no `docker-compose.yml`, com o mesmo profile e sem os arquivos do selo (em dev o selo responde
"não configurado"). A suíte PHP não depende dele. Para testar no navegador, com o mesmo token nos dois lados:

```bash
# Execute manualmente no terminal externo
cd /home/samuel-bluejus/projetos/bluejus && TOKEN="$(openssl rand -hex 32)" && printf '\nASSINATURA_VALIDADOR_TOKEN=%s\n' "$TOKEN" >> .env && printf '\nASSINATURA_VALIDADOR_URL=http://validador_pdf:8080\nASSINATURA_VALIDADOR_TOKEN=%s\n' "$TOKEN" >> app/.env.local && unset TOKEN
docker compose --profile assinatura up -d validador_pdf
docker compose up -d php
```

O `.env` da raiz é o que o compose lê para o validador (e repassa o mesmo token ao php e ao worker); o `app/.env.local`
é o que o Symfony do php lê para a URL. A URL fica só no `app/.env.local` de propósito: a suíte PHP roda no mesmo
contêiner e não pode achar que o validador está configurado. Rode o primeiro comando uma vez só.
