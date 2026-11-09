---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, etapa E5 do desenho 16 (VS 08.11.26): a aba "Equipe" da /ponto ganha o painel do desenho (L210 a L219), buscado quando a aba abre: 5 indicadores, a tabela da equipe (situação de hoje, entrada, último registro, banco, pendências e escala) e as justificativas a analisar com "Abonar selecionadas". Só para quem gere a equipe no escritório da sessão (`GestaoDoPonto::veEquipe`).
- Escala (P56 a): as 4 escalas do desenho são modelos. Trocar a escala grava uma versão nova da jornada pessoal valendo a partir de hoje (`TrocarEscalaNaEquipeUseCase` sobre o `SalvarVersaoDaJornadaUseCase`), com a mesma conferência de choque com outro escritório da ficha, agora em `ChoqueDaJornadaProposta` (a ficha passa a usá-la, sem mudar o comportamento). Os dias de antes não mudam.
- Abono em lote (P57): `AbonarJustificativasDaEquipeUseCase` confere escritório, vínculo ativo e a análise da própria (P43 a) id a id e chama o abono da ficha pela interface `AbonoDeJustificativa`. O adaptador de hoje é provisório e falha até ser ligado ao AnalisarJustificativaUseCase, que outra frente extrai do TenantController.
- O subtítulo da justificativa (esquecimento e abono parcial) passa a sair de um macro só, usado pela aba "Minhas Justificativas" e pela lista da Equipe, sem mudar o que a aba mostra. Sem migration.

## Para o cliente
- No Ponto Eletrônico, quem gere a equipe passa a ver, numa aba "Equipe", quem está trabalhando, quem está no intervalo, quem ainda não registrou o ponto hoje e quem já encerrou o dia, com o horário de entrada, o último registro, o banco de horas e as pendências de cada pessoa.
- Na mesma aba, o gestor troca a escala de trabalho de uma pessoa escolhendo uma das escalas da lista. A escala nova vale a partir de hoje e os dias anteriores continuam como estavam.
- As justificativas que aguardam análise aparecem numa lista na mesma aba, com a opção de marcar várias e abonar de uma vez. Cada uma passa pelas mesmas regras de hoje: o gestor que não é o administrador principal não abona a própria justificativa.
