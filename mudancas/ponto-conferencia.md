---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, conferência do desenho 16 (09/11/2026): o texto da coluna "Detalhe" da aba Auditoria segue o formato do desenho. A batida mostra os segundos e as marcas " · home office" e " · sem internet" (chave `sem_internet` gravada no detalhe dos eventos novos); a justificativa sai como "dd/mm/aaaa · Tipo · subtítulo", com a batida esquecida ou o abono parcial (`parcial`, `parcial_de` e `parcial_ate` no detalhe); o abono em lote de um dia só mostra "(N justificativas)". O texto é gerado na leitura, então os eventos antigos também mudam de aparência, sem mexer no selo.
- A chave de uma função do Escritório ligada ou desligada entra na trilha ("Configuração do escritório"), na mesma transação da chave: se a trilha falha, a chave não muda (`AlterarFuncaoDoPontoNoEscritorioUseCase`).
- A rodada da fila sem internet que enviou batidas grava o evento "Sincronização" na trilha: `POST /ponto/fila/sincronizada` (`FilaDoPontoController`, `RegistrarSincronizacaoDaFilaUseCase`) com os `idEnvio` da rodada; o servidor conta só as batidas da pessoa, neste escritório, vindas da fila e ainda fora de outra sincronização (`pg_advisory_xact_lock` da trilha).
- Na tela /ponto, a saída prevista some do aviso do repouso quando o trabalhado até o repouso já cobre a meta do dia.
- A chave Global do Ponto mostra o aviso no balão flutuante do Ponto, e os botões de página da aba Auditoria seguem o molde do desenho (espaço de 6px e fundo azul claro no passar do mouse).
- Spec `docs/specs/ponto-desenho-0811.md`: decisões da conferência registradas na E1, E9, E14 e E16.

## Para o cliente
- Na aba Auditoria do Ponto Eletrônico, cada evento ficou mais fácil de ler: a batida mostra a hora com os segundos e avisa quando foi feita em home office ou guardada sem internet, e a justificativa mostra a data, o tipo e o horário em uma linha só.
- A aba Auditoria passa a registrar também quando o escritório liga ou desliga uma função do Ponto e quando as batidas guardadas sem internet chegam ao sistema.
- No intervalo, a tela do Ponto deixa de mostrar a saída prevista quando a pessoa já cumpriu as horas do dia antes de sair para o intervalo.
