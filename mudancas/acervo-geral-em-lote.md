---
tipo: alteracao
---
## Changelog
- Acervo geral do expediente (e o painel de um marcador, que usa a mesma Lista): clientes, marcadores, metas e vínculos de processo de todas as pastas da página saem em uma consulta por coleção (`PastaRepository::carregarColecoesDaLista`), e o nome de cada pasta é resolvido de uma vez (`ResolvedorIdentificadorDaPasta::primeParaPastas`). Antes, cada linha consultava sozinha.
- Medido em teste com 10 pastas: de 84 para 49 consultas (primário), de 94 para 59 (colaborador), de 76 para 41 (Master). Com 1 pasta: de 39 para 40.
- Continua uma consulta por pasta com cliente cadastrado: o cliente principal (Cliente tem herança PF/PJ e o Doctrine não agrupa essa carga). Fica para outra entrega.
- Teste: `AcervoGeralEmLoteTest` (número de consultas com 1 e com 8 pastas cheias; conteúdo da Lista igual ao de antes, com cadeado, meta concluída e excluída e pasta de outro escritório).

## Para o cliente
- O acervo geral do expediente abre mais rápido: o sistema busca de uma vez os clientes, os marcadores e as metas de todas as pastas da página, em vez de buscar pasta por pasta. A lista mostra exatamente o mesmo de antes, e quem pode ver cada pasta continua igual.
