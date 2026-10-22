---
tipo: alteracao
---
## Changelog
- Expediente: o menu "Colunas da tabela" guarda o ajuste de cada usuário no servidor, por escritório (`preferencia_usuario`, chave `expediente.colunas`, rota POST `/expediente/colunas`), traz uma vez o ajuste local da própria pessoa e deixa de ler a chave local sem usuário.

## Para o cliente
- No Expediente, a escolha das colunas da tabela fica guardada para cada pessoa e acompanha você em qualquer computador.
