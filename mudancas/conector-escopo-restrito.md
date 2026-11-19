---
tipo: correcao
---
## Changelog
- Conector de I.A. no acesso restrito (spec acesso-restrito-e-compartilhar, fatia 3): conferidas as ferramentas novas (`ler_clientes`, `atualizar_pasta`, `mudar_andamento`, `prazos_e_metas_da_pasta`, `registrar_na_pasta`, `documentos_e_pecas`, `financeiro_da_pasta`, `ler_pasta` com os ids, `confirmar_acao`, `processos_da_pasta`, `gravar_na_agenda` e `ler_processos` `cnj`), com o teste `ConectorNoAcessoRestritoEscritaTest`.
- `atualizar_pasta` `responsavel`: quem tem o acesso restrito não troca o responsável, com a frase da tela (L7), antes de procurar o colega pedido.
- `ler_processos` `cnj`: processo fora do alcance da pessoa (cadeado ou fora do escopo do restrito) é tratado como inexistente também no segredo; a resposta não diz que ele existe.

## Para o cliente
- Para quem tem o acesso restrito às pastas sob responsabilidade, o app de I.A. (Claude ou ChatGPT) segue as mesmas regras da tela: só alcança as pastas da pessoa e os clientes, documentos e processos delas.
- Pelo app de I.A., quem tem o acesso restrito também não troca o responsável da pasta, como já acontece na tela.
- A consulta ao CNJ pelo app de I.A. não revela processo de pasta que a pessoa não pode ver.
