---
tipo: novidade
---
## Changelog
- Central de envios, modo simples (decisão do dono 21/10, F3i (a); spec comercial-e-emails §12; dc 05 L274, L596, L659): "Adicionar imagem" / "Trocar imagem" e "Remover". O navegador reduz a 1080 px em JPEG, como o desenho; o servidor (`POST /master/comercial/imagens`, só o Master, CSRF) aceita só JPG ou PNG pelo conteúdo, até 1 MB e 4000 px, e regrava sem EXIF.
- A imagem fica no BlueJus (tabela `comercial_imagem`, migration `Version20261020149700`, categoria `COMERCIAL_IMAGEM`) e o e-mail a busca em `GET /e/i/{token}.{jpg|png}`, público, sem sessão, com `nosniff` e `noindex`. A mensagem guarda só o token; URL e `data:` são recusados.
- Validade: 30 dias sem uso; 1 ano a partir de cada envio (inclusive cada execução da recorrente) ou de cada modelo salvo. O `app:purgar-dados-expirados` apaga a linha e, depois do COMMIT, o arquivo.
- nginx de produção: `location ^~ /e/` manda as rotas dos e-mails (pixel `.gif`, imagem, clique, confirmação) ao PHP antes do regex de imagens estáticas, que respondia 404. Um teste guarda a ordem.

## Para o cliente
- Nada muda para o escritório. Os e-mails do BlueJus podem trazer uma imagem.
