---
description: Cria um commit local no padrão do projeto — revisa status/diff/staged, faz o staging por arquivo e executa o `git commit`. Nunca faz push.
---

Cria um commit local bem formatado seguindo as convenções do projeto. Você
(orquestrador) **executa** `git add` e `git commit` diretamente — commit local é
permitido. **Nunca faz push, merge, rebase ou reset** (isso é do humano).

## Fluxo (obrigatório revisar antes de commitar)
1. Rode `git status` — veja exatamente o que está modificado/novo/staged.
2. Rode `git diff` (e `git diff --staged` se já houver algo staged) — leia as
   mudanças de verdade, não presuma.
3. Decida o agrupamento: um commit = uma mudança lógica. Se houver mais de uma
   frente misturada na working tree, **faça commits separados** — não junte.
4. **Stage por arquivo/escopo:** `git add <caminho1> <caminho2> …`. **Nunca use
   `git add .` cego** quando houver risco de arrastar alteração fora do escopo.
   Se for `add .`, confirme antes no `git status` que só há o que deve entrar.
5. Confira o que ficou staged (`git status` / `git diff --staged`) e então
   **execute** o commit:

```bash
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
- **Nunca `--no-verify`** — não burle os hooks.
- **Nunca `push`** (nem merge/rebase/reset). Se a instrução pedir publicar,
  monte o comando em bloco `# Execute manualmente no terminal externo` para o
  humano executar.

## Após o commit
Informe o **hash curto**, a **mensagem** e os **arquivos incluídos**. Em seguida,
explique a atualização na visão do usuário final: o que mudou na prática, sem
jargão técnico, como se descrevesse a um cliente o que ele vai encontrar de
diferente no sistema.
