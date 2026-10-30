---
tipo: alteracao
---
## Changelog
- Expediente no desenho 03 (VS 30.10.26): linhas da tabela zebradas (#ffffff e #eef3f7, borda #eef2f5); hover azul e destaque de linha continuam por cima. Tema escuro com zebra suave.
- Pasta arquivada esmaecida (opacidade .62, cinza .35) com a dica "Pasta arquivada".
- Modal Nova Pasta do Expediente: botão "Importar em lote" à esquerda do rodapé (só com o módulo Pastas). O clique fecha a Nova Pasta e, depois de fechada, dispara `bj:importar-lote` no document; o modal do lote abre por esse evento.
- Nada sai da tela; sem migration.
