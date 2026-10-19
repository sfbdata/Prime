---
tipo: correcao
---
## Changelog
- Auditoria: compromisso "Só eu" da Agenda não expõe mais título, descrição e local no registro de auditoria (antes e depois, inclusive na troca de visibilidade nos dois sentidos); o registro mantém a ação, quem e quando, e o rótulo vira "Compromisso particular". Contrato `App\Shared\Contract\ConteudoParticular`, implementado pelo `Evento`. A tela antiga da Agenda não avisa mais os participantes de um "Só eu" (eles não o veem, e o aviso levava o título à auditoria pela Notificacao). Registros de auditoria já gravados não mudam.

## Para o cliente
- Compromissos marcados como "Só eu" na Agenda agora ficam particulares também na Auditoria: quem administra vê que houve uma alteração, mas não o conteúdo do compromisso. Vale para as alterações feitas a partir desta versão.
