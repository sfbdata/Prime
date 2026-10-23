---
tipo: alteracao
---
## Changelog
- Excluir pasta passa a mandá-la sempre para a lixeira (lápide restaurável), inclusive a última da sequência, que antes era apagada de verdade com os arquivos; o número da pasta excluída continua ocupado.
- Confirmação e aviso da exclusão com os textos do desenho 02 Expedientes: "Essa ação vai para a lixeira por 30 dias e fica registrada no histórico." e "Pasta {n} enviada para a lixeira (restaurável por 30 dias)".
- Sai o caminho de remoção definitiva do ExcluirPastaUseCase (ResultadoExclusaoPasta, NumeracaoDePasta::existeNumeroMaiorQue, PastaDocumentoRepository::listarParaRemocaoDaPasta). A purga automática depois de 30 dias não foi implementada (decisão do dono): até lá a pasta fica na lixeira sem prazo e o espaço dos arquivos dela continua contando no armazenamento.

## Para o cliente
- Ao excluir uma pasta, ela vai para a lixeira e pode ser restaurada pelo botão Restaurar na própria pasta.
