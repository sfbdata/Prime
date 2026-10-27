---
tipo: correcao
---
## Changelog
- BlueJus IA: "Analisar esta pasta" (e os outros comandos longos, como Briefing e Raio-X) falhava em pasta grande com "a resposta foi cortada no limite de tokens". O limite dos comandos dos agentes subiu de 2500 para 4500 e uma resposta cortada agora é repetida uma vez com o dobro do limite antes de falhar.
- Sem migration.

## Para o cliente
- A análise de pastas com muitos itens deixa de falhar por resposta cortada.
