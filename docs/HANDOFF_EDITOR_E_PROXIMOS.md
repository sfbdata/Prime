# Handoff — Editor rico, Kanban, Djen e o que vem a seguir

> Estado em 2026-07-24. Para retomar em outro chat. Tudo abaixo foi feito, testado e **pushado**
> em `origin/master` (HEAD `05ced07`). **O que falta é DEPLOY e as próximas frentes.**

## ✅ Feito e pushado (falta só deploy)

Três frentes, **nenhuma com migração** — deploy é só rebuild na VPS
(`ssh <vps> 'cd /opt/jusprime && git pull && ./scripts/deploy-prod-tls.sh'`). Suíte **2569/2569**.

1. **Editor rico (barra de formatação)** em 5 campos de anotação — commits `77651dd`, `65e2e47`,
   `8612079`, `1e02049`, `c1c8235`.
2. **Kanban — XSS armazenado fechado** — commit `e1d9839`.
3. **Djen — sanitizador inerte corrigido** (truncava em 20 KB) — commit `05ced07`.

Detalhes completos de arquitetura, gotchas e lições estão na **memória**
`project_editor_rico_texto.md` (índice em `MEMORY.md`). Leia de lá — não repito aqui.

**No deploy:** avisar a equipe do **Ctrl+Shift+R**. Há CSS/JS novos (Quill + `editor-rico.js`) e o
projeto **não versiona assets** — sem o hard refresh a barra aparece sem estilo. Foi exatamente o que
confundiu na Central na manhã de 23/07 (arquivo certo no servidor, cache velho no navegador).

## ⏸️ Em espera — Central da Cobrança, Fatia 2 (decisão do dono)

Ver memória `project_cobranca_central_acompanhamento.md`. Resumo do que travou:

- A Central terá **3 abas**: Atividade (✅ já em prod), **Resultado** e **Pendências** (Fatia 2, não
  iniciada). A aba **Extrato do devedor foi ADIADA** (o cadastro não tem CPF/CNPJ — 125 pessoas para
  110 nomes, mesmo devedor cadastrado várias vezes; o dono confirmou que "objeto = unidade" já modela
  isso, mas o sistema não sabe que é o mesmo devedor; Extrato só se justifica juntando por pessoa,
  o que hoje não dá com segurança). Volta quando houver documento no cadastro.
- **Resultado** = conteúdo financeiro do painel atual (reusa `MontarDashboardCobrancaUseCase` inteiro,
  sem reescrever). **Pendências** = os 4 números operacionais + a lista de alertas por carteira +
  critério novo.
- **Filtro único no topo** (período + carteira); em Pendências o período fica DESABILITADO com aviso
  "Pendências mostram a situação de agora" (decidido com o dono).
- Painel `/cobrancas/painel` e alertas `/cobrancas/alertas` são **aposentados** (viram redirect para a
  Central) — só DEPOIS da Atividade em prod. O motor `AlertasCobranca` FICA (a tela do caso usa).
- **🔴 DECISÃO PENDENTE — os "60 dias"** (novo 5º tipo de alerta, rótulo do dono = **"Inadimplências
  esquecidas"**, nota "60 dias sem contato"): **o que zera o contador de 60 dias?** Recomendação minha
  = **qualquer movimentação no caso** (contato, acordo, pagamento, anotação), reusando
  `TipoEventoHistorico::ehTrabalhoDeCobranca()` que já existe (§5.1 da spec da Central) — assim
  "trabalhar um caso" significa a mesma coisa nas duas abas. A alternativa é "só contato registrado"
  (mas um acordo fechado há 10 dias sem ligação apareceria como esquecido, o que é errado). **O dono
  ainda não bateu o martelo.** Sem isso não escrevo a spec da Fatia 2.

## ⏭️ Próxima frente — cálculos das obrigações (NÃO iniciada)

Risco **ALTO** (coração do dinheiro da Cobrança). O dono pediu para tratar depois de Kanban+Djen.
**Ainda não descreveu o problema** — o próximo chat deve começar perguntando: é um caso concreto de
número errado (pedir objeto/caso + valor esperado × obtido, para reproduzir com dado real), uma regra
que mudou (taxa/juros/base/arredondamento), ou uma auditoria? Seguir o ciclo ALTO: investigar
(subagente read-only) → spec em `docs/specs/` → confirmar CADA decisão com o dono → implementar (TDD)
→ revisar contra a spec → re-revisar. Contexto do motor de encargos em
`project_cobranca_encargos.md` e `project_cobranca_ajustes_pos_taxa.md`.

## Fluxo de frentes paralelas (montado por outra sessão, em prod)

`scripts/frente-abrir.sh` / `frente-testar.sh` / `frente-fechar.sh` + hook `pre-commit` (recusa commit
na branch errada, exige `.frente`) + registro `docs/frentes-ativas.md`. Guard `block-git-writes.py`
bloqueia push/merge/rebase/reset/checkout/restore e falha fechado. Regras na skill `workflow`.
Fatos medidos do ambiente em `docs/worktrees-frentes-paralelas.md`. **Uma sessão do Ponto rodou em
paralelo hoje e foi mergeada no master (`977d7c1`); a suíte com as duas frentes juntas passou.**
