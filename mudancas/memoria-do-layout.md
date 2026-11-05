---
tipo: alteracao
---
## Changelog
- Desempenho: o layout deixa de repetir a mesma consulta várias vezes na mesma página (spec `docs/specs/memoria-do-layout.md`).
  - `NivelNoCadeado`: a pergunta "Master ou primário?" usa o vínculo já guardado e as pastas ocultas ficam guardadas por pessoa e escritório; `esquecer()` limpa as duas.
  - `CentralExtension`: o vínculo de quem está logado é lido uma vez para `central_disponivel()` e `presenca_sou_primario()`.
  - `ConfiguracaoDePresencaRepository`: o limite de ausência fica guardado por escritório; `salvar()` esquece.
  - `PreferenciaDoUsuarioRepository`: as chaves já lidas ficam guardadas por escritório e pessoa; `gravar()` e `apagarDoUsuario()` esquecem.
  - `BloqueioPorPedidoDeExclusao`: o "tem pedido pendente?" fica guardado por escritório; ouvinte de entidade do pedido esquece ao criar, decidir ou apagar.
  - Tudo zera entre requisições e entre mensagens do worker (`ResetInterface`). Nenhuma regra de acesso mudou.
  - Testes: `NivelNoCadeadoMemoriaTest`, `BloqueioPorPedidoDeExclusaoMemoriaTest` (unitário e funcional), `PreferenciaDoUsuarioRepositoryMemoriaTest`, `MemoriaDaCentralNoLayoutTest`, `QueriesDoLayoutGeralTest`.

## Para o cliente
- As páginas do sistema ficaram um pouco mais leves: o cabeçalho, o sininho e o menu deixaram de buscar a mesma informação várias vezes ao abrir cada tela.
- Pastas trancadas pelo cadeado, preferências do Meu Perfil e avisos do sininho continuam valendo do mesmo jeito: cada pessoa segue vendo só o que já via.
