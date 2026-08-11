# Runbook — importar o lote da contábil em produção pelo canal restrito

Spec e decisões: `docs/specs/cobranca-importacao-prod-remota.md`.
Instalação (§1–§3) é **do dono** — é acesso a produção. Depois de instalada, a operação do dia a dia
(§5) roda daqui.

---

## 1. Gerar a chave dedicada (uma vez, nesta máquina)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_jusprime_importacao -C jusprime-importacao -N ''
cat ~/.ssh/id_ed25519_jusprime_importacao.pub
```

> **Sem passphrase, e isso é uma escolha consciente:** o script roda sem ninguém digitando nada. O que
> compensa é o alcance da chave — ela não abre shell, só executa o wrapper. É o contrário da chave que
> existe hoje, que não tem passphrase **e** é root irrestrito.

## 2. Instalar na VPS (uma vez)

```bash
# Execute manualmente no terminal da VPS

# 2.1 o wrapper — dono root, sem escrita para mais ninguém
install -o root -g root -m 0700 /dev/stdin /usr/local/bin/bluejus-importar <<'FIM'
<<< cole aqui o conteúdo de scripts/vps/bluejus-importar >>>
FIM

# 2.2 conferir que o arquivo instalado é o MESMO que foi revisado
sha256sum /usr/local/bin/bluejus-importar
#    compare com o `sha256sum scripts/vps/bluejus-importar` da sua máquina — tem de ser idêntico.
#    (o `estado` também imprime esse hash, para conferir a qualquer momento depois)

# 2.3 diretórios de lote, de log e de saída dos passos
mkdir -p /opt/jusprime/lotes && chmod 700 /opt/jusprime/lotes
mkdir -p /var/log/bluejus-importar.d && chmod 700 /var/log/bluejus-importar.d
touch /var/log/bluejus-importar.log && chmod 600 /var/log/bluejus-importar.log

# 2.4 ⚠️ garantir que o authorized_keys termina em quebra de linha ANTES de acrescentar
[ -s /root/.ssh/authorized_keys ] && [ "$(tail -c1 /root/.ssh/authorized_keys | wc -l)" -eq 0 ] \
  && echo >> /root/.ssh/authorized_keys

# 2.5 autorizar a chave — LINHA NOVA, sem tocar na que já existe
cat >> /root/.ssh/authorized_keys <<'FIM'
restrict,command="/usr/local/bin/bluejus-importar" ssh-ed25519 AAAA...COLE_A_PUBLICA_AQUI... jusprime-importacao
FIM

# 2.6 conferir que ficaram DUAS linhas: a sua e a nova
awk '{print NR": "$1" ... "$NF}' /root/.ssh/authorized_keys
```

> 🔴 **O passo 2.4 não é frescura.** Se o `authorized_keys` atual não terminar em quebra de linha, a
> linha nova gruda no fim da chave pessoal e **as duas ficam inválidas** — você perde o acesso à VPS e
> o MCP de leitura para de funcionar. **Não feche esta sessão SSH até o teste do §4 passar**; com ela
> aberta, um erro aqui ainda é reversível.

`restrict` desliga pty, encaminhamento de porta e de agente. `command=` amarra a chave ao wrapper: o
que o cliente pedir chega em `$SSH_ORIGINAL_COMMAND` e nunca vira comando de shell.

> **Não mexa na primeira linha.** É a sua chave pessoal e é a mesma que o MCP de leitura usa. Pôr
> `command=` nela quebraria seu acesso e o MCP.

## 3. Apontar o alias daqui

Acrescentar ao `~/.ssh/config` desta máquina:

```
Host bluejus-importar
    HostName 72.60.146.89
    User root
    IdentityFile ~/.ssh/id_ed25519_jusprime_importacao
    IdentitiesOnly yes
    ServerAliveInterval 30
```

> 🔴 **`IdentitiesOnly yes` não é opcional.** Sem ela o SSH oferece as chaves que encontrar, a chave
> pessoal (irrestrita) pode autenticar primeiro, e a sessão sai **sem a tranca** — parecendo que tudo
> funciona. É a falha mais fácil de não perceber em toda esta montagem.

E liberar o script no `.claude/settings.local.json`, em `permissions.allow`:

```json
"Bash(scripts/importar-lote-prod.sh:*)"
```

Só o script, nunca `Bash(ssh …:*)`.

> **Saiba o que essa regra é e o que não é.** Ela é mais estreita que liberar `ssh`, mas **não é uma
> tranca**: o script mora no repositório e o agente tem permissão de escrever nele. Quem quiser
> contornar, reescreve o arquivo e executa. A contenção real está inteira do outro lado do cano, no
> wrapper da VPS — que não afrouxa por nada que aconteça aqui. É por isso que o §4 importa tanto.

## 4. Provar a tranca — antes de qualquer uso real

```bash
ssh bluejus-importar 'id'                                  # esperado: RECUSADO
ssh bluejus-importar 'importar 2026-08-11 top_life_1'      # esperado: RECUSADO (falta --confirmar)
ssh bluejus-importar 'receber-lote ../../etc'              # esperado: RECUSADO (data inválida)
ssh bluejus-importar                                       # esperado: RECUSADO (não abre shell)
ssh bluejus-importar 'estado'                              # esperado: FUNCIONA
```

**Se qualquer um dos quatro primeiros executar, pare.** A tranca não existe e nada do resto vale.

Confira também o registro: `ssh bluejus-importar 'estado'` e, no terminal da VPS,
`tail /var/log/bluejus-importar.log` — cada recusa tem de estar lá.

---

## 5. O ciclo do dia a dia

```bash
scripts/importar-lote-prod.sh estado                     # linha de base ANTES de mexer
scripts/importar-lote-prod.sh emitir                     # baixa os 15 arquivos da contábil
scripts/importar-lote-prod.sh enviar                     # confere os 15 e manda
scripts/importar-lote-prod.sh simular                    # as 3 carteiras, sem persistir
```

Ler a simulação inteira antes de seguir. Ela é o melhor ensaio disponível — o dev não é cópia da
produção (a TL1 tem 81 unidades no dev contra 230 em prod; um ensaio no dev já produziu alarme falso).

> 🔴 **Mas a simulação não é o ensaio perfeito, e saber onde ela mente é obrigatório.** Ela roda os 5
> passos **sem persistir nenhum**, e os passos 3 a 5 consultam o banco para decidir o que criar. Como
> os passos 1 e 2 não gravaram, eles projetam contra o estado **anterior ao lote**.
>
> Na prática, e a distinção é por **número**, não por passo:
>
> | Número | Vale como | Por quê |
> |---|---|---|
> | passo 1 (cadastro), tudo | **previsão** | nada grava antes dele |
> | passo 2 — obrigações, casos criados, rejeitadas, ignoradas, sacados divergentes | **previsão** | não dependem do que o cadastro grava (o cadastro não abre caso) |
> | passo 2 — objetos criados, pessoas criadas | **teto** | o cadastro os teria criado, e na simulação ele não gravou |
> | passos 3 a 5 — o que será criado | **teto** | contam contra um banco que ainda não recebeu os passos 1 e 2 |
> | passo 3 — *"em obrigação que JÁ existia"* | 🔺 **PISO** | a obrigação que o passo 2 vai criar migra para esta linha na execução real |
> | passo 3 — *"já importados antes (ignorados)"* | **previsão** | boleto cuja obrigação nasce no passo 2 não tem alocação anterior em nenhum dos dois modos |
>
> **Teto** = vai criar no máximo isso, provavelmente menos. **Piso** = no mínimo isso, provavelmente
> mais. Então: número de teto que veio **menor** na execução real não é defeito; a linha de piso vindo
> **maior** também não — ela *tem* de subir. O que não pode divergir são as **obrigações do passo 2**:
> essas a simulação acerta, e diferença ali merece investigação antes de seguir.

Depois, carteira por carteira, conferindo entre uma e outra:

```bash
scripts/importar-lote-prod.sh importar 2026-08-11 top_life_1  --confirmar
scripts/importar-lote-prod.sh importar 2026-08-11 top_life_2  --confirmar
scripts/importar-lote-prod.sh importar 2026-08-11 amli_br_060 --confirmar
scripts/importar-lote-prod.sh estado                     # a emissão tem de ter avançado nas 3
```

### O que esperar

- **Reimportar não duplica.** Os importadores são idempotentes: a segunda execução do mesmo arquivo
  dá zero mudanças.
- **Se um passo falhar valendo, a carteira para ali** — os seguintes leem o estado que ele deixou.
  Corrija a causa e rode a carteira de novo; o que já entrou não entra duas vezes.
- **Dois rótulos da saída mentem** e não indicam problema: *"parcelas liquidadas na planilha… a baixa
  NÃO foi feita"* (o importador nunca checa a baixa) e *"o saldo devedor das unidades listadas mudou"*
  (sai mesmo com tudo zero).
- **"Contas que NUNCA tiveram boleto" é descrição, não ação.**

### Se a conexão cair no meio

**Não repita às cegas.** O processo dentro do container **sobrevive** à queda da sessão — o que se
perde é a saída na sua tela, não a importação. Descubra o que aconteceu antes de agir:

```bash
scripts/importar-lote-prod.sh estado          # o fim do registro diz onde parou
```

No terminal da VPS, o detalhe completo:

```bash
tail -40 /var/log/bluejus-importar.log                    # início, rc de cada passo, desfecho
ls -la /var/log/bluejus-importar.d/                       # saída completa de cada passo
```

Procure a linha `FIM VALENDO :: <carteira> ::`. Se ela existir, a carteira terminou. Se só houver
`INÍCIO`, algum passo ainda estava rodando quando a sessão caiu — **espere e consulte de novo**.

> 🔴 **Neste cenário o `flock` NÃO te protege, e é importante saber disso.** A trava é do wrapper; ao
> cair a sessão o wrapper morre, o sistema libera a trava, e o `bin/console` **continua rodando dentro
> do container**. Um segundo `importar` seria aceito e correria em paralelo com o órfão, contra o mesmo
> banco. O `flock` só impede dois pedidos simultâneos com a sessão viva.
>
> Antes de repetir uma carteira que caiu no meio, confirme no terminal da VPS que não sobrou processo:
>
> ```bash
> docker exec jusprime_php_prod sh -c 'grep -la "cobranca:[i]mportar" /proc/[0-9]*/cmdline >/dev/null 2>&1 && echo RODANDO || echo PARADO'
> ```
>
> (o container de prod não tem `ps`; por isso a checagem é pelo `/proc`)
>
> 🔑 **Os colchetes em `[i]mportar` não são enfeite.** Sem eles o `grep` encontra o **próprio comando**
> — o padrão está nos argumentos dele — e a resposta é `RODANDO` para sempre, mesmo sem importação
> nenhuma. Medido: com o padrão sem colchetes, o único `/proc/<pid>/cmdline` que casa é o do grep.

Com `PARADO` confirmado, repetir é seguro — os importadores são idempotentes.

### Quando o deploy entra

Só se a importação passar a depender de código novo. Nesse caso a ordem é **deploy → `enviar` →
`simular` → `importar`**: o deploy recria o container e apaga o que foi copiado para dentro dele.
Em 11/08/2026 os 5 comandos já estavam em prod e o `master` local batia com o `origin/master`.

## 6. Pendência aberta que muda o resultado

A decisão §9.1: **143 recebimentos, R$ 42.442,73** entram como obrigação de R$ 0,00 (só honorário e
juros). Não impede a importação — muda o histórico exibido na tela. É decisão do dono.
