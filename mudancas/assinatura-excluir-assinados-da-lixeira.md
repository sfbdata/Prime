---
tipo: alteracao
---
## Changelog
- Assinatura eletrônica, Documentos, Lixeira: "Excluir definitivamente" (individual e em massa) agora também apaga documentos enviados e assinados, não só rascunhos. Quando a seleção tem algum documento com assinatura, a tela pede uma confirmação avisando que as evidências (signatários, registros e versões do PDF) saem de vez. O servidor recusa (409) a exclusão de enviados sem a confirmação (`confirmar_enviados`). A cópia salva na pasta não é tocada.

## Para o cliente
- Na Lixeira da Assinatura eletrônica, agora você pode excluir de vez também os documentos que já foram enviados ou assinados. Antes de apagar, o sistema avisa que os registros da assinatura (quem assinou, o histórico e as versões do PDF) serão apagados para sempre, e só segue se você confirmar. A cópia do documento que foi salva na pasta do cliente continua lá.
