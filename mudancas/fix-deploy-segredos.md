---
tipo: interno
---
## Changelog
- O Release volta a passar os segredos do ambiente production ao Deploy (`secrets: inherit`): sem
  isso, o deploy automático da v2.1.0 parou na conferência dos segredos, antes de tocar a produção.
