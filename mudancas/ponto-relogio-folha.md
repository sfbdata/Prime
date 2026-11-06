---
tipo: correcao
---
## Changelog
- Ponto: o relógio "Tempo trabalhado" da tela /ponto passa a fazer a mesma conta da folha (`CalculadoraJornada::calcularMinutosDaEscolha`), achado da revisão do #666.
  - `ponto/index.html.twig`: `calcSegundosTrabalhados` conta os dois trechos só com intervalo válido (retorno depois do repouso); sem ele, o span da entrada até a saída (ou agora). Ao vivo, em repouso sem retorno, para no repouso.
  - Antes: retorno sem repouso somava o trecho do retorno por cima do span (entrada 08:00, retorno 13:00, saída 17:00 dava 13 h na tela e 9 h na folha); repouso sem retorno com saída parava no repouso (4 h na tela, 9 h na folha).
  - Par invertido com a saída batida (saída antes da entrada, repouso antes da entrada, saída antes do retorno) conta 0, como `CalculadoraJornada::parInvertido` (achado da revisão).
  - Limite: a folha corta cada trecho no minuto e o relógio soma os segundos; a tela pode ficar até 1 minuto por trecho acima da folha.
  - Uma conta só para o relógio e o contador de horas excedentes (sai a `segundosComoNaFolha`).
  - Testes: `RelogioContaComoAFolhaTest`; `ExcedenteDoDiaNaTelaTest` ajustado.

## Para o cliente
- O relógio de tempo trabalhado da tela do ponto agora mostra o mesmo total que a folha de ponto. Antes, quando a pessoa batia a volta do intervalo sem ter batido a ida, o relógio contava parte da tarde duas vezes e mostrava mais horas do que a folha registrava.
- Também quando a ida ao intervalo foi batida e a volta não, depois da saída o relógio passa a mostrar o dia inteiro, como a folha.
