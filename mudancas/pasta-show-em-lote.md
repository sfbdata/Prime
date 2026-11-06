---
tipo: alteracao
---
## Changelog
- Desempenho: a tela da pasta (`GET /pasta/{id}`) deixa de consultar o banco uma vez por meta e de repetir leituras na mesma página (PR 4b da revisão de queries por página).
  - `TarefaRepository::carregarResponsaveisEDespachosDaPasta`: os responsáveis (com o perfil), o criador e as respostas de todas as metas da pasta chegam em duas consultas, presas ao escritório. O relatório da meta, os "Próximos prazos" e o "Excluir" da meta (`ExcluirTarefaUseCase::podeExcluir`, mesma regra) leem as coleções já carregadas.
  - `VisibilidadeDasAbasDaPasta`: as regras de aba oculta de cada pasta ficam guardadas por escritório e pasta durante a requisição; ouvinte de entidade de `AbaOcultaDaPasta` esquece ao gravar ou apagar; zera entre requisições (`ResetInterface`).
  - `AndamentoDaPasta::publicacoesRecentes`: o estado do andamento e as publicações recentes do Push são lidos uma vez e servem às sugestões de fase e à faixa histórico (`HistoricoDaTrilhaDaPasta::montar` aceita a lista já lida).
  - Cadeado: o `PastaController::show` monta o cadeado uma vez e o entrega ao template como `cad`; a equipe do cadeado não é mais lida duas vezes.
  - Medição (cenário da revisão): primário 97 para 91 consultas com 1 meta e 115 para 91 com 10 metas; colaborador 102 para 98 e 112 para 99.
  - Testes: `PastaShowEmLoteTest` (mesma contagem com 1 e 8 metas; mesmo conteúdo para primário, colaborador, colaborador em Visualizar e fora do cadeado).

## Para o cliente
- A tela da pasta abre mais leve, principalmente em pastas com muitas metas: o sistema busca os responsáveis e as respostas de todas as metas de uma vez, em vez de uma por uma.
- A tela continua mostrando as mesmas abas, metas, responsáveis, botão de excluir, linha do tempo e histórico, e o cadeado continua valendo do mesmo jeito para cada pessoa.
