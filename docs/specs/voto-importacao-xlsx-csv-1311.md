# Importação de unidades por XLSX e CSV

## História

Como gestor da assembleia, quero enviar uma planilha XLSX ou CSV para cadastrar unidades sem montar manualmente um texto separado por ponto e vírgula.

## Regras

- A tela oferece o modelo XLSX com as colunas `Unidade`, `Bloco`, `Situação`, `Fração`, `Responsável` e `E-mail`, nesta ordem.
- O XLSX lê somente a primeira aba. O CSV aceita separador ponto e vírgula ou vírgula e campos entre aspas.
- Arquivos têm limite de 10 MB e 3000 linhas por 6 colunas. Fórmulas no XLSX são recusadas.
- O arquivo passa pelo mesmo leitor e validações da colagem. Linhas inválidas e duplicadas continuam no relatório existente.
- A importação é aditiva: unidades existentes permanecem e duplicadas são ignoradas. A interface informa esse comportamento.
- A lista continua bloqueada após a abertura da votação.

## Fluxo

O gestor abre Unidades e links, seleciona um XLSX/CSV ou cola texto, e confirma Importar. O controller delega a leitura de arquivo e a importação ao domínio. O resultado mantém o relatório atual e redireciona para a lista.

## Erros

Arquivo ausente usa o texto colado. Extensão, tipo XLSX, tamanho, dimensão ou conteúdo inválido geram aviso e não alteram unidades. A validação individual mantém o motivo por linha.

## Fora do escopo

Pré-visualização antes de confirmar, edição/adicionar unidade e soma de frações são itens separados da lista do dono.
