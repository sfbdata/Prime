---
tipo: correcao
---
## Changelog
- Sino e Ver todas (auditoria de segurança, item 1.17): a leitura refaz a pergunta da Timeline da pasta, e não só a do cadeado.
  - Novo `LeituraDasPastasDosAvisos` (Pasta): pega os ids das pastas nos links dos avisos da pessoa no escritório e decide, em lote, quais ela não pode mais ver (`PermissionChecker::idsPermitidosComVinculo`, com os cadeados carregados antes) e quais têm a aba Financeiro oculta para ela (`VisibilidadeDasAbasDaPasta::abasOcultasPorPasta`). Primário e Master: nada. Resposta guardada até o fim da requisição (`ResetInterface`).
  - `NotificacaoRepository`: `findNaoLidasByUsuario`, `countNaoLidasByUsuario`, `findPaginadasByUsuario` e `countByUsuario` recebem `cortesDaLeitura`. Pasta sem leitura tira o aviso com link `/pasta/{id}` (barra, âncora ou query), menos a resposta ao pedido de acesso. Financeiro oculto tira o aviso com âncora `#financeiro`. O corte de leitura é pelo link, não pela meta: o responsável continua vendo a meta sem ver a pasta.
  - `NotificacaoService::cortesDaLeituraDe` alimenta o sino, o selo e o `NotificacaoController::index`.
  - Testes: `SinoReaplicaALeituraDaPastaTest`.

## Para o cliente
- Quem perde o acesso a uma pasta deixa de ver, no sino e em "Ver todas", os avisos antigos que mostravam trechos daquela pasta.
- Quando a aba Financeiro de uma pasta fica oculta para alguém, os avisos antigos de observações financeiras dessa pasta somem do sino e da lista dessa pessoa.
- O número do sino passa a contar só os avisos que a pessoa consegue ver na lista.
