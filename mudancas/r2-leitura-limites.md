---
tipo: alteracao
---
## Changelog
- Leitura pelo R2 (§5.0 da spec): a espera pela trava do cache de leitura termina em 60 s, com 503, em vez de prender o worker; o download que deixaria o disco do cache com menos de 5 GiB livres é recusado (503); o cálculo de hash e de páginas dos documentos lê só o disco local, sem buscar no R2.

## Para o cliente
- A abertura de documentos antigos guardados na cópia na nuvem ficou mais segura quando várias pessoas abrem o mesmo arquivo ao mesmo tempo: o sistema não fica mais travado esperando.
