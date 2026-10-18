---
tipo: interno
---
## Changelog
- O Claude pode atualizar a própria branch de trabalho com o master (`git merge --no-edit
  origin/master`); qualquer outro merge continua bloqueado no guard (testes no CI).
- Check "Aviso de sobreposição" em todo PR: lista os outros PRs abertos que mexem nos mesmos
  arquivos, destaca arquivos quentes e diz se a branch está atrás do master. Só avisa, não bloqueia.
- Regras leves para várias sessões ao mesmo tempo (CLAUDE.md); o registro em
  `docs/frentes-ativas.md` deixa de ser obrigatório.
