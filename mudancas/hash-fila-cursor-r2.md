---
tipo: correcao
---
## Changelog
- `app:documentos:calcular-hash`: a fila passa a ter cursor (`--a-partir-de=N`, só ids maiores que N), nos modos hash e `--paginas`. O resumo traz `ultimo_id`, `a_frente` e `do_espelho` no fim da linha e sugere a próxima fatia. Antes, documento que ficava NULL prendia o começo da fila e toda fatia com `--limite` repetia os mesmos ids.
- `app:documentos:calcular-hash --paginas` lê o PDF liberado do disco pelo `ArmazenamentoEspelhado` (cache de leitura com teto, piso de 5 GiB e trava por SHA). Exceção da spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.0 só para este comando; o modo hash continua lendo só o disco.
- No modo hash, o documento fora do disco recebe o SHA da prova do espelho (envio `verificado` da outbox ou liberação aberta), sem baixar do R2.

## Para o cliente
- Os documentos antigos das pastas, que hoje ficam guardados na nuvem, passam a ter a contagem de páginas e a verificação de integridade preenchidas, como os documentos novos.
