---
tipo: novidade
---
## Changelog
- BlueJus Voto, fase A1b (docs/specs/bluejus-voto.md): liberação por escritório pelo Master, no molde da liberação da I.A. Nova aba "Voto" no Painel do Master (`/master/voto`, `master_voto_dados` e `master_voto_escritorio`, token `master_voto`) com a lista dos escritórios ativos e a chave "Liberado" por linha.
- Trava nova no Voto: além da permissão `modules.voto.view` e da chave `voto` do catálogo, o escritório precisa estar liberado (`LiberacaoDoVotoNoEscritorio`, Twig `voto_liberado()` no menu lateral); o Master passa sempre. Liberar e bloquear ficam no audit_log do escritório alterado.
- Migration Version20261109140000: cria o schema `voto` (primeiro fora do `public`) e a tabela `voto.liberacao`, com a FK do escritório em ON DELETE CASCADE.
- Spec do Voto: decisões da D8 (correções do desenho) registradas item por item.

## Para o cliente
- No Painel do Master, a nova aba "Voto" mostra os escritórios e permite liberar o BlueJus Voto para cada um. O Voto continua em preparação e ainda não aparece para os escritórios.
