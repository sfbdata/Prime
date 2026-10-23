---
tipo: alteracao
---
## Changelog
- Idioma do sistema: o seletor (menu do usuário e Meu Perfil) oferece só Português (Brasil), Inglês e Espanhol (`Idioma::oferecidos()`); os outros sete saem da lista, sem apagar caso do enum nem catálogo. `POST /perfil/idioma` grava `pt-BR`, `en` e `es` e recusa o resto com 422. Quem tinha outro idioma gravado no perfil cai em português, sem erro. Sem migration.
- Termos de Uso (`/termos/aceitar`, GET e POST) e Política de Privacidade (página e PDF) fixam o locale `pt_BR` na rota, e o `IdiomaDaRequisicaoListener` respeita: o aceite e a ciência continuam amarrados ao texto em português, qualquer que seja o idioma da pessoa.

## Para o cliente
- Em "Idioma do sistema" agora dá para escolher entre Português (Brasil), Inglês e Espanhol. Os Termos de Uso e a Política de Privacidade continuam em português.
