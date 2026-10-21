---
tipo: interno
---
## Changelog
- Idioma do sistema, base (spec `docs/specs/idioma-do-sistema.md`, entregas 1 e 2): o idioma escolhido fica no perfil (`user_profiles.idioma`) e vale para a requisição e para o `<html lang/dir>`; o modal "Idioma do sistema" sai da lista do enum `Idioma` e grava pelo `POST /perfil/idioma`. Filtros de formato `|data`, `|data_hora`, `|hora`, `|numero` e o `BJFormato` no JS. Só o português está liberado: a tela não muda.
