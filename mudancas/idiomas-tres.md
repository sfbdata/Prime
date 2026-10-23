---
tipo: alteracao
---
## Changelog
- Idioma do sistema: o seletor (menu do usuário e Meu Perfil) lista só Português (Brasil), Inglês e Espanhol (`Idioma::oferecidos()`); os outros sete saem da lista, sem apagar caso do enum nem catálogo. Só o português está liberado: inglês e espanhol seguem "em breve" e o `POST /perfil/idioma` continua recusando tudo que não é `pt-BR` (422). Quem tinha outro idioma gravado no perfil cai em português, sem erro. Sem migration.
- Termos de Uso (`/termos/aceitar`, GET e POST) e Política de Privacidade (página e PDF) fixam o locale `pt_BR` na rota, e o `IdiomaDaRequisicaoListener` respeita: o aceite e a ciência ficam amarrados ao texto em português, qualquer que seja o idioma da pessoa.

## Para o cliente
- A lista de "Idioma do sistema" ficou só com os três idiomas planejados: Português (Brasil), Inglês e Espanhol (estes dois ainda em breve).
