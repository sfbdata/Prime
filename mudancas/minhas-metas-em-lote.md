---
tipo: alteracao
---
## Changelog
- Desempenho: Minhas Metas (`GET /tarefas/minhas`) deixa de consultar o banco uma vez por meta e de repetir a leitura das caixas da esteira (PR 4c da revisão de queries por página).
  - `TarefaRepository::carregarCartoesDasMetas`: a pasta (com o cliente principal), o criador, os responsáveis e os clientes da pasta de todas as metas da lista chegam em duas consultas, presas ao escritório. O "Concluir etapa" do cartão (`QuemCuidaDaMeta`) lê os responsáveis já carregados; responsáveis e clientes saem na ordem do id, a mesma da leitura de antes em produção.
  - `ListarMinhasMetasUseCase`: chama a leitura em lote e o `ResolvedorIdentificadorDaPasta::primeParaPastas` (identificador e cliente de todas as pastas da lista numa consulta). A lista continua vindo filtrada como antes (aba ou esteira, cadeado, pastas só acompanhadas sem ver, pastas visíveis na esteira).
  - `CaixaDaEsteiraRepository::configuradasDoEscritorio` roda uma vez por tela: o `TarefaController::minhas` lê as caixas e as entrega à lista (nome da etapa no cartão) e à esteira.
  - Medição (cenário da revisão): primário 50 consultas com 1 meta e 71 com 10 passou para 51 e 51; colaborador 55 e 91 passou para 54 e 54.
  - Testes: `MinhasMetasEmLoteTest` (mesma contagem com 1 e 8 metas nas abas Sou responsável, Acompanhando, Todas e na lista da esteira; mesmo conteúdo para primário, colaborador, quem só acompanha meta de pasta que não vê, quem está fora do cadeado e a esteira vista por quem não vê a pasta) e casos novos nos testes unitários da lista e da esteira.

## Para o cliente
- A tela Minhas Metas abre mais leve, principalmente para quem tem muitas metas: o sistema busca a pasta, o cliente e os responsáveis de todas as metas de uma vez, em vez de uma por uma.
- A tela continua mostrando as mesmas metas, abas, contagens, indicadores do topo, "Seu andamento", "Precisa de atenção" e esteira, e o cadeado das pastas continua valendo do mesmo jeito para cada pessoa.
