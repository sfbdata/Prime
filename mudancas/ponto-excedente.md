---
tipo: novidade
---
## Changelog
- Ponto: contador de horas excedentes na tela /ponto, pedido de 05/11.
  - `PontoController::index` passa `metaDiaMinutos`: `JornadaResolver::resolverMetaDia` (a mesma meta do alerta de saída, já sem o almoço), zerada em feriado como na folha.
  - `ponto/index.html.twig`: `#excedente-hoje` logo abaixo do relógio, verde `#1e7b45` (desenho do Expediente), com `data-meta-dia-minutos`. O JS da página mostra `+HH:MM` quando o trabalhado passa da meta em pelo menos 1 minuto; pausa no intervalo e fica parado depois da saída (mesma conta da folha: retorno sem repouso não conta em dobro). Abaixo da meta ou com meta 0, escondido.
  - Nada é gravado; banco de horas e folha seguem as regras de sempre.
  - Testes: `ExcedenteDoDiaNaTelaTest`.

## Para o cliente
- Na tela do ponto, logo abaixo do tempo trabalhado, aparece um número verde (por exemplo, "+00:37 a mais hoje") quando a pessoa passa da jornada do dia. Ele conta ao vivo.
- No intervalo o número para, junto com o tempo trabalhado. Depois de bater a saída, fica parado no total do dia.
- Em feriado e em dia fora da escala de trabalho o número não aparece. É só um aviso na tela: o banco de horas continua calculado como sempre.
