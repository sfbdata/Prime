# Peticionar (desenho 07): P1, P2 e fatias F1..F9

**Risco:** BAIXO (tela; rotas que já existem) · **Frente:** `peticionar-desenho-07` (sessão 9b) · **Data:** 20/10/2026

## Estado da frente

- 20/10: aprovada pelo coordenador depois da conferência dos desenhos sem sessão. A partir do master, sem migration.
- Não tocar em `PeticionarController.php` (#68), `layout_peticionar.html.twig` (#56) nem `PecaImagemController.php` (#120);
  se for inevitável, avisar o coordenador antes.

## Escopo

Desenho: `docs/design/redesign-19-10-26/07 - Peticionar 1.0.dc.html`. Tela: `/pasta/{id}/peticionar` (`templates/pasta/peticionar.html.twig`).

- **P1**
  - "Voltar à pasta" no cabeçalho (L38).
  - Menu ⋮ e botão direito na linha do documento (L54, L101-105, L161): Abrir, Renomear, Baixar, Classificar, Excluir (vai para a
    lixeira). Rotas existentes: `pasta_documento_edit`, `pasta_documentos_excluir_lote` e a de download já usada na tela.
  - Na barra do documento selecionado (L77-85): renomear com duplo clique no nome, Baixar, Excluir e "tamanho · data".
  - Na linha (L53, L138): tamanho do arquivo e ícone por tipo.
- **P2**
  - Soltar arquivos na lista para enviar (L47, L147), pela rota `pasta_peticionar_upload` e as mesmas regras do modal "Adicionar peça".
  - Dica na lista vazia (L57-58).

Fora agora: ordem manual (número e arrastar), seletor do processo de destino (depois do #91), visualizador de DOCX.

## Fatias do redesign 25/10 (desenho `docs/design/redesign-25-10-26/07 - Peticionar 1.0.dc.html`)

Decisões do coordenador (27/10): (a) navbar enxuta como no desenho; (b) ordem manual compartilhada com a aba Documentos da
pasta (`PastaDocumento::$ordem`, rota `pasta_documentos_reordenar`), sem separador por dia quando a ordem é manual;
(d) manter o TinyMCE salvo o desenho dizer outro; (e) biblioteca do visualizador DOCX/XLSX decidida na spec da F9, de
preferência via composer; (f) aceitar qualquer formato, com entrega segura (frente própria, cuidado de segurança).

| Fatia | Conteúdo | Estado |
|---|---|---|
| F1 | Lista de documentos (linhas, ícone por tipo, tamanho) | PR desta entrega |
| F2 | Rodapé e painel de pré-visualização (`.petic-preview-corpo`), sem trilho/bolinhas nem player de áudio/vídeo | PR desta entrega |
| F3 | Modal Adicionar peça: opções Anexo e Texto com dicas do desenho | PR desta entrega |
| F4 | Cabeçalho/navbar enxuta (arquivo quente `layout_peticionar.html.twig`; avisar antes) | PR desta entrega |
| F5 | Seletor do processo, somente leitura | pendente |
| F6 | Ordem manual compartilhada com a aba Documentos | pendente |
| F7 | Destino por peça (spec antes; migration 153800) | pendente |
| F8 | Editor (TinyMCE mantido) | pendente |
| F9 | Visualizador DOCX/XLSX | pendente |
| F10 | Aceitar qualquer formato | pendente |

Até a F10, a dica do anexo lista só o que o servidor aceita hoje: PDF, Word, Excel, imagens, XML e KML.

## Para o dono

1. A ordem dos documentos no Peticionar é a mesma ordem manual da pasta? No desenho as duas telas dividem a lista; hoje o Peticionar
   ordena por data, e os separadores por dia não convivem com ordem manual. Toca a frente da pasta por dentro (b5).
2. Classificar pelo nome do arquivo, com as categorias do desenho (Petição, Decisão, Documento pessoal…), ou manter a escolha no modal
   com as categorias atuais (Peça, Identificação, Gratuidade…).
3. Aceitar qualquer formato no envio (hoje há lista de tipos aceitos).
4. Onde guardar o processo de destino da peça (em cada peça ou na sessão); o seletor espera o #91 (apensados).
5. Salvar o texto sozinho (autosalvamento do desenho) ou manter o "Salvar na pasta".
6. Visualizador de DOCX/XLSX: escolher biblioteca ou conversão.
7. Cor dos ícones por tipo: paleta do Guia visual (`arquivo_icone`, a mesma da aba Documentos) ou as cores do desenho 07
   (PDF #c0392b, imagem #1e7b45). Hoje: a do Guia visual.
8. O selo de categoria segue as cores atuais (o desenho usa cinza #5f7684); decide-se junto com o item 2.
9. O nome exibido na linha passou de `titulo` para `nome_original` (o mesmo da aba Documentos e o que o Renomear grava):
   documentos antigos podem aparecer com o nome do arquivo.
10. Renomear a peça de texto pelo menu grava só o nome do arquivo; o título interno (lido, por exemplo, pela IA) só se
    atualiza ao salvar no editor, como já acontece na aba Documentos.
