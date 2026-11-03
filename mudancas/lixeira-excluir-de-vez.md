---
tipo: novidade
---
## Changelog
- Lixeira da aba Documentos: botão "Excluir de vez" por item e "Excluir de vez os selecionados" no rodapé, sempre com confirmação. Nova rota `POST /pasta/{id}/documentos/excluir-de-vez` (`pasta_documentos_excluir_de_vez`), com a mesma permissão, token e prova de posse do restaurar (pasta `edit`, CSRF `pex_lote_<id>`, só item na lixeira desta pasta e deste escritório). Apaga na hora a linha e, depois do COMMIT, o arquivo, pelo mesmo laço da purga de 30 dias (`PurgarLixeiraUseCase::purgarSelecao`, via `ExcluirDeVezDaLixeiraUseCase`): subpasta leva a subárvore, arquivo com outra referência fica, banco que recusa não tira nada do disco. Com o espelho R2 ligado, a cópia remota sai pelo decorador do armazenamento.

## Para o cliente
- Na lixeira dos documentos da pasta, agora dá para apagar de vez um item (ou vários selecionados) sem esperar os 30 dias. O sistema pede confirmação antes, e o que é excluído de vez não pode mais ser restaurado. Quem pode é quem já podia excluir e restaurar documentos da pasta.
