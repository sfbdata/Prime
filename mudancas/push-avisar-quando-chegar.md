---
tipo: novidade
---
## Changelog
- Aba Push da pasta: cartão "Avisar quando chegar" na coluna direita (desenho 1.2.3), com três chaves por pasta: "Responsável da pasta", "Por e-mail" e "Análise da BlueJus IA" (POST /pasta/{id}/push/avisos, exige editar a pasta). Por e-mail só vale com o aviso ao responsável ligado; a IA só com a IA da plataforma e do escritório ligadas. Padrões: responsável e e-mail ligados, IA desligada.
- Ao fim de cada captação do DJEN (comando diário e "Buscar agora"), cada pasta viva com publicação nova vinculada avisa o responsável ativo: um aviso no sino por pasta (tipo push_publicacao_pasta) e, se ligado, um e-mail com a lista das movimentações (tipo, data, órgão e resumo de até 220 caracteres), o botão "Abrir a pasta" e o link "Não quero mais receber estes avisos". Com a chave da IA ligada, pede o resumo do Push da pasta. O aviso sai uma vez por execução (no comando diário, uma vez por escritório ao fim das duas fases). Falha de e-mail ou de IA vai ao log e não interrompe a captação.
- Descadastro de e-mail reaproveitável em Shared (DescadastroDeEmailInterface: verificar, registrar e gerar o link assinado), por remetente (escritório, ou a plataforma), endereço e assunto, com o opt-out total "todos" no mesmo escopo. Rota pública /email/descadastro: o GET só mostra o pedido (endereço mascarado, botão "Confirmar" e a opção de não receber nenhum e-mail do remetente); o POST na URL assinada grava, inclusive pelo one-click do List-Unsubscribe-Post.
- Migration: tabelas pasta_aviso_push e email_descadastro.

## Para o cliente
- Na aba Push da pasta, o cartão "Avisar quando chegar" escolhe quem fica sabendo quando chega uma movimentação nova: o responsável pela pasta recebe o aviso no sino e, se quiser, por e-mail; a BlueJus IA pode já resumir as novidades.
