---
tipo: alteracao
---
## Changelog
- Ponto: a jornada pessoal de cada colaborador passa a ter versões, cada uma com a data em que começa a valer (`jornada_colaborador.vale_a_partir_de`, migration `Version20261107100000`). O índice único passa a ser pessoa, escritório e data.
- A folha (tela, PDF e XLSX), o saldo do mês e o banco de horas do ano usam, em cada dia, a versão que valia nele (`HistoricoDeJornadas`, `JornadaColaboradorRepository::historicoDe`). O cabeçalho do PDF e do XLSX mostra a versão do último dia do mês. A tela do ponto, o alerta e o choque entre escritórios usam a versão de hoje.
- A migration transforma cada jornada existente em uma versão só, com a data do primeiro registro de ponto da pessoa no escritório. A versão mais antiga vale também para os dias anteriores à sua data, por isso nenhum saldo muda (teste `MigracaoDaJornadaComVigenciaTest` compara a folha e o saldo do ano antes e depois).
- Ainda sem tela para criar versões: o campo "Vale a partir de" e o histórico chegam na próxima etapa.
- Spec: `docs/specs/ponto-jornada-vigencia.md`.

## Para o cliente
- O BlueJus passa a guardar a data em que cada jornada de trabalho começa a valer. É o primeiro passo para que uma troca de jornada (por exemplo, de 40 para 44 horas por semana) valha só dali em diante, sem recalcular a folha e o banco de horas dos meses anteriores.
- Nesta versão todas as folhas e todos os saldos, de qualquer mês, ficam exatamente com os mesmos números. O campo para escolher a data de início da nova jornada chega em seguida, na tela de jornada do colaborador.
