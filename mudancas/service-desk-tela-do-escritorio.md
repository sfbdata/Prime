---
tipo: novidade
---
## Changelog
- Service Desk (desenho 12), fatia 3: tela "Meus chamados" em `/service-desk` (saldo, extrato, filtro por status, busca, abrir chamado, ver chamado e adicionar saldo por PIX). O item de menu Service Desk passa a abrir a tela nova; as rotas do help desk antigo (`/servicedesk`) seguem no ar.
- `RecargaManual` passa a devolver o PIX copia e cola estático da BlueJus, com a referência da recarga (BJSD...) como txid. O PIX é só mostrado ao abrir "Adicionar saldo" (referência assinada, nada gravado); o pendente nasce em "Já paguei". Cartão fica fora da tela até existir o adaptador do provedor.
- Arquivos quentes tocados: `_sidebar.html.twig` e `base.html.twig` (uma linha cada).

## Para o cliente
- Nova tela "Meus chamados": você vê o saldo para atendimento técnico, o extrato e a lista dos seus chamados, e abre chamados de suporte ou de treinamento com a equipe técnica do BlueJus.
- É preciso ter pelo menos R$ 20,00 de saldo para abrir um chamado. Para adicionar saldo, escolha o valor (R$ 20, 50, 100 ou 200) e pague por PIX; o saldo é liberado quando a equipe confirma o pagamento.
