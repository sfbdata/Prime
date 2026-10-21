---
tipo: alteracao
---
## Changelog
- Revisão de OAB do Master (`/admin/platform/oab`) passa a listar por inscrição (principal e suplementares), com o selo PRINCIPAL ou SUPLEMENTAR, número/UF, usuário, situação e data da verificação.
- Confirmar, reverter e re-verificar agem pelo id da inscrição (`ConfirmarInscricaoOabUseCase`, `ReverterInscricaoOabUseCase`, `VerificarInscricaoOabUseCase`); a principal segue espelhada no usuário. Id inexistente devolve 404.
- Confirmar e reverter valem para o número e a UF exibidos na fila: se a inscrição mudou depois, a ação é recusada com aviso.
- Ficam sem uso, para remover depois: `UserRepository::buscarParaRevisaoOab` e `contarParaRevisaoOab`, `ConfirmarOabManualmenteUseCase` e `ReverterOabUseCase`.
