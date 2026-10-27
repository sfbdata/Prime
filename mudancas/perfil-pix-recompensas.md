---
tipo: novidade
---
## Changelog
- Meu Perfil, "Editar informações": novo campo "Chave PIX para recompensas" (desenho 07), depois do telefone. Aceita CPF, CNPJ, e-mail, celular ou chave aleatória, conferidos pelo formato; vazio vale. A chave só aparece no próprio modal de edição (não entra na grade de dados, na revisão do cadastro nem na completude) e a tela de dados pessoais do admin não a apaga.
- Nova coluna nula `user_profiles.chave_pix_recompensa` (migration `Version20261027153900`); nenhum dado existente é alterado.

## Para o cliente
- No seu perfil, em "Editar informações", você pode informar a chave PIX onde quer receber recompensas de indicação. Ela é opcional e só você a vê.
