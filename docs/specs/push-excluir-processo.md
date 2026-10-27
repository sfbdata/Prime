# Push, aba Processos da OAB: "Excluir processo" (desenho 11)

Fatia 4 da auditoria do desenho 11. Risco BAIXO (sem identidade, sem permissão nova). Migration 153300.

## O que o desenho diz
Menu do botão direito na linha do processo: "Excluir processo". Pergunta: "Excluir o processo N? Ele sai do Push, do
acompanhamento e da pasta vinculada. As publicações já recebidas continuam no histórico." Depois, "digite EXCLUIR"
(cancelar: "Exclusão cancelada: a confirmação não conferiu."). Sucesso: "Processo excluído."

## Decisão (Coordenador, 27/10: o desenho manda)
Excluir é diferente de "Parar de acompanhar": o processo **não volta** ao Relatório e **sai da pasta**.

1. Sai do acompanhamento: processo cadastrado é pausado no monitoramento; o número incluído na mão deixa de ser acompanhado.
2. Sai da pasta: desvincula de todas as pastas ativas em que está. Quem não pode editar alguma dessas pastas
   (cadeado/permissão) não exclui: a ação inteira é recusada com a mensagem, nada é gravado pela metade.
3. Sai do Push: marca `excluido_em` em `djen_processo_acompanhado` (a linha é criada se não existir). Números com
   `excluido_em` não aparecem no Relatório. Incluir o número de novo (campo INCLUIR ou Incluir do Relatório) limpa a marca.
4. As publicações e as anotações ficam; a aba Publicações continua mostrando-as.

## Peças
- `ProcessoAcompanhado::$excluidoEm` + `excluir()` (para de acompanhar e marca) + `acompanhar()` limpa a marca.
- UseCase `ExcluirProcessoDoPushUseCase` (`Tenant`, `User`, número CNJ de 20 dígitos).
- Rota `POST /push-processual/processos/excluir` (CSRF `push_processos`), menu "Excluir processo" com confirm + EXCLUIR.
- `ProcessosDaOabRepository::relatorio()` ignora números com `excluido_em`.

## Testes
Unit do UseCase (número inválido, sem permissão, sucesso) e Functional da rota (sai da lista, da pasta e do Relatório;
publicação continua; incluir de novo limpa a marca).
