---
tipo: alteracao
---
## Changelog
- Desempenho: a tela do processo (`GET /processos/{id}`) deixa de consultar o banco uma vez por meta da aba Tarefas (PR 4d da revisão de queries por página).
  - `TarefaRepository::carregarResponsaveisDasMetasDoProcesso`: os responsáveis (com o perfil) de todas as metas da aba chegam numa consulta só, presa ao escritório. Os ids vêm do `findByProcesso` de sempre, com o filtro de escritório e sem as metas das pastas ocultas pelo cadeado. Responsáveis na ordem do id do usuário, a mesma da leitura de antes em produção.
  - Medição (cenário da revisão): primário 36 consultas com 1 meta e 45 com 10 passou para 36 e 36; colaborador 40 e 50 passou para 40 e 41 (a consulta a mais vem do sininho de avisos do topo, não da aba Tarefas).
  - Testes: `ProcessoShowEmLoteTest` (mesma contagem com 1 e 8 metas para primário e colaborador; mesmo conteúdo para primário, colaborador, colaborador fora do cadeado de uma das pastas, colaborador com todas as pastas ocultas e outro escritório).

## Para o cliente
- A tela do processo abre mais leve quando ele tem muitas metas: o sistema busca os responsáveis de todas as metas de uma vez, em vez de uma por uma.
- A aba Tarefas continua mostrando as mesmas metas e os mesmos responsáveis, e o cadeado das pastas continua valendo do mesmo jeito para cada pessoa.
