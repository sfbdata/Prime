# Trocar a deploy key da VPS por uma somente leitura

A VPS só faz `git fetch`/`git pull` do `sfbdata/Prime`. A chave atual, `deploy-prime-vps`
(id 154375478), tem **escrita**: quem invadir a VPS consegue empurrar branches para o GitHub com
ela. Uma chave somente leitura basta. A ordem abaixo nunca deixa a VPS sem acesso: a chave nova é
cadastrada e testada **antes** de a antiga sair.

Tudo aqui é do dono (VPS e configuração do GitHub).

## 1. Ver qual chave a VPS usa hoje

```bash
# Execute manualmente no terminal externo
ssh bluejus 'cd /opt/jusprime && git remote -v; ssh -G github.com | grep -iE "^(identityfile|user) "'
```

Anote o caminho do `identityfile` (ex.: `/root/.ssh/id_ed25519`).

## 2. Gerar a chave nova na VPS (sem senha, só para o GitHub)

```bash
# Execute manualmente no terminal externo
ssh bluejus 'ssh-keygen -t ed25519 -N "" -C "deploy-prime-vps-leitura" -f /root/.ssh/deploy_prime_leitura && cat /root/.ssh/deploy_prime_leitura.pub' > /tmp/deploy_prime_leitura.pub
cat /tmp/deploy_prime_leitura.pub
```

## 3. Cadastrar no GitHub como somente leitura

```bash
# Execute manualmente no terminal externo
gh api -X POST repos/sfbdata/Prime/keys -f title=deploy-prime-vps-leitura \
  -f key="$(cat /tmp/deploy_prime_leitura.pub)" -F read_only=true -q '{id, read_only}'
rm /tmp/deploy_prime_leitura.pub
```

Tem de responder `"read_only": true`.

## 4. Apontar o Git da VPS para a chave nova e testar

```bash
# Execute manualmente no terminal externo
ssh bluejus 'cd /opt/jusprime && git config core.sshCommand "ssh -i /root/.ssh/deploy_prime_leitura -o IdentitiesOnly=yes" && git fetch origin && echo "✓ fetch com a chave nova"'
# A chave nova NÃO pode empurrar (tem de dar "denied" / "read-only"):
ssh bluejus 'cd /opt/jusprime && git push --dry-run origin HEAD:refs/heads/teste-chave-leitura 2>&1 | tail -2'
```

`core.sshCommand` vale só para o repositório `/opt/jusprime`: outros usos de SSH da VPS não mudam.

## 5. Remover a chave antiga

Só depois do `✓` do passo 4:

```bash
# Execute manualmente no terminal externo
gh api -X DELETE repos/sfbdata/Prime/keys/154375478
gh api repos/sfbdata/Prime/keys -q '.[]|{id,title,read_only}'   # só a nova, read_only true
ssh bluejus 'cd /opt/jusprime && git fetch origin && echo "✓ deploy continua lendo"'
```

Se a chave antiga (passo 1) não for usada para mais nada na VPS, apague também o arquivo dela lá.

## Reversão

Se o `git fetch` falhar depois da troca: `ssh bluejus 'cd /opt/jusprime && git config --unset
core.sshCommand'` volta a usar a chave antiga (enquanto ela não tiver sido removida no passo 5).
Depois do passo 5, cadastre de novo a chave pública antiga, ou gere outra (passos 2 a 4).
