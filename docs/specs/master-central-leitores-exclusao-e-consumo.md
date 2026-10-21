# Spec: fontes extras da Central de Notificações do Master (exclusão de conta e alerta de consumo)

Risco: BAIXO (só leitura de dados que já existem, mais a resposta pelo caminho da Central). Base: a Central da b5
(#192, `docs/specs/master-central-de-notificacoes.md`). Contrato da coordenação (21/10): implementar
`App\Master\Notificacoes\OrigemDaCentral` (tag autoconfigurada), devolvendo `MensagemDaCentral`, sem mexer no
controller nem na tela, salvo a correção abaixo. Sem migration. O pedido de plano ou espaço fica para depois do #95.

## Origens

| Origem | Tabela | Tipo visual | Rótulo | Responder |
|---|---|---|---|---|
| `exclusao_conta` | `pedido_exclusao_conta` (#125), todos os pedidos, mais novos primeiro | `alerta` (vermelho) | "Pedido de exclusão" | sim, para quem pediu (e-mail; senão, aviso na Central no escritório do pedido) |
| `alerta_consumo` | `audit_log` com `action = 'alerta'` e `entity_class = EspacoAtingiuFaixa` (gravado pelo `AlertaDeConsumoNaTrilha`) | `alerta` | "Alerta" | não (D7) |

- Pedido de exclusão: de = o primário que pediu; escritório = "Conta Nº 000123"; assunto "Quer excluir a conta
  {escritório}"; texto com o motivo e a situação (aguardando a decisão, desistiu, reativada, excluída, ou excluída e
  recuperada quando o escritório voltou ao ar). A decisão continua em Contas e escritórios.
- Alerta de consumo: de "Sistema BlueJus"; assunto "Alerta de consumo"; texto "Alerta de consumo: {escritório}
  (Conta Nº …) passou de N% do armazenamento. Uso atual: P%."
- As duas leem por SQL direto, de todos os escritórios: o pedido é TenantAware, e o filtro da sessão do Master o
  esconderia. O escritório da resposta vem pelo id da linha, pelo mesmo motivo.
- Tipo visual: o desenho só tem as cores de sug, prob, falta, alerta, sup e fale. As duas origens usam o vermelho dos
  alertas, e o rótulo diz o que é.

## Correção na tela (ok da b5 e da coordenação)

O link "Abrir Indicações" da janela da mensagem aparecia em todo tipo `alerta`. Agora aparece só na origem
`alerta_indicacao` (`public/js/master/notificacoes.js`).
