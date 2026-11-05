---
tipo: correcao
---
## Changelog
- Monitor de desempenho: o limite de 60 eventos por minuto (`POST /monitor/evento`) lê a hora pelo relógio do Symfony (`ClockInterface`) em vez de `gmdate`. O teste `MonitorEventoControllerTest::testLimite` para o relógio no minuto corrente (`MockClock`), sem falhar quando o minuto vira no meio das 61 requisições, e confere que a janela recomeça no minuto seguinte.

## Para o cliente
- O aviso de desempenho que as telas mandam ao servidor segue limitado a 60 por minuto por pessoa; a conferência automática desse limite ficou estável.
