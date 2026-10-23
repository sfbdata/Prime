---
tipo: correcao
---
## Changelog
- Campos de formulário escuros em tela clara para quem usa o sistema operacional em modo escuro: `base.html.twig` passa a declarar `<meta name="color-scheme" content="light">` (antes "light dark"); o tema escuro do sistema segue pelo `data-bs-theme="dark"`.
- Equipe > Editar Permissões, aba Dados Pessoais: os 5 campos ganham `form-control`. Cobrança > Acordo, janela Editar: o campo do valor total negociado também.

## Para o cliente
- Os campos de preenchimento voltam a aparecer claros nas telas claras, mesmo com o computador em modo escuro.
