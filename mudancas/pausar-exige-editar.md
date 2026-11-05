---
tipo: correcao
---
## Changelog
- Push Processual, aba Processos da OAB: "Parar de acompanhar" (`PararDeAcompanharProcessoUseCase`) exige que a pessoa edite cada pasta não excluída do processo (`AcessoAPasta::podeEditar`, a mesma regra do "Excluir processo"). Sem isso, recusa com "Você não tem permissão para pausar o processo da Pasta X. Nada foi pausado." e não pausa nenhum dos números enviados. Decisão do Coordenador a partir da auditoria de segurança (lote A, 3.09).

## Para o cliente
- Na aba Processos da OAB do Push Processual, só quem pode editar a pasta do processo consegue parar de acompanhá-lo. Quem só pode visualizar a pasta recebe um aviso dizendo qual pasta impede a pausa, e o processo continua sendo acompanhado.
