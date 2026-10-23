---
tipo: alteracao
---
## Changelog
- Pasta, janela "Editar dados" (desenho 02, VS 18.10.26): a Ação vira só leitura ("Ação · vem do menu Processo"), com o botão "Abrir", que fecha a janela, vai à aba Processo e abre a edição da Ação. O campo `nome_acao` saiu do formulário e o `pasta_edit` passa a ignorá-lo no servidor.
- O CPF/CNPJ do cliente principal aparece só leitura, formatado (000.000.000-00 ou 00.000.000/0000-00), com atalho "Cadastro" para a edição do cliente; sem cliente ou sem documento, texto neutro e sem atalho. Sem migration.

## Para o cliente
- Em "Editar dados" da pasta, a Ação agora é alterada pelo menu Processo: o botão "Abrir" leva direto até lá.
- O CPF ou CNPJ do cliente aparece já formatado, com um atalho para o cadastro do cliente, onde ele é alterado.
