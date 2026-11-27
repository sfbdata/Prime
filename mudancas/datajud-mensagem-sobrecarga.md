---
tipo: correcao
---
## Changelog
- Mensagem do DataJud para a resposta HTTP 429 (LimiteExcedido) agora informa que o serviço do CNJ está sobrecarregado, em vez de sugerir excesso de consultas do usuário.

## Para o cliente
- Quando o CNJ está sobrecarregado e não responde à consulta de processos, o aviso agora diz isso com clareza: "O serviço do CNJ está sobrecarregado no momento. Tente de novo em alguns minutos." Antes, o texto dava a entender que você tinha feito consultas demais.
