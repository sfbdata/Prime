---
name: criar-migration
description: "Ritual para gerar e limpar migrations Doctrine do jusprime: fotografar a divergência antes do make:migration, tirar o lixo de outra frente e os DROP INDEX de índice funcional. Carregue ao criar, editar ou revisar arquivos em app/migrations/ ou ao mudar o mapeamento de uma entidade."
---

# Migrations — o arquivo gerado nunca vale puro

`make:migration` é o caminho normal (é o único gerador `make:*` que serve aqui), mas o diff que ele
gera mistura o seu com o que já estava divergente.

```bash
# 1. ANTES de gerar: fotografe a divergência que já existia (essa não é sua)
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:update --dump-sql'
# 2. gere a migration
docker exec jusprime_php_dev bash -c 'cd app && php bin/console make:migration'
# 3. compare: tudo que já aparecia no passo 1 sai do arquivo gerado
```

Duas fontes de lixo no diff, ambas silenciosas:

- alteração de **outra frente** ainda não aplicada no dev;
- `DROP INDEX` em **índice funcional**: o Doctrine não sabe representá-lo no mapeamento, então propõe
  apagar índices criados por SQL cru (a `Version20260710130000` já avisa disso num comentário).
  Aceitar um desses `DROP` não quebra nada visível: derruba performance e, se o índice for `unique`,
  deixa entrar duplicata.

## Coordenação

- **Uma migration por vez** entre os PRs abertos: o segundo traz o master e roda a suíte de novo
  (CLAUDE.md, "Várias sessões ao mesmo tempo").
- Antes do merge, `scripts/frente-fechar.sh <frente>` confere a ordem da migration contra o
  `origin/master` e a sincronia do banco com o mapeamento.
