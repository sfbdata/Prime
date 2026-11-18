---
tipo: correcao
---
## Changelog
- Conector de I.A.: o mesmo pedido de autorização enviado de novo logo depois de autorizado (segundo clique enquanto a volta ao app carregava) mostra "Conexão feita" em vez de "Este pedido de conexão não vale mais", sem conceder nada de novo; depois de negado, o reenvio continua recusado.
- Conector de I.A.: a tela de autorização envia o formulário uma vez só.
- Conector de I.A.: autorizar de novo o mesmo aplicativo no mesmo escritório derruba as autorizações anteriores dele (motivo `substituida`), com os tokens e as renovações delas; valem só as permissões escolhidas agora.
- Conector de I.A.: o log `mcp` registra também o método JSON-RPC e a versão do protocolo de cada requisição, conferidos pelo formato, para diagnosticar o app conectado.

## Para o cliente
- Ao conectar o Claude ou outro aplicativo de I.A. ao BlueJus, clicar duas vezes em Autorizar não mostra mais a mensagem de pedido vencido: aparece "Conexão feita" e basta voltar ao aplicativo.
- Ao conectar de novo o mesmo aplicativo de I.A., só vale o que você autorizou agora: se antes ele podia alterar dados e agora só pode ler, a permissão antiga deixa de valer na hora.
