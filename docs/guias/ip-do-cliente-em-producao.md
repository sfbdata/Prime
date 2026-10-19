# IP do cliente em produção (SYMFONY_TRUSTED_PROXIES)

Achado da sessão 0d, confirmado em 19/10/2026: o IP do cliente podia ser forjado pelo `X-Forwarded-For`, o que
furava todos os limitadores por IP. Os afetados eram login, cadastro, aceite de convite, "esqueci a senha" e o PDF
da política, e a auditoria gravava o IP forjado.

## Por que acontecia

O nginx fala com o PHP-FPM por **FastCGI**. Em FastCGI, o `REMOTE_ADDR` que o PHP recebe **é o IP do cliente**,
repassado pelo `fastcgi_params` do nginx, e não o IP do nginx. A produção tinha:

- `SYMFONY_TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR`, ou seja, "confie em quem estiver em `REMOTE_ADDR`", que é o
  próprio cliente;
- no nginx, `fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for`, que é a lista que o cliente mandou
  mais o IP real no fim.

O Symfony tirava da lista o IP "confiável" (o real) e devolvia o que o cliente tinha escrito.

Medido de ponta a ponta com `nginx:alpine` na frente do PHP-FPM:

| nginx | `X-Forwarded-For` mandado | `getClientIp()` |
|---|---|---|
| antigo | `1.2.3.4` | `1.2.3.4` (forjado) |
| antigo | `1.2.3.4, 10.0.0.1` | `10.0.0.1` (forjado) |
| novo | qualquer | IP real |

## A correção: duas camadas, e cada uma fecha o furo sozinha

1. **nginx** (`nginx/conf.d/nginx.prod.conf`, entra com o release): `fastcgi_param HTTP_X_FORWARDED_FOR
   $remote_addr;`. O cabeçalho passa a ser sempre igual ao IP real, e não há mais o que forjar.
2. **Variável de ambiente** (`.env.prod` na VPS, mudança do dono): `SYMFONY_TRUSTED_PROXIES=127.0.0.1`. Ninguém é
   proxy confiável, então o PHP usa o `REMOTE_ADDR`, que já é o IP real. O valor `127.0.0.1` só existe para a
   variável não ficar vazia (`scripts/go-live-check.sh`).

O HTTPS continua reconhecido sem proxy confiável: o `fastcgi_params` do nginx manda `HTTPS on` e
`SERVER_PORT 443`.

Não use `private_ranges`. Quando uma conexão chega ao nginx pelo gateway do Docker (IPv6 ou acesso a partir do
próprio host), o `$remote_addr` vira `172.x.0.1`, que é IP privado, e o furo volta. O teste
`IpDoClienteAtrasDoNginxTest` prende tudo isso.

## Passo a passo na VPS (dono)

### 1. Antes: fotografar o estado (só leitura)

```bash
docker exec jusprime_php_prod printenv | grep SYMFONY_TRUSTED
docker exec jusprime_nginx_prod nginx -T 2>/dev/null | grep -nE 'fastcgi_param HTTP_X_FORWARDED_FOR|real_ip|fastcgi_pass'
dig +short bluejus.com.br A
sudo ss -tlnp | grep -E ':(80|443)\b'
```

O que esperar de cada comando:

- `printenv`: hoje mostra `SYMFONY_TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR`.
- `nginx -T`: hoje mostra `$proxy_add_x_forwarded_for` e nenhum `real_ip`.
- `dig`: deve dar o IP da própria VPS. **Se der IP da Cloudflare, pare e me avise**: com CDN na frente a correção é
  outra (`set_real_ip_from` mais `real_ip_header`).
- `ss`: só o `docker-proxy` deve escutar 80 e 443.

### 2. Aplicar

1. **Release com este PR.** O deploy atualiza o `nginx.prod.conf`. Se o deploy não recarregar o nginx, rode
   `docker exec jusprime_nginx_prod nginx -t && docker exec jusprime_nginx_prod nginx -s reload`.
2. **No `.env.prod` da VPS**, troque a linha por `SYMFONY_TRUSTED_PROXIES=127.0.0.1` e recrie o PHP para ler a
   variável: `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d php worker`.

### 3. Conferir que funcionou

```bash
docker exec jusprime_php_prod printenv | grep SYMFONY_TRUSTED_PROXIES
docker exec jusprime_nginx_prod nginx -T 2>/dev/null | grep -n 'fastcgi_param HTTP_X_FORWARDED_FOR'
```

- O primeiro deve mostrar `127.0.0.1`.
- O segundo deve mostrar `$remote_addr`.

Prova do furo fechado, feita de fora da VPS (do seu computador):

```bash
for i in 1 2 3 4 5 6; do
  curl -s -o /dev/null -w '%{http_code}\n' -H "X-Forwarded-For: 10.9.$i.$i" \
    -X POST https://bluejus.com.br/login -d '_username=naoexiste@exemplo.test&_password=x'
done
```

- Antes da correção, cada `X-Forwarded-For` diferente contava como um IP novo.
- Depois, a 6ª tentativa passa a ser barrada pelo `login_throttling` (5 em 15 min), que conta o seu IP real.
- A auditoria grava o seu IP, nunca o `10.9.x.x`.
- Espere 15 minutos depois do teste, ou use outra rede.

Conferir que o IP real continua chegando, e que não virou um IP só para todo mundo:

```sql
SELECT ip_address, count(*) FROM audit_log WHERE created_at > now() - interval '1 day' GROUP BY 1 ORDER BY 2 DESC LIMIT 10;
```

Devem aparecer IPs variados, e nenhum `172.x`. Referência de antes da correção, medida em 19/10/2026 nos últimos
30 dias: 183 IPs distintos em 4.152 linhas, nenhum de gateway do Docker e nenhum com vírgula. Depois da correção,
os números devem continuar nessa ordem.

### 4. Desfazer

- Variável: volte a linha do `.env.prod` para `127.0.0.1,REMOTE_ADDR` e rode
  `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d php worker`.
- nginx: reverter o PR, fazer um release e rodar `nginx -s reload`.

As duas mudanças são independentes: dá para desfazer uma e manter a outra.
