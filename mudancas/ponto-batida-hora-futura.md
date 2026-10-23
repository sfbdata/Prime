---
tipo: correcao
---
## Changelog
- Ponto: batida esquecida com data e hora acima de agora, no fuso do escritório, é recusada no lote de justificativas do admin, no pedido de esquecimento do colaborador e na aprovação desse pedido (individual e "Aprovar todos", que deixa o pedido pendente). Antes, uma batida de hoje com hora futura passava. Regra única em `App\Ponto\Service\HorarioFuturoDaBatida`; o fuso do escritório saiu do `HomeOfficeResolver` para `FusoDoEscritorio`. Sem migration.

## Para o cliente
- O ponto não aceita mais registrar uma batida esquecida num horário que ainda não chegou; aparece o aviso "Não é possível registrar batida em horário futuro.".
