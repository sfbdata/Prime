---
tipo: alteracao
---
## Changelog
- /expediente: os submarcadores do trilho vêm na consulta dos marcadores (`MarcadorRepository::findRaizPorTenant` com join em `filhos`). Antes, uma consulta por marcador de topo. A tela Demandas usa o mesmo método e ganha o mesmo.
- `ia_disponibilidade()` guarda a resposta durante a requisição (por usuário e escritório, `reset()` no kernel.reset). A página chamava duas vezes, e cada chamada eram até 5 consultas.
- Preferências do usuário: a primeira leitura da requisição traz todas as linhas da pessoa naquele escritório (no máximo 3 em produção). O layout pedia chaves diferentes em consultas separadas.
- Aviso da cota de e-mail (Master): o total de enviados nas últimas 24 horas é contado uma vez só.
- Medido no dev (Master, escritório com 6 marcadores): de 53 para cerca de 41 consultas. No teste: primário 35, colaborador 31, e o total não cresce com o número de marcadores.
- Testes: `ExpedienteQueriesDaPaginaTest` (trava a contagem), `InteligenciaExtensionMemoriaTest`, novo caso em `CotaDeEnvioTest`.

## Para o cliente
- A tela Expedientes abre mais rápido: o sistema busca de uma vez os marcadores com os submarcadores e deixa de repetir algumas buscas da barra do topo. A tela mostra exatamente o mesmo de antes, e quem pode ver cada coisa continua igual.
