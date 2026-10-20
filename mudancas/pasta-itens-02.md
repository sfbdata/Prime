---
tipo: novidade
---
## Changelog
- Modal Marcadores da pasta: a busca ganha a linha "Criar marcador" quando o texto não bate com nenhum marcador; cria pela rota `expediente_marcador_criar` (módulo Expediente e CSRF), e o novo marcador já entra marcado. A busca passa a ignorar acento.
- Aba Detalhes da pasta: card "Pontos do relatório inicial" (objetivos do cliente, acordos iniciais, resultado esperado), com selo e botão "Completar no relatório", calculado por `PontosDoRelatorioInicial` sobre todas as observações do Relatório inicial de Atendimento.
- O card se atualiza sozinho quando a pessoa envia, responde, edita ou exclui uma observação (rota GET `pasta_detalhes_pontos_relatorio`, mesma permissão de ver a pasta), e acende ou apaga a pendência da aba Detalhes.
- Linha vermelha da aba Detalhes quando falta algum ponto do relatório inicial (`PastaPendenciasOutput`); a mesma pendência entra no resumo impresso da pasta.
  Decisão do dono (T2, 20/10): a linha vermelha fica como no desenho, mesmo acendendo em cerca de 83% das pastas ativas.
- Sai da aba Detalhes o card "Como usar esta aba", que o desenho não tem.

## Para o cliente
- Agora dá para criar um marcador direto na janela Marcadores da pasta: digite o nome e clique em "Criar marcador".
- A aba Detalhes mostra quais pontos do relatório inicial já foram registrados e quais ainda faltam, e se atualiza assim que você registra uma observação.
