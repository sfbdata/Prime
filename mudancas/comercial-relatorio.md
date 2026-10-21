---
tipo: novidade
---
## Changelog
- Comercial (F3d): o bloco "Monitoramento" do Painel do Master vira o "Relatório como caixa de entrada" da Central de envios, como no desenho 05 de 21/10. Abas Enviadas, Agendadas e Recorrentes com a contagem, busca por campanha, público ou e-mail, páginas de 50 e seleção. Cada linha traz o selo do estado, o público, o nome, a leitura (✓ enviado, ✓ verde recebido, ✓✓ com o percentual de lidos, e os números no tooltip) e a data. O visualizador mostra os 4 indicadores (Destinatários, Enviados, Lidos, Taxa de leitura), o e-mail como sai (remontado da cópia guardada na campanha) e os destinatários com o estado do envio, com "Duplicar" (leva assunto e título ao editor) e "Cancelar envio" nas campanhas agendadas e recorrentes. Rotas `GET /master/comercial/relatorio`, `GET /master/comercial/campanhas/{id}` e `POST /master/comercial/campanhas/{id}/cancelar`, só para o Master.

