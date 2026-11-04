---
tipo: novidade
---
## Changelog
- Importar pastas em lote: botão "Sugerir agrupamento com IA" (rota `expediente_importar_lote_sugerir`, `SugerirAgrupamentoDoLoteUseCase`). A IA (Haiku 5.5 fixo) lê só a árvore de nomes, mascarada e neutralizada, até 2.000 itens, e escolhe pastas do computador que viram um caso cada; o servidor recusa arquivo, pasta dentro de outra escolhida e cliente inválido.
- Novo tipo de análise `agrupamento_lote` (sem migration): conta na cota do escritório, guarda só o hash e as contagens, nunca os nomes nem a resposta.
- A IA nunca grava: as pastas continuam criadas pelo Importar, pela rota de sempre.

## Para o cliente
- No Importar pastas em lote, quem tem a BlueJus IA ligada ganha o botão "Sugerir agrupamento com IA": a IA olha só os nomes das pastas e dos arquivos e sugere qual pasta do computador vira cada caso, com o nome do cliente. Você confere, ajusta os nomes e só então clica em Importar.
- A IA nunca junta casos diferentes, mesmo do mesmo cliente; no máximo avisa "mesmo cliente que" outra pasta. O conteúdo dos arquivos não é lido e cada clique conta como uma análise na cota do escritório.
