---
tipo: novidade
---
## Changelog
- Várias inscrições na OAB por pessoa (desenho 07, VS 18.10.26): uma principal e suplementares, no máximo uma por seccional; card "Registro OAB" com o tipo e a situação de cada uma (Pendente, Confirmada, Não confirmada) e modal "Inscrição na OAB" para adicionar, editar, trocar a principal e remover suplementares.
- Tabela nova inscricao_oab com backfill da OAB atual como principal (migration Version20261020131500); os campos de OAB do usuário passam a ser a cópia da principal. A revisão de OAB do Master continua igual e também atualiza a principal; leitor e UseCases por inscrição prontos para a fila do Master mostrar as suplementares.

## Para o cliente
- Agora você pode cadastrar mais de uma inscrição na OAB (a principal e as suplementares de outros estados) direto no seu perfil.
