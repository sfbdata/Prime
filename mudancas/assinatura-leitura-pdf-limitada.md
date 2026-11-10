---
tipo: seguranca
---
## Changelog
- Limita cada leitura da saída do Ghostscript a 8 KiB antes de conferir o teto total. Um produtor rápido não acumula mais saída no buffer interno do Symfony Process antes da aplicação do limite.
- Preserva o temporário privado, o descarte da saída de erro, o código de saída e a interrupção com recolhimento do processo no teto ou no tempo. Acrescenta regressão com 50 execuções de saída sem fim em processo PHP limitado a 32 MiB.

## Para o cliente
- A conferência de PDFs recebeu uma proteção para interromper arquivos que geram informação demais, evitando consumo excessivo de memória durante a leitura.
