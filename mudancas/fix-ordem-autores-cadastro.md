---
tipo: correcao
---
## Changelog
- Cadastro do cliente: o seletor de autores da pasta tem ordem fixa (o principal primeiro, depois o nome e, no empate, o id). Antes a ordem vinha da tabela de ligação sem ordenação e podia trocar entre aberturas, o que também deixava instável o teste `ClienteCadastroControllerTest::testAutores`.
