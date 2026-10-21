---
tipo: novidade
---
## Changelog
- Janela de Cadastro do cliente: nenhum campo é obrigatório para salvar (decisão do dono, 21/10/2026). Nome, razão social, CPF e CNPJ podem ficar vazios; CPF/CNPJ informado continua precisando ser válido e não pode ser de outro cadastro do escritório. O que falta segue como pendência, sem asterisco.
- Correção: o "Novo cliente" com "Empresa" escolhido era enviado sem o tipo e caía como pessoa física ("Informe o nome completo."). O JS agora envia o `tipo`.
- Modal Nova carteira de cobrança: link "Cadastrar cliente" abre a mesma janela de Cadastro sem pasta. Rotas novas `cliente_cadastro_novo` (GET/POST `/clientes/cadastro/novo`) e `cliente_cadastro_novo_documento`, só para quem tem `resources.carteira.gerenciar` (`CriarClienteAvulsoUseCase`). Ao fechar depois de salvar, a página recarrega com o cliente no select.

## Para o cliente
- O cadastro de cliente salva com o que você tiver em mãos: o que faltar aparece como pendência, sem travar.
- Na Nova carteira de cobrança, se o cliente credor ainda não existe, clique em "Cadastrar cliente" logo abaixo da lista.
