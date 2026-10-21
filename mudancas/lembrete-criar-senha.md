---
tipo: alteracao
---
## Changelog
- Lembrete de confirmação (`email/comercial/automacao/lembrete_confirmacao.html.twig`) e modelo "validar" da Central de envios: o botão passa a "Confirmar e criar minha senha" e o prazo a "O link vale por 7 dias.", porque o link agora abre "Crie sua senha" e vale 7 dias (decisão (a) do dono, frente cadastro-senha-no-link). Um teste amarra o texto a `ConfirmacaoEmail::VALIDADE`.

## Para o cliente
- O e-mail que lembra de confirmar o cadastro agora diz que o link serve para criar a senha e vale por 7 dias.
