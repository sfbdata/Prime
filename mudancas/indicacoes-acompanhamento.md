---
tipo: interno
---
## Changelog
- Painel do Master, Indicações do BlueJus: a seção lê só os totais e os limites (`ObterPainelDeIndicacoesUseCase::resumo()`), sem consultar a tabela por pessoa nem os alertas, que a tela de 21/10 não mostra.
- Spec `indicar-bluejus.md`: registrado o desvio aprovado pelo dono (limite 0 ou vazio é recusado com mensagem, em vez de virar 1).
