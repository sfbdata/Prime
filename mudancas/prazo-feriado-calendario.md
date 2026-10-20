---
tipo: alteracao
---
## Changelog
- Pasta: os prazos estimados lidos dos registros ("prazo de N dias") passam a usar o calendário do sistema (`CalendarioDoSistema` da Agenda) e a UF do escritório (`Tenant::estado`), pelo serviço `DiasUteisDoEscritorio`. Não é dia útil: fim de semana, feriado nacional (inclui Sexta-feira Santa, que faltava), feriado estadual da UF e o recesso. Ponto facultativo (Carnaval, Corpus Christi), dias de tribunal, eleições e comemorativas continuam úteis.
- Prazo em dias corridos que termina em dia não útil passa para o próximo dia útil (CPC art. 224, §1º). Data escrita pelo advogado, audiência, "amanhã" e "hoje" não mudam.
- A regra nova só vale para registros criados a partir de `PRAZOS_CALENDARIO_DESDE` (variável de ambiente, data `AAAA-MM-DD`). Vazia ou inválida, nada muda: o cálculo é o de antes, inclusive na releitura automática dos prazos já gravados. Parâmetro `prazos_calendario_desde` em `config/services.yaml`; linha comentada no `.env`. Sem migration.
- Só passa a valer quando `PRAZOS_CALENDARIO_DESDE` for definida no deploy (o dia seguinte ao deploy); valor inválido desliga e avisa no log.
