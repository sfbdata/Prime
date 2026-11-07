---
tipo: novidade
---
## Changelog
- Ponto, aba "Jornada Personalizada" do colaborador: campo "Vale a partir de" (padrão: hoje) e "Histórico da jornada", com o período de cada versão, a que vale hoje, as agendadas e os botões Editar e Excluir.
- Salvar com uma data nova cria uma versão e deixa as anteriores como estavam; com a data de uma versão existente, atualiza aquela. "Editar" muda a versão escolhida, inclusive a data, desde que não caia no dia de outra (422). "Excluir" passa os dias da versão para a vizinha e não apaga a única (422). "Habilitar alertas de ponto" vale para todas as versões.
- "Limpar jornada personalizada" apaga todo o histórico. A confirmação avisa que a folha de todos os meses volta a usar a jornada do escritório. Data no passado pede confirmação de que a folha e o saldo serão recalculados desde aquele dia.
- Coluna nova `jornada_colaborador.cobre_dias_anteriores` (migration `Version20261107110000`, `true` em todas as linhas existentes). A primeira jornada criada pela tela vale só da data dela em diante, então criar a jornada de alguém não muda o passado dele.
- API: o GET devolve `hoje`, `versaoVigenteId` e `versoes[]`. O POST aceita `valeAPartirDe` e `versaoId`. Rota nova `DELETE /tenant/{tenantId}/user/{id}/jornada-colaborador/versao/{versaoId}`. UseCases `SalvarVersaoDaJornadaUseCase`, `ExcluirVersaoDaJornadaUseCase` e `LimparJornadaPessoalUseCase`. O choque entre escritórios continua conferido em toda gravação.
- Na tela do ponto do colaborador, os dias antes da primeira jornada criada pela tela continuam calculados pela jornada do escritório, como antes de ela existir (`HistoricoDeJornadas::comJornadaAntesDaPrimeira`). O `down` da migration recusa quando há jornada criada pela tela.
- Spec: `docs/specs/ponto-jornada-vigencia.md` §10.

## Para o cliente
- Na ficha do colaborador, aba "Jornada Personalizada", agora dá para dizer a partir de que dia a jornada nova vale. Exemplo: quem passou de 40 para 44 horas em maio fica com 40 horas até 30 de abril e 44 horas a partir de 1º de maio. A folha e o banco de horas de abril continuam contando 40 horas.
- A aba mostra o histórico das jornadas da pessoa: o período de cada uma, qual vale hoje e qual está agendada para começar depois. Cada jornada do histórico pode ser corrigida ou excluída.
- Ao escolher uma data no passado, o sistema avisa que a folha e o saldo serão recalculados desde aquele dia, antes de gravar.
- "Limpar jornada personalizada" apaga a jornada e todo o histórico dela, e a folha de todos os meses volta a usar a jornada do escritório. O aviso de confirmação explica isso antes.
