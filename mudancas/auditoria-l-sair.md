---
tipo: seguranca
---
## Changelog
- "Manter conectado" com token persistente (auditoria 3.29): o `remember_me` do firewall `main` passa a usar `token_provider: doctrine` (tabela `rememberme_token`, migration `Version20261105130000`). Cada cookie é uma série guardada no servidor; sair apaga a série, e uma cópia do cookie feita antes do logout não autentica mais. A senha segue na assinatura do token (`signature_properties` padrão): trocar a senha continua derrubando todos os cookies. Login sem "Manter conectado" não grava série.
- O banco guarda só o HMAC (kernel.secret) do valor do cookie (`TokenDoManterConectado`, que delega ao `DoctrineTokenProvider` do Symfony e também é o `token_verifier`): quem só lê o banco (consulta de leitura, dump, backup) não monta um cookie válido. O `EsquemaDoManterConectadoListener` mantém a tabela no esquema do Doctrine. A cada série nova, as que passaram 30 dias sem uso são apagadas. Valor errado numa série existente (o Symfony trata como cookie roubado e não registra) vai para o log como aviso.
- Fica de fora: a exclusão de conta não apaga as séries na hora (elas somem em até 30 dias sem uso, e o e-mail fica na coluna `username` até lá).
- Na publicação, os cookies assinados do formato antigo deixam de valer: quem estava com "Manter conectado" entra de novo com a senha uma vez.
- Sem o botão "Sair de todos os aparelhos" (função nova sem desenho, fica para decisão).

## Para o cliente
- Ao clicar em Sair, o "Manter conectado" daquele navegador acaba de verdade: nem uma cópia guardada do acesso consegue entrar depois. Logo depois desta versão, quem usava "Manter conectado" precisa entrar com a senha uma vez.
