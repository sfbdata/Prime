# NEW_CHAT_PROMPT — Gestão de Cobranças (RODADA DE AJUSTES)

> Cole o bloco abaixo como primeira mensagem de um chat NOVO do Claude Code para continuar a **rodada de ajustes** do módulo (já em produção). NÃO confie em resumos — confirme tudo no repositório e no Git.

---

```
Vou implementar o ITEM 7 da RODADA DE AJUSTES do módulo Gestão de Cobranças
(App\Cobranca) do JusPrime — módulo JÁ EM PRODUÇÃO (bluejus.com.br). É risco
MÉDIO/ALTO (regra financeira: parcelamento, aritmética de centavos, edição de
acordo com pagamentos), então o fluxo é: INVESTIGAR → escrever SPEC própria →
EU aprovo o plano → só então IMPLEMENTAR por fatias. NÃO vá direto ao código.
NÃO confie neste resumo: confirme tudo no repositório e no Git.

## 1. Leia primeiro (nesta ordem)
Memória (fora do repo): MEMORY.md → project_gestao_cobrancas.md (bloco do topo).
Docs vivos no repo (docs/gestao-cobrancas/):
- SESSION_HANDOFF.md — estado atual (FONTE DE VERDADE)
- AJUSTES_BACKLOG.md — item 7 tem a "Ideia final (decidida)" com o humano. LER.
Spec base do módulo: docs/gestao-cobrancas/FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md
(regras/invariáveis; acordo = Etapa 4). Specs dos ajustes já feitos:
docs/specs/cobranca-ajuste2/4/5/6-*.md (padrões a reusar).
Regras da camada: CLAUDE.md raiz + app/src/CLAUDE.md + Controller/UseCase/DTO/
Form/Repository/Entity/templates/tests CLAUDE.md. Autorização: docs/AUTORIZACAO.md.

## 2. Carregue a skill `workflow` ANTES de tocar em código.

## 3. Confirme o estado no Git
- Branch `gestao-cobrancas` (local, NÃO pushada). HEAD deve ser `bef127e`
  (ou posterior). Working tree LIMPO.
- tests/Cobranca 449/449, global 1764/1764.
  docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'
- Itens 1,2,3,4,5,6 já commitados nesta rodada. Se divergir do esperado, PARE e
  reporte a divergência (corrija o entendimento pelo Git, não pelos docs).

## 4. O item 7 (o que fazer) — "Formulário de acordo inteligente + abrir/editar acordo"
Ideia já fechada com o humano no AJUSTES_BACKLOG.md §7:
- **Gerador de parcelamento:** seleciona obrigações → total automático; total
  NEGOCIÁVEL (desconto/juros ajusta) + entrada opcional; escolhe qtd de parcelas +
  data da 1ª + periodicidade (mensal/quinzenal/semanal) → gera parcelas (valor
  dividido igualmente, sobra de centavos na 1ª ou última, "Parcela k/n",
  vencimentos em sequência editáveis).
- **Recálculo ao vivo (JS):** sobrescrever o valor de uma parcela a FIXA; as
  não-fixadas redistribuem o restante pra fechar o total. Servidor REVALIDA
  Σ parcelas == total no submit.
- **Abrir acordo:** nova tela/painel de detalhe (parcelas, status, obrigações
  substituídas, entrada, total, desconto/juros).
- **Editar acordo (novo EditarAcordoUseCase):** edita parcelas AINDA NÃO PAGAS;
  guardas: parcela com pagamento alocado não muda; acordo rompido/cancelado é
  congelado. Auditado.
- **Migration provável (aditiva):** hoje o Acordo NÃO tem colunas total/entrada/
  desconto (total é derivado das parcelas). Avaliar na spec se persiste. Parcelas
  continuam sendo Obrigacao com `acordoOrigem`.
- Conecta o item 8 (parcelas na aba Obrigações), que vem depois e depende deste.

## 5. O que fazer, na ordem (risco MÉDIO/ALTO)
a) INVESTIGAR (subagente Explore read-only): entidade Acordo + StatusAcordo (ehVigente),
   Obrigacao.acordoOrigem/acordoSubstituto, ObrigacaoRepository::doCasoExigiveis
   (status-aware: romper/cancelar restaura originais por DERIVAÇÃO — invariável 20),
   CriarAcordo/RomperAcordo/CancelarAcordo/MarcarAcordoCumprido UseCases + Inputs +
   Forms + os modais de acordo (caso/objeto `_acoes_modais*`), CalculadoraHonorarios
   (rateio §18), como a aba "Acordos" renderiza hoje, e os testes existentes.
b) ESCREVER A SPEC em docs/specs/cobranca-ajuste7-acordo-inteligente.md, resolvendo
   as sub-decisões (ver §6 abaixo) — inclusive SE precisa de migration e qual.
c) ME APRESENTAR o plano/spec + as sub-decisões (usar AskUserQuestion se houver
   escolha real de produto). EU APROVO antes de qualquer código.
d) IMPLEMENTAR por fatias, cada uma: TDD (teste primeiro) → MOSTRAR smoke visual no
   navegador → EU aprovo → suíte completa + /review (feature-review-agent) →
   corrigir → commit atômico → próxima fatia.

## 6. Sub-decisões prováveis para a SPEC (resolver com o humano)
- Persistir total negociado / entrada / desconto no Acordo (migration aditiva) vs
  manter derivado? (o "abrir/editar acordo" provavelmente exige persistir).
- Aritmética da sobra de centavos: 1ª ou última parcela absorve? (definir e testar,
  como o rateio de honorários já faz).
- "Fixar parcela" no recálculo ao vivo: contrato do JS + revalidação no servidor.
- Editar acordo com pagamentos alocados: escopo do que pode/não pode mudar (guardas).
- Interação com honorários acrescidos (§18) ao gerar parcelas de acordo.

## 7. Gotchas (herdados dos itens 1–6)
- Docker: tudo no container jusprime_php_dev; phpunit/cache exigem
  `-d memory_limit=512M`. Ex.: docker exec jusprime_php_dev bash -c 'cd app &&
  php -d memory_limit=512M bin/phpunit tests/Cobranca'.
- Dinheiro = int centavos; CentavosType no form; saída via |centavos. Aritmética
  inteira, arredondamento meio-para-cima (ver CalculadoraHonorarios).
- **GOTCHA de saldo:** obrigação tem `encargosReconhecidos` → valorExigivel =
  valorOriginal + encargos. NUNCA assumir saldo só pelo valor original.
- Smoke Playwright dev (localhost:8080, farlei.rocha@gmail.com/Prime123!): o modal
  #modalAlertaPonto intercepta cliques → remover via browser_evaluate antes de
  interagir. Objetos com dados reais: carteira 3; objeto 296 (acordo CANCELADO,
  caso 295, ótimo p/ testar acordo); objeto 104/107/137 (acrescido_divida 10%).
- **Modais reutilizáveis:** a `action` é injetada por JS no botão da linha; já há um
  guard (item 6) que bloqueia submit com action vazia (evita POST na página = 405).
  Replicar o padrão em qualquer modal reutilizável novo do acordo.
- CSRF stateless (submit): não usar referer externo em teste.
- Multi-tenant inegociável: findOneByIdDoTenant→404 ANTES de efeito; selects via
  Repository::opcoesDoTenant + ChoiceType (nunca EntityType).
- Git: pode commitar local (revisando status+diff). push/merge/rebase/deploy/migration
  em prod são do HUMANO (depois do DJEN). Nada da rodada foi pushado/deployado.

## 8. Comece assim
Confirme o Git, carregue a skill workflow, LEIA o AJUSTES_BACKLOG §7 + a spec do
acordo (Etapa 4), dispare a investigação read-only, e então me traga a SPEC + as
sub-decisões para eu aprovar ANTES de implementar.
```

---

**Observações para quem cola o prompt:**
- Estado em 2026-07-14: itens 1–6 da rodada de ajustes COMMITADOS (HEAD `bef127e`, branch `gestao-cobrancas` local, não pushada). tests/Cobranca 449/449, global 1764/1764. Working tree limpo.
- **Nada da rodada foi pushado nem deployado** — merge no master + deploy são decisão do humano, DEPOIS do DJEN.
- Depois do item 7 vem o **item 8** (parcelas do acordo na aba Obrigações — BAIXO, reusa o detalhe do acordo do item 7).
- Mantenha SESSION_HANDOFF.md e AJUSTES_BACKLOG.md como fonte viva entre chats.
