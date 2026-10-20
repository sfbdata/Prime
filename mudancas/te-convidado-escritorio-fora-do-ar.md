---
tipo: alteracao
---
## Changelog
- Trabalho Externo: o Convidado de uma demanda cujo escritório está fora do ar (suspenso pelo Master ou excluído e em quarentena) só lê. Não manda mensagem, não registra andamento nem checklist, não envia documento, não apaga mensagem e não usa os botões de execução. Ver a demanda, o chat e o histórico, baixar documento e marcar como lida continuam. A tela mostra "Esta demanda está pausada. Fale com a Central." no lugar dos botões, sem o nome nem a situação do escritório. A Central segue agindo (spec `docs/specs/te-convidado-escritorio-fora-do-ar.md`).
- Aceitar convite de demanda de escritório fora do ar é recusado com "Este convite não está mais disponível.".
- Regra num lugar só: `App\Tenant\Service\SituacaoDaConta::foraDoAr`, consultada pelo `LocalizadorDeDemanda`, que marca o contexto do Convidado como só leitura. Sem migration.
