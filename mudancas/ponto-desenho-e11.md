---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, E11 do desenho 16 (P21, alertas que não bloqueiam): o retorno com o intervalo abaixo do mínimo e a entrada antes da interjornada deixam de ser recusados. A primeira tentativa volta com o alerta (200, `success: false`, `alerta`) e nada grava; a janela "Antes de registrar" da tela (moldura das janelas do Ponto, sem modal do Bootstrap) mostra o alerta e a frase "O alerta não impede o registro: a folha deve refletir o horário real."; "Registrar assim mesmo" manda de novo a mesma batida (o mesmo `idEnvio` da E13) com `confirmarAlerta: true`, e ela grava. A folha continua marcando o intervalo intrajornada e a interjornada como não conformes pelos horários.
- Os mínimos são os da Jornada do escritório (não o 1h e 11h fixos do desenho), no formato do desenho: "Faltam 18 minutos para completar o intervalo mínimo de 30min.", "Faltam 2h00 para completar 11h de descanso desde a saída de ontem.". Novo alerta do 7º dia seguido: "Este será o 7º dia seguido com registro, sem o descanso semanal." (6 ou mais dias seguidos com registro antes de hoje neste escritório, só na primeira batida do dia; segue a validação da interjornada da Jornada).
- Regras em `AlertasAntesDaBatida` (`src/Ponto/Service`), que o `PontoController::batida` chama no lugar do bloco do repouso e do método `interjornada()`. A saída vinda de esquecimento continua só avisando depois (R4); a batida da fila sem internet grava sem a janela e nunca fica presa no alerta.
- Chave `ponto-alertas` ("Ponto: alertas de intervalo") no catálogo do Master e na aba Configurações do Ponto (camada Escritório), nascendo ligada (P96). Desligada em qualquer camada: volta a recusa de antes (422, as mesmas frases), sem o alerta do 7º dia e sem o aviso do intervalo no cartão (P12).
- Atalho do cabeçalho: com o alerta, pergunta (confirm do navegador, como na saída) e, confirmado, manda a mesma batida com a confirmação. Sem migration.

## Para o cliente
- No Ponto Eletrônico, voltar do intervalo antes do mínimo ou começar o dia antes de completar o descanso entre jornadas não impede mais a batida. O sistema mostra um aviso "Antes de registrar" com quanto tempo falta, e a pessoa escolhe entre aguardar ou registrar assim mesmo. A folha de ponto continua mostrando o intervalo fora da regra.
- Os avisos usam os tempos mínimos configurados na jornada do escritório.
- Novo aviso quando a pessoa vai registrar o sétimo dia seguido sem o descanso semanal.
- O escritório pode desligar esses avisos nas configurações do Ponto. Desligados, o sistema volta a recusar a batida fora do intervalo mínimo, como antes.
