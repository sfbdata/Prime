---
tipo: alteracao
---
## Changelog
- /expediente: as 6 contagens da seção EXPEDIENTES do trilho (Respondidos nos últimos 10 dias, Prazo findou, Audiências, Prazos, Metas sem resposta, A protocolar) saem numa consulta só (`PastaRepository::contagensDosFiltrosDoExpediente`, com `SUM(CASE WHEN ...)`). Antes eram 6 consultas.
- A condição de cada filtro continua vindo do mesmo `aplicarFiltroDoExpediente`. A base também é a mesma: escritório, só as pastas que a pessoa vê pelo cadeado e sem as excluídas. Nenhum número muda.
- Teste: `ContagensDosFiltrosDoExpedienteTest` compara, filtro por filtro, com o caminho de antes para o Master, o primário, o colaborador liberado e o de fora do cadeado e para alguém de outro escritório. Também trava em uma consulta.

## Para o cliente
- A tela Expedientes abre mais rápido: os números ao lado de Prazos, Audiências, Metas e dos outros atalhos da seção EXPEDIENTES são contados de uma vez, e não um por um. Os números são os mesmos de antes, e cada pessoa continua contando só as pastas que pode ver.
