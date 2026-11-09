---
tipo: interno
---
## Changelog
- Teste de arquitetura `ColunasDeChaveNoEspelhoArquiteturaTest`: toda coluna que guarda chave de arquivo (a que uma fábrica `ChavesDe*`/`ChavesDo*` lê da entidade, ou com sufixo `_chave`) tem de estar numa fonte de `FontesDeReferencia`, a reconciliação do espelho remoto (R2). As exceções ficam numa lista curta, cada uma com o motivo.
- O teste achou `assinatura_eletronica_signatario.rubrica_chave` fora da reconciliação (coluna no ar desde a v2.43.0, ainda sempre vazia em produção). Entra a fonte `assinatura_signatario_rubrica`, o mesmo commit da branch `assinatura-desenho`, para o merge sair limpo.

## Para o cliente
- Mais uma conferência automática garante que toda cópia de arquivo guardada na nuvem seja verificada.
