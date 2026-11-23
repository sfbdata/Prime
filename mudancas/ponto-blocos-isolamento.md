---
tipo: seguranca
---
## Changelog
- Ponto: `bloco_jornada` e `bloco_jornada_colaborador` ganham `tenant_id NOT NULL` (Version20261123190000), copiado da jornada dona no backfill e em toda gravação (`PrePersist`; bloco sem escritório é recusado). As duas entidades passam a `TenantAware` e entram no `TenantFilter`; saem da lista de pendentes do `TenantAwareCoberturaTest`. A purga do escritório apaga os blocos pela coluna ou pelo pai.

## Para o cliente
- Os horários de trabalho do escritório e de cada pessoa ficam protegidos automaticamente: um escritório nunca enxerga os horários de outro. As telas do ponto continuam iguais.
