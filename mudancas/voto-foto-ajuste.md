---
tipo: novidade
---
## Changelog
- Voto, cadastro das candidaturas: ao escolher a foto 3x4, abre a janela "Ajustar a foto" (`_janela_ajustar_foto.html.twig`, `public/js/voto-foto-ajuste.js`, estilos `.vModFoto` em `voto-janela.css`). Dá para arrastar e enquadrar o rosto, aproximar (barra ou roda do mouse, de 1 a 3 vezes), girar 90° e mover com as setas do teclado. "Usar esta foto" troca o arquivo do campo por um JPEG 360x480 recortado no navegador; "Cancelar", Esc ou clique fora desfazem a escolha. Navegador sem suporte ou imagem ilegível seguem com o arquivo original, reduzido como antes. O servidor continua validando tipo e tamanho. Segue o modelo do editor da foto do cliente da pasta.

## Para o cliente
- Ao carregar a foto do candidato, agora você ajusta o enquadramento antes de salvar: arraste para centralizar o rosto, aproxime com a barra e gire se a foto veio deitada. A urna mostra a foto exatamente como ficou na moldura.
