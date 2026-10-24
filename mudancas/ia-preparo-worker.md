---
tipo: interno
---
## Changelog
- BlueJus IA: o worker trava a análise antes de chamar o provedor (uma mensagem reentregue ou dois workers não cobram a mesma análise duas vezes), re-tenta respeitando o `retry-after` da Anthropic (teto de 5 minutos) e o adaptador envia `inference_geo` (`IA_INFERENCE_GEO`, padrão `us`). A IA continua desligada em produção. Sem migration.
