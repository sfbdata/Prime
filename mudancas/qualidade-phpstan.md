---
tipo: interno
---
## Changelog
- PHPStan nível 5 em `app/src` no CI e no `composer qa`: o legado fica congelado em dois baselines
  (`phpstan-baseline.neon` e, à parte, `phpstan-baseline-doctrine.neon` com as 224 divergências entre
  tipo PHP e mapeamento Doctrine); erro novo reprova.
- PHP-CS-Fixer (`@PSR12` + regras da casa) só nos arquivos PHP criados pelo PR; arquivo alterado não é
  reformatado. No host: `scripts/qa.sh` (`--corrigir` para aplicar).
