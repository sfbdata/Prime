# Relatório com IA por pessoa (perfil individual do Painel)

Risco **ALTO** (ver §6). Só spec: nenhum código nesta entrega. Estado: aguardando as decisões do dono (§5).
Desenho: `docs/design/redesign-25-10-26/01 - Dashboard - VS 25.10.26.dc.html` (fora do git; L720 botão da linha,
L787 "← Equipe", L1098-1190 e L2431-2480 perfil). Base já feita: PR-I e PR-M de `docs/specs/dashboard-parte-1.md`
(perfil por regras fixas, `ObterPerfilDaPessoaUseCase`, `AnaliseDaPessoa`, `SinaisDaPessoa`).

## 1. O que é e o que o dc mostra

No perfil individual de uma pessoa (aberto por quem é Master ou primário ativo), um botão "Gerar relatório com IA"
pede ao modelo um texto de leitura em linguagem natural sobre os NÚMEROS que o perfil já mostra: carga, entregas,
atrasos, comparação com o período anterior e com a média da equipe. O dc mostra o perfil com métricas, diagnóstico
"Observado", "Por que chegou a essa conclusão", comparação, Ver tarefas e "Marcar como analisado". O relatório da IA
é um bloco a mais ali, com selo "IA", data de geração, aviso de limites e o aviso do art. 20 (§3.1).

A IA **não** classifica, não dá nota, não recomenda punição, demissão, corte ou bônus e não prevê comportamento.
Ela descreve o que os números mostram e aponta perguntas que o gestor pode fazer. É apoio à conversa do gestor
com a pessoa, nunca decisão.

## 2. O que vai à IA e o que nunca vai

**Vai (só agregados do período, os mesmos do Painel, com cadeado e "Zerar" já aplicados):**
período (datas); recebidas, concluídas, pendentes, atrasadas, prazos próximos, taxa de conclusão, tempo médio de
conclusão (dias); os mesmos números do período anterior e da média dos OUTROS membros da equipe (sem listar quem são);
o tamanho da amostra (quantas metas por número) e as travas de lastro já calculadas (ex.: "sem comparação, passado
sem dado"); as marcas do perfil só como texto fixo de enum (status "em acompanhamento": sim/não), nunca o motivo.

**Pseudonimização da pessoa:** a IA nunca recebe nome, e-mail, id, cargo ou setor. O bloco usa o rótulo fixo
`Pessoa A` dentro de `<pessoa_do_relatorio>` (NÃO `<pessoa>`, que no chat da Central é o primeiro nome de quem
pergunta). A IA não conhece o escritório. A resposta é guardada ligada à pessoa por `alvo_id` (id do usuário), só no
nosso banco; o rótulo é trocado pelo nome na tela, no servidor, na hora de exibir.

**Nunca vai:** nome ou qualquer dado de cliente, parte, processo, NUP, número de pasta; texto livre de pasta,
meta, anotação, observação, documento, movimentação, comentário ou checklist; título de meta (é texto digitado);
dado financeiro; pasta sob cadeado oculta à pessoa (já fora dos números); pasta em segredo de justiça (fora por
construção: não entra pasta, só contagem); dado de ponto eletrônico, salário, saúde ou qualquer dado sensível
(LGPD art. 5º II); mensagens da Central; histórico de relatórios anteriores como entrada (cada pedido é independente,
evita viés de ancoragem); resposta anterior do modelo. Campo novo no prompt exige emenda desta spec.

Como não há texto livre, `Neutralizador` e `Mascarador` ficam como defesa em profundidade (o bloco é montado só de
números e rótulos fixos; o teste prova que nenhuma string de banco entra no prompt).

## 3. Privacidade e LGPD

### 3.1 Art. 20 (decisão automatizada) e art. 6º
- O relatório **não é decisão**: sem nota, ranking, score, semáforo de "risco da pessoa" nem recomendação de
  consequência. O prompt proíbe e o intérprete de resposta rejeita (lista de termos: nota, demissão, punir, advertir,
  desligar, ranking) e regenera uma vez; se persistir, a análise fica `falhou` com motivo fixo.
- Texto fixo na tela, acima do relatório: "Leitura gerada por IA a partir de números do Painel. Pode estar errada e
  não é avaliação da pessoa. Não use como único motivo para qualquer decisão sobre ela. Converse com a pessoa antes."
- O colaborador pode pedir revisão e explicação (art. 20 §1º): ver §3.4. A explicação dos critérios é exatamente a
  lista do §2, que o escritório pode entregar.

### 3.2 Base legal (proposta, a validar com o jurídico)
Dado de gestão de pessoas tratado pelo empregador: **legítimo interesse (art. 7º IX)** do escritório para organizar
o trabalho, com teste de balanceamento (LIA) registrado, não consentimento (em relação de emprego o consentimento é
frágil por assimetria). Os números já existem no Painel e já são vistos pelo gestor; a IA só os reescreve em texto.
O BlueJus é **operador**; o escritório é **controlador**. A Anthropic é suboperadora.

### 3.3 Provedor, ZDR e transferência internacional
Mesma cadeia de `docs/specs/ia-anthropic-ligar.md` §10.2 e `inteligencia-provedor-anthropic.md`: `inference_geo=us`
(decisão 24/10), sem Batch nem Files API. A transferência internacional se apoia nas cláusulas do DPA da Anthropic
(art. 33). Pelo decidido em 26/10 o ZDR só é pedido antes de abrir a outros escritórios; este recurso herda essa
regra (§5, D5). Como o prompt não leva nome nem texto de caso, o dado enviado é anonimizado de fato para o provedor
(sem chave de reidentificação com ele), o que reduz o risco mesmo sem ZDR.

### 3.4 Transparência e direito de revisão do colaborador
Recomendação: **a pessoa é avisada** de forma geral (política interna + termo, §5 D1), não a cada relatório. No perfil
"Meu Perfil" (futuro, fora de escopo) caberia mostrar "relatórios gerados sobre mim". Nesta entrega: registro
consultável por Master/primário de quando houve relatório (§3.6) para o escritório responder ao titular (art. 18).
O pedido de revisão é tratado pelo escritório (controlador); o BlueJus entrega a lista de campos (§2) e a data.

### 3.5 Minimização e retenção
Só agregados (§2). O relatório fica em `texto_da_analise` e expira pela retenção geral da IA
(`meses_de_retencao`, 12 padrão), que apaga o conteúdo e mantém a linha pelos números
(`ExpirarConteudoDaInteligenciaUseCase`). Recomendação para este tipo: prazo **mais curto, 90 dias**, fixo no
código (§5 D3), por ser dado sobre pessoa. Sai antes na exclusão do usuário (LGPD): a purga de usuário e a de
escritório passam a cobrir análises `relatorio_pessoa` com `alvo_id` = usuário.

### 3.6 Quem vê, auditoria, cadeado
- **Quem vê/gera:** a mesma regra do perfil (PR-I): Master ou primário ativo (`RegraDosPrimarios::podeGerirPrimarios`);
  os demais, 403. "Master" aqui = **primário ativo do escritório**; ROLE_SUPER_ADMIN só entra se estiver com sessão
  no escritório (sem seletor novo). O relatório é do **escritório**, visível a todo primário, não só a quem pediu
  (`VisibilidadeDaAnaliseDaPasta` e `RegistroDeAuditoriaOutput` têm `match` exaustivo: o tipo novo é decidido
  nos dois, não cai em default).
- **Nunca para a própria pessoa nem para pares:** a pessoa analisada não vê o texto gerado por padrão (decisão do
  controlador; D1). Gerar relatório sobre si mesmo: 422.
- **Auditoria:** `audit_log` à mão, como PR-I, `action` com no máximo 10 caracteres: `ia_relpes` (gerou), `ia_relver`
  (abriu um já gerado), `ia_relexc` (excluiu); `entity_class` User, `changes` só `{evento, pessoa_id, analise_id}`,
  sem nome nem texto. `ehParticular()` de `AnaliseDeInteligencia` passa a incluir `RelatorioDePessoa`, senão o
  texto do relatório vazaria no `audit_log` pelo `AuditLogSubscriber`.
- **Cadeado:** pasta oculta não alimenta. Os números vêm de `ObterDadosDashboardUseCase` com o cadeado repassado
  (como no PR-I); o worker REFAZ o cálculo com tenant explícito, não confia em números vindos da solicitação.
- **Exclusão LGPD:** botão "Excluir relatório" (apaga o conteúdo, mantém a linha); excluir a pessoa apaga os seus.

### 3.7 Vieses e texto de limites
Riscos: amostra pequena (poucas metas, percentual enganoso), período curto, carga desigual entre pessoas (número
bruto compara quem recebeu muito com quem recebeu pouco), metas de natureza diferente, reabertura que subestima o
passado (PR-M), "Zerar" que apaga linhas, ausência (férias, licença) que parece baixo desempenho.
Mitigação: o prompt recebe o tamanho da amostra e a regra "abaixo de 10 metas no período, descreva sem comparar e
diga que a amostra é pequena"; período menor que 14 dias gera só aviso, sem relatório; o texto sempre termina com o
bloco fixo "O que esta leitura não sabe" (férias, tipo de trabalho, qualidade, contexto pessoal, reaberturas). Esse
bloco é nosso, não do modelo.

## 4. Desenho técnico (fatias)

Dependência: **#439** (cria `FluxoDeAnaliseSemPasta`, `TipoDeAnalise::ChatDaCentral` e `historico`/`pessoa` em
`NeutralizadorDeConteudo::DELIMITADORES`) e a sessão IA. Partir do master depois do #439. O PR 2b da IA não toca
`TipoDeAnalise`, `Neutralizador` nem o handler.

- **Fatia A (feita):** perfil sem IA (PR-I, PR-M).
- **Fatia B (backend, sem tela), em `app/src/Inteligencia/`:**
  - `Enum/TipoDeAnalise.php`: `case RelatorioDePessoa = 'relatorio_pessoa'`.
  - `Fluxo/FluxoDoRelatorioDaPessoa implements FluxoDeAnaliseSemPasta` (modelo `FluxoDoChatDaCentral`): relê o
    usuário por id + tenant, reconfere vínculo ativo e que quem pediu ainda é primário, refaz os agregados com
    tenant explícito e cadeado; falha com motivo fixo (`\LogicException`) sem chamar o provedor.
  - `Prompt/PromptDoRelatorioDaPessoa` (`relatorio-pessoa-v1`): bloco `<pessoa_do_relatorio>` e `<numeros>`,
    ambos novos em `NeutralizadorDeConteudo::DELIMITADORES` (exige o #439 mergeado); temperatura por intenção.
  - `InterpretadorDeRespostaDoRelatorio`: rejeita termos de nota/punição (§3.1).
  - `UseCase/SolicitarRelatorioDaPessoaUseCase`: pessoa por id + tenant (404 se de fora), permissão do §3.6,
    `DisponibilidadeDeInteligencia`, idempotência por (pessoa, período) com pendente, período mínimo 14 dias,
    não é a si mesmo, grava `contexto_resumo` só com ids e contagens (nunca números nem texto), enfileira depois do commit.
  - `AnaliseDeInteligencia::ehParticular()`; ramos em `RegistroDeAuditoriaOutput` e `VisibilidadeDaAnaliseDaPasta`
    (`match` exaustivo; o teste cobre todos os cases do enum).
  - Retenção: prazo próprio de 90 dias em `ExpirarConteudoDaInteligenciaUseCase` para este tipo; purga de usuário.
  - Sem migration prevista (usa `analise_inteligencia` com `alvo_id`); confirmar se `alvo_tipo`/pasta nula basta.
  - Testes: unit do UseCase e do fluxo (prompt sem nenhuma string de banco; cross-tenant 404; pasta oculta fora;
    não-primário 403); `ProvedorFalso` em `app/tests/`.
- **Fatia C (tela):** em `_perfil_pessoa.html.twig` e `dashboard-perfil-pessoa.js`: botão "Gerar relatório com IA",
  aviso do art. 20, estados (indisponível, gerando, pronto, falhou, expirado), "Excluir relatório", texto
  "O que esta leitura não sabe". Só aparece com `ia_disponibilidade()` ligada; segue o dc pelo método da skill
  `desenho-fiel` (sem componentes do sistema). Rotas: `POST /dashboard/inteligencia/pessoa/{id}/relatorio`,
  `GET .../relatorio/{analiseId}` (polling), `DELETE` do relatório.
- **Custo e limite:** conta na cota diária/mensal e no orçamento da IA do escritório como qualquer análise
  (`CustoDaIa`); prompt pequeno (só números), saída até ~400 tokens; limite de 1 relatório por pessoa por dia.
- **Feature flag:** nova chave de módulo/permissão não; liga com a IA do escritório (`ia_disponibilidade()`) MAIS um
  interruptor próprio do escritório "Relatório de pessoa com IA", desligado por padrão (§5 D2).

## 5. Decisões do dono (leia esta seção)

| # | Decisão | Recomendação |
|---|---|---|
| D1 | A pessoa é avisada? | Sim, aviso geral na política interna e no termo de uso do escritório, texto pronto do BlueJus; a pessoa não vê o relatório, mas pode pedir revisão ao gestor. |
| D2 | Quem recebe? | Só o escritório piloto, interruptor próprio desligado por padrão, ligado pelo Master. |
| D3 | Retenção do relatório | 90 dias fixos (menor que os 12 meses da IA), apagável na hora pelo primário. |
| D4 | Quem gera e vê | Master do escritório = primário ativo (como o perfil); sem seletor de ROLE_SUPER_ADMIN. |
| D5 | Provedor e ZDR | Anthropic como está; ZDR só antes de abrir a outros escritórios (decisão de 26/10); aqui vale porque nada de nome ou caso vai. |
| D6 | Texto no contrato/termo | Sim: cláusula de que o escritório pode usar IA sobre números de trabalho, sem decisão automatizada; redação pelo jurídico. |
| D7 | DPO e RIPD | Fazer um RIPD curto antes de ligar no piloto e registrar a LIA; o escritório (controlador) indica o encarregado. |
| D8 | Liberar em produção | Só depois de D1, D6 e D7 prontos; antes disso a Fatia B pode entrar atrás do interruptor, sem tela. |

## 6. Risco

**ALTO.** Trata dado sobre pessoa natural no trabalho (LGPD art. 20, possível discriminação), manda dado a terceiro
no exterior e toca `User`/identidade e auditoria. Rigor: spec revisada, `feature-review-agent` antes e depois,
re-revisão em ALTO, teste cross-tenant e de cadeado, `match` exaustivo coberto. A fatia B sozinha (sem tela, atrás de
interruptor desligado) é MÉDIO.

## 7. Fora de escopo
Nota, ranking, score ou semáforo por pessoa; recomendar punição ou premiação; relatório de equipe; comparação
nominal entre pessoas; texto livre ou títulos de meta no prompt; "Explicar contexto" livre; "Redistribuir" (spec
`painel-acompanhamento-redistribuir.md`); tela "Meu Perfil" com relatórios sobre mim; exportar PDF; envio do
relatório por e-mail ou Central; agendamento automático (só sob pedido); IA lendo pasta, cliente ou processo.
