---
tipo: novidade
---
## Changelog
- Painel do Master, aba "Configurações da I.A.": bloco novo "Questões jurídicas". Cada questão mostra problema, risco, opções, sugestão e fontes. O conteúdo vem do catálogo em código (`config/inteligencia/questoes_juridicas.json`, lido por `CatalogoDeQuestoesJuridicas`).
- Download da versão atual de cada documento: Política de Privacidade, Termos de Uso, rascunho da política da IA (cópias em `app/resources/legal/`, porque `docs/` não vai para a imagem de produção) e o texto do aceite da IA, gerado na hora em .txt com os mesmos textos da tela do aceite.
- Upload da versão ajustada de cada documento (.docx, .doc, .pdf, .md, .txt e .odt, até 20 MB, com o tipo conferido pelo conteúdo), com histórico: quem enviou, quando, nome original, documento e questão. O download das versões enviadas também vale. Arquivos na categoria nova `inteligencia_documento_juridico` (escopo global, `public/uploads/documentos-juridicos`).
- Status de cada questão (pendente, em análise, resolvida), alterável pelo Master, com histórico de quem mudou e quando, e auditoria no escopo de plataforma.
- Aviso no topo das telas do Master, "Questões jurídicas a serem verificadas", enquanto houver questão não resolvida, com o link para o bloco.
- Tabelas `inteligencia_questao_juridica`, `inteligencia_questao_juridica_historico` e `inteligencia_documento_juridico_versao` na migration `Version20261020151900`. Rotas `master_ia_juridico_*`, só para o Master.

## Para o cliente
- Nada visível para os escritórios.
