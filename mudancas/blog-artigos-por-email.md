---
tipo: novidade
---
## Changelog
- Blog: artigo novo por e-mail (spec `docs/specs/blog-artigos-por-email.md`). No primeiro "Publicar agora", `EnviarArtigoPorEmail` vai para a fila `comercial` com 15 minutos de atraso; `EnviarArtigoPorEmailUseCase` manda pelo Carteiro, tipo lista `blog_artigos`, origem `blog_artigo:<id>`, a quem tem vínculo válido e e-mail confirmado (`CadastradosRepository::comEmailConfirmado`), pulando quem já recebeu (`EnvioDeEmailRepository::destinatariosDaOrigem`) e quem saiu da lista. Cota da Hostinger, List-Unsubscribe e rodapé vêm do Comercial. Republicar não reenvia. Sem migration.
- Descadastro público (`/email/descadastro`) com o assunto `blog_artigos`: textos próprios ("Não receber mais artigos") e sem a opção de sair de todos os e-mails; o servidor ignora `todos` nesse assunto.

## Para o cliente
- Quando sai um artigo novo nos blogs do BlueJus, quem tem cadastro com e-mail confirmado recebe um e-mail com o título, um resumo e o link para ler.
- No fim de cada e-mail há um link discreto para não receber mais os artigos. Basta um clique e a confirmação na página que abre, sem precisar entrar no sistema. Os outros e-mails do BlueJus continuam chegando normalmente.
