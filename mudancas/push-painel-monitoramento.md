---
tipo: novidade
---
## Changelog
- Aba Push da pasta em duas colunas (desenho 1.2.3): à direita, o cartão de Monitoramento com o switch do processo principal (POST /pasta/{id}/push/monitoramento, exige editar a pasta), a linha "nº · tribunal" e a última verificação no DJEN.
- "Buscar agora" (POST /pasta/{id}/push/buscar, exige ver a pasta): consulta o DJEN pelo número de cada processo da pasta com monitoramento ativo, nos últimos 30 dias, com a mesma deduplicação e reconciliação da captação; uma busca por pasta a cada minuto (429) e parada no limite do DJEN.
- A captação do DJEN pula os processos com monitoramento pausado e grava a última verificação de cada número consultado. Migration: colunas monitoramento_pausado_em, monitoramento_pausado_por_id e ultima_verificacao_em em processo; tabela previsao_processual.
- "Situação atual": previsão por regras (porte das REGRAS_PROC do desenho) com fase, próximo ato, prazo em dias úteis nacionais (feriados do calendário do sistema e recesso de 20/12 a 20/01), data provável, confiança e a taxa de acertos das previsões anteriores, guardada no servidor.

## Para o cliente
- Na aba Push da pasta, um painel novo mostra se o processo está sendo monitorado, quando foi a última consulta ao diário e em que pé ele está, com o próximo ato e a data provável. Dá para pausar o monitoramento e buscar as publicações na hora.
