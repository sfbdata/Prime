---
tipo: novidade
---
## Changelog
- Página Novidades (`/novidades`) no menu do usuário, com o que cada versão trouxe em linguagem de
  escritório e um selo de "novo" até a pessoa abrir; o texto vem de `app/config/novidades.yaml`.
- Versionamento automático: cada PR descreve a mudança em `mudancas/`, e um robô mantém o PR
  "Lança a vX.Y.Z" com o número calculado, o CHANGELOG e as novidades montados.
- Deploy automático depois do merge do PR de release, com backup conferido, versão anterior
  guardada, guarda contra migration destrutiva, conferência e volta automática
  (`scripts/vps/deploy-automatico.sh`).

## Para o cliente
- Nova página Novidades, no menu do seu usuário: veja o que mudou no BlueJus a cada versão.
