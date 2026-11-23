---
tipo: novidade
---
## Changelog
- Cadastro pelo Voto: `GET /cadastro?produto=voto` guarda o produto na sessão por lista fechada (só `voto`; outro valor é ignorado e não apaga o guardado). Os três caminhos de criação (e-mail, Google e Facebook) leem o produto só da sessão, nunca do JSON, e a chave sai da sessão depois de criar.
- Com o Voto, na mesma transação do `CriarContaEEscritorioUseCase`: o escritório nasce sem os marcadores padrão do Expediente (`TenantBootstrapService::bootstrap(..., semearMarcadores: false)`), com a contratação `voto` (origem `cadastro`) e a `LiberacaoDoVoto` liberada; a I.A. não é ligada. Sem produto, o cadastro é o de sempre, sem linha em `contratacao`.
- `PaginaInicialDoEscritorio`: a primeira tela depois do login, do `/inicio` e da escolha do escritório segue os produtos do escritório (Expediente; depois Voto, Cobranças, Ponto, Cálculo, Kanban e Assinatura; sem nenhum, Meu Perfil). O escritório completo continua no `expediente_index`. `voto_index` entra nos destinos aceitos depois do login.
- Resumo diário por e-mail: escritório sem o Expediente fica de fora (`semEnvio`).

## Para o cliente
- Quem cria a conta pelo site do BlueJus Voto ganha um escritório pronto para assembleias e votações: o Voto já vem liberado e, ao entrar, a pessoa cai direto na tela do Voto.
- Os escritórios que já usam o BlueJus continuam abrindo no Expediente, com todas as ferramentas de sempre.
