---
tipo: novidade
---
## Changelog
- Painel BlueJus Intelligence, perfil individual: botão "Criar acompanhamento" com confirmação (prazo de revisão de 24 horas, 48 horas ou 7 dias), chip "Em acompanhamento" (ou "Revisar acompanhamento" quando o prazo passa), "Renovar prazo" e "Encerrar acompanhamento". Só o Master e os usuários primários criam, renovam e encerram; nada é criado sozinho e a pessoa acompanhada não recebe aviso. Nova tabela `dashboard_acompanhamento_pessoa` (sem texto livre) e auditoria `acomp_cria`, `acomp_renov` e `acomp_fim`.

## Para o cliente
- No perfil de cada pessoa do Painel, o gestor pode marcar que vai acompanhar o trabalho dela por alguns dias e depois renovar ou encerrar. É só um lembrete de gestão: não cria nota, não muda as metas e a pessoa não é avisada.
