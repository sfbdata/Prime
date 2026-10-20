---
tipo: novidade
---
## Changelog
- Cadeado completo da pasta (spec docs/specs/pasta-cadeado.md): o Master e o primário escolhem, pelo ⋮ da pasta ("Acesso · Primário"), ocultar a pasta para toda a equipe ou dar Ocultar, Visualizar ou Editar por pessoa. Substitui a tranca binária da Lista (`pasta.trancada_em/por`), migrada para o cadeado "Ocultar para todos" e removida.
- Teto do cadeado no `PermissionChecker::canAccessResource` para `pasta` (depois dos bypasses e antes do `ResourceAccess`): um pedido de acesso genérico aprovado não fura o cadeado. Barreira de rota (`BarreiraDoCadeadoListener`) com o resolvedor de pasta compartilhado (`PastaDaRota`) e teste de arquitetura das rotas de pasta.
- Tela "Pasta trancada" para quem está oculto, faixa de somente visualização com "Pedir para editar" e pedido de acesso com estados (pendente, liberado para visualizar ou editar, recusado), janela de 24 horas e avisos no sino para os primários e para quem pediu.
- Fora da pasta: a Lista, a busca, o CSV, a tela do processo e a busca do agente de IA mostram da pasta oculta só o número e o cliente; o Push global e as Minhas Metas deixam de mostrar o que é dela; a auditoria do escritório esconde o conteúdo das linhas dela; a imagem embutida em peça dela é recusada; pasta com cadeado sai do sync do Drive.

## Para o cliente
- O usuário primário agora pode trancar uma pasta para toda a equipe ou escolher, pessoa por pessoa, quem não vê, quem só visualiza e quem edita. Quem encontra a pasta trancada pode pedir acesso ao primário pela própria tela.
