---
tipo: correcao
---
## Changelog
- Cadeado da pasta e permissão de ver a pasta: sobras do lote B da auditoria de segurança.
  - Envio automático do relatório de pastas paradas: o relatório é montado uma vez e filtrado por destinatário. A Controladoria e o responsável não recebem a pasta oculta pelo cadeado para eles (`AcessoAPasta::idsOcultosPara`) nem a que não veem pela visibilidade do relatório: nem o número, nem o responsável, nem a contagem. Primário e Master recebem tudo; quem fica sem pasta não é avisado.
  - Envio do relatório de pastas sem processo (2ª rodada, 1.20), automático e manual: o resumo da Controladoria é refeito por destinatário sem as pastas ocultas para ela (`ResumoSemProcesso::deAnalises`), e o responsável oculto não recebe o número da pasta trancada. Primário e Master recebem o total; o histórico mensal continua com o resumo do escritório inteiro.
  - Minhas Metas (3.12 nas listas): a meta só acompanhada de pasta que a pessoa não pode ver sai das abas Acompanhando e Todas, das contagens das abas (também as do clique em "acompanhar"), do trilho e das atrasadas lidas pelo chat da I.A. Responsável e criador continuam vendo. Em lote: `PastasSoAcompanhadasSemVer` + `PermissionChecker::idsPermitidosComVinculo`. Regra em `docs/AUTORIZACAO.md`.

## Para o cliente
- O aviso mensal das pastas paradas não mostra mais a quem está fora do cadeado de uma pasta o número, o cliente, o responsável nem a contagem dessa pasta.
- O aviso do relatório de pastas sem processo também não conta nem mostra o número de uma pasta para quem está fora do cadeado dela.
- Em Minhas Metas, quem só acompanha uma meta de uma pasta que não pode abrir deixa de ver essa meta e de contá-la. Quem é responsável pela meta ou a criou continua vendo normalmente.
