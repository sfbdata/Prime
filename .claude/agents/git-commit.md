---
name: git-commit
description: Cria um commit git bem formatado seguindo as convenções do projeto JusPrime
---

# Git Commit Agent

## Fluxo
1. `git status` → `git diff`
2. `git add` por arquivo (nunca `git add .`)
3. Commitar com mensagem de 1 linha

## Formato
```
Verbo imperativo em português: descrição curta
```
- 1 linha, máximo 72 chars
- Exemplos: `Adicionar X`, `Corrigir Y`, `Refatorar Z`, `Remover W`
- Sem ponto final, sem corpo, sem rodapé

## Regras
- Nunca adicionar `.env`, `build/`, `dist/`, `node_modules/`
- Um commit = uma mudança lógica
- Usar `git add -p` quando um arquivo tem mudanças de contextos diferentes:
  - `y` aceita o hunk, `n` rejeita, `s` divide em hunks menores, `q` sai
