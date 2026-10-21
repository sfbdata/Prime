---
tipo: novidade
---
## Changelog
- Trabalho Externo: currículos e banco de talentos (F3a, spec §4.5). O usuário externo (Solicitante ou Convidado) cadastra "Meu currículo", um por usuário, com consentimento LGPD (data e versão do texto), percentual de preenchimento e "Complete: ..." calculados no servidor.
- Arquivos do currículo (foto, carteira da OAB, comprovante do curso, currículo): tipo conferido pelo conteúdo, até 1,5 MB (foto até 5 MB, regravada em JPG 256x256), só depois do primeiro salvar. Download sempre pela rota, com a permissão, como anexo (a foto abre na tela) e `nosniff`. Categoria de arquivo nova `trabalho_externo_curriculo`, escopo global, em `trabalho-externo/curriculos/`.
- Banco de talentos da Central: lista com importância, marcações pessoais (estrela, lixeira, visto), situação no banco (Novo, Em análise, Entrevista, Favorito, Arquivado) e nota interna, que nunca sai para o dono. Mudar situação, escrever nota e exportar CSV exigem a chave `talentos`; excluir o currículo de outra pessoa é só do Master.
- Currículo formatado em HTML (pré-visualização, inclusive de dados ainda não salvos) e em PDF pelo Dompdf. CSV `curriculos-bluejus.csv` com as colunas do desenho, da lista filtrada.
- Rotas novas em `/trabalho-externo/api/curriculos` e `/trabalho-externo/curriculos/...`. Migration `Version20261019213000`: tabela `trabalho_externo_curriculo`.

## Para o cliente
- Agora quem procura vaga pode cadastrar o currículo uma vez, com foto e documentos, e baixá-lo formatado em PDF. A Central passa a ter um banco de talentos com filtros, situação de cada currículo, nota interna e exportação para planilha.
