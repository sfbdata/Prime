---
description: Monta o(s) comando(s) de commit git no padrão do projeto para o humano executar no terminal. NÃO executa git de escrita.
---

Monta um commit git bem formatado seguindo as convenções do projeto, para o
**humano executar manualmente**. Você (orquestrador) NÃO roda `git add` nem
`git commit` — git de escrita é responsabilidade exclusiva do humano, e o
`block-git-writes.py` bloqueia a execução. Seu papel é montar o bloco pronto.

## Fluxo
1. Rode `git status` e `git diff` (leitura, permitido) para ver o estado real.
2. Decida o agrupamento: um commit = uma mudança lógica. Se houver mais de uma
   frente misturada na working tree, proponha commits separados.
3. **Monte o bloco** com `git add` por arquivo (nunca `git add .`) seguido do
   `git commit`, prefixado para o humano colar:

```bash
# Execute manualmente no terminal externo
git add caminho/do/arquivo1.php caminho/do/arquivo2.php
git commit -m "Verbo imperativo em português: descrição curta"
```

## Formato da mensagem
- 1 linha, máximo 72 chars
- Verbo imperativo em português: `Adicionar X`, `Corrigir Y`, `Refatorar Z`, `Remover W`
- Sem ponto final, sem corpo, sem rodapé

## Regras
- Nunca adicionar `.env`, `build/`, `dist/`, `node_modules/`
- Um commit = uma mudança lógica
- Mostre o bloco e aguarde; o humano aprova e executa. Se ele relatar erro,
  proponha o próximo comando — sempre em bloco, nunca executando.

## Após o commit (quando o humano confirmar que executou)
Explique a atualização na visão do usuário final: o que mudou na prática, sem
jargão técnico. Use linguagem simples, como se estivesse descrevendo a um
cliente o que ele vai encontrar de diferente no sistema.
