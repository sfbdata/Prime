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

### F2 (extrato imutável, crédito e estorno manuais)

Empilhada na F1 (#420). Migration 20261026152000. Risco ALTO (dinheiro): só livro e ajuste manual; ninguém é pago.

- Tabela `lancamento_de_recompensa`, só INSERT: `id`, `user_id` (indicador; FK `ON DELETE SET NULL`, a exclusão LGPD
  da pessoa apaga o vínculo e o extrato fica sem nome), `tipo` (`bonus` nesta fatia; `pago`, `liberado` e `mensal`
  entram nas fatias seguintes), `valor_centavos` (inteiro com sinal: positivo é crédito, negativo é estorno; nunca
  zero; |valor| até R$ 100.000,00), `observacao` (até 255), `chave_de_idempotencia` (único; o navegador manda um
  UUID por abertura do diálogo, então duplo clique não lança duas vezes), `criado_em`, `criado_por_id`
  (`SET NULL`). Auditável (plataforma). Sem setter e sem remoção no repositório: lançamento errado se corrige com
  outro lançamento de sinal oposto.
- O cálculo da F1 passa a somar os lançamentos: `credito = ativos x valor + soma(bonus)`; o resto não muda
  (`saldo = max(0, credito - pago)`).
- Tela (desenho L601-L610 e JS L875/L898): menu ⋮ ganha "Lançar crédito ou estorno" (diálogo com Valor em R$, "Use
  valor negativo para estornar", observação; erro "Informe o valor do crédito (use negativo para estornar)."), "Ver
  extrato deste indicador" (filtra o extrato, chip com o nome e botão para mostrar todos) e "Baixar extrato (CSV)".
  Bloco "Extrato de pagamentos e liberações" (últimos 60; colunas data, nome, tipo, valor, forma, e-mail; vazio
  "Nenhum pagamento ou liberação ainda."), com o botão "Exportar CSV" (todos). Os botões "Avisar..." e a coluna de
  e-mail enviado entram na F7; "forma" e comprovante na F4. Tipo exibido: "Crédito manual" ou "Estorno" conforme o sinal.
- CSV: GET `/master/recompensas/extrato.csv[?usuario=ID]`, BOM UTF-8, separador `;`, aspas duplas escapadas, e
  prefixo `'` em célula que comece com `=`, `+`, `-`, `@` (injeção de fórmula). Só Master. Sem e-mail do indicado.
- POST `/master/recompensas/usuarios/{id}/lancamento` (CSRF `master_recompensas`), 422 com `{erro}`, 404 se o
  usuário nunca indicou, resposta com o painel novo.

### F3 (antifraude)

Empilhada na F2 (#422). Migration 20261026152100 (CREATE TABLE e ALTER TABLE ADD COLUMN; sem UPDATE/DELETE). Sem
dinheiro novo: só decide quais contas contam.

- Sinais que o sistema tem (o desenho também cita CPF e aparelho, que o BlueJus não guarda; entram só depois de o dono
  decidir a LGPD): conta indicada que pertence ao MESMO escritório de quem indicou; mesmo CNPJ de escritório (qualquer
  escritório do indicador e qualquer do indicado, CNPJ não nulo); mesmo domínio de e-mail, exceto provedor público
  (gmail, hotmail, outlook, yahoo, icloud, bol, uol, como no desenho). "Mesmo e-mail" não existe (e-mail é único).
- Conta com suspeita NÃO conta (nem ativa, nem em carência) até o Master aprovar. Aprovação por conta, tabela
  `aprovacao_de_conta_de_recompensa` (`indicador_id`, `conta_id` únicos juntos, `aprovada_em`, `aprovada_por_id`; FKs
  CASCADE; auditável de plataforma). Item do menu "Revisar suspeitas e aprovar" (só quando há suspeita): confirma com
  a quantidade e os motivos (sem nome nem e-mail do indicado) e aprova todas as pendentes do indicador.
- Alerta: aceitas nos últimos 7 dias >= `alertaQtd` (do programa; individual não existe) => situação "Alerta: N
  contas em 7 dias", em vermelho, como no desenho. Só sinaliza; não bloqueia.
- Situação, na ordem do desenho: Bloqueado, Em análise (N suspeitas), Alerta, Liberado, Pronto para liberar, N em
  carência, Em andamento. Dica (title) com os motivos agregados.
- Bloquear/desbloquear recompensa e "Apagar da lista" (digitar APAGAR; o extrato continua guardado): colunas
  `bloqueado_em` e `apagado_em` (nulas) em `condicao_de_recompensa`; a condição só some quando não tem nenhum efeito
  (incluindo bloqueio e apagado). Bloqueado: o indicador sai do filtro "A pagar" e mostra "Bloqueado". Apagado: sai da
  lista e dos KPIs; reaparece se fizer nova indicação? NÃO: fica fora até o Master reverter (sem tela de reversão
  nesta fatia; pendência anotada).
- Auto-indicação: a conta indicada que é o próprio indicador já é ignorada na atribuição; a F3 não muda isso.
- Só vínculo ativo (`user_tenant.is_active`) com escritório não excluído gera sinal de escritório e de CNPJ.
- Provedores públicos: a lista é AMPLIADA em relação ao desenho (que tem gmail, hotmail, outlook, yahoo, icloud, bol, uol):
  entram também live, msn, terra, ig, globo, globomail, proton, protonmail e zoho, comparados pelo primeiro rótulo do
  domínio. Decisão a confirmar com o dono.
- Aprovar suspeitas carrega a quantidade vista (`vistas`, inteiro >= 1); se a quantidade pendente no servidor for outra,
  recusa com 422 ("A lista de suspeitas mudou...") e nada é aprovado. Bloquear, apagar e aprovar rodam sob a trava das
  indicações do usuário.
- Pendências: "Pronto para liberar" depende do lançamento tipo `liberado` (fatia futura; hoje Liberado cobre os dois);
  "apagar" com saldo > 0 não avisa e não tem reversão (pergunta ao dono).
- Fora: alerta na Central (a Notificacao é por escritório; vira fatia própria depois de decidir o destinatário).

## Planos: fatias

| Fatia | Conteúdo | Estado |
|---|---|---|
| F1 | Visual do desenho: faixa OAB, rótulo, selos, "Ajustar", "usuários ilimitados", "Todos os recursos liberados", nota | PR #419 |
| F2 | Limite efetivo de usuários passa a ilimitado (D5 revogada) | PR próprio, aviso antes |
| F3 | Resumo de 4 KPIs e receita por plano | depende do dono (existe assinatura paga?) |
| F4 | Edição inline com "Restaurar padrão" no lugar do modal | depende do dono |
| F5 | Aviso por e-mail antes do reajuste | depende do Gateway |

## Estado da frente (fim do dia, 26/10/2026)

- Entregue e mergeado: Planos visual (#419), Recompensas F0 (#421), F1 (#420), F2 (#422); P4a mapa de indicações (#415), P4b convênio por UF (#417), P4c e-mail do convênio (#424; só estado ou valor reenviam, motivo e data não).
- Em PR: F3 antifraude, bloqueio e apagar da lista (#423), teto TenantAware 86. Depois do merge, conferir o teto contra o master.
- Esperam o dono (pendências 2i/2j/2k): F4 baixa manual com comprovante e PIX, F5 caixa e fechamento, F6 repasse e recorrência, F7 avisos em lote e mapa pago. F4 é risco ALTO (dinheiro): spec, revisão e re-revisão.
- Planos, o que falta: tirar o campo "usuários" do modal Editar plano; KPIs e receita por plano (precisa de assinatura paga); edição inline com "Restaurar padrão"; aviso por e-mail no reajuste (depende do Gateway).
- Convênio: soma do valor na receita espera resposta do dono.
- Registrar P4a/P4b na spec `perfis-1.2-vs-25-10-metas-mapa-config.md` (ainda não feito).
- Nunca feitos, fora desta frente: Service Desk · saldos, Custos; Desempenho é de outra sessão.
- Rotina ao retomar: `git fetch`, trazer `origin/master` em cada PR aberto, migrar o banco da frente, conferir o teto de `TenantAwareCoberturaTest`.
