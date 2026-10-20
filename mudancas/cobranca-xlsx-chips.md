---
tipo: novidade
---
## Changelog
- Carteira (desenho 1B): item "Exportar lista (.xlsx)" no menu "⋮" do cabeçalho (rota GET `cobranca_carteira_exportar`). A planilha traz a lista de cobranças da tela inteira, sem paginação, com a busca, o filtro de Estado e a ordenação atuais; colunas da tela (identificação e descrição do objeto, pessoa cobrada, estado, pronto para encerrar, saldo, vencido, tem documentos), valores em número e nome de arquivo `carteira-{id}-{data}.xlsx`. Teto de 5.000 linhas: acima disso, volta para a carteira com aviso para recortar a lista. Mesma guarda da tela da carteira; o "⋮" passa a aparecer também para quem só lê a carteira, só com o exportar.
- Objeto (desenho 1A): a área vazia da aba Documentos mostra os chips de exemplo do desenho (Boletos, Notificações, Acordos assinados, Comprovantes).

## Para o cliente
- Na carteira de cobrança, o menu "⋮" agora tem "Exportar lista (.xlsx)": baixa em planilha a lista que está na tela, com a busca e o filtro que você aplicou.
