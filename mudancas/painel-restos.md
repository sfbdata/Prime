---
tipo: novidade
---
## Changelog
- Painel BlueJus Intelligence: rodapé com o controle "Teste, simular dia" do desenho. Arrastar o controle recalcula o "dia X de Y", o dia útil, o selo, o gauge e o ritmo necessário como se hoje fosse outro dia do período; o painel avisa que é simulação.
- A simulação é só de leitura (parâmetro `dia_simulado` da tela): não grava nada, não muda o "hoje" do sistema, só vale dentro do período e até o fim do mês, e os números realizados continuam os de hoje. Fechar o painel desfaz. Pasta oculta pelo cadeado continua fora do selo. Sem migration.

## Para o cliente
- No painel de Inteligência há um controle de teste no rodapé para ver como o ritmo da meta ficaria em outro dia do mês. Nada é alterado nos seus dados.
