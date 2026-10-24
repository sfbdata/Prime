---
tipo: correcao
---
## Changelog
- Pasta: a página dava erro 500 quando a pasta tinha um cliente pessoa física com o CPF vazio. A lista de clientes da janela "Editar dados" lia a razão social, que a pessoa física não tem; agora usa o nome de exibição do cliente (`nomeExibicao`). Teste `PastaClienteSemCpfTelaTest`.

## Para o cliente
- A pasta de um cliente pessoa física sem CPF cadastrado voltou a abrir normalmente.
