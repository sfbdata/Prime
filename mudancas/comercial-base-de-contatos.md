---
tipo: novidade
---
## Changelog
- Painel do Master, Comercial: nova "Base de contatos" (tabela `comercial_contato`, migration `Version20261125150000`), aberta pelo botão "Base de contatos" da Central de envios. Lista de 20 em 20 com e-mail, nome, origem (Importação ou Manual), situação (Ativo ou Descadastrado, derivada do descadastro existente, sem coluna própria), último convite e data de criação.
- Busca no servidor por e-mail ou nome, sem acento e sem diferenciar maiúsculas (mínimo 2 letras). Incluir contato, editar o nome, excluir um ou vários (confirmação na janela do painel) e importar CSV (colunas `email[,nome]`, mesmos limites da lista informada: 5.000 linhas e 1 MB). A importação responde "N novos, M atualizados, K inválidos"; repetido não duplica e só troca o nome se a linha trouxer um.
- Exportar CSV (e-mail, nome, origem, situação, último convite, criado em), respeitando a busca atual.
- Rotas em `/master/comercial/base-de-contatos`, só `ROLE_SUPER_ADMIN`, com token CSRF `master_comercial` nas escritas. Cada escrita deixa uma linha no `audit_log` (`ctt_inc`, `ctt_nome`, `ctt_exc`, `ctt_imp`).
- Esta entrega não usa a base como público das campanhas nem preenche `ultimo_convite_em` (vem no próximo PR).

## Para o cliente
- Na área de envio de e-mails da equipe do BlueJus, agora existe uma lista de contatos salva. A equipe inclui pessoas uma a uma, importa uma planilha, corrige nomes, apaga contatos e baixa a lista completa. Quem pediu para não receber e-mails aparece marcado como Descadastrado. Esta lista ainda não é usada nos envios; isso vem na próxima etapa.
