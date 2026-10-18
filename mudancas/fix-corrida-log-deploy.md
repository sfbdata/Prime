---
tipo: interno
---
## Changelog
- Deploy automático: o arquivo de log é criado antes de o script se relançar em segundo plano. Antes,
  às vezes o `tail` abria o log antes de ele existir e o script terminava com erro enquanto o deploy
  seguia sozinho na VPS (pego como teste instável no Qualidade do master).
