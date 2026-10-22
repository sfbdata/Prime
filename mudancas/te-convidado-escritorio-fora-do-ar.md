---
tipo: alteracao
---
## Changelog
- Trabalho Externo: o Convidado de uma demanda cujo escritório está fora do ar (suspenso pelo Master ou excluído e em quarentena) só lê. Não manda mensagem, não registra andamento nem checklist, não envia documento, não apaga mensagem e não usa os botões de execução. Ver a demanda, o chat e o histórico, baixar documento e marcar como lida continuam. A tela mostra "Esta demanda está pausada. Fale com a Central." no lugar dos botões, sem o nome nem a situação do escritório. A Central segue agindo (spec `docs/specs/te-convidado-escritorio-fora-do-ar.md`).
- O link do convite de demanda de escritório fora do ar responde como o convite vencido ("Convite indisponível"), sem formulário: nem aceitar logado nem criar a conta gravam o aceite.
- Regra num lugar só: `App\Tenant\Service\SituacaoDaConta::foraDoAr`, consultada pelo `LocalizadorDeDemanda`, que marca o contexto do Convidado como só leitura. Sem migration.
- Vagas: a vaga aberta de escritório fora do ar sai da lista e do detalhe de quem é de fora, do link público de vagas e do e-mail da campanha, e recusa candidatura ("Vaga indisponível"). A pendente desse escritório sai da fila de aprovação do Master, e aprovar, reabrir ou ofertar de novo é recusado. Quem já se candidatou continua abrindo a vaga por "Minhas candidaturas".
- Avisos e entrada: o escritório fora do ar não recebe os avisos do Trabalho Externo, e quem tem vínculo com escritório fora do ar não é mandado direto ao Trabalho Externo depois do login: passa pela seleção de escritório e vê o aviso da conta.
