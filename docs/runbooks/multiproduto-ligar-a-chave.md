# Runbook: ligar a chave `MULTIPRODUTO_MODO` em produção

Para o Samuel ligar, em dois passos e com volta fácil, a chave que faz o menu e as telas obedecerem à escolha de
ferramentas de cada conta (spec `docs/specs/multiproduto-contratacao-e-voto.md`, §6, §7 e D2/D3 do §11).

Nenhuma senha aparece aqui. O valor de `MULTIPRODUTO_MODO` não é segredo: pode aparecer na tela.

Todos os comandos rodam na VPS, na pasta do projeto (`/opt/jusprime`).

---

## 1. O que a chave faz

- A chave tem três valores: `desligado` (como hoje: o menu mostra tudo para todos), `observacao` e `bloqueio`.
- Em `observacao`, o menu já obedece à escolha da conta e as telas seguem abertas. Quando alguém abriria uma tela de
  ferramenta que a conta não tem, o sistema só anota no log.
- Em `bloqueio`, essa tela passa a dar "página não encontrada".
- Escritório sem linha na tabela `contratacao` (os 36 de hoje) continua com tudo, em qualquer valor. Em produção a
  tabela está vazia. Quem nasce pelo site do Voto (`/cadastro?produto=voto`) tem só o Voto.

Só o serviço `php` precisa da chave (a guarda e o menu rodam nele). O `worker` e o `scheduler` leem o mesmo
`.env.prod`, mas a guarda não roda neles: não precisam ser recriados. O container do Voto não lê o `.env.prod`.

---

## 2. Antes de começar

1. Confira que o código com a guarda está no ar. O canal de log `multiproduto` existe na configuração do container:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   docker exec jusprime_php_prod grep -c multiproduto /var/www/app/config/packages/monolog.yaml
   ```

   **Esperado:** um número maior que 0.
   Se vier `0` ou erro de arquivo, a versão com a guarda ainda não foi publicada: pare aqui.

2. Veja o valor atual da chave (só o valor dela, nada mais):

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   cd /opt/jusprime && grep -c '^MULTIPRODUTO_MODO=' .env.prod; grep '^MULTIPRODUTO_MODO=' .env.prod
   ```

   **Esperado hoje:** `0` e nenhuma linha. Se vier `1` ou mais, já existe linha: no passo 1 do item 3, use o
   segundo bloco (o que apaga as antigas e grava a nova).

---

## 3. Passo 1: modo `observacao`

1. Grave a linha nova (o `\n` antes garante que ela não cola no fim de outra linha):

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   cd /opt/jusprime && printf '\nMULTIPRODUTO_MODO=observacao\n' >> .env.prod
   grep '^MULTIPRODUTO_MODO=' .env.prod
   ```

   **Esperado:** uma linha só, `MULTIPRODUTO_MODO=observacao`. Se aparecerem duas ou mais linhas (uma antiga e a
   nova), apague as antigas e deixe só a certa:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   cd /opt/jusprime && sed -i '/^MULTIPRODUTO_MODO=/d' .env.prod && printf '\nMULTIPRODUTO_MODO=observacao\n' >> .env.prod
   grep -c '^MULTIPRODUTO_MODO=' .env.prod
   ```

   **Esperado:** `1`.

2. Recrie o php e recarregue o nginx logo depois (sem o reload, o site dá 502). O site fica fora do ar (erro 502) até o php ficar healthy, cerca de 1 a 2 minutos. Faça em horário calmo.

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps --no-build php
   docker exec jusprime_nginx_prod nginx -t && docker exec jusprime_nginx_prod nginx -s reload
   docker inspect -f '{{.State.Health.Status}}' jusprime_php_prod
   ```

   **Esperado:** `test is successful` e, no último comando, `healthy` em até 3 minutos (repita o comando).

3. Confira que o site abre e que o valor chegou ao container:

   ```bash
   # Execute manualmente no terminal externo (na VPS)
   curl -sS -o /dev/null -w '%{http_code}\n' https://bluejus.com.br/
   docker exec jusprime_php_prod sh -c 'echo "$MULTIPRODUTO_MODO"'
   ```

   **Esperado:** primeiro `200` ou `302` (nunca `502`), depois `observacao`. Se vier `502`, repita o
   `nginx -s reload` do item 2. Se o valor vier vazio, a linha não entrou no `.env.prod` ou o php não foi recriado:
   repita os itens 1 e 2.

---

## 4. Ler o log do modo observação (nos 2 ou 3 dias seguintes)

Em produção a guarda escreve no `stderr` do php, uma linha JSON por ocorrência, no canal `multiproduto`. Para ler:

```bash
# Execute manualmente no terminal externo (na VPS)
docker logs --since 72h jusprime_php_prod 2>&1 | grep '"channel":"multiproduto"' | tail -50
```

Cada linha é JSON com `"message":"Rota de produto que o escritório não tem"` e, dentro de `"context"`, a `rota`, o
`tenant` (id do escritório) e os `produtos` que a rota exige.

Para contar por escritório (a coluna da esquerda é a quantidade, a da direita aparece como `"tenant":ID`):

```bash
# Execute manualmente no terminal externo (na VPS)
docker logs --since 72h jusprime_php_prod 2>&1 | grep '"channel":"multiproduto"' | grep -o '"tenant":[0-9]*' | sort | uniq -c | sort -rn
```

Se o php for recriado (deploy) durante a observação, o log anterior se perde: consulte antes de cada deploy e anote o resultado.

Sem nenhuma linha: normal. Hoje ninguém tem linha em `contratacao`, então nada deve ser anotado.

O que é **normal** ver: só escritórios que nasceram pelo Voto (ou que alguém mexeu em Meu Perfil > Ferramentas), com a
rota de uma ferramenta que eles não escolheram. Isso é o que o `bloqueio` vai travar.

O que é **surpresa**: qualquer escritório antigo (dos 36 de hoje) aparecendo. Ele não tem linha e deveria ter tudo.
Nesse caso **não siga para o passo 2 (bloqueio)**: anote o `tenant` e a `rota` e avise o Coordenador.

Também é surpresa: escritório novo anotado em rota que ele deveria ter (por exemplo, conta só Voto na rota do
Voto). Avise o Coordenador.

---

## 5. Passo 2: modo `bloqueio`

Só depois de 2 ou 3 dias sem surpresa no log.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && sed -i 's/^MULTIPRODUTO_MODO=.*/MULTIPRODUTO_MODO=bloqueio/' .env.prod
grep '^MULTIPRODUTO_MODO=' .env.prod
```

Se o grep não mostrar linha nenhuma, a linha não existe: use o bloco do printf do passo 1.

**Esperado:** uma linha só, `MULTIPRODUTO_MODO=bloqueio`. Se aparecer mais de uma, apague as repetidas:

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && sed -i '/^MULTIPRODUTO_MODO=/d' .env.prod && printf '\nMULTIPRODUTO_MODO=bloqueio\n' >> .env.prod
grep -c '^MULTIPRODUTO_MODO=' .env.prod
```

Recrie, recarregue o nginx e confira. O site fica fora do ar (erro 502) até o php ficar healthy, cerca de 1 a 2 minutos. Faça em horário calmo.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps --no-build php
docker exec jusprime_nginx_prod nginx -t && docker exec jusprime_nginx_prod nginx -s reload
docker inspect -f '{{.State.Health.Status}}' jusprime_php_prod
curl -sS -o /dev/null -w '%{http_code}\n' https://bluejus.com.br/
docker exec jusprime_php_prod sh -c 'echo "$MULTIPRODUTO_MODO"'
```

**Esperado:** `healthy`, `200` ou `302`, e `bloqueio`.

---

## 6. O que olhar na tela depois do bloqueio

1. **Conta só Voto** (crie uma pelo `/cadastro?produto=voto`, ou use uma de teste): vê só o Voto no menu, e a
   tela inicial é a do Voto. Abrir à mão o endereço de uma tela de outra ferramenta (por exemplo de Pastas) dá
   "página não encontrada".
2. **Ferramentas:** com uma conta que não é só Voto, abra Meu Perfil > Ferramentas, desligue uma ferramenta e
   salve. Ela some do menu. Ligue de novo e salve: ela volta.
3. **Escritório antigo:** entre com uma conta de um dos 36 escritórios de hoje que não mexeu em Ferramentas. O menu
   e as telas continuam iguais a antes.
4. **Conector de IA e botão Judicializar da Cobrança:** o bloqueio vale para os dois. Só aparecem se a conta tem a
   ferramenta ligada.
5. **Master:** entra em tudo como sempre, inclusive em ferramenta que um escritório não tem.

Se algo destoar, volte atrás (passo 7) e avise o Coordenador.

---

## 7. Voltar atrás

Troque o valor para `desligado`, recrie o php e recarregue o nginx. Volta a ser exatamente como era antes da chave. O site fica fora do ar (erro 502) até o php ficar healthy, cerca de 1 a 2 minutos. Faça em horário calmo.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && sed -i 's/^MULTIPRODUTO_MODO=.*/MULTIPRODUTO_MODO=desligado/' .env.prod
grep '^MULTIPRODUTO_MODO=' .env.prod
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps --no-build php
docker exec jusprime_nginx_prod nginx -t && docker exec jusprime_nginx_prod nginx -s reload
docker inspect -f '{{.State.Health.Status}}' jusprime_php_prod
docker exec jusprime_php_prod sh -c 'echo "$MULTIPRODUTO_MODO"'
```

Se o grep não mostrar linha nenhuma, a linha não existe: use o bloco do printf do passo 1 (com `desligado`).

**Esperado:** uma linha `MULTIPRODUTO_MODO=desligado` (se vier mais de uma, apague as repetidas como no passo 5),
`healthy` e `desligado`. Para voltar do `bloqueio` para o `observacao`, o mesmo roteiro, trocando a palavra.
