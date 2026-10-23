---
tipo: correcao
---
## Changelog
- Convites: o banco guarda só o hash do código do link do convite; os links já enviados continuam valendo (achado de auditoria interna).
- Convites: reenviar um convite gera um link novo, e o link anterior deixa de valer.
- Auditoria: o código do link do convite não entra mais no registro de auditoria, e o que já estava gravado foi apagado.

## Para o cliente
- O link do convite fica ainda mais protegido: só quem recebe o e-mail tem o código, e reenviar o convite troca o link.
