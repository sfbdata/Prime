---
tipo: novidade
---
## Changelog
- Editor de artigos dos blogs (Painel do Master): botão Inserir tabela (plugin `table` do TinyMCE, ícone bi-table, ao lado do Inserir símbolo). Fora da tabela abre a grade linhas × colunas; a tabela nasce com a primeira linha como cabeçalho (`thead > tr > th`). Dentro da tabela, a barra de contexto traz as ações da tabela da pasta (inserir linha acima/abaixo, coluna à esquerda/direita, remover linha, coluna e tabela) com os mesmos ícones e textos. Sem mesclar células, propriedades, largura, estilo nem atributo.
- `ListaBrancaDoArtigo` aceita a tabela só no nível de cima do corpo e sempre na mesma forma (`table > thead > tr > th` + `tbody > tr > td`, sem atributos); na célula só ficam texto, strong, em, a e br (bloco vira texto, imagem cai, tabela dentro de tabela vira texto).
- `HtmlDoArtigoParaMarkdown` exporta a tabela como tabela GFM (`| a | b |`, `| --- | --- |`, uma linha por linha), com `|` escapado, br virando espaço e as linhas completadas com células vazias; `MarkdownDoArtigoParaHtml` (importação) lê a tabela GFM e devolve o mesmo HTML, fechando a ida e volta.
- A tabela só fica solta no corpo: o botão fica desabilitado dentro de lista, citação, bloco BlueJus ou outra tabela, e a importação recusa, com mensagem, tabela dentro de diretiva, citação ou lista. Na importação, a tabela fecha em linha horizontal e no começo de outro bloco, e o parágrafo para antes da linha que começa uma tabela.
- Estilo simples da tabela (borda clara) no editor e na pré-visualização.

## Para o cliente
- No Painel do Master, o editor de artigos dos blogs ganhou o botão Inserir tabela: escolha quantas linhas e colunas, e a primeira linha já vem como cabeçalho.
- Com o cursor dentro da tabela, aparecem os botões para inserir ou remover linhas e colunas e para remover a tabela, iguais aos do editor da pasta.
- A tabela vai para o artigo publicado no blog do jeito que foi escrita.
