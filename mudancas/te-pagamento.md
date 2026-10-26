---
tipo: novidade
---
## Changelog
- Trabalho Externo: nova aba "Pagamento" na demanda (entre Chat e Histórico), conforme o desenho de 25/10. Mostra o valor da última proposta aceita, a forma de pagamento, o QR Code e o código PIX "copia e cola" (gerados no servidor, só enquanto não pago) e o estado do pagamento em três faixas: aguardando, comprovante enviado e confirmado.
- Trabalho Externo: o solicitante envia o comprovante do PIX (PDF ou imagem, até 20 MB, um por demanda) e a Central confirma o recebimento pela própria aba; a confirmação usa a ação "Registrar pagamento" (histórico "PIX R$ x", aviso de andamento e de WhatsApp). "Enviar no chat" grava o aviso do PIX na conversa.
- O convidado não vê a aba, o valor, o comprovante nem os registros de pagamento no chat e no histórico.
- Nova dependência: `bacon/bacon-qr-code` (QR Code em SVG, PHP puro). Nova coluna `trabalho_externo_documento.comprovante`, com índice único parcial (um comprovante por demanda); confirmar recebimento e enviar comprovante travam a demanda na transação.
- Decisão a confirmar pelo dono: hoje a Equipe só confirma o recebimento com a opção "Alterar a situação das demandas" ligada, a mesma regra do botão "Registrar pagamento".

## Para o cliente
- Nova aba "Pagamento" na demanda: veja o valor combinado, pague pelo QR Code ou pelo código PIX e envie o comprovante. A Central confirma o recebimento e você é avisado.
