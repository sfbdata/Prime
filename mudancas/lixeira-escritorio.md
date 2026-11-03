---
tipo: novidade
---
## Changelog
- Expediente: botão "Lixeira" no cabeçalho abre a lixeira do escritório inteiro, agrupada pela pasta de onde cada item saiu. Cada grupo mostra o número e o nome da pasta, quantos documentos e subpastas estão na lixeira (a subpasta conta com a subárvore, como na lixeira da pasta), a exclusão mais antiga e a mais recente, quem excluiu, o link "Abrir a lixeira da pasta" (`?pex_lixeira=1` abre a janela da lixeira no explorador) e as ações "Restaurar tudo" e "Excluir de vez tudo", com confirmação.
- Nova rota `GET /expediente/lixeira` (`expediente_lixeira`, JSON, só leitura, módulo Expediente), `LixeiraDoEscritorioController` + `ListarLixeiraDoEscritorioUseCase`. A contagem é uma agregação no banco (GROUP BY pasta, categoria e autor; `excluido_em IS NOT NULL` e tenant na consulta, dentro do `AcessoALixeira`). Só entra a pasta em que a pessoa pode EDITAR (`PermissionChecker`, a mesma permissão da lixeira da pasta) e vê a aba Documentos; o contrato de honorários com o Financeiro oculto não conta. Páginas de 50 pastas conferidas por pedido (`?apos=`).
- As ações usam as rotas que já existem por pasta (`pasta_documentos_restaurar` e `pasta_documentos_excluir_de_vez`, token `pex_lote_<id>`), com os ids lidos da lixeira da própria pasta e em lotes de até 2.000 itens. Pasta excluída aparece só para olhar.

## Para o cliente
- Na tela Expediente há agora um botão "Lixeira" que mostra, num lugar só, tudo o que foi excluído nas pastas do escritório, separado por pasta: quantos documentos, quando e por quem foram excluídos. Dali dá para restaurar tudo de uma pasta, excluir de vez (com confirmação) ou abrir a lixeira da pasta. Cada pessoa só vê as pastas em que já podia restaurar documentos.
