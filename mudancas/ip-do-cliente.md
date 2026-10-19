---
tipo: correcao
---
## Changelog
- Segurança: o IP do cliente em produção podia ser forjado pelo cabeçalho X-Forwarded-For, o que furava os limitadores por IP (login, cadastro, aceite de convite, "esqueci a senha", PDF da política) e gravava o IP forjado na auditoria. O nginx de produção passa a mandar ao PHP só o IP que ele viu; o exemplo de produção e o DEPLOY.md deixam de confiar em REMOTE_ADDR e em private_ranges. Passo a passo da VPS em docs/guias/ip-do-cliente-em-producao.md.

## Para o cliente
- Reforçamos a proteção contra tentativas repetidas de acesso e de cadastro.
