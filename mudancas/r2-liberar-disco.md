---
tipo: alteracao
---
## Changelog
- Espelho R2 (etapa C, sem esvaziar): `armazenamento:liberar-disco --categoria=pasta_documento [--frio-dias=90] [--lote=200] [--sem-acesso] [--executar --confirmo-reconciliacao-limpa]`. Simula por padrão. Na execução, cada arquivo frio já provado no R2 é baixado e conferido por SHA, ganha a linha em `armazenamento_liberacao` e sai do lugar para `uploads/pastas/.liberados/<lote>/`, sem apagar nada. Manifesto em `var/liberacao/<lote>.tsv`.
- `armazenamento:restaurar-lote (--lote=ID | --chave=CHAVE) [--executar] [--baixar-para=PASTA]`: devolve os arquivos ao lugar (dos liberados ou baixando do R2), nunca sobrescreve e fecha a linha. `--baixar-para` só confere o R2 numa pasta do operador.
- Exclusão de um arquivo liberado (pelo usuário ou pela purga) apaga também a cópia em `.liberados` e registra a exclusão para o R2. Documento na lixeira nunca é liberado. Os dois comandos têm trava de execução única.
- O esvaziamento dos liberados continua bloqueado. Nenhum comando novo roda sozinho: nada entra no agendador.

## Para o cliente
- Documentos antigos, que ninguém abre há meses, passam a poder ficar guardados só na nuvem e continuam abrindo normalmente pelo sistema. O primeiro acesso a um deles pode levar alguns segundos a mais.
- Quando um documento é apagado de vez, a cópia guardada também é apagada.
