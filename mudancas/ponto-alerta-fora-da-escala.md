---
tipo: correcao
---
## Changelog
- Ponto, aviso de horário (`VerificadorAlertaPonto`, /ponto/alerta-horario): o dia que a escala não cobre (folga, domingo; meta do dia 0, como na folha e na tela) passa a seguir o mesmo critério do feriado da pessoa. Saem os avisos que vêm da escala ("Você ainda não registrou sua entrada hoje!", o repouso no horário da escala e o "Jornada concluída. Hora de registrar sua saída!"); ficam os das batidas de quem trabalha mesmo assim (6 horas sem repouso e intervalo mínimo concluído). Antes, o aviso de entrada aparecia em qualquer dia sem entrada.

## Para o cliente
- Na folga e no domingo (os dias fora da escala de trabalho da pessoa), o Ponto deixa de avisar que falta registrar a entrada.
- Quem trabalha num dia fora da escala continua recebendo os avisos de pausa: 6 horas sem repouso e fim do intervalo mínimo.
