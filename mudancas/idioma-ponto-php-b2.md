---
tipo: alteracao
---
## Changelog
- Ponto: os textos da batida que vêm dos Services (cerca, fila sem internet, trabalho externo, chave do ponto, choque de jornada entre escritórios, entrada e turno abertos, alertas antes da batida, horário futuro e batida já registrada) passam pelo tradutor com a chave no Service (`TextoDoPonto`). As recusas do motivo do trabalho externo levam um código (`recusaDoMotivo`) que o JS compara no lugar do texto. O português fica idêntico, cada texto é conferido contra a constante.

## Para o cliente
- No Ponto, os avisos ao bater o ponto (fora do raio da sede, GPS impreciso, ponto sem internet, trabalho externo, intervalo e interjornada, horário futuro, registro repetido) passam a aparecer em inglês para quem usa o sistema em inglês, com a distância no formato do idioma. Em português, os textos seguem os mesmos.
