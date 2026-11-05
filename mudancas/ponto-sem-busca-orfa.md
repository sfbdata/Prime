---
tipo: correcao
---
## Changelog
- Ponto: remove `JustificativaPontoRepository::findOneByUserAndData`, consulta sem chamador em `src` e `tests` que buscava justificativa só por pessoa e data, sem filtrar o escritório (sobra do lote J da auditoria, item 1.13). Sem migration.

## Para o cliente
- Saiu do sistema uma busca antiga de justificativas de ponto que não separava os escritórios. Ela não era usada por nenhuma tela, e assim não pode ser usada por engano no futuro.
