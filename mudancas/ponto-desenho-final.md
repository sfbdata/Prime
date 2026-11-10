---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, abas de gestão na /ponto (E5, E6 e E9): o `PontoController::index` passa `veEquipe`, `veConfiguracoes` e `veAuditoria` (`GestaoDoPonto`) e o número da aba Equipe (`JustificativasAAnalisarDaEquipe::contarAAnalisar`, contado só para quem vê a Equipe). O endereço abre `?aba=equipe` e `?aba=configuracoes`; quem não vê a aba pedida cai em Pendências.
- Excluir a justificativa sem recarregar (E3, P41): a rota `ponto_justificativa_excluir` responde com JSON, sem flash e sem redirecionar. Outra pessoa, outro escritório e a já analisada continuam recusadas (403, 404 e 422, com a frase).
- Na tela (`ponto-abas.js`): a linha sai na hora, o número de "Minhas Justificativas" acompanha (e o da Equipe, quando a justificativa contava lá) e a Folha e as pendências são buscadas de novo pelo caminho de depois da batida (`atualizarTelaDoPonto`), sem recarregar a página.

## Para o cliente
- Quem gere a equipe ou o ponto do escritório passa a ver, na tela do Ponto Eletrônico, as abas "Equipe" (com o número de justificativas esperando análise) e "Configurações". Quem não tem essa permissão continua vendo só as abas de antes.
- Excluir uma justificativa em análise agora tira a linha da lista na hora, sem recarregar a tela. A Folha e as pendências do dia se atualizam sozinhas logo em seguida.
