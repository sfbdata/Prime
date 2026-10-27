# Spec: Master recusa o comprovante de pagamento (aba Pagamento do Trabalho Externo)

Risco: **MÉDIO** (dinheiro). Pedido do dono via coordenador (26/10). Sem migration.

## Regra
- Só o **Master** recusa (botão "Recusar comprovante", ao lado de "Confirmar recebimento"). Equipe e Solicitante não; Convidado nem vê a aba. Fora da prévia.
- Só com comprovante enviado e `pagoEm` nulo, nas situações de `RegistrarPagamento`. Sem comprovante, ou já pago: 409.
- Motivo obrigatório, 10 a 300 caracteres. Trava de linha (`comADemandaTravada`), CSRF, como as outras escritas da aba.

## Efeito
- O documento do comprovante é removido (registro e arquivo, só depois do COMMIT, skill `armazenamento-de-arquivos`). O Solicitante pode enviar outro.
- Trilha: marco no histórico da demanda ("Comprovante recusado. Motivo: ...", quem e quando). O nome do arquivo recusado entra no marco.
- Mensagem de sistema no chat (tipo `Pagamento`, Convidado não lê): "Comprovante de pagamento recusado. Envie um novo comprovante na aba Pagamento." Sem o motivo no chat.
- Aviso de andamento ao Solicitante com o motivo e a orientação de reenviar (mesmo canal do pagamento).
- Com o comprovante fora, o Master volta a poder editar o valor (#407).

## Tela
Botão no padrão do diálogo de confirmação existente (`abrirConf`, com campo de motivo como no ajuste de valor), só para o Master, só quando há comprovante e não pago. Faixa volta a "Aguardando pagamento". Sem travessão nos textos.

## Testes
Unit do UseCase (Master ok; Equipe, Solicitante e prévia 403; sem comprovante 409; pago 409; motivo curto 422; arquivo removido do storage; marco gravado) e Functional (CSRF, Convidado, outro escritório 404, Solicitante reenvia depois, edição do valor liberada).
