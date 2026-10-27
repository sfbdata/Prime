---
tipo: interno
---
## Changelog
- Teste de cobertura do isolamento por escritório: entidade nova que não é filtrada por tenant passa a declarar isso na própria classe com `#[SemIsolamentoDeTenant('motivo')]`, em vez de entrar na lista central e subir o teto numérico do teste. Acaba o conflito de merge nesse arquivo. As listas antigas ficam congeladas. Sem migration e sem mudança de comportamento.
