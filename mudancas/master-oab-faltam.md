---
tipo: novidade
---
## Changelog
- Painel do Master, mapa "Onde estamos atuando" (desenho 05, Perfis 1.2, VS 21.10.26, L118, L140 e L481-495; spec `docs/specs/master-oab-estados-que-faltam.md`): no Brasil, cada estado da lista "Estados que faltam" e cada linha da tabela de UFs abre a janela da OAB daquela UF. O item mostra o ícone quando há nota e, no lugar do "0", "N subseções" ou "sem endereço". No Mundo, o clique avisa que a janela vale só para os estados do Brasil.
- Janela da OAB: "SECCIONAL · SEDE" com a sede da seccional, "Editar" (o mesmo "Editar sede"), "Ver no mapa" (Google Maps, só com endereço) e "Site oficial"; "SUBSEÇÕES" com "Adicionar endereço" (nome, endereço e telefone) e "Remover" com confirmação; "NOTA EXPLICATIVA" (uma por UF, compartilhada entre os Masters, até 1000 caracteres, com a data da última gravação). Nota vazia apaga.
- Rotas novas `POST /master/seccionais/{uf}/subsecoes`, `POST /master/seccionais/{uf}/subsecoes/{id}/remover` e `POST /master/seccionais/{uf}/nota` (só Master, CSRF `master_seccionais` no header, auditoria de plataforma na trilha do Master). O `GET /master/dados/seccionais` passa a trazer as subseções e a nota de cada UF. Migration `Version20261020148800`: tabelas `master_subsecao_oab` e `master_nota_seccional`.
