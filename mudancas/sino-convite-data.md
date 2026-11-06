---
tipo: correcao
---
## Changelog
- Testes: `SinoSemEscritorioTest::testConvidadoComConviteVencidoNaoVe` marcava o convite como vencido com o `CURRENT_DATE` do Postgres, que no CI está em UTC. Das 21h à meia-noite em Brasília o "ontem" do banco é o "hoje" do PHP, o convite ainda valia e o teste falhava (Suíte completa do #668 e do #669, 06/11 por volta de 00:00Z). Agora a validade é "ontem" pelo relógio do PHP, como em `ConviteDoTrabalhoExternoTest`.

## Para o cliente
- Ajuste interno nos testes automáticos do sistema, que falhavam perto da meia-noite por causa do fuso horário. As telas e os dados continuam funcionando do mesmo jeito.
