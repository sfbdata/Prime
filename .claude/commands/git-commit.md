Cria um commit git bem formatado seguindo as convenções do projeto JusPrime.

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
