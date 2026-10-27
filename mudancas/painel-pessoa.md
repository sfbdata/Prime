---
tipo: novidade
---
## Changelog
- Painel do Escritório: cada linha da tabela Desempenho ganha o botão "Análise de desempenho de {nome}". Ele abre o painel BlueJus Intelligence no modo individual, com "← Equipe", o nome da pessoa no título e o perfil dela, por regras fixas (sem IA): Recebidas, Concluídas, Pendentes, Atrasadas, Prazos próximos e Taxa de conclusão, comparação com o período anterior, diagnóstico com a tag "Observado" e "Por que chegou a essa conclusão".
- Os números saem do mesmo cálculo da tabela (cadeado e "Zerar" já aplicados) e levam às tarefas da pessoa quando a lista conta o mesmo número. O que o sistema não registra (prazos cumpridos, situação, causa, plano de ação) não aparece.
- Só o Master ou um primário ativo abre a análise; quem não pode vê o botão bloqueado ("Seu perfil não pode abrir análises individuais"). "Marcar como analisado" e "Essa análise ajudou?" guardam a marca de cada usuário; cada visualização e cada marca entram no registro de auditoria (`ia_pessoa`, `ia_marca`). Novas rotas `GET /dashboard/inteligencia/pessoa/{id}` e `POST /dashboard/inteligencia/pessoa/{id}/marca`. Sem migration.

## Para o cliente
- No Painel do Escritório, o gestor do escritório abre a análise de desempenho de cada pessoa pelo botão da estrela na linha dela. A leitura é feita por regras fixas sobre os números do período e mostra o que está acontecendo, sem classificar a pessoa nem estimar o que o sistema não registra.
