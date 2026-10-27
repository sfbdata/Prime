---
tipo: novidade
---
## Changelog
- Chat com o BlueJus IA na Central: nasce o caso de uso da pergunta (`PerguntarABlueJusIaUseCase`). Confere vínculo, texto (1 a 2000 caracteres) e disponibilidade da IA (plataforma, escritório, módulo, cota, orçamento), grava a mensagem da pessoa, cria a análise e a enfileira só depois do commit. Pergunta já em andamento devolve a mesma, sem gastar cota. Ainda sem tela nem rota (vêm no PR seguinte).
- Retenção: a rotina diária `app:inteligencia:expirar-conteudo` passa a apagar também as mensagens do chat com a IA (pergunta e resposta) anteriores ao prazo escolhido pelo Master. A saída e o log do comando trazem só números.
- Sem migration.

## Para o cliente
- Nada muda na tela por enquanto. Quando o chat com o BlueJus IA abrir na Central, as conversas dele ficam guardadas pelo mesmo prazo das demais respostas da IA e depois são apagadas.
