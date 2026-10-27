# Push: Buscar processos da OAB (desenho 11)

Risco BAIXO. Sem migration. Sem credencial ou contrato externo: usa o DJEN que o Push já consulta.

## Situação hoje

O botão "Buscar processos da OAB" (aba Processos) faz o mesmo POST de "Sincronizar" (`push_processual_sincronizar`): consulta só o **último dia** e volta para a aba Publicações. Não captura histórico, não avisa quando não há OAB e não informa quantos processos foram achados.

## Desenho (`capturar`)

1. Sem OAB ativa: avisa "Cadastre ao menos uma OAB para buscar os processos." e leva a OABs monitoradas.
2. Com OAB: estado de busca no botão (carregando) e, ao concluir, aviso "Busca concluída: N processos encontrados para NNNN/UF, ...".

## Decisão

A captura de processos por OAB é a leitura das publicações do DJEN por OAB (a lista da aba é montada por elas). O DataJud não pesquisa por OAB, então não há outra fonte sem contrato. "Buscar" passa a puxar um período maior.

## Entrega

- Rota `POST /push-processual/processos/buscar` (`push_processual_processos_buscar`), CSRF `push_processos`, mesmo guard do módulo.
- `BuscarProcessosDaOabUseCase`: sem OAB ativa lança `NenhumaOabAtivaException`; senão chama `SincronizarPublicacoesDjenUseCase` com `PeriodoConsulta::ultimosDias(90, hoje)` (janela do histórico; constante nomeada) e devolve processos distintos antes e depois (contagem da `ProcessosDaOabRepository`).
- Controller volta para a aba Processos com flash: sucesso "Busca concluída: N processos encontrados para OABs." (N = processos da lista, como o desenho), parcial se `temFalhas()`, erro genérico se falhar.
- Sem OAB: flash de aviso e redirect para OABs monitoradas.
- Botão: o formulário da aba Processos aponta para a rota nova; JS troca o rótulo por "Buscando..." e desabilita no submit.
- Respeita o limite do DJEN já tratado no use case (espera entre consultas).

## Fora do escopo

Histórico além de 90 dias e captura em tribunal por OAB (exigiria contrato com agregador). Registrado para o dono se quiser.

## Testes

Unit do use case (sem OAB lança; com OAB chama sync com 90 dias). Functional: sem OAB avisa; com OAB mostra "Busca concluída"; token inválido; botão da aba aponta para a rota nova.
