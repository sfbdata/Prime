---
tipo: novidade
---
## Changelog
- Voto, Portal do Eleitor: quando todas as unidades aptas da lista têm CPF, o passo 1 pede só nome completo e CPF (casam nome normalizado e CPF exato) e o passo 2 mostra a unidade e o bloco do cadastro junto do nome. Sem CPF na lista, ou com alguma unidade apta sem CPF, o portal segue com nome, unidade e bloco, e a tela "Unidades e links" avisa quantas aptas estão sem CPF. Dono de mais de uma unidade escolhe qual no passo 2 (rota `voto_assembleia_portal_escolher`; escolha fora da lista da sessão dá 422). Nome e CPF que não casam vão ao passo 2 só com o nome e o código não chega a ninguém. No modo CPF, o POST de localizar consome uma ficha do limitador novo `voto_portal_localizar` (60 por 10 minutos por IP), separado do envio do código; o modo antigo não consome.
- Editar unidade (menu ⋮ da lista) ganha o campo CPF, com a mesma validação da importação: é por ele que se completa o CPF que faltou, porque a segunda importação não altera unidade já cadastrada.
- Importação da lista: coluna 9 opcional `CPF` (com ou sem pontuação, gravada só com dígitos, dígitos verificadores conferidos por `DigitoVerificador`); CPF inválido vira erro da linha. O modelo XLSX ganha a coluna CPF e o limite de colunas sobe de 8 para 9. A duplicação da assembleia copia o CPF.
- Nomes dos campos Unidade e Bloco configuráveis na assembleia (até 40 caracteres, só em rascunho, vazio volta ao padrão): usados no portal, no cabeçalho da lista de unidades e levados na duplicação.
- Migration `Version20261119130000`: `voto.assembleia_unidade.cpf` (VARCHAR 11, nulo), `voto.assembleia.rotulo_unidade` e `rotulo_bloco` (VARCHAR 40, nulos).

## Para o cliente
- Na votação, o eleitor pode se identificar só com o nome completo e o CPF. Depois de conferido, a tela mostra a unidade e o bloco dele e segue como antes: o código de 6 números chega no e-mail cadastrado.
- Quem organiza a votação pode incluir uma coluna "CPF" na lista de unidades (com ou sem pontos e traço). Se algum CPF estiver errado, a linha aparece no relatório da importação para ser corrigida.
- O CPF que faltou ou veio errado pode ser corrigido no "Editar" de cada unidade, na lista de unidades.
- Quem tem mais de uma unidade escolhe, na segunda tela, por qual unidade vai votar.
- O pedido de nome e CPF só começa quando todas as unidades que podem votar têm CPF. Enquanto faltar algum, o eleitor continua informando nome, unidade e bloco, como sempre, e a tela "Unidades e links" mostra quantas estão sem CPF.
- Quem organiza a votação pode trocar os nomes "Unidade" e "Bloco" por outros, como "Matrícula" e "Seção", na tela de configuração da assembleia. O novo nome aparece para o eleitor e na lista de unidades.
