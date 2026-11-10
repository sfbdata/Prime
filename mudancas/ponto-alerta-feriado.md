---
tipo: correcao
---
## Changelog
- Ponto, aviso de horário (`VerificadorAlertaPonto`, /ponto/alerta-horario): o aviso não olhava feriado nenhum, nem o de todas as sedes. Agora lê os feriados do escritório e aplica o `FeriadosDaPessoa` (o de todas as sedes ou o da lotação da pessoa no dia, a mesma regra da folha). No feriado da pessoa saem os avisos que vêm da escala: "Você ainda não registrou sua entrada hoje!", o repouso no horário da escala e o "Jornada concluída. Hora de registrar sua saída!". Ficam os que vêm das batidas de quem trabalha no feriado: 6 horas sem repouso e intervalo mínimo concluído.

## Para o cliente
- No feriado, o Ponto deixa de avisar que falta registrar a entrada e de avisar a hora da saída. Vale para o feriado de todas as sedes e para o feriado da lotação de quem está usando; o feriado de outra lotação não cala o aviso.
- Quem trabalha no feriado continua recebendo os avisos de pausa: 6 horas sem repouso e fim do intervalo mínimo.
