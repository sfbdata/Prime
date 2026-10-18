---
tipo: interno
---
## Changelog
- Testes: o cache estático de consultas do DAMA fica desligado. Com o tenant_id literal no SQL
  (TenantFilter), cada teste gravava consultas novas e o cache crescia sem limite durante a suíte.
  A suíte completa caiu de 10m23s para 5m28s e o pico de memória de 629 MB para 400 MB.
