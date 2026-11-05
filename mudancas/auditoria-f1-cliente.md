---
tipo: correcao
---
## Changelog
- Cliente e pasta (auditoria de segurança, lote F1: 1.06, 3.15, 3.17):
  - 1.06: a ficha do cliente (`GET /clientes/{id}`) só lista as pastas que a pessoa pode ver. Usa `canAccessResourceIgnoringPastaLock` de `pasta`/`view`, a regra da janela do cliente sem o teto do cadeado. A pasta trancada segue aparecendo só com o número (D1).
  - 3.15: a recusa da ficha, da edição e da exclusão do cliente manda `resourceLabel = "Cliente #<id>"` no pedido de acesso, sem o nome do cliente na URL.
  - 3.17: `POST /pasta/{id}/cliente/vincular` carrega o cliente com o tenant explícito (`findOneBy(id, tenant)`) e exige `canAccessResource('cliente', view)`. Quem não pode ver o cliente recebe o mesmo 404 de id inexistente, sem vínculo gravado e sem CPF/CNPJ na resposta.
  - Testes: `ClienteBaixasDaAuditoriaTest`.

## Para o cliente
- Na ficha do cliente, cada pessoa da equipe passa a ver só as pastas que ela pode abrir, como já acontecia na janela "Detalhes do cliente".
- Quando alguém sem acesso tenta abrir um cliente, o pedido de acesso não mostra mais o nome dele, só o número do cadastro.
- Para vincular um cliente a uma pasta, a pessoa precisa poder ver esse cliente. Quem não pode não recebe mais o CPF ou CNPJ dele.
