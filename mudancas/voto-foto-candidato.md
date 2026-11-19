---
tipo: correcao
---
## Changelog
- Voto: a foto da candidatura aparecia quebrada na lista "Candidaturas", na prévia da urna e no formulário da pauta. Causa: a URL da foto terminava em `.jpg`/`.png` e o nginx de produção responde 404 sozinho a todo caminho com extensão de imagem (regra de arquivos estáticos), sem passar pelo PHP. A rota `voto_assembleia_foto_candidatura` passa a receber o nome da foto sem a extensão (`/voto/assembleias/{id}/fotos/{32 hex}`); o repositório procura a opção da assembleia entre as extensões aceitas (jpg, jpeg, png, webp). A guarda continua igual: o nome só vale se uma opção dessa assembleia o tem. A rota da urna do eleitor (`/votar/{token}/foto/{opcao}`) não mudou.

## Para o cliente
- A foto do candidato volta a aparecer na lista de candidaturas e na tela do voto. Quem já cadastrou a foto não precisa enviar de novo.
