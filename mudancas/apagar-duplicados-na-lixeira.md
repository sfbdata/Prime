---
tipo: interno
---
## Changelog
- Novo comando `app:documentos:apagar-duplicados`: manda para a lixeira as cópias repetidas (mesmo sha256) dentro da mesma pasta, em simulação por padrão, com `--confirmar`, fatias por pasta, CSV sem nomes e regra de qual cópia fica (a com vínculo; sem vínculo, a mais antiga; mais de uma com vínculo, o grupo é pulado). Roteiro em `docs/runbooks/apagar-duplicados.md`.

## Para o cliente
- Quando o BlueJus limpar as cópias repetidas de um mesmo documento dentro da mesma pasta, elas vão para a lixeira da pasta e ficam lá por 30 dias, onde você pode restaurar. Fica uma cópia de cada, a que já está ligada a uma assinatura, a um cliente ou aos favoritos, ou a mais antiga. Versões diferentes do documento, como DOC e PDF, não são mexidas.
