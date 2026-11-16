---
tipo: alteracao
---
## Changelog
- Contribua igual ao desenho de 14 e 15/11 ("o BlueJus é gratuito"): selo GRATUITO, linha "Sem mensalidade" recolhível, faixa Convênio OAB, Perguntas frequentes, nova ordem das seções e Apoie recolhível (abre com `#apoie`).
- Meu espaço: caixa "Solicitar mais espaço" em 3 estados, opções de compra ocultas até a liberação (pelo Master ou com uso a partir de 97%), garantias em duas versões, "Não comporta seu uso" no pacote menor que o uso, painel "Pedidos de espaço" do Master com liberar e recusar.
- Pedidos de espaço: tabela `contribua_pedido_de_espaco` e colunas de liberação em `contribua_espaco_do_escritorio` (migration Version20261116180000); um pedido pendente por escritório, só o primário pede, trava por escritório contra clique duplo; checkout e "Quero +N GB" recusados sem liberação, exceto Master e escritório com plano pago; purga do escritório apaga os pedidos.
- Avisos em todas as telas: barra de espaço reescrita, pop-up dos 97% (marca `auto` gravada na resposta), barra do Master com pedidos pendentes e modal global de pedido (`data-espaco-solicitar`).
- Chave "Indique e ganhe" no catálogo de recursos: desligada, some o painel da Contribua, o botão da barra e o item "Indicar" do menu (o Master continua vendo, marcado).
- E-mails: confirmação do pedido, "Liberamos as opções de espaço extra" na liberação pelo Master, resposta na recusa e novo assunto do aviso de 100%.

## Para o cliente
- A página "Quem somos e contribua" foi reorganizada para deixar claro que o BlueJus é gratuito: tem um selo de gratuito, a explicação do convênio com a OAB, uma seção de perguntas frequentes e a contribuição voluntária por PIX ficou mais discreta.
- Se o espaço do escritório ficar pequeno, o usuário principal pode pedir mais espaço com um clique. A equipe BlueJus libera as opções de espaço extra e avisa por e-mail; quando o uso passa de 97%, as opções aparecem sozinhas. Pedir não gera cobrança e nenhum arquivo é apagado.
- O aviso de espaço que aparece no topo das telas mostra quanto falta e deixa pedir mais espaço direto dali, sem sair da tela.
- No Painel do Master, a função "Indique e ganhe" pode ser ligada ou desligada para todos os escritórios, e a tela Contribua mostra a lista de pedidos de espaço para liberar ou recusar.
