# Chat I.A em desenvolvimento: chave e servidor de teste

Frente `ia-chat-pagina` (spec: `docs/specs/chat-pagina-inteira.md`). Este guia é do dono: como conectar a chave da
Anthropic só no computador dele e como ver a página sem mexer na 8080.

## 1. Onde colar a chave

A chave **nunca** vai para arquivo versionado. Em desenvolvimento ela mora em `app/.env.local` (o Git ignora esse
arquivo: confira com `git check-ignore -v app/.env.local`). Dentro de uma worktree, o arquivo é o da worktree
(`.claude/worktrees/<frente>/app/.env.local`), porque o `.env.local` não acompanha o checkout.

```
ANTHROPIC_API_KEY=cole-a-chave-aqui
IA_HABILITADA=1
```

Depois confira, uma chamada mínima (16 tokens), dentro do container:

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/ia-chat-pagina/app && php bin/console app:inteligencia:testar-conexao'
```

- `conectado (modelo claude-haiku-5-5)`: a chave funciona.
- `Sem conexão: a ANTHROPIC_API_KEY não está configurada...`: faltou a chave (sai com erro, sem chamar a API).
- `Falha na conexão: Anthropic: HTTP 401 (authentication_error)`: a chave foi recusada. A chave nunca aparece na saída.

Para o escritório de teste usar o chat, o Master precisa ter liberado a I.A para ele e o administrador do escritório
ter ligado a I.A com o aceite (tela `/admin/inteligencia`). Sem isso o chat responde "desligada neste escritório".

## 2. Limites de custo

Valem os padrões de `app/config/services.yaml`: 2000 tokens por resposta e 200000 tokens por dia por pessoa. Para trocar,
descomente em `app/.env.local`:

```
CHAT_IA_TETO_TOKENS_RESPOSTA=2000
CHAT_IA_TETO_TOKENS_DIA=200000
```

Estourou o dia: o chat responde `429 teto_diario`.

## 3. Ver a página sem mexer na 8080

```bash
scripts/servir-frente.sh ia-chat-pagina 8091
```

O script confere os argumentos, descobre o IP do container `jusprime_php_dev`, imprime a URL
(`http://<ip>:8091/`) e roda o servidor do PHP **em primeiro plano**. Abra a URL no navegador e acrescente `chat-ia`.
Para parar: `Ctrl+C` no terminal. Ninguém deixa o servidor rodando.

O script sobe o `php -S` com `PHP_CLI_SERVER_WORKERS=4` (o servidor de processo único trava durante o SSE: uma resposta
em andamento bloquearia a lista de conversas) e, ao sair (Ctrl+C ou terminal fechado), derruba o `php -S` da porta no
container. Fechar o terminal à força pode deixar o servidor vivo; confira que não sobrou nenhum (o container não tem
`pgrep`, então a varredura é pelo `/proc`):

```bash
docker exec jusprime_php_dev sh -c 'for p in /proc/[0-9]*; do tr "\0" " " < $p/cmdline 2>/dev/null | grep "^php -S"; done'
```

Sem saída = nada rodando. Se aparecer linha, rode o script de novo e saia com `Ctrl+C`, ou
`docker exec jusprime_php_dev sh -c 'kill <pid>'` com o número da pasta `/proc` correspondente.

- A porta tem de estar entre 1024 e 65535 e não pode ser a 8080 (é do master).
- O banco é o **`saas_chat`**, cópia do `saas_ux` (o da 8080) feita com `pg_dump`, e a worktree aponta para ele em
  `app/.env.local` (`DATABASE_URL`, mais `IA_HABILITADA=1`; a chave da Anthropic o dono cola aí). O `saas_ux` NÃO recebe
  a migration desta frente (ela só entra nele depois do merge, por `scripts/migrar-dev.sh`). Entre com um usuário que
  exista na cópia. Quem não tem o módulo da I.A recebe 403.
- A sessão (login) é de outro endereço (IP:porta), então o login na 8080 não vale aqui: entre de novo.
- Mudança de código aparece ao recarregar (o servidor do PHP lê os arquivos a cada pedido). Migration nova da frente:
  `docker exec jusprime_php_dev bash -c "cd /var/www/.claude/worktrees/ia-chat-pagina/app && php -d memory_limit=1G bin/console doctrine:migrations:execute 'DoctrineMigrations\\<Versao>' --up -n"`
  (só ela, no `saas_chat`; o `migrations:migrate` inteiro rodaria as ~120 que o banco não tem).

## 4. O que olhar na tela (`/chat-ia`)

Passo a passo: (1) cole a chave em `app/.env.local` e rode `app:inteligencia:testar-conexao` (seção 1); (2) suba o servidor
com `scripts/servir-frente.sh ia-chat-pagina 8091`; (3) entre com um usuário do escritório de teste e abra
`http://<ip>:8091/chat-ia`; (4) `Ctrl+C` no terminal ao terminar. Sem chave, a página abre com o banner "A IA ainda não está
conectada" (isso também vale olhar).

- **Tela vazia:** saudação pela hora ("Boa tarde, Dr(a). Farlei"), caixa arredondada no centro com clipe, "Citar pasta", seletor de modelo e botão de enviar.
- **Conversa:** sua pergunta em balão discreto à direita; a resposta sem balão, aparecendo aos poucos; título da conversa mudando na barra lateral ao fim da primeira resposta.
- **Parar:** o botão de enviar vira quadrado durante a resposta; ao clicar, a resposta fica marcada "Resposta interrompida".
- **Markdown:** peça "uma tabela, uma lista numerada com sublista e um trecho de código": tabela rola na horizontal, código com botão Copiar.
- **Rolagem:** role para cima durante a resposta (ela deixa de acompanhar) e use o botão de seta para voltar ao fim.
- **Ações sob a resposta:** Copiar, Gerar de novo (última resposta) e, na última pergunta, Editar (reenvia).
- **Barra lateral:** Novo chat, busca, grupos, menu "..." (Renomear, Fixar, Excluir com diálogo próprio), recolher e reabrir.
- **Anexo e pasta:** clipe, arrastar um PDF/imagem/DOCX para a tela e colar uma imagem; `#` ou "Citar pasta" busca pelo NUP.
- **Claro/escuro:** o ícone no pé da barra alterna; sem escolha, segue o sistema.
- **Celular** (estreite a janela): a barra vira gaveta com fundo escurecido, caixa fixa embaixo, sem rolagem horizontal.
- **Erros:** sem aceite de envio de dados, pasta com segredo de justiça, teto diário: aviso vermelho acima da caixa, sem perder o texto digitado.

## 5. Produção: timeouts para o SSE

A resposta do chat (`POST /chat-ia/conversas/{id}/mensagens`) é um fluxo `text/event-stream` que pode durar minutos. Se algum
elo da cadeia (nginx, FastCGI, PHP-FPM) cortar antes, o cliente vê a resposta parar no meio. O app já manda
`X-Accel-Buffering: no` e `Cache-Control: no-store` e dá `flush` a cada trecho. O resto é configuração do servidor, abaixo,
com o valor que o repositório tem HOJE (lido em 28/10/2026; nada foi alterado).

| Ponto | Valor atual no repositório | O que o SSE precisa |
|---|---|---|
| `fastcgi_read_timeout` (nginx, `nginx/conf.d/nginx.prod.conf`, `location ~ \.php$`) | `120s` para todo PHP | 300s só no local do chat (ver abaixo); o restante pode ficar em 120s |
| `proxy_read_timeout` | não existe no bloco do JusPrime (a conexão com o PHP é FastCGI). O único `proxy_read_timeout 120s` é do bloco `grupojusprime.tech`, que é de outro sistema: NÃO mexer | não se aplica ao chat |
| `fastcgi_buffering` | não definido (padrão `on`); o `X-Accel-Buffering: no` da resposta já o desliga por pedido | `off` no local do chat (cinto e suspensório) |
| `gzip` | nenhum `gzip` em `nginx.conf` nem em `nginx.prod.conf`; a imagem `nginx:alpine` traz `#gzip on;` comentado no `nginx.conf` principal, ou seja, desligado | manter desligado para `text/event-stream` (gzip retém o fluxo em buffer). Se um dia ligar, não incluir esse tipo em `gzip_types` |
| `max_execution_time` (PHP) | não definido no `Dockerfile` (só `max_input_vars`, `upload_max_filesize`, `post_max_size` em `limits.ini`): vale o padrão do PHP, `30` segundos | `300` para esta rota (ver abaixo) |
| `request_terminate_timeout` (pool do PHP-FPM) | não definido (o único arquivo de pool do repositório é `zz-seguranca.conf`, com `register_argc_argv`): padrão `0` (sem limite) | se o dono definir um limite global, ele tem de ser maior que o do chat |
| `pm.max_children` | não definido: padrão da imagem oficial `php:fpm` | cada resposta em curso ocupa UM processo do FPM até o fim; veja o aviso abaixo |
| `nginx.conf` (desenvolvimento) | sem timeout próprio (padrão do nginx, 60s de `fastcgi_read_timeout`) | só importa se alguém testar respostas longas no dev |

### O que mudar (quando o dono decidir publicar)

No `nginx/conf.d/nginx.prod.conf`, dentro do `server` de `bluejus.com.br`, um local ANTES do `location ~ \.php$` só para a rota
das mensagens, que repete os `fastcgi_param` do bloco do PHP e muda só o que o fluxo exige:

```nginx
location ~ ^/chat-ia/conversas/\d+/mensagens$ {
    include fastcgi_params;
    fastcgi_pass php:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param HTTP_X_FORWARDED_PROTO https;
    fastcgi_param HTTP_X_FORWARDED_HOST $host;
    fastcgi_param HTTP_X_FORWARDED_PORT 443;
    fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;
    fastcgi_read_timeout 300s;
    fastcgi_buffering off;
    gzip off;
}
```

No PHP, `max_execution_time = 300` não deve valer para o site inteiro. Como o fluxo já controla o próprio tempo, a forma mais
limpa é o controller chamar `set_time_limit(300)` antes de abrir o SSE (mudança de código, fora desta documentação), ou um
`php_admin_value[max_execution_time] = 300` no pool (`zz-*.conf`, como o `zz-seguranca.conf`). `request_terminate_timeout` do
pool, se for definido, tem de ser igual ou maior que 300s. Depois de mudar o nginx: recarregar o nginx (`nginx -s reload`),
lembrando que subir o `php` sem recarregar o nginx dá 502.

Aviso de capacidade: o SSE segura um processo do FPM durante toda a resposta. Com o padrão de poucos `pm.max_children`, algumas
pessoas conversando ao mesmo tempo esgotam o pool e o resto do sistema espera. Antes de abrir o chat a muita gente, confira o
`pm.max_children` do pool em produção e o teto de tempo da resposta (`CHAT_IA_TETO_TOKENS_RESPOSTA`).

Como conferir em produção: uma resposta longa (peça um texto grande) tem de terminar com o evento `fim`, sem o evento `erro` e
sem o fluxo parar em 30s (`max_execution_time`) nem em 120s (`fastcgi_read_timeout`). `nginx -T` e `php-fpm -tt` mostram os
valores efetivos.
