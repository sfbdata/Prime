---
tipo: novidade
---
## Changelog
- Nova Pasta (desenho 03, L276): quando as pastas com o nome de cliente digitado apontam para um cadastro só (o cliente principal), `POST /pasta/nova` liga a nova pasta a esse cadastro (`CriarPastaDTO::vincularAoCadastroDoMesmoNome`, `PastaRepository::cadastroUnicoPorNomeDeCliente`). Nome sem cadastro, homônimos com cadastros diferentes e cadastro de outro escritório não ligam nada. Acervo, Drive e cobrança continuam sem vínculo por nome.
- `GET /expediente/nova-pasta/sugestoes` devolve `cadastro` em cada cliente; o aviso da Nova Pasta só mostra "A nova pasta será vinculada ao mesmo cadastro." quando é verdade.

## Para o cliente
- Ao abrir uma pasta nova para um cliente que já tem cadastro, a pasta já nasce ligada ao mesmo cadastro, e a janela avisa isso antes de salvar.
