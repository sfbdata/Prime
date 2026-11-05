---
tipo: correcao
---
## Changelog
- Auditoria de segurança, lote C (3.10, 3.11): trocar os marcadores da pasta exige permissão de EDITAR a pasta. `SincronizarMarcadoresDaPastaUseCase` recebe o usuário, busca a pasta por id e escritório (404) e confere `canAccessResource(pasta, edit)` (403), nas rotas do Expediente e das Demandas. A Lista (Expediente e Demandas) e o cabeçalho da pasta só entregam o botão e o token dos marcadores a quem edita (`PastasEditaveisDaPagina`, em lote).
- Auditoria de segurança, lote C (3.02, 3.03): as rotas antigas da Agenda (`/agenda/{id}`, editar, excluir, cancelar) aplicam a visibilidade da Agenda nova (`EventoDaAgendaRepository::visivelPara`): "Só eu" de outra pessoa e "Para Todos" com participantes de que a pessoa não faz parte dão 404, até para o administrador. Editar, excluir e cancelar passam a exigir o módulo agenda. No detalhe, editar, cancelar e excluir só aparecem para o criador ou quem administra usuários.

## Para o cliente
- Os marcadores de uma pasta agora só podem ser trocados por quem tem permissão de editar essa pasta. Quem só pode ver a pasta continua vendo os marcadores, mas não vê mais o botão de trocar.
- Na agenda, um compromisso marcado como "Só eu" fica visível apenas para quem o criou, inclusive pelos links antigos. Uma reunião com convidados só abre para quem a criou e para os convidados. Os botões de editar, cancelar e excluir aparecem só para quem pode usá-los.
