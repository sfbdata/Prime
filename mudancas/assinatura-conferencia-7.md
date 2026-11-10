---
tipo: novidade
---
## Changelog
- Assinatura de documentos, conferência de 10/11/2026 (E3-11 da etapa 3): a falha definitiva do worker do PDF final desfaz o vínculo pendente. O `FalhaDoPdfFinalListener` ouve o `WorkerMessageFailedEvent` do `GerarPdfFinalDoDocumento` sem nova tentativa (`willRetry()` falso, a falha que lança ao gerar ou gravar; a do selo que tenta de novo volta à fila sem lançar) e chama `VinculoComAPasta::pdfFinalFalhou()`: "vínculo não aplicado" com o motivo do PDF indisponível, em nome de quem vinculou, e o vínculo pendente desfeito (o "Vincular à pasta" volta). Se outro processo guardou o PDF final no meio, o vínculo vai para a pasta como sempre. Nenhuma falha do ouvinte sobe: o erro vai para o log e a mensagem segue para a fila de falhas.
- `DocumentoParaAssinarRepository::reabrirSeFechado()`: o EntityManager fechado por uma exceção dentro da transação é reaberto antes de o ouvinte ler o documento.
- Testes: o ouvinte com a oficina do vínculo (falha definitiva, nova tentativa, PDF guardado no meio, nada a fazer, erro no ouvinte) e o reabrir no repositório com o banco.

## Para o cliente
- Na assinatura de documentos, quando o PDF final não pode ser gerado, o documento deixa de esperar a pasta para sempre: o histórico mostra que o vínculo não foi aplicado e o botão "Vincular à pasta" volta para tentar de novo.
