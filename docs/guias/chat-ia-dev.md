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
