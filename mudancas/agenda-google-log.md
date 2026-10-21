---
tipo: correcao
---
## Changelog
- Google Agenda: a falha na volta da conexão (e o escritório trocado entre a ida e a volta) passa a ir ao log de erro de produção com o motivo; antes era um aviso que o log de produção descartava. A recusa do token traz o código do OAuth (ex.: `invalid_client`), sem a descrição livre do Google.
