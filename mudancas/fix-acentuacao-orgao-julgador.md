---
tipo: correcao
---
## Changelog
- Processo: o nome do órgão julgador vindo do CNJ (processo e movimentações) não tem mais a acentuação estragada ("1ª Vara Cível" virava "1Âª VARA CÃ­VEL"). O `DatajudProcessoMapper` só converte o texto quando ele não é UTF-8 válido.
- Comando `app:processo:corrigir-acentuacao-do-orgao` para reverter os nomes já gravados (simula por padrão; grava com `--aplicar`).

## Para o cliente
- Os nomes das varas e dos órgãos julgadores voltam a aparecer com os acentos certos.
