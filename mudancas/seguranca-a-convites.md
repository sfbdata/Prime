---
tipo: correcao
---
## Changelog
- Convites: as telas de quem gerencia convites (escritório e plataforma) e as listas do convidado logado deixam de levar o código do link do convite; revogar, reenviar, aceitar e recusar passam a usar o número do convite (achados de auditoria interna).
- Convites: quem gerencia convites só vê e mexe no convite de perfil que ele próprio poderia atribuir; convite de outro escritório ou fora do alcance responde 404.
- Convites: o e-mail do convite é validado na criação (mesma regra do cadastro) e a confirmação de reenviar e revogar sai do JavaScript da página.

## Para o cliente
- O link do convite agora só existe no e-mail de quem foi convidado, e a lista de convites mostra a cada pessoa só os convites que ela pode gerenciar.
