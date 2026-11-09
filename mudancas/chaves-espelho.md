---
tipo: interno
---
## Changelog
- Teste de arquitetura `ColunasDeChaveNoEspelhoArquiteturaTest`: toda coluna que guarda chave de arquivo (a que uma fábrica `ChavesDe*`/`ChavesDo*` lê da entidade, ou com sufixo `_chave`) tem de estar numa fonte de `FontesDeReferencia`, a reconciliação do espelho remoto (R2). As exceções ficam numa lista curta, cada uma com o motivo.

## Para o cliente
- Mais uma conferência automática garante que toda cópia de arquivo guardada na nuvem seja verificada.
