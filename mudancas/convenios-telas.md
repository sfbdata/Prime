---
tipo: novidade
---
## Changelog
- Convênios, PR 4 da spec `docs/specs/convenios.md`: lista de convênios no painel do Master (link "Convênios" ao lado de "Convênio OAB por estado", em Planos e limites), com criar, editar, ligar, desligar com aviso prévio e desligar por instituição.
- Rotas `master_convenio_lista`, `master_convenio_salvar`, `master_convenio_ligar` e `master_convenio_instituicao_desligar` (só o Master, CSRF por header).
- Convênio novo é por código de convite (8 letras e números, sem 0, O, 1 e I), com "Gerar outro".
- Desligar marca o fim: 30 dias de aviso com benefício real; gratuidade pode desligar na hora. E-mail aos primários dos escritórios vinculados, dentro da transação (falha desfaz). Religar antes do dia cancela o fim e avisa "o convênio continua".
- A tarefa diária desliga o convênio no dia do fim e fecha os vínculos com `convenio_desligado`.
- Trilha: convênio e vínculo ganham rótulos; o aviso gravado no vínculo fica na Trilha do escritório.

## Para o cliente
- O BlueJus passa a ter convênios com outras instituições além da OAB, cadastrados pela equipe do BlueJus.
- Se um convênio da sua conta for terminar, os usuários principais do escritório recebem um e-mail com a data do fim, com pelo menos 30 dias de antecedência quando o convênio dá desconto ou espaço a mais. Nenhum dado é apagado e o plano continua o mesmo. Se o fim for cancelado, chega um e-mail avisando que o convênio continua.
