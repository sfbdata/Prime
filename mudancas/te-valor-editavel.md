---
tipo: novidade
---
## Changelog
- Trabalho Externo: o Master altera o valor do pagamento na aba "Pagamento" (lápis ao lado do valor), informando o novo valor e o motivo (10 a 300 caracteres). Só com proposta aceita, antes do pagamento e sem comprovante enviado; o valor é lido e validado no servidor, com trava da demanda na transação.
- O novo valor passa a valer em tudo: QR Code, código PIX, mensagem do chat, histórico ("PIX R$ x") e confirmação do recebimento. A proposta aceita não muda; aceitar outra proposta zera o ajuste.
- Trilha: marco no histórico (valor antigo, novo, quem, quando e motivo), mensagem de sistema no chat sem o motivo e aviso de andamento ao solicitante. O convidado não vê nada disso.
- Nova coluna `trabalho_externo_demanda.valor_pagamento_ajustado` (migration `Version20261024150900`) e rota `POST /trabalho-externo/api/demandas/{codigo}/pagamento/valor`.

## Para o cliente
- O valor do pagamento pode ser corrigido pela Central BlueJus antes do pagamento. Você é avisado no chat e a aba Pagamento já mostra o QR Code e o código PIX com o valor novo.
