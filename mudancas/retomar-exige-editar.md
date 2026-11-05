---
tipo: correcao
---
## Changelog
- Push Processual, aba Processos da OAB: retomar o acompanhamento de um processo que já tem pasta exige que a pessoa edite cada pasta não excluída dele (`AcessoAPasta::podeEditar`, a mesma regra do "Parar de acompanhar" do #634 e do "Excluir processo"). A regra vale para incluir pelo formulário, incluir do Relatório, incluir cadastrado (`IncluirProcessoNoAcompanhamentoUseCase`, `IncluirProcessoCadastradoNoPushUseCase`) e cadastrar com número que já existe. Sem a permissão, a tela recusa com "Você não tem permissão para retomar o acompanhamento do processo da Pasta X." e nada é gravado. Cadastrar processo novo, sem pasta, continua igual. Decisão do Coordenador a partir da auditoria de segurança (lote A, 3.09).

## Para o cliente
- Na aba Processos da OAB do Push Processual, só quem pode editar a pasta de um processo consegue voltar a acompanhá-lo. Quem só pode visualizar a pasta recebe um aviso dizendo qual pasta impede, e o processo continua pausado. Cadastrar um processo novo funciona como antes.
