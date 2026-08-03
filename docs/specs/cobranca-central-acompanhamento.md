# Central de Acompanhamento da Cobrança — Fatia 1 (aba Atividade)

> Spec de design validada com o dono do projeto em 2026-07-22 (brainstorm). Risco **MÉDIO-baixo**:
> não escreve nada no domínio nem toca no caminho do dinheiro, mas cria rota/tela nova que expõe
> desempenho individual e agrega dados de vários casos — exige guarda multi-tenant explícita.
>
> **Fatia 1 é a única coberta por esta spec.** As demais abas ganham spec própria quando chegar a vez.

## 1. O problema

O escritório tem funcionárias dedicadas a cobrar inadimplentes. Hoje ninguém consegue responder
"o que a equipe fez esta semana": o painel existente (`/cobrancas/painel`,
`MontarDashboardCobrancaUseCase`) é financeiro e por carteira — saldo, recuperado, honorários, taxa de
recuperação. Não existe nenhuma visão **por pessoa** e **por período**.

A matéria-prima já é registrada: `cobranca_evento_historico` guarda, para cada caso, o que aconteceu
(22 tipos), quando, **quem fez** e um payload JSON. Medição no dev em 2026-07-22: **658 de 658 eventos
têm usuário preenchido**. A feature é, portanto, de LEITURA — não de instrumentação.

## 2. Decisões tomadas no brainstorm

| Decisão | Escolha do dono | Consequência |
|---|---|---|
| Estrutura | Central com 4 abas: Atividade · Resultado · Pendências · Extrato do devedor | Fatiado; Fatia 1 = Atividade + a base que as outras reusam |
| O que conta como trabalho | **Volume e efetividade lado a lado**, sem nota única nem ranking | Colunas separadas de tentativa e de desfecho útil; nada de score |
| Acesso | **Todos veem tudo** (dentro do tenant) | Sem permissão nova; o gate é o próprio módulo `cobrancas` |
| Saída | **Tela agora**, exportação depois | Nenhum PDF/Excel nesta fatia |
| Abordagem | **A — leitura pura** do que já se registra | Nenhuma escrita nova, nenhum evento novo, nenhum job |

Ressalva registrada e assumida pelo dono: painel de desempenho visível a todos costuma gerar atrito
entre colegas. Restringir depois é um filtro, não uma refatoração.

## 3. Escopo

**Dentro da Fatia 1:**

- rota `/cobrancas/central` com o esqueleto das 4 abas (só Atividade preenchida; as outras exibem
  "em construção");
- tabela de atividade por pessoa, com total do setor;
- detalhe ao clicar numa pessoa (desfechos + lista de eventos);
- filtros de período e carteira;
- índice novo para as consultas por período.

**Fora da Fatia 1** (não implementar, mesmo que pareça fácil):

- abas Resultado, Pendências e Extrato do devedor;
- exportação PDF/Excel;
- metas, ranking, score, semáforo de desempenho;
- qualquer alteração no registro de contato (o modal atual fica **como está** — decisão do dono);
- coluna "Cadastros atualizados" (ver §9).

## 4. A tela

```
┌─ CENTRAL DE COBRANÇAS ──────────────────────────────────────────────┐
│  [ Período: Esta semana ▾ ]   [ Carteira: Todas ▾ ]                  │
├─ Atividade │ Resultado │ Pendências │ Extrato do devedor ────────────┤
│                                                                      │
│  QUEM              CONTATOS  FALOU COM   ACORDOS   BAIXAS   ÚLTIMA   │
│                              O DEVEDOR   FECHADOS  REGISTR.  AÇÃO    │
│  ──────────────────────────────────────────────────────────────────  │
│  TOTAL DO SETOR         142         58         7       12   há 4 min │
│    Maria                 61         27         4        5   há 4 min │
│    Joana                 49         19         2        6   há 1 h   │
│    Cláudia               32         12         1        1   ontem    │
│    Samuel                 0          0         0        0   —        │
└──────────────────────────────────────────────────────────────────────┘
```

**Faixa de canais, acima da tabela** (respeitando os filtros de período e carteira) — resposta direta à
pergunta do dono "quantos contatos e por qual meio" (levantada em 2026-07-22):

```
Contatos no período: 142   ·   Telefone 71 · WhatsApp 54 · E-mail 12 · SMS 5
```

Clicar numa linha abre, abaixo dela, o detalhe daquela pessoa no período:

- **desfechos em pastilhas**: Atendido 27 · Não atendido 21 · Caixa postal 6 · Número errado 4 ·
  Pediu retorno 2 · Informou outro número 1 · Outro 0;
- **canais em pastilhas**, na mesma linha visual dos desfechos: Telefone 34 · WhatsApp 21 · E-mail 5 ·
  SMS 1 — mesma consulta, trocando `resultado` por `canal` no payload;
- **lista dos eventos** (hora · tipo · objeto · resumo), do mais recente para o mais antigo, limitada a
  200 itens com aviso quando truncar — mostrando por padrão só trabalho de cobrança, com o restante
  resumido numa linha expansível (§5.1).

**Quem não trabalhou aparece zerado, não some da lista.** É a informação que o gestor foi procurar;
uma lista que esconde os zerados esconde justamente o que ele quer ver. Entram na listagem todos os
usuários **ativos do tenant** que têm acesso ao módulo `cobrancas`, mesmo com zero eventos.

## 5. Definição exata de cada métrica

Fonte única: tabela `cobranca_evento_historico`, agrupada por `usuario_id`, filtrada por `tenant_id` e
pela faixa de `ocorrido_em`.

| Coluna | Regra |
|---|---|
| **Contatos** | `tipo = 'contato_realizado'` — toda tentativa, com ou sem sucesso |
| **Falou com o devedor** | `tipo = 'contato_realizado'` **e** `dados->>'resultado' = 'atendido'` |
| **Acordos fechados** | `tipo = 'acordo_criado'` |
| **Baixas registradas** | `tipo IN ('pagamento_registrado', 'liquidacao_registrada')` |
| **Última ação** | `MAX(ocorrido_em)` **restrito aos tipos de trabalho de cobrança** (ver §5.1), no período |
| **Detalhe: desfechos** | `tipo = 'contato_realizado'`, agrupado por `dados->>'resultado'`, rotulado por `ResultadoContato::label()` |
| **Contatos por canal** | `tipo = 'contato_realizado'`, agrupado por `dados->>'canal'`, rotulado por `CanalContato::label()` — Telefone · WhatsApp · E-mail · SMS |

Notas obrigatórias para quem implementar:

1. O desfecho mora no **payload JSON** (`dados->>'resultado'`), gravado por
   `RegistrarTentativaCobrancaUseCase` com o `value` do enum `ResultadoContato` — nunca o label.
2. `ResultadoContato::PrometeuPagar` **não é mais selecionável** (saiu do formulário no ajuste de
   2026-07), mas ainda existe no enum para ler histórico antigo. O detalhe deve exibi-lo **apenas se
   houver ocorrência** no período — não listar como pastilha zerada.
3. `usuario_id` é nullable (`onDelete: SET NULL`). Eventos sem usuário entram numa linha
   **"Sem responsável"** ao final da tabela, nunca somem nem são atribuídos a alguém.
4. **"Baixas registradas" não é mérito de negociação** — quem registra o pagamento nem sempre é quem
   negociou. Por isso a coluna conta o ato, e **valor recuperado não entra nesta aba** (fica na aba
   Resultado, por carteira). Não adicionar coluna de R$ por pessoa nesta fatia.

## 5.1 Trabalho de cobrança × lançamento de cadastro (correção de 2026-07-22)

A primeira versão desta spec mandava o detalhe listar **todos** os eventos e a "Última ação" ser o
`MAX` de qualquer tipo. Isso contradizia o próprio §12: rejeitamos o `audit_log` porque uma importação
inflaria os números, mas deixamos a mesma distorção entrar pela fonte escolhida. Medição no dev:
**537 `obrigacao_criada` + 121 `caso_aberto`** vindos de uma importação contra **3 contatos** reais —
o trabalho de cobrança afundava, e quem só importou planilha aparecia com "ação recente" sem ter
falado com ninguém. Decisão do dono em 2026-07-22: **separar**.

**Tipos que contam como trabalho de cobrança:**
`contato_realizado` · `boleto_enviado` · `novo_prazo` · `negociacao` · `acordo_criado` ·
`acordo_editado` · `acordo_rompido` · `acordo_cancelado` · `acordo_cumprido` ·
`pagamento_registrado` · `pagamento_corrigido` · `liquidacao_registrada` ·
`pessoa_cobrada_alterada` · `judicializacao` · `vinculo_pasta` · `encerramento` · `anotacao` ·
`qualificacao_contato`

> `qualificacao_contato` entrou em 2026-07-27 (spec `cobranca-objeto-show-cabecalho-responsaveis.md`):
> carimbar recusa de pagamento / telefone inexistente / promessa de pagamento sai de uma ligação, não
> de uma planilha. É trabalho de cobrança e conta aqui.

**Tipos de cadastro/importação (fora):**
`caso_aberto` · `obrigacao_criada` · `obrigacao_editada` · `obrigacao_excluida` ·
`valor_atualizado_reconhecido` · `revisao_vinculo` · `pagamento_excluido`

> `pagamento_excluido` entrou em 2026-08-01 (spec `cobranca-excluir-recebimento.md`) e ficou **fora**:
> apagar um recebimento lançado por engano é correção administrativa, não uma das quatro ações do corte
> (falar, negociar, receber, encaminhar) — mesmo critério de `obrigacao_excluida`. Contar aqui faria
> quem só desfez o próprio erro aparecer com "ação recente" sem ter cobrado ninguém.
>
> ⚠️ **Resíduo conhecido:** o evento `pagamento_registrado` do recebimento apagado PERMANECE (não há FK
> entre `cobranca_evento_historico` e `cobranca_pagamento`), então o contador de baixas segue contando
> aquela baixa. Decisão consciente: o histórico registra o que aconteceu, e o lançamento aconteceu —
> apagar evento passado contradiria o desenho de rastro firmado na frente `cancelar acordo`.

Aplicação:

1. **"Última ação"** considera apenas a primeira lista. Quem só importou aparece com `—`, que é a
   informação correta: importar planilha não é cobrar ninguém.
2. **Detalhe da pessoa** mostra por padrão apenas trabalho de cobrança, e **resume o restante numa
   única linha** — "+ 537 lançamentos de cadastro/importação" — que o usuário pode expandir. Não
   esconder em silêncio: o §12 proíbe truncar sem avisar, e isto é truncagem por tipo.
3. As **colunas da tabela** não mudam: nenhuma delas contava evento de cadastro.
4. O corte é por **tipo**, não por origem. Uma obrigação criada à mão continua fora da "última ação" —
   é trabalho de cadastro, e distinguir importação de digitação exigiria marcar a origem no evento,
   o que é escrita nova (fora da abordagem A).

## 6. Filtros

**Período:** Hoje · Ontem · Esta semana · Este mês · Personalizado (duas datas). Padrão: **Hoje**
(revisar quando o dono responder a pergunta 1.4 do questionário — ver §11). O intervalo é fechado no
início e aberto no fim (`>= inicio AND < fimExclusivo`), sempre no fuso da aplicação.

**Carteira:** "Todas" ou uma carteira específica. O caminho do join é
`evento_historico → caso → objeto → carteira` (`ObjetoCobranca::$carteira`). Filtrar por carteira
restringe todas as colunas e o detalhe.

## 7. Acesso e multi-tenancy

- Gate: o mesmo do módulo, via trait `AutorizacaoCobranca` (`MODULO_COBRANCAS = 'cobrancas'`) — quem já
  entra em Cobranças abre a central. **Sem permissão nova** (decisão "todos veem tudo").
- **Toda** consulta filtra por `tenant_id` do `TenantContext`. Nenhum id vem do cliente sem validação de
  posse: o filtro de carteira resolve a carteira por id **+ tenant**, e carteira de outro escritório
  responde 404, não lista vazia.
- Teste cross-tenant é obrigatório (§10).

## 8. Arquitetura

Segue o fluxo padrão do projeto (`Request → Controller → UseCase → Repository → DTO`):

| Camada | Arquivo | Responsabilidade |
|---|---|---|
| Controller | `App\Cobranca\Controller\CentralController` | rota, gate, resolução dos filtros, render. Usa `AutorizacaoCobranca` |
| UseCase | `App\Cobranca\UseCase\MontarAtividadeEquipeUseCase` | orquestra: resolve período/carteira, chama o repositório, monta os DTOs, inclui os usuários zerados |
| Repository | métodos novos em `App\Cobranca\Repository\EventoHistoricoRepository` | **agregação em SQL/DQL**, nunca em PHP sobre a coleção inteira |
| DTO | `AtividadeEquipeOutput` (a tabela) e `AtividadePessoaOutput` (a linha) | saída tipada para o Twig; nada de entidade Doctrine no template |
| Template | `templates/cobranca/central/index.html.twig` + partial `_aba_atividade.html.twig` | tema-aware (claro/escuro), sem rolagem horizontal |

O detalhe da pessoa é uma **segunda rota** (`/cobrancas/central/atividade/{usuarioId}`), carregada sob
demanda — não vem embutida na tabela, para a primeira consulta não crescer com o número de pessoas.

## 9. Performance e índices

Os índices atuais de `cobranca_evento_historico` são `(tenant_id)`, `(caso_id)`, `(usuario_id)` e
`(tenant_id, caso_id)` — **nenhum serve para filtrar por período**, que é o eixo desta tela.

**Migração necessária:** criar índice `(tenant_id, ocorrido_em)`.

```sql
CREATE INDEX idx_cobranca_evento_tenant_ocorrido ON cobranca_evento_historico (tenant_id, ocorrido_em);
```

A agregação deve ser **uma consulta** com `GROUP BY usuario_id` e contagens condicionais
(`COUNT(*) FILTER (WHERE ...)`), não uma consulta por pessoa nem hidratação de entidades. Referência de
volume: 3.638 eventos no dev após uma importação; produção crescerá com o uso diário.

**Coluna "Cadastros atualizados" fica FORA desta fatia.** Ela viria de `audit_log`, e a medição de
2026-07-22 mostrou que a fonte não sustenta o número: dos 344 registros de `Cobranca\Entity\Pessoa`,
apenas **137 têm `actor_user_id`** (o restante veio de importação/console, sem usuário HTTP) e
`entity_id` é **nulo em 100 % das criações** (o subscriber resolve o id antes de ele existir no
`onFlush`). Publicar essa coluna seria publicar um número que subestima o trabalho de quem atualiza
cadastro. Saídas possíveis, para decisão futura: corrigir o `AuditLogSubscriber` para preencher
`entity_id` no `postFlush`, ou gravar evento de domínio próprio ao atualizar telefone/e-mail/endereço
(era a "abordagem B", recusada nesta rodada).

## 10. Testes obrigatórios

**Unit** (`tests/Cobranca/Unit/MontarAtividadeEquipeUseCaseTest.php`):

- conta contatos, atendidos, acordos e baixas corretamente a partir de um conjunto conhecido;
- usuário sem nenhum evento aparece com zeros;
- eventos com `usuario_id` nulo caem em "Sem responsável";
- evento fora da faixa de período não entra;
- desfecho lido do payload, incluindo o legado `prometeu_pagar` quando presente.

**Functional** (`tests/Cobranca/Functional/CentralControllerTest.php`):

- `/cobrancas/central` responde 200 para quem tem o módulo e nega quem não tem;
- **isolamento cross-tenant**: evento de outro escritório não aparece em nenhuma contagem;
- filtro de carteira de **outro tenant** responde 404 (anti-IDOR), não lista vazia;
- filtro de período reflete na tabela;
- rota de detalhe responde 200 e lista os eventos da pessoa.

Suíte inteira verde antes de commitar (`tests/Cobranca` e global).

## 11. Riscos e pendências

1. ✅ **Respondido em 2026-07-22** (pelo intermediário do dono, questionário em
   `docs/gestao-cobrancas/PERGUNTAS_CENTRAL_COBRANCAS.md`): **o que o dono quer ver é** quantos
   contatos foram realizados, **por qual meio**, quantos acordos fechados e quantos pagamentos. Os
   quatro estão cobertos — os três últimos já eram colunas; o "por qual meio" entrou como faixa de
   canais (§4) e pastilhas no detalhe (§5).
2. ✅ **RESOLVIDO — promessa de pagamento NÃO existe no negócio.** Perguntado em 2026-07-22: no fluxo
   real o devedor não "promete pagar" — ou se **gera um novo boleto**, ou se **fecha um acordo**, ou
   ele **pede para retornar depois**. Os três já são registráveis hoje (`boleto_enviado`,
   `acordo_criado` e o desfecho `Pediu retorno`). **Nenhuma feature de escrita é necessária**, e os
   tipos órfãos `NovoPrazo`/`Negociacao` seguem sem uso — candidatos a remoção em limpeza futura.
3. ✅ **A equipe registra toda tentativa de contato** (confirmado em 2026-07-22), inclusive quando
   ninguém atende. A central nasce com lastro — o risco de "tela vazia com todo mundo trabalhando"
   não se aplica.
3.1. **Parâmetro para a Fatia 3 (Pendências):** devedor **sem contato há mais de 60 dias** é o corte
   de "esquecido", definido pelo dono em 2026-07-22.
4. `MontarDashboardCobrancaUseCase` documenta um teto conhecido de bind params com dezenas de milhares
   de casos. A central **não** deve repetir o padrão de carregar ids em `IN (...)`: agregue no banco.

## 12. O que NÃO fazer

- Não mexer no registro de contato (modal, form, UseCase) — congelado por decisão do dono.
- Não criar score, nota, ranking ou semáforo de desempenho.
- Não somar valor recuperado por funcionária nesta aba.
- Não usar `audit_log` como fonte de produtividade (uma importação gera milhares de linhas de
  auditoria: quem importou apareceria como a pessoa mais produtiva do mês).
- Não implementar as outras três abas "já que a base está pronta".
