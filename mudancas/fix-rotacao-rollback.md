---
tipo: interno
---
## Changelog
- O deploy automático só apaga os rollbacks antigos que ele mesmo criou (`..._pre_vX.Y.Z`): no
  deploy da v2.1.0, a rotação apagou também os containers de rollback criados à mão em 13/10
  (preloop1 e preloop2); as cópias em arquivo deles continuam em `/opt/backups/imagens/`.
