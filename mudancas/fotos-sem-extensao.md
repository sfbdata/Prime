---
tipo: correcao
---
## Changelog
- Pasta e Cliente: a foto do cabeçalho da pasta (do cliente ou da própria pasta) podia aparecer quebrada em produção. Causa: a URL terminava em `.jpg`/`.png` e o nginx de produção responde 404 sozinho a todo caminho com extensão de imagem (regra de arquivos estáticos), sem passar pelo PHP. As rotas `pasta_foto` (`/pasta/{id}/foto/{nome}`) e `cliente_foto` (`/clientes/{id}/foto/{nome}`) passam a receber o nome SEM a extensão; a entrega confere o nome guardado sem a extensão (`pathinfo` FILENAME) e manda o arquivo com o nome completo. Links atualizados no cabeçalho da pasta (`_cabecalho_foto_cliente.html.twig`) e na resposta JSON de trocar/remover (`FotoDoCabecalhoDaPasta`). Mesmo conserto do #811 (foto da candidatura no Voto). `/perfil/foto/` não muda: já tem exceção própria no nginx.

## Para o cliente
- A foto do cliente e a foto da pasta voltam a aparecer no topo da pasta. Quem já enviou a foto não precisa enviar de novo.
