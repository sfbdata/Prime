---
tipo: novidade
---
## Changelog
- Pasta, menu ⋮: item "Acompanhar alterações" na seção Preferências, antes de "Fixar nos favoritos", como no desenho 02 EXPEDIENTES VS 18.10.26. Padrão ligado para quem vê a pasta; desligar grava a exceção em `pasta_acompanhamento_desligado` (migration `Version20261020151100`). Rota `pasta_acompanhamento_alternar` (POST, CSRF, guardas de escritório e de VER; passa no cadeado em Visualizar, recusada em Oculto e na pasta excluída).
- Mensagem nova no Registro da pasta avisa quem acompanha (tipo `pasta_acompanhada`, no sino e na Caixa da Central), menos o autor, o respondido e os mencionados; no máximo um aviso não lido por pasta e pessoa. Os demais eventos da pasta ficam para depois.

## Para o cliente
- Na pasta, o menu ⋮ ganhou "Acompanhar alterações": quem acompanha recebe no Chat I.A um aviso quando chega mensagem nova no Registro da pasta. Vem ligado; dá para desligar pasta a pasta.
