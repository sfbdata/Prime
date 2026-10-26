# Perfis 1.2 (25/10): Recompensas e reforma de Planos

Desenho: `docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html` (seção `recomp` L306-338; JS L854-964;
Planos L236-247). Inventário: `perfis-1.2-vs-25-10-telas-novas-inventario.md`. Risco: Recompensas ALTO (dinheiro),
Planos MÉDIO (limites e cobrança).

## Decisões do dono

- 26/10/2026: baixa de recompensa MANUAL, com comprovante, no começo; pagamento por API do gateway só depois que o
  Gateway estiver pronto.
- 26/10/2026: "usuários ilimitados" do desenho vale; a regra D5 (3/8/15/sem limite) está revogada. Mexer no limite
  efetivo no código fica em PR próprio, com aviso ao Coordenador antes.
- Em aberto (não travam F1 a F3): o que é "caixa" (contábil ou real) e se o Fechamento debita; repasse automático (de
  quê, quando); "uso ativo" na carência e cancelamento da conta; estorno depois de pago; quem recebe (CPF, retenção);
  canais que valem (WhatsApp e link copiado não deixam rastro); teto e dupla aprovação; antifraude com CPF/CNPJ/aparelho
  (LGPD); recorrência ★ na v1; mudança de regra sobre indicações já feitas (recomendado congelar o valor na liberação).
  Planos: existe assinatura paga (KPIs de receita)? "Mais escolhido" fixo ou do Master? "Restaurar padrão" volta para quê?

## Recompensas: fatias

| Fatia | Conteúdo | Dinheiro |
|---|---|---|
| F0 | `/i/{codigo}` plantável (atribuição de conta por link) deixa de ser risco com recompensa | não |
| F1 | Seção "Recompensas": regras do programa + condição individual ★ + KPIs + lista de Indicadores (leitura) | não, só cálculo na leitura |
| F2 | Extrato imutável, crédito e estorno manuais, CSV | sim (livro) |
| F3 | Antifraude (suspeita, alerta na Central), revisar suspeitas, bloquear | não |
| F4 | Dar baixa manual + comprovante + PIX do indicador, trava por usuário | sim (ALTO) |
| F5 | Caixa, aporte, Fechamento do mês | sim (ALTO) |
| F6 | Repasse da receita, recorrência ★ | sim (ALTO) |
| F7 | Avisos em lote por e-mail, mapa "Recompensas pagas" | não |

### F1 (esta entrega)

Dado de plataforma, sem tenant. Migration 151900.

- `programa_de_recompensa` (linha única, id 1): `ativo` (padrão verdadeiro), `valor_centavos` (1000), `min_escritorios`
  (10), `carencia_dias` (30), `alerta_qtd` (5), `exige_pasta` (verdadeiro), `alterado_em`, `alterado_por_id`.
- `condicao_de_recompensa` (uma por indicador, PK `user_id`): `valor_centavos`, `min_escritorios`, `carencia_dias`
  (nulos usam a regra geral), `recorrente`, `mensal_centavos`, `motivo`, `alterada_em`, `alterada_por_id`. A recorrência
  só é guardada; quem lança é a F6.
- Auditáveis (plataforma). Dinheiro sempre em centavos inteiros.
- Cálculo na leitura (o crédito não é gravado em F1): para cada indicador, indicações com conta criada (aceitas);
  `ativos` = aceitas cuja carência (dias desde a criação da conta) passou e, se `exige_pasta`, cujo escritório tem pelo
  menos uma pasta; `credito = ativos x valor`; `saldo = credito` (pago é zero até F4); liberado quando
  `ativos >= min_escritorios`. Situações: Liberado, Pronto para liberar (ativos >= min e ainda sem baixa, igual
  Liberado em F1), N em carência, Em andamento. KPIs: INDICADORES, CONTAS ABERTAS, LIBERADOS, A PAGAR, JÁ PAGO (zero).
- Telas (componentes do desenho): bloco "Regras do programa Indique e ganhe" (chave, valor, libera com, carência,
  alerta antifraude, só conta com pastas abertas, nota, "N com condição individual"), KPIs, lista Indicadores com busca,
  filtros (Todos, A pagar, Liberados, Ainda não liberados, ★) e linha por usuário; menu ⋮ só com "Condição individual"
  (valor, libera com, carência, recorrente + mensal, motivo) e "Voltar à regra geral". "Dar baixa", caixa, extrato e
  Fechamento entram nas fatias seguintes (os botões não aparecem antes de existirem).
- Seção externa do painel (`#sec=recomp`, ícone `bi-gift`), mesmo mecanismo da seção Indicações.
- Segurança: só `ROLE_SUPER_ADMIN`, CSRF `master_recompensas`, 403 sem token, 422 com `{erro}`; valor negativo e
  inteiros inválidos recusados no servidor.

## Planos: fatias

| Fatia | Conteúdo | Estado |
|---|---|---|
| F1 | Visual do desenho: faixa OAB, rótulo, selos, "Ajustar", "usuários ilimitados", "Todos os recursos liberados", nota | PR #419 |
| F2 | Limite efetivo de usuários passa a ilimitado (D5 revogada) | PR próprio, aviso antes |
| F3 | Resumo de 4 KPIs e receita por plano | depende do dono (existe assinatura paga?) |
| F4 | Edição inline com "Restaurar padrão" no lugar do modal | depende do dono |
| F5 | Aviso por e-mail antes do reajuste | depende do Gateway |
