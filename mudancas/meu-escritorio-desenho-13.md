---
tipo: novidade
---
## Changelog
- Meu Escritório refeito conforme o desenho 13 (28/10): cartão "Informações do Escritório" com logo, código da conta, nome, CNPJ ou CPF (com conferência dos dígitos), registro na OAB, e-mail, telefone, endereço, data de criação e situação, edição no próprio cartão e o rodapé "Alterado por X em".
- Só o usuário primário (e o Master) edita os dados e a logo, conferido no servidor; a tentativa de um secundário é recusada e fica na trilha de auditoria.
- Novo cartão "Backup de arquivos" ligado à conexão do Google Drive que já existia, e cartão "Excluir conta" com senha do primário e nome do escritório, que segue para o pedido de exclusão já em vigor (guarda de 365 dias).
- Migration `Version20261028150000`: colunas novas e nulas em `tenant` (OAB, e-mail, telefone, logo, quem e quando alterou, código da conta) e índice único parcial. Sem UPDATE e sem DROP. O código da conta é gerado no primeiro acesso à tela.
- Nova categoria de arquivo `TENANT_LOGO` (logo do escritório, guardada por chave, apagada junto com o escritório).

## Para o cliente
- Em Meu Escritório, o usuário primário agora cadastra os dados do escritório (CNPJ, OAB, contato e endereço) e envia a logo. Os demais usuários só consultam.
- O pedido de exclusão do escritório continua em duas etapas, com código enviado por e-mail, e os dados ficam guardados por 365 dias.
