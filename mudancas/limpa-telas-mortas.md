---
tipo: interno
---
## Changelog
- Apaga seis templates que nenhuma rota renderizava (invitation/index, pasta/index, pasta/_tabela, pasta/_card, registration/index e registration/confirm), as chaves de idioma que só eles usavam e as referências nos testes de arquitetura; o teste da Lista do Expediente passa a ler o template vivo (expediente/_lista).
