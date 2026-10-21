---
tipo: novidade
---
## Changelog
- Painel do Master: nova seção "Indicações do BlueJus" (desenho 05 de 21/10). O cabeçalho mostra quantos convites foram enviados, quantos nas últimas 24 horas e quantos viraram conta; a chave pausa ou libera os convites de todos; os campos "Limite por dia (por pessoa)", "Limite por mês" e "Avisar no chat a partir de (24h)" gravam os limites (inteiros de pelo menos 1). Rotas `GET /master/indicacoes`, `POST /master/indicacoes/pausa` e `POST /master/indicacoes/limites`, só do Master, com token CSRF.
