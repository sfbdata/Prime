---
tipo: novidade
---
## Changelog
- Meu Perfil: nova seção "Backup e Exportação de Dados" no fim da página, para qualquer pessoa com escritório. O botão "Gerar backup" pede o ZIP em segundo plano (limite de 1 pedido por dia e um por vez), a lista mostra os últimos 5 pedidos da pessoa com situação, data, tamanho e o "Baixar" (7 dias de validade). O download é por rota autenticada, só para quem pediu, e grava `backup_dl` no audit_log. Os avisos do sino `backup_pronto` e `backup_falhou` agora abrem essa seção.

## Para o cliente
- Agora você pode gerar uma cópia de segurança do que você vê no escritório (pastas, clientes e documentos). Vá em Meu Perfil, no fim da página, e clique em "Gerar backup". Quando o arquivo ficar pronto, o sino avisa e você baixa o arquivo na mesma tela, em até 7 dias. É possível gerar um backup por dia.
