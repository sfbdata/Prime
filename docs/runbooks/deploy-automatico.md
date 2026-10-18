# CI/CD automático: versão, release e deploy

Como o código chega à produção depois desta mudança, o que configurar uma vez (só o dono) e como
desligar ou voltar. Política de versões: [../versionamento.md](../versionamento.md). Proteção das
tags: [protecao-tags-versao.md](protecao-tags-versao.md).

## 1. O fluxo

```
PR com arquivo em mudancas/  →  checks verdes  →  merge
        │
        ▼  (a cada merge no master)
Preparar release: recalcula a versão e atualiza o PR "Lança a vX.Y.Z"
        │
        ▼  (quando o dono decide: merge do PR de release)
Qualidade verde no master  →  Release: tag vX.Y.Z + release no GitHub
        │
        ▼  (DEPLOY_AUTOMATICO=ligado)
Deploy: backup conferido → versão anterior guardada → publicação → conferência
        └─ falhou depois da troca? volta sozinho para a versão anterior e o job fica vermelho
```

| Workflow | Quando roda | O que faz |
|---|---|---|
| `mudancas.yml` ("Registro de mudança") | todo PR | exige um arquivo em `mudancas/` e confere o formato |
| `preparar-release.yml` | todo push no master | mantém o PR "Lança a vX.Y.Z" (ou o fecha, se não há o que lançar) |
| `release.yml` | Qualidade verde num push no master | cria tag e release se a versão do commit ainda não tem tag; chama o Deploy |
| `deploy.yml` | chamado pelo Release, ou à mão | publica na VPS por `scripts/vps/deploy-automatico.sh` |

Mergear um PR comum **não publica nada**: só o merge do PR de release publica.

## 2. Configuração única (dono), nesta ordem

### 2.1 Dar ao App permissão de abrir o PR de release

O App "BlueJus Release" hoje só escreve conteúdo (tags). Para manter o PR de release ele precisa
também de pull requests:

1. `https://github.com/settings/apps` → o App → **Permissions & events** → Repository permissions →
   **Pull requests: Read and write** → **Save changes**.
2. O GitHub pede para aceitar a mudança na instalação: `https://github.com/settings/installations`
   → o App → **Review request** → **Accept new permissions**.

### 2.2 Tirar a aprovação manual do ambiente `release`

O merge do PR de release passa a ser a decisão de publicar; uma segunda aprovação a cada release
(e a cada atualização do PR de release, que também usa o App) travaria o fluxo. A proteção que
fica: o ambiente só aceita jobs do master, e o master só recebe por PR com os checks.

```bash
# Execute manualmente no terminal externo
gh api -X PUT repos/sfbdata/Prime/environments/release --input - <<'JSON'
{"wait_timer": 0, "reviewers": [], "deployment_branch_policy": {"protected_branches": false, "custom_branch_policies": true}}
JSON
gh api repos/sfbdata/Prime/environments/release/deployment-branch-policies -q '[.branch_policies[].name]'   # ["master"]
```

### 2.3 Chave SSH só do deploy, na VPS

Uma chave nova, usada só pelo GitHub Actions para entrar na VPS:

```bash
# Execute manualmente no terminal externo
ssh-keygen -t ed25519 -N "" -C "github-actions-deploy-prime" -f ~/.ssh/deploy_prime_actions
ssh-copy-id -i ~/.ssh/deploy_prime_actions.pub bluejus
ssh -i ~/.ssh/deploy_prime_actions -o IdentitiesOnly=yes bluejus 'echo ok'
```

### 2.4 Ambiente `production` com os segredos da VPS

```bash
# Execute manualmente no terminal externo
gh api -X PUT repos/sfbdata/Prime/environments/production --input - <<'JSON'
{"wait_timer": 0, "reviewers": [], "deployment_branch_policy": {"protected_branches": false, "custom_branch_policies": true}}
JSON
gh api -X POST repos/sfbdata/Prime/environments/production/deployment-branch-policies -f name=master -f type=branch

base64 -w0 ~/.ssh/deploy_prime_actions | gh secret set VPS_SSH_KEY_B64 --env production --repo sfbdata/Prime
ssh-keyscan -t ed25519 72.60.146.89 2>/dev/null | gh secret set VPS_KNOWN_HOSTS --env production --repo sfbdata/Prime
gh secret set VPS_HOST --env production --repo sfbdata/Prime --body 72.60.146.89
gh secret set VPS_USER --env production --repo sfbdata/Prime --body root
shred -u ~/.ssh/deploy_prime_actions   # a chave privada passa a existir só no segredo
gh secret list --env production --repo sfbdata/Prime
```

Confira o `VPS_KNOWN_HOSTS` contra a impressão digital que você já aceitou ao entrar na VPS
(`ssh-keygen -lf ~/.ssh/known_hosts -F 72.60.146.89`): é o que impede o Actions de falar com um
servidor falso.

### 2.5 Ensaiar e ligar

```bash
# Execute manualmente no terminal externo
# 1. Simulação da versão que já está no ar: confere tudo, faz e apaga um backup, não muda nada
gh workflow run deploy.yml --repo sfbdata/Prime --ref master -f tag=v2.0.0 -f simulacao=true
gh run watch --repo sfbdata/Prime "$(gh run list --repo sfbdata/Prime --workflow deploy.yml -L 1 --json databaseId -q '.[0].databaseId')"

# 2. Com a simulação verde: primeiro modo simulação no fluxo automático, depois ligado
gh variable set DEPLOY_AUTOMATICO --repo sfbdata/Prime --body simulacao
gh variable set DEPLOY_AUTOMATICO --repo sfbdata/Prime --body ligado
```

### 2.6 Tornar o "Registro de mudança" obrigatório (opcional, recomendado)

```bash
# Execute manualmente no terminal externo
gh api -X POST repos/sfbdata/Prime/branches/master/protection/required_status_checks/contexts -f 'contexts[]=Registro de mudança'
```

## 3. No dia a dia

- **Cada PR:** um arquivo em `mudancas/` (formato em `mudancas/README.md`). O Claude Code escreve.
- **Para publicar:** abrir o PR "Lança a vX.Y.Z", ler o que entra, **Merge**. Acompanhar em
  Actions → Release → Deploy. O GitHub manda e-mail se algo falhar.
- **Saídas do deploy:** verde = produção na versão nova; vermelho com "VOLTOU" = falhou e a versão
  anterior está no ar; "NADA foi alterado" = parou antes de mexer; "precisa do dono" = a versão
  tem migration que apaga ou altera dado (a detecção é conservadora: inclui `ALTER ... DROP`,
  mudança de tipo, `UPDATE`); o deploy é à mão, com dump antes; "VOLTA TAMBÉM FALHOU" = urgente,
  entrar na VPS. A volta automática restaura código e imagem, **não o banco**: é por isso que
  migration destrutiva nunca vai pelo automático.
- **Não mergeie outro PR entre o merge do PR de release e o fim do Release** (~2 min). O robô
  refaz o PR de release a cada merge, mas a proteção do master não exige branch atualizada.

## 4. Desligar e voltar

| Situação | O que fazer |
|---|---|
| Pausar o deploy automático | `gh variable delete DEPLOY_AUTOMATICO --repo sfbdata/Prime` (a release continua saindo; o deploy não) |
| Voltar a versão do ar à mão | o rollback por imagem de `docs/versionamento.md`, seção 7; as imagens ficam como `jusprime-rollback:*-pre-vX.Y.Z` e em `/opt/backups/imagens/` |
| Restaurar o banco | o dump de cada deploy fica em `/opt/backups/deploy/jusprime-pre-vX.Y.Z-*.dump` (guarda 5); restaurar com `scripts/restore.sh <arquivo>.dump`, ver [restaurar-backup.md](restaurar-backup.md) |
| Ver o log de um deploy na VPS | `ls -t /var/log/jusprime-deploy/ | head` |
| A versão do PR de release ficou sem tag (Release falhou, ou outro PR entrou no meio) | Refaça a execução do Release **do commit do merge do PR de release**: `gh run list --repo sfbdata/Prime --workflow release.yml -L 10` e `gh run rerun <id>` daquela execução. O botão "Run workflow" usa a ponta do master, que já tem mudança nova pendente, e é recusado de propósito. |
| Imagens antigas de rollback | o script guarda as dos 2 últimos deploys (tag, container parado e `.tar.gz`) e apaga as mais antigas sozinho |

## 5. O que foi testado sem tocar em produção

- `scripts/test_mudancas.py` (versão, CHANGELOG, novidades, formato) e `scripts/testar-deploy-automatico.sh`
  (simulação, publicação, migration destrutiva, build quebrado, falha depois da troca com volta
  automática, conferência falha, site fora, pré-condições), num git e num docker falsos.
- O passo "decidir" do Release e o job de release, executados com `gh` falso em todos os caminhos
  de recusa.
- O caminho real (App, ambientes, SSH) só existe depois da seção 2: o primeiro teste de verdade é a
  simulação do passo 2.5.
