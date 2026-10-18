---
tipo: interno
---
## Changelog
- `scripts/central-demo.sh` descobre a rede pelo próprio `jusprime_php_dev` (o nome muda com a pasta do projeto) e não deixa mais o ruído de `/proc` ao parar.
- `scripts/migrar-dev.sh` novo: aplica migrations já mergeadas no banco que o `localhost:8080` usa (o do `.env.local` da pasta principal) e no de teste da pasta principal, só as versões pedidas.
