---
tipo: novidade
---
## Changelog
- Voto, Portal do Eleitor: quando ao menos uma unidade da lista tem CPF, o passo 1 pede só nome completo e CPF (casam nome normalizado e CPF exato) e o passo 2 mostra a unidade e o bloco do cadastro junto do nome. Sem CPF na lista, o portal segue com nome, unidade e bloco. Nome e CPF que não casam vão ao passo 2 só com o nome e o código não chega a ninguém. O POST de localizar passa a consumir uma ficha do `votoPortalEnvioLimiter` por tentativa e IP.
- Importação da lista: coluna 9 opcional `CPF` (com ou sem pontuação, gravada só com dígitos, dígitos verificadores conferidos por `DigitoVerificador`); CPF inválido vira erro da linha. O modelo XLSX ganha a coluna CPF e o limite de colunas sobe de 8 para 9. A duplicação da assembleia copia o CPF.
- Nomes dos campos Unidade e Bloco configuráveis na assembleia (até 40 caracteres, só em rascunho, vazio volta ao padrão): usados no portal, no cabeçalho da lista de unidades e levados na duplicação.
- Migration `Version20261119130000`: `voto.assembleia_unidade.cpf` (VARCHAR 11, nulo), `voto.assembleia.rotulo_unidade` e `rotulo_bloco` (VARCHAR 40, nulos).

## Para o cliente
- Na votação, o eleitor pode se identificar só com o nome completo e o CPF. Depois de conferido, a tela mostra a unidade e o bloco dele e segue como antes: o código de 6 números chega no e-mail cadastrado.
- Quem organiza a votação pode incluir uma coluna "CPF" na lista de unidades (com ou sem pontos e traço). Se algum CPF estiver errado, a linha aparece no relatório da importação para ser corrigida.
- Quando a lista não tem CPF, o eleitor continua informando nome, unidade e bloco, como sempre.
- Quem organiza a votação pode trocar os nomes "Unidade" e "Bloco" por outros, como "Matrícula" e "Seção", na tela de configuração da assembleia. O novo nome aparece para o eleitor e na lista de unidades.
