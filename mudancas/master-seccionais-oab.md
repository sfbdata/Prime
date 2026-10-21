---
tipo: novidade
---
## Changelog
- Painel do Master, mapa "Onde estamos atuando" (desenho 05, Perfis 1.2, VS 21.10.26, F8; spec `docs/specs/master-seccionais-oab.md`): bloco "SECCIONAIS VISÍVEIS" embaixo da tabela de UFs no Brasil + Pessoas, com os 27 chips (clique abre o cartão da sede; botão direito mostra ou oculta a seccional no mapa, guardado no navegador), "Mostrar todas" / "Ocultar todas" e ponto colorido pela última divulgação.
- Cartão da sede com usuários inscritos na seccional (inscrição principal e suplementar, uma vez por pessoa, só com vínculo ativo), endereço, telefone, site, link oficial do CFOAB, "Editar sede" e as 5 últimas divulgações. Sem endereço cadastrado, o cartão diz "Endereço não cadastrado. Confira no CFOAB.".
- Mapa do Brasil: marcador da seccional no centro de cada estado e "Registrar divulgação" (Material enviado, Reunião, Parceria, com nota e data) no menu do botão direito, acima das cores.
- Rotas novas `GET /master/dados/seccionais`, `POST /master/seccionais/{uf}/sede` e `POST /master/seccionais/{uf}/divulgacoes` (só Master, CSRF `master_seccionais` no header, auditoria de plataforma). Migration `Version20261020148400`: tabelas `master_seccional_oab` (carga das 27 UFs, só UF e nome) e `master_divulgacao_seccional`.
