# Spec: Master edita o valor do pagamento (aba Pagamento do Trabalho Externo)

Risco: **MÉDIO** (dinheiro). Pedido do dono, via coordenador (26/10). Migration reservada: `Version20261024150900`.

## Regra
- Só o **Master** edita (lápis ao lado do valor, na aba Pagamento). Equipe e Solicitante não; Convidado nem vê a aba.
- Só com proposta aceita, `pagoEm` nulo e nas situações de `RegistrarPagamento`.
- **Bloqueado se já há comprovante enviado** (409): o cliente pode ter pago o valor velho.
- Valor novo vem só do servidor (campo decimal validado: maior que zero, teto de R$ 9.999.999,99, 2 casas), com CSRF e trava de linha (`comADemandaTravada`), como confirmar recebimento. Valor igual ao efetivo: 409 sem gravar.
- Motivo obrigatório (10 a 300 caracteres).

## Dado
`demanda.valor_pagamento_ajustado` numeric(12,2) NULL. A proposta aceita não muda. Valor efetivo = ajustado, se houver, senão o da proposta aceita. Valor, txid, QR, código PIX, mensagem do chat, histórico "PIX R$ x" e confirmação passam todos a usar o efetivo (um só ponto de leitura). Se a proposta aceita for trocada, o ajuste é zerado.

## Trilha e aviso
- Marco no histórico da demanda: valor antigo, novo, quem, quando, motivo (imutável, só leitura).
- Mensagem de sistema no chat (tipo `Pagamento`, Convidado não lê): "Valor do pagamento alterado de R$ x para R$ y." Sem o motivo no chat.
- Aviso de andamento ao solicitante, mesmo canal do pagamento.

## Tela
Lápis só para o Master na aba Pagamento, abrindo diálogo do desenho (campo valor + motivo + Salvar). Sem desenho para o diálogo: usar o padrão do diálogo de confirmação já existente na tela, sem componente do sistema (nada de modal Bootstrap).

## Testes
Unit do UseCase (Master ok; Equipe 403; pago 409; comprovante 409; igual 409; valor inválido; motivo curto; troca de proposta zera) e Functional do controller (CSRF, Convidado, outro escritório 404, QR/txid usam o novo valor).
