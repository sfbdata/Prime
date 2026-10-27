---
tipo: novidade
---
## Changelog
- Painel, BlueJus Intelligence: com a meta de pastas do mês definida e o painel no mês inteiro (sem responsável nem cargo), o painel ganha o selo do ritmo (Ritmo adequado, de atenção, abaixo do necessário ou crítico), o gauge de quatro faixas, o "Ritmo atual" e o "Ritmo necessário", contados em dias úteis. Sem meta ou em outro recorte, o bloco não aparece.
- Painel, Central de Inteligência: cada alerta ganha os botões Resolvido, Ignorar, Aplicar e Não se aplica. A marca é pessoal (cada usuário vê a sua), fica gravada no servidor (chave `dashboard.ia_alertas` em `preferencia_usuario`, sem migration), esmaece o alerta e o leva para o fim da lista; tocar de novo desfaz. Novo endpoint `POST /dashboard/inteligencia/alerta`.
- Perguntas guiadas: abre uma resposta por vez.
- Fora desta entrega, de propósito: "Criar tarefa" (não existe tela de tarefa avulsa para receber o título) e "simular dia" (controle de teste do protótipo).

## Para o cliente
- No Painel, o botão BlueJus Intelligence mostra, quando a meta de pastas do mês está definida, se o escritório está no ritmo para bater a meta e quantas pastas por dia útil faltam.
- Na Central de Inteligência, cada alerta pode ser marcado como Resolvido ou Ignorado e a recomendação pode ser avaliada com Aplicar ou Não se aplica. A marca vale só para você.
