---
tipo: novidade
---
## Changelog
- Painel do Master: aba nova "Configurações da I.A." (`data-aba="ia"`). Ela tem:
  - o aviso da retenção zero (ZDR) com o que pedir à Anthropic;
  - o estado da instalação, que diz só se a chave está gravada, nunca o valor;
  - a chave geral para todos os escritórios;
  - a escolha do modelo, Haiku 5.5 por padrão;
  - o prazo de guarda das respostas, de 1 a 120 meses, 12 por padrão;
  - a tabela de escritórios: liberar, modo de cobrança e orçamento mensal em US$.
- Configuração da plataforma em linha única: `inteligencia_plataforma`, Auditavel.
- Três colunas novas em `inteligencia_configuracao`, todas na migration `Version20261020151800`.
- Rotas `master_ia_*`: POST JSON com CSRF no header.
- A disponibilidade ganhou o caso `nao_liberada_pelo_master`: a IA só funciona com a chave geral ligada E o escritório liberado pelo Master.
- Depois do deploy a IA fica desligada para todos até o Master ligar a chave geral e liberar o escritório piloto.
- O orçamento ainda não bloqueia (PR C).

## Para o cliente
- Nada visível para os escritórios. A BlueJus IA passa a ser liberada escritório a escritório pelo BlueJus.
