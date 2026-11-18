---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 9): ferramenta `confirmar_acao` (`proposta_id` e o `resumo` exato), com escopo `mcp:escrita`, `destructiveHint` e `_meta["anthropic/requiresUserInteraction"]`, e teto próprio de chamadas (`conector_confirmacao`).
- `PropostasDoConector::propor` grava a proposta (`conector_ia_proposta`) presa à pessoa, ao escritório, ao consentimento e ao app de I.A. do token, com os dados, o hash deles e o resumo, 10 minutos de validade e uso único; só o hash do id vai ao banco. Ao propor, as pendentes vencidas do escritório viram expiradas e perdem os dados.
- `ConfirmarPropostaDoConectorUseCase`: trava de linha numa transação; recusa com mensagem fixa a proposta usada, vencida, de outra pessoa, de outro escritório, de outro app ou de consentimento revogado; confere o resumo e o hash, a linha da tabela de aba e chave e a reconferência da ferramenta; grava, mede o resultado antes de confirmar, guarda só os ids e zera dados e resumo. Toda recusa (salvo o vencimento) e toda falha desfazem a transação e deixam a proposta pendente.
- Contrato `ExecutorDeProposta` para as ferramentas de gravação (PRs 10 a 13): `reconferir` sem gravar e `executarConfirmada` dentro da transação da confirmação.

## Para o cliente
- Com o conector de I.A. ligado, quando o Claude ou o ChatGPT for excluir algo, trocar uma informação já preenchida ou cadastrar cliente no BlueJus, o BlueJus primeiro devolve um resumo do que vai acontecer e só grava quando chega a confirmação desse resumo.
- O app pede a sua aprovação antes de confirmar, salvo se você marcou nele a opção de aprovar sempre sem perguntar. Recomendamos não marcar essa opção para o BlueJus.
- Cada resumo vale por 10 minutos e para uma vez só. Se algo mudar nesse meio tempo (por exemplo, você perdeu o acesso à pasta), o BlueJus recusa a gravação.
- As ferramentas que gravam ainda chegam nas próximas versões: esta parte prepara a confirmação que todas elas vão usar.
