---
tipo: interno
---
## Changelog
- BlueJus IA: retenção de 12 meses (padrão adotado em 24/10/2026, o dono pode rever; `docs/specs/ia-anthropic-ligar.md` §10). Tarefa agendada nova `app:inteligencia:expirar-conteudo` (todo dia às 00:20, exige `--force`) apaga resumo, pontos, textos e edições das análises terminadas há mais de 12 meses, zera o texto das perguntas da conversa e tira a análise das telas (`excluida_em`). Ficam modelo, tokens e datas para o custo e a auditoria. Análise pendente ou em processamento nunca é tocada.
