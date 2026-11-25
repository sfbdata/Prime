---
tipo: alteracao
---
## Changelog
- Janela "Indicar o BlueJus": saiu a lista de convites (canal, data e selo). Fica o título "SUAS INDICAÇÕES (N)" e, logo abaixo, "X viraram conta" (ou "Nenhuma virou conta ainda"). O GET /indicacao deixa de devolver `ultimas` e passa a devolver `contasCriadas` (`IndicacaoRepository::contarContasCriadasDoUsuario`).
- Painel do Master, seção "Indicações do BlueJus": nova tabela "Quem indica" (pessoa, escritório, enviados, viraram conta, último envio), com mais envios primeiro, busca por nome ou escritório no servidor (mínimo 2 letras) e paginação. Rota GET /master/indicacoes/quem-indica, só `ROLE_SUPER_ADMIN`.
- Perfis do escritório: o selo FIXO fica legível com o mouse sobre a linha (`perfis.css`).
- Sem migration.

## Para o cliente
- Na janela de indicar o BlueJus, você vê só o total de convites e quantos já viraram conta, sem a lista longa de convites. A janela ficou mais curta e limpa.
- Na tela de Perfis do escritório, a etiqueta FIXO agora continua legível quando você passa o mouse sobre o perfil.
