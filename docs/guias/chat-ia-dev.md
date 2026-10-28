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

- A porta tem de estar entre 1024 e 65535 e não pode ser a 8080 (é do master).
- O banco é o de **desenvolvimento do container** (o mesmo da 8080). Entre com um usuário de dev que já exista nele; se
  o seu usuário ainda não existe, crie pelo cadastro ou peça o de teste à equipe. Quem não tem o módulo da I.A recebe 403.
- A sessão (login) é de outro endereço (IP:porta), então o login na 8080 não vale aqui: entre de novo.
- Mudança de código aparece ao recarregar (o servidor do PHP lê os arquivos a cada pedido). A migration da frente precisa
  estar aplicada no banco de dev: `scripts/migrar-dev.sh`.
