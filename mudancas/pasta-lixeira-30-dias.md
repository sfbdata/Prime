---
tipo: alteracao
---
## Changelog
- Excluir pasta passa a mandá-la sempre para a lixeira (lápide restaurável), inclusive a última da sequência, que antes era apagada de verdade com os arquivos; o número da pasta excluída continua ocupado.
- Confirmação e aviso da exclusão com os textos do desenho 02 Expedientes, sem o prazo (decisão do dono em 23/10): "Essa ação vai para a lixeira e fica registrada no histórico." e "Pasta {n} enviada para a lixeira (restaurável a qualquer momento)".
- Sai o caminho de remoção definitiva do ExcluirPastaUseCase (ResultadoExclusaoPasta, NumeracaoDePasta::existeNumeroMaiorQue, PastaDocumentoRepository::listarParaRemocaoDaPasta). Sem purga (decisão do dono em 23/10): a pasta fica na lixeira até ser restaurada e o espaço dos arquivos dela continua contando no armazenamento.

## Para o cliente
- Ao excluir uma pasta, ela vai para a lixeira e pode ser restaurada pelo botão Restaurar na própria pasta.
