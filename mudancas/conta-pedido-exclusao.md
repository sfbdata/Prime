---
tipo: novidade
---
## Changelog
- Meu Perfil: o usuário primário pede a exclusão da conta do escritório em 2 etapas (motivo; código de 6 dígitos enviado ao e-mail + EXCLUIR). A conta fica "suspensa a pedido": a equipe perde o acesso (sai da seleção de escritório, do login automático e do seletor), o primário fica só no Meu Perfil e pode "Desistir e reativar". Os Masters são avisados no sino. Tabelas `pedido_exclusao_conta` e `codigo_exclusao_conta`; limitador `exclusao_conta_codigo`.
- Painel do Master: reativar uma conta "suspensa a pedido" decide o pedido; excluir (quarentena de 365 dias) também, e a contagem começa na decisão do Master.

## Para o cliente
- O usuário primário agora pode pedir a exclusão da conta do escritório pelo Meu Perfil, com confirmação por código no e-mail. Até o BlueJus analisar, dá para desistir.
