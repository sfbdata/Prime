---
tipo: alteracao
---
## Changelog
- Pasta judicial aberta pela cobrança passa a se chamar só pelo cliente da carteira (nome fantasia, senão razão social ou nome completo), sem o nome da pessoa cobrada. A ordenação da coluna Cliente do Expediente acompanha a mesma regra.
- Expediente e demais telas: pasta que não veio da cobrança e tem cliente vinculado mostra o cliente principal; o nome gravado na pasta vira reserva e aparece só quando não há cliente. Pasta comum continua com o nome editável.
- Novo comando pontual `app:cobranca:regravar-nome-pasta-judicial --tenant-id=<id>` regrava o nome gravado das pastas judiciais no padrão novo. Simula por padrão; só grava com `--aplicar`. Sem migration.

## Para o cliente
- Nas pastas de cobrança judicial, o nome da pasta mostra o cliente (o credor da carteira) e não mais o devedor. No Expediente, pastas com cliente cadastrado mostram o nome desse cliente.
