---
tipo: correcao
---
## Changelog
- Pasta, cabeçalho: o círculo do cliente abre o menu "Carregar foto" também na pasta sem cliente cadastrado (desenho 02 VS 18.10.26); antes ficava inerte. A foto fica na própria pasta (migration `Version20261020152000`: `pasta.foto` e `pasta.foto_atualizada_em`, categoria `pasta_foto` em `uploads_dir/fotos`), com as mesmas regras da foto do cliente: editar a pasta, escritório, CSRF, lápide e cadeado, imagem até 3 MB, a anterior apagada depois do commit, registro na pasta e purga do escritório.
- Com cliente vinculado depois, a foto da pasta continua aparecendo até o cliente ter foto própria. Rotas novas `pasta_foto_trocar`, `pasta_foto_remover` e `pasta_foto`; as respostas das rotas da foto passam a dizer a origem da foto mostrada.

## Para o cliente
- Na pasta sem cliente cadastrado, clique no círculo ao lado do nome para carregar, ajustar ou remover a foto. Quando um cliente for vinculado, a foto continua até ele ganhar uma foto própria.
