---
tipo: novidade
---
## Changelog
- Painel do Master > Perfis, gráfico Crescimento (desenho 05 - Perfis 1.2, VS 03.11.26): visão "Diário" (linha fina, referência de 30 dias atrás e ponto final verde ou vermelho) e botão "Acessos ao sistema" (linha com escala própria à direita, tracejada por mês e na linha contínua, média de 7 dias no Diário, oculta em "Por ano").
- Foto diária das métricas (contas, usuários, pastas, armazenamento) na tabela `master_metrica_diaria`, gravada pela tarefa agendada `app:master:retratar-metricas` às 23h50; o passado é reconstruído pela data de criação.
- Registro de acesso por pessoa e por dia (`master_acesso_diario`, uma linha por pessoa por dia) no login bem-sucedido; uma falha ao gravar nunca impede o login. A linha de acessos começa no dia da publicação.
- Migration `Version20261104100000` (as duas tabelas).

## Para o cliente
- O BlueJus passou a guardar, uma vez por dia, apenas o registro de que cada pessoa entrou no sistema naquele dia (sem horário nem outra informação), para acompanhar o uso da plataforma. O registro é apagado junto com a conta. A entrada no sistema continua igual.
