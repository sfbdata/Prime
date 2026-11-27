---
tipo: correcao
---
## Changelog
- A reconciliação noturna da cópia de segurança (`armazenamento:reconciliar-espelho`) agora repete até 2 vezes a conferência de um arquivo que deu erro (pausa de 2 s e 5 s) antes de contá-lo como `erro_de_consulta`. Só o erro que persiste nas 3 tentativas falha a rodada e dispara o aviso ao Master. Arquivo ausente e chave inválida não repetem.

## Para o cliente
- A conferência noturna da cópia de segurança dos seus documentos não acusa mais falha por uma instabilidade passageira de poucos segundos. O aviso só aparece quando o problema continua depois de novas tentativas.
