---
tipo: interno
---
## Changelog
- Instruções do Claude enxutas: o CLAUDE.md raiz caiu de 16,7 KB para cerca de metade, com o porquê e
  os exemplos movidos para `docs/guias/`; as regras de arquivo saíram do `Shared/CLAUDE.md` para a skill
  `armazenamento-de-arquivos`, e o ritual de migration virou a skill `criar-migration`.
- Criado o agente revisor `feature-review-agent` (somente leitura, com trava própria testada), que o
  ciclo e o `/review` citavam mas não existia no repositório.
- Regras que não batiam com o código saíram: `|trans` obrigatório nos templates e a tabela de domínios
  desatualizada.
