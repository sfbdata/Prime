---
tipo: correcao
---
## Changelog
- Push Processual: o teor em texto corrido (TJGO, TJDFT e outros) decodifica as entidades HTML antes de escapar, então "JUDICI&Aacute;RIO", "&nbsp;" e "-&gt;" aparecem como acento, espaço e seta. Vale também para as publicações já gravadas, porque a correção é na exibição. O texto continua escapado na tela (sem abrir XSS).

## Para o cliente
- No Push Processual, o texto das intimações e publicações volta a mostrar os acentos e símbolos certos. Antes, alguns tribunais (como o TJGO) apareciam com códigos estranhos no lugar das letras, por exemplo "JUDICI&Aacute;RIO" em vez de "JUDICIÁRIO". Vale também para as publicações que já estavam no sistema.
