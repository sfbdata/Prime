---
tipo: novidade
---
## Changelog
- Página da meta, Fase 2b do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, seção 3.2 L9 e L10 e C3/C4):
  - **@menção no despacho:** mesmo mecanismo da pasta, com aviso na Central para quem é marcado e "quem → para" na linha do tempo. O placeholder volta a ser "Escreva o despacho. Digite @ para marcar alguém da equipe.".
  - **Anexo do despacho:** vira documento da pasta, pela regra de chaves, com a origem "Despacho · Meta #N", e aparece na linha do tempo como "nome · juntado à pasta". Os anexos antigos continuam como estavam.
  - **Extrato de tramitação:** o botão "Extrato" abre a tramitação para imprimir ou salvar em PDF pelo navegador, com a DEMANDA e cada despacho com natureza, quem → para, Cumprir até, Ciente e a certidão.
  - **Lembrar:** o botão na faixa "Aguardando" avisa na Central quem está com a meta (o autor, quando a meta está em revisão), no máximo 1 vez por hora por pessoa, e grava "Lembrete enviado para ..." no histórico. Tipo de aviso novo `tarefa_lembrete`.
- Sem migration.

## Para o cliente
- No despacho da meta dá para marcar colegas com @, que são avisados. O arquivo anexado vai direto para os documentos da pasta. O botão "Extrato" imprime toda a tramitação da meta, e o "Lembrar" avisa quem está com a meta parada.
