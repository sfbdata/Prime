---
tipo: seguranca
---
## Changelog
- Processo: no Novo/Editar processo, os dados trazidos pela busca no DataJud (nome, documento e papel das partes; data, descrição, tipo e órgão das movimentações; nome e código dos assuntos) e a mensagem de erro da busca passam por escape antes de entrar na página. Um texto com HTML ou script vindo do tribunal aparece como texto e não executa nada.

## Para o cliente
- Mais segurança ao buscar um processo no DataJud: o que vem do tribunal é sempre mostrado como texto.
