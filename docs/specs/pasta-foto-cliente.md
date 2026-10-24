# Pasta: foto do cliente no cabeçalho

Risco: BAIXO (cabeçalho da pasta, arquivo novo por categoria). Desenho: `02 - EXPEDIENTES - VS 18.10.26.dc.html`
(botão L1255, foto L3853, menu L472-482, editor L483-505, `fotoVals` L3845-3884).

## Entrega 1 (PR #345)

A foto fica no CLIENTE (`cliente.foto`, categoria `cliente_foto`) e vale para todas as pastas dele. Rotas
`pasta_cliente_foto_trocar`, `pasta_cliente_foto_remover` (JSON, CSRF `cliente_foto_<id>`, editar o cliente,
escritório, cliente da pasta, lápide e cadeado) e `cliente_foto` (entrega a quem vê o cliente). Registro da pasta
("Cadastro do cliente: Foto do cliente alterada/removida") e Histórico do cadastro. Pasta sem cliente cadastrado
ficava com o ícone inerte.

## Entrega 2 (fix-foto-cliente-clique, 24/10/2026)

**Defeito:** no 8080 o dono clicou no avatar e nada aconteceu. Causa provada: a pasta não tinha cliente cadastrado
(no `saas_ux`, só 1 de 1056 pastas ativas do escritório 1 tem cliente), e o #345 desenhava um `span` inerte nesse
caso. O desenho mostra o botão sempre ativo (`ft.abrirMenu`) e guarda a foto por pasta (`fotoChave`, L3845).

**Decisão do dono (via coordenador):** o botão sempre abre o menu "Carregar foto", como no desenho.

- Pasta SEM cliente cadastrado: a foto fica na própria pasta (`pasta.foto`, `pasta.foto_atualizada_em`, migration
  `Version20261020152000`), categoria `pasta_foto` (`uploads_dir/fotos`, plana, escopo do escritório da pasta,
  `ChavesDePasta::foto/fotoPorNome/novaFoto`). Fora da cota, como a do cliente.
- Pasta COM cliente: continua guardando no cliente (rotas do #345). A rota da pasta recusa a troca (403).
- Exibição: a foto do cliente, se ele tem foto e quem olha o vê; senão a da pasta; senão o ícone.
- **Cliente vinculado depois:** a foto da pasta não é apagada nem copiada. Ela continua aparecendo até o cliente
  ter foto própria; aí a do cliente vence. Removida a foto do cliente, a da pasta volta a aparecer. "Remover foto"
  vai para o dono da foto mostrada (`data-origem`): a da pasta exige editar a pasta (sem permissão, o item some).
- Rotas novas: `pasta_foto_trocar` (POST `/pasta/{id}/foto`), `pasta_foto_remover` (POST `/pasta/{id}/foto/remover`),
  `pasta_foto` (GET `/pasta/{id}/foto/{nome}`). CSRF `pasta_foto_<id>`; `#[PastaPorId]` (lápide e cadeado pela rota);
  guardas em `PastaParaFoto`: escritório (404), ver a pasta, EDITAR a pasta (`pasta` + `edit`, o cadeado em
  Visualizar recusa), lápide. Entrega só a quem vê a pasta, só o nome que ela guarda; outro escritório é 404.
- Mesma regra de imagem do #345 (JPEG, PNG ou WebP pelo conteúdo, até 3 MB; `TrocarFotoDoClienteUseCase::exigirImagemValida`).
  Arquivo anterior sai só depois do COMMIT (`TransacaoComArquivoNovo` + `RemocaoAposTransacao`).
- Registro da pasta: "Dados da pasta: Foto do cliente alterada/removida" (sem cliente não há Histórico de cadastro).
- Purga do escritório: `ARQUIVOS_POR_REGISTRO['pasta']`, com a prova de pertencimento das categorias planas.
- As respostas JSON das duas rotas passaram a `{ok, url, origem}`: a foto que o círculo mostra depois da ação
  (`FotoDoCabecalhoDaPasta`).
