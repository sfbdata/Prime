---
tipo: novidade
---
## Changelog
- Painel do Escritório: meta de pastas do mês editável no card "Pastas criadas", como no desenho VS 25.10.26: barra de progresso, traço do ritmo esperado, "X de Y no mês" e status (No ritmo, Abaixo do ritmo, Meta batida). Sem meta, o primário vê o botão "Definir meta do mês".
- Só o usuário primário define, altera ou remove a meta (lápis do card ou botão direito no card: "Alterar meta do mês / Ver histórico"); os demais veem a meta sem o botão, e a rota recusa com 403. A meta vale do mês em que foi definida em diante, até ser trocada; remover encerra a meta a partir do mês corrente.
- O ritmo esperado conta dias úteis do mês (fim de semana, feriados nacionais e recesso), decisão provisória: o dono pode rever.
- Sem migration: usa a tabela `dashboard_meta_pastas` do PR3. Rotas novas `dashboard_meta_pastas_definir` e `dashboard_meta_pastas_remover` (POST, CSRF).

## Para o cliente
- No Painel, o card "Pastas criadas" agora mostra a meta de pastas do mês, quanto já foi feito e se o escritório está no ritmo. O usuário primário define e altera a meta; os outros acompanham.
