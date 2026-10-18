---
tipo: interno
---
## Changelog
- Teste de arquitetura `MetodoLongoArquiteturaTest`: método em `app/src` tem no máximo 40 linhas; os 466
  métodos legados acima disso ficam em `metodos-longos-tolerados.json` e podem crescer no máximo 5 linhas.
- Skills `criar-*` com regras de tamanho e organização de método (meta de ~20 linhas, um nível de
  abstração por método) e repository deixa de ser `final` na regra da casa (é mockado nos testes de UseCase).
