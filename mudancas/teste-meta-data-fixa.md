---
tipo: correcao
---
## Changelog
- `DuplicarMetaControllerTest`: a meta de teste tinha prazo fixo em 20/11/2026; depois dessa data ela nascia "atrasada" e o teste da aba quebrava (a Suíte completa do master e do PR #858 ficaram vermelhas em 23/11). O prazo passa a ser daqui a 30 dias e a cópia é comparada com o prazo da original.

## Para o cliente
- A conferência automática do "Duplicar meta" ficou mais confiável: ela agora cria a meta de exemplo com prazo para daqui a 30 dias, em vez de uma data fixa do calendário, e volta a validar cada nova versão antes de ir ao ar.
