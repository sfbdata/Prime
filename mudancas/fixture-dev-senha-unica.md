---
tipo: seguranca
---
## Changelog
- `scripts/carregar-fixture-dev.sh` (auditoria 4.04, item 2): depois do restore e das migrations, regrava o hash de TODOS os usuários do banco de dev com uma senha só, lida de `DEV_SENHA_USUARIOS` em `app/.env.local` (o script confere que o arquivo está fora do git). O hash sai do `security:hash-password` com o hasher do User; a senha entra pela entrada padrão, nunca como argumento, e nunca é impressa. Sem a variável, o script para antes de apagar o banco, com a instrução de como defini-la. O aviso final deixa de mandar criar um super-admin.

## Para o cliente
- Ao copiar dados para o ambiente de desenvolvimento, as senhas dos usuários de produção não ficam mais nele: todas são trocadas por uma senha só de desenvolvimento. Assim, uma cópia de trabalho perdida ou exposta não revela as senhas de quem usa o BlueJus.
