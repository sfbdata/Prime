---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 10): ferramentas de escrita na pasta `atualizar_pasta`, `mudar_andamento`, `registrar_na_pasta` e `prazos_e_metas_da_pasta`, cada uma com o executor de proposta à parte (`<Nome>Executor`), porque uma classe não pode ser ferramenta e executor ao mesmo tempo.
- Acrescentar, marcar e escolher numa lista fechada gravam direto; trocar valor já preenchido, voltar fase, reativar pasta finalizada e toda exclusão vão por proposta (6.6, 6.7). A confirmação confere tudo de novo e recusa se o valor mudou desde a proposta.
- `atualizar_pasta` ganha a ação `excluir` (decisão 31), com alvo `pasta`, `marcador` ou `reu`, cada um com a mesma permissão da tela. `situacao` para Ativo e `restaurar` recusam quando o cliente principal recebe o envio automático do Push (`ErroDaFerramenta::envioAutomatico`).
- `CriarMetaUseCase` sai do `TarefaController::criarParaPasta`: a tela e o conector criam a meta da pasta pelo mesmo caminho (módulo de metas, ver a pasta, pasta fora da lixeira, responsáveis ativos, análise do prazo e aviso).
- `GuardaDaAcaoDoConector::exigirEdicaoDaPasta` passa pelo `AcessoAPasta::podeEditar`; `ErroDaFerramenta` ganha `recusada()` com mensagem fixa.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT passam a poder trabalhar na pasta: mudar a fase do andamento, mudar situação, responsável, prioridade e carteira, cuidar de marcadores e réus, escrever no Registro e nos relatórios, lançar e cumprir prazos e criar metas.
- Cada ação respeita as mesmas permissões da tela: quem só visualiza a pasta não consegue mudar nada nela pelo conector, e o Financeiro continua escondido de quem não o vê.
- Excluir, trocar uma informação já preenchida, voltar uma fase ou reativar uma pasta finalizada sempre passam por um resumo que você confirma antes de gravar. A pasta excluída vai para a lixeira e pode ser restaurada.
- Prazo lançado pelo conector já aparece na Agenda da equipe, e a resposta repete data, tipo e responsável para você conferir.
- Para cliente que recebe o envio automático do Push, reativar ou restaurar a pasta continua sendo feito só pela tela do BlueJus.
- Na tela, criar meta pela pasta segue igual: só o caminho por dentro do sistema foi reorganizado.
