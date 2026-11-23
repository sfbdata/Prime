---
tipo: correcao
---
## Changelog
- `DuplicarMetaControllerTest`: a meta de teste tinha prazo fixo em 20/11/2026; depois dessa data ela nascia "atrasada" e o teste da aba quebrava (a Suíte completa do master e do PR #858 ficaram vermelhas em 23/11). O prazo passa a ser daqui a 30 dias e a cópia é comparada com o prazo da original.

## Para o cliente
- Ajuste interno nos testes automáticos que conferem o "Duplicar meta". Nada muda na tela: a função continua igual, e o teste deixa de dar alarme falso por causa da data do calendário.
