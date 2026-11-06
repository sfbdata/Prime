---
tipo: alteracao
---
## Changelog
- /dashboard: as opções dos filtros Responsável e Cargo usam a equipe que o `ObterDadosDashboardUseCase` já leu (colaboradores ativos, cargo e foto), que agora vem no `DashboardOutput` como arrays simples. Antes o controller repetia as 3 consultas. Quando o UseCase não leu (filtro que esvazia a tabela pula a foto), o controller consulta como antes.
- Medido: de 48 para 45 consultas (primário e Master), de 51 para 48 (colaborador).
- Teste: `DashboardEquipeNumaLeituraTest` (uma leitura de cada consulta de equipe, com e sem filtro; mesmas opções, foto, cargo e "Sem cargo"; nada de outro escritório).

## Para o cliente
- O painel (dashboard) abre um pouco mais rápido: a lista de pessoas e cargos dos filtros é lida uma vez só, em vez de duas. Os filtros mostram exatamente as mesmas opções de antes.
