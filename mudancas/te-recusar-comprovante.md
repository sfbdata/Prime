---
tipo: novidade
---
## Changelog
- Trabalho Externo, aba Pagamento: o Master ganha o botão "Recusar comprovante" (com motivo de 10 a 300 caracteres), ao lado de "Confirmar recebimento", quando há comprovante enviado e o pagamento ainda não foi confirmado.
- A recusa remove o comprovante (registro e arquivo, o arquivo só depois do COMMIT), grava o marco "Comprovante recusado" no histórico (arquivo e motivo; o Convidado não vê), deixa a mensagem de sistema do tipo Pagamento no chat (sem o motivo) e avisa o solicitante com o motivo. Sem migration.
- Sem comprovante o valor volta a ser editável pelo Master e o solicitante pode enviar outro. Nova rota `POST /trabalho-externo/api/demandas/{codigo}/pagamento/recusa` (Master, CSRF, trava de linha).

## Para o cliente
- Se o comprovante de pagamento enviado não estiver correto, o Master do BlueJus pode recusá-lo informando o motivo. Você recebe o aviso com o motivo e envia um novo comprovante na aba Pagamento.
