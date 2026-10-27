# Push: Diário da OAB com registro manual (desenho 11)

Risco MÉDIO (tabela nova, dado sigiloso do advogado). Uma migration. Captura automática do DEOAB fica para depois (sem fonte contratada); esta frente entrega o registro manual.

## Situação hoje

A aba `/push-processual/diario` é uma casca: busca, filtros, "Selecionar" e "Limpar lidas" sem ação, lista sempre vazia, badge "0 novas" fixo. Não existe entidade, tabela, UseCase nem rota de gravação.

## O que o desenho define

Só leitura: o desenho 11 não tem botão nem formulário para registrar (os 3 itens são seed). Define: busca "Processo, ato ou texto"; filtros Todas / Ético-disciplinares / Comunicados; colunas DATA, SECCIONAL, ATO, ÓRGÃO, PRAZO; barra de seleção (Marcar como lidas, Excluir com confirmação e digitar EXCLUIR, Limpar lidas); linha expansível com o texto (marca como lida); pílula "Pasta N" ou "Avulsa"; vincular e desvincular pasta; "Vence em N d" e meta com o prazo; aviso de sigilo (art. 72, Lei 8.906/1994).

## Decisão (a confirmar com o dono)

Registro manual exige uma tela que o desenho não traz. Adoto um botão "Registrar publicação" no topo da aba e um modal no estilo dos modais do desenho 11 (mesmo cabeçalho azul #0a6fa0, campos de 38px). É desvio por acréscimo; some quando houver captura automática.

## Modelo

`PublicacaoDoDiarioDaOab` (tabela `djen_diario_oab`), por escritório e por usuário (só quem registrou vê: "Só você vê esta aba"):
- tenant, usuario, dataPublicacao (date), seccional (string 20, ex. OAB/DF, CFOAB), tipo (enum `TipoDeAtoDoDiario`: EticoDisciplinar, Comunicado), ato (255), numeroProcesso (nullable, 50), orgao (255), prazoDias (nullable smallint; 15 é o padrão do desenho para ético-disciplinar), texto (text), lidaEm, excluidaEm, processo (nullable, ManyToOne), criadoEm.
- Índice (tenant_id, usuario_id, excluida_em, data_publicacao).

## Entregas (um PR cada)

1. Base: migration, entidade, enum, repository, `RegistrarPublicacaoDoDiarioUseCase` (valida data, ato, órgão, tipo, seccional), `ListarDiarioDaOabUseCase` (filtro por tipo e busca, ordem data desc), rota POST de registro, modal, lista com linha expansível e badge de não lidas na aba. Testes: unit do use case, functional do controller e isolamento por usuário e tenant.
2. Seleção e limpeza: Marcar como lidas, Excluir (EXCLUIR), Limpar lidas, expandir marca lida.
3. Pasta e meta: vincular/desvincular por processo (reaproveita `VincularProcessoUseCase`), prazo "Vence em N d" (15 dias úteis a partir do 1º dia útil seguinte, `PrazoDaPublicacaoCalculador`) e criar meta com o prazo (`bi-bullseye`).

## Migration

Uma, só na entrega 1 (número pedido ao Coordenador: próxima livre 154200).

## Fora do escopo

Captura automática do DEOAB, notificações, e-mail.
