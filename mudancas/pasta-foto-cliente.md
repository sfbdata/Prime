---
tipo: novidade
---
## Changelog
- Pasta, cabeçalho: foto do cliente (desenho 02 VS 18.10.26). O círculo do cliente vira botão para quem edita o cliente, com o menu "Carregar foto" / "Carregar nova foto", "Ajustar foto" e "Remover foto" e o editor de recorte do desenho (arrastar, zoom de 1 a 3, girar 90°); o navegador salva um JPEG 320x320. Quem só vê o cliente vê a foto sem menu; pasta excluída ou só de visualizar não deixa trocar.
- Migration `Version20261020151200`: colunas `cliente.foto` e `cliente.foto_atualizada_em`. Arquivo na categoria nova `cliente_foto` (`clientes_uploads_dir/fotos`), entregue por `cliente_foto` só a quem vê o cliente; a foto antiga sai do disco depois do commit; a purga do escritório apaga as fotos.
- Rotas `pasta_cliente_foto_trocar` e `pasta_cliente_foto_remover` (JSON, CSRF, editar cliente + escritório + cliente da pasta). A troca e a remoção ficam registradas no histórico da pasta e no do cliente.

## Para o cliente
- No cabeçalho da pasta, clique no círculo do cliente para carregar, ajustar ou remover a foto dele. A foto vale para todas as pastas do cliente.
