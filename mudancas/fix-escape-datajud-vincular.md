---
tipo: seguranca
---
## Changelog
- Janela de vincular processo ("Buscar no CNJ"): os dados que vêm do Datajud (nome, documento e papel das partes; data, descrição, tipo e órgão das movimentações) passam a ser escapados antes de entrar no HTML das linhas. Antes, um texto com `"><script>` vindo da base externa virava HTML na tela.
