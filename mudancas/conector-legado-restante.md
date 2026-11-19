---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 14):
  - `buscar_pastas` ganha os relatórios do Expediente `pastas_paradas` e `pastas_sem_processo`. Em `pastas_paradas`, o `sem_contrato` (que é do Financeiro) vai nulo e fica fora do total para quem não vê o Financeiro da pasta.
  - `ler_pasta` ganha as partes `timeline` e `controladoria` (alertas e pontos do relatório inicial).
  - `financeiro_da_pasta` ganha `renomear_documento`, por proposta.
  - `ler_documentos_e_modelos` ganha `modelos_de_checklist`.
  - `documentos_e_pecas` ganha `excluir_de_vez` da lixeira, por proposta.
  - `processos_da_pasta` ganha `excluir_processo`, por proposta, com o recurso `processo` de excluir, como a tela.
- Saem de controller para UseCase, sem mudar as telas: `ListarAlertasDaPastaUseCase`, `LerPontosDoRelatorioInicialUseCase`, `RenomearDocumentoDoFinanceiroUseCase`, `ListarModelosDeChecklistUseCase` e `ExcluirProcessoUseCase`.
- Confirmação do conector: `ResultadoDaConfirmacao` ganha um passo que roda só depois do COMMIT (remoção de arquivo, INV-6), o que estava na memória é gravado antes do executor, e a proposta é relida depois dele. A purga da lixeira ganha o caminho que devolve os arquivos para remover depois do COMMIT.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT passam a ver os relatórios de pastas paradas e de pastas sem processo, a linha do tempo da pasta e os alertas da Controladoria, sempre com o que você já vê na tela. No relatório de pastas paradas, o Claude só fica sabendo se a pasta tem contrato quando você pode ver o Financeiro dela.
- Também passam a renomear documento do Financeiro, listar os modelos de checklist, excluir de vez o que está na lixeira dos documentos e excluir um processo.
- Excluir de vez e excluir processo não têm volta: o BlueJus mostra um resumo que avisa isso e só apaga depois da sua confirmação. Só quem já pode fazer isso na tela consegue pelo conector.
- Nas telas, a Controladoria, o resumo da pasta, o renomear do Financeiro, os modelos de checklist e o excluir processo seguem iguais.
