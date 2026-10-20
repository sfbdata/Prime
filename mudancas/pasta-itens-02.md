---
tipo: novidade
---
## Changelog
- Modal Marcadores da pasta: a busca ganha a linha "Criar marcador" quando o texto não bate com nenhum marcador; cria pela rota `expediente_marcador_criar` (módulo Expediente e CSRF), e o novo marcador já entra marcado. A busca passa a ignorar acento.
- Aba Detalhes da pasta: card "Pontos do relatório inicial" (objetivos do cliente, acordos iniciais, resultado esperado), com selo e botão "Completar no relatório", calculado por `PontosDoRelatorioInicial` sobre as observações do Relatório inicial de Atendimento.
- Linha vermelha da aba Detalhes quando falta algum ponto do relatório inicial (`PastaPendenciasOutput`).

## Para o cliente
- Agora dá para criar um marcador direto na janela Marcadores da pasta: digite o nome e clique em "Criar marcador".
- A aba Detalhes mostra quais pontos do relatório inicial já foram registrados e quais ainda faltam.
