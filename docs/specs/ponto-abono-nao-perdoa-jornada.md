# Spec — Abono técnico não perdoa jornada; dia mal batido não vira falta

**Risco:** ALTO (ponto eletrônico) · **Deploy:** humano · **Base:** `master`
**Origem:** conferência das folhas reais da EDLUCIA LINS PEREIRA (user 11, tenant 1), 06 e 07/2026,
em `docs/folha-de-ponto/`. **Vale para todo colaborador de todo escritório.**

## O que estava errado

A aritmética das folhas estava certa — cada coluna e cada total fecham ao minuto. O que estava errado
era **o que o sistema decidia contar**. Dois defeitos independentes, que se cruzavam.

### Defeito 1 — o abono de esquecimento perdoava a jornada, não só a batida

Ao abonar um "Esquecimento de Registro", `TenantController::abonarJustificativa` **cria a batida que
faltava**. O dia passa a ter os quatro registros e o cálculo roda com os horários reais — a
justificativa já cumpriu seu papel ali. Mas ela seguia no dia como `abonado`, e o builder fazia
`if ($saldoDia < 0) { $saldoDia = 0; }`.

O efeito era duplo: além de repor o horário, o abono apagava **atraso e saída antecipada**, que nada
têm a ver com a batida esquecida. Em 18/06 a colaboradora chegou 43 min depois e saiu 48 min antes;
o esquecimento de *uma* batida perdoou os dois. Foram **21 dias com esquecimento em dois meses** —
quase metade dos dias úteis.

### Defeito 2 — dia com batida incompleta virava falta cheia

`CalculadoraJornada::calcularMinutosTrabalhados` só reconhecia **as 4 batidas** ou **entrada + saída**;
qualquer outra combinação devolvia `0`, e `calcularSaldoDia` fazia `trabalhado − carga`. Um dia em que
a pessoa bateu a entrada e esqueceu o resto virava **−8:48**. Em julho isso ocorreu em 21, 24, 29 e 31
— **−35:12** num documento assinado.

No sentido oposto, sem batidas de intervalo o span inteiro contava como trabalhado: 27/07
(09:02→18:10) creditou 9:07 com o almoço dentro. O cálculo nunca aproveitava o que dava para medir —
ora jogava o dia fora, ora não descontava nada.

**Os dois se cruzavam:** 24/06 e 16/07 têm batida incompleta e só não apareciam porque o abono do
defeito 1 os estava tapando. Nos 4 dias de julho em que ninguém lançou o abono, o defeito 2 apareceu
inteiro.

## Regra

### 1. Justificativa da categoria `Tecnica` não zera saldo negativo

`TipoJustificativa::abonaSaldo()` devolve `false` para toda a categoria `Tecnica` — Esquecimento de
Registro, Registro Incorreto, Ajuste Manual Autorizado, Falha de Sistema, Correção de Ponto — e para
`FaltaNaoJustificada`. Todas corrigem o **registro**; nenhuma justifica **ausência**.

Elas continuam aparecendo na coluna de observações e continuam repondo a batida na aprovação. O que
deixam de fazer é perdoar o déficit que sobra depois da reposição.

A regra mora no enum, não no builder: a categoria já existe em `categoria()`, e um tipo técnico novo
herda o comportamento sem que ninguém precise lembrar de editar o cálculo.

**Retrocompatibilidade:** justificativa com `tipo` nulo ou com slug desconhecido continua abonando,
como sempre fez. O ramo que zera é o default; só os tipos listados saem dele.

### 2. Dia com registro incompleto → saldo 0, nem crédito nem débito

Um dia é **apurável** quando as batidas presentes cobrem os tipos que a escala daquele dia pede
(`JornadaResolver::tiposEsperadosNoDia`). Faltando qualquer um, o dia é **incompleto**: saldo 0 e
marcador de pendência.

- **Dia útil cuja escala prevê intervalo** exige os quatro tipos. É o que tira 27/07 do crédito
  indevido do almoço e tira 21, 24, 29 e 31/07 da falta cheia.
- **Dia fora da escala** (sábado, domingo, feriado) não tem intervalo previsto: **entrada + saída
  basta**. Exigir quatro batidas ali seria exigir o que a escala não pede — e descartaria hora
  extra real de fim de semana (o sábado 13/06, 4:59, continua creditando).
- **Dia sem batida nenhuma não é incompleto, é ausência** — continua gerando débito. 09, 15 e 17/07
  são falta mesmo. Sem essa distinção, a regra apagaria toda falta do sistema.

**Num dia incompleto a justificativa não altera o zero**, nem o abono parcial. A pendência é o sinal
para o admin **corrigir as batidas**; abonar por cima devolveria ao dia um número que ninguém mediu.
É essa premissa que faz junho cair de +22:51 para +6:29: os "Ajuste de Jornada (Parcial)" de 01, 02,
05, 09, 10 e 11/06 caem em dias incompletos e param de somar.

### 3. Duas justificativas no mesmo dia: vence a que abona

O sistema **aceita** várias justificativas por data — não há constraint única em `(user_id, data)`, e
isso é intencional: o caso comum é esquecer duas batidas no mesmo dia e lançar uma para cada, com o
horário de cada uma. Medido em produção: **34 dias com 2 a 3 justificativas**, 28 deles só
esquecimentos, em 5 colaboradores diferentes.

Mas o cálculo só honra uma por dia, e a regra 1 tornou a escolha relevante: um dia com
`esquecimento_registro (abonado)` **e** `atestado_medico (abonado)` — existe, 02/06/2026 — daria
saldos opostos conforme quem vencesse. A consulta ordenava só por `data`, então entre linhas da mesma
data o SGBD não garantia ordem: **o saldo do dia podia mudar de um carregamento para o outro**.

`JustificativaPontoRepository::indexarPorDia` passa a escolher por mérito:
**abonada que perdoa o déficit** > abonada que não perdoa (técnica, falta não justificada) >
pendente/rejeitada. Empate: a última da ordem recebida, que é a de maior `id` porque a consulta
ganhou `addOrderBy('j.id', 'ASC')` — a ordenação faz parte da regra, não é enfeite.

Se o admin deferiu um atestado naquele dia, houve ausência justificada; um esquecimento lançado ao
lado não apaga isso. Escolher pelo que abona também reproduz o comportamento anterior a
`abonaSaldo()` (qualquer abonada zerava), então **nenhum dia passa a ser cobrado a mais por causa do
desempate**.

Decisão do dono: **não bloquear** a criação de justificativas repetidas — 28 dos 34 casos são uso
legítimo, e barrar quebraria um fluxo que cinco pessoas usam.

### 4. A coluna "Horas Trabalhadas" continua mostrando o medido

A regra 2 é sobre o **saldo**. Onde há como medir a permanência, ela continua exibida — assim
"Detalhe de Horas Trabalhadas no Mês" não despenca no documento assinado por causa de uma batida
faltante.

### 5. A pendência aparece só nas telas internas

`_folha_table.html.twig` (que serve as duas telas: `/ponto` do colaborador e a ficha do admin) ganha
o marcador. **PDF e XLSX não mudam de layout** — decisão do dono: o documento assinado não anuncia
pendência operacional.

## Efeito medido

| | Antes | Depois |
|---|---|---|
| Junho/2026 | +22:51 | **+6:29** |
| Julho/2026 | −62:02 | **−30:33** |
| Banco no fim de julho (anterior de maio mantido, +9:40) | **−29:31** | **−14:24** |

⚠️ **Muda o saldo de todo colaborador de todo escritório**, e vale **retroativamente**: folha
reimpressa de mês já emitido sai diferente da assinada. Mesma classe de impacto do deploy de horas
pagas de 31/07.

## Dado histórico que fica a descoberto (lançamento na tela, não código)

**03/06 e 12/06** caem no período de meio período da EDLUCIA (18/05–12/06, 5h/dia). Os outros 7 dias
úteis do período receberam "Ajuste de Jornada" (+3:48); esses dois **não receberam porque já tinham
Esquecimento**, que zerava o déficit por acidente. Sem o zero, passam a ser cobrados contra 8:48 — e
precisam de Ajuste de Jornada lançado na tela.

Ver `project_ponto_edlucia_correcao` para o contexto da rebaseline de meio período.

## Fora do escopo (medido e descartado)

- **A meta de 8:48 está certa** — 44h ÷ 5 dias, confirmada pelo dono e provada em três dias do próprio
  PDF (30/06, 14/07, 22/06). Os dias 18 e 19/06 fecharam negativo, não positivo: a coluna "Horas
  Extras" só imprime valor positivo, e o pouco de negativo que havia foi apagado pelo abono.
- **`jornada_colaborador.carga_horaria_diaria = 480`** existe em produção (conferido na VPS) mas é
  **inerte**: `JornadaResolver` prioriza o bloco (528 min) e o campo plano só é lido no fallback de
  quem não tem bloco. Não aparece em template nenhum.
- **`diffMinutos` descarta os segundos** (`$diff->i`), perdendo até 1 min por par de batidas, sempre
  contra o colaborador. Registrado, não corrigido nesta frente — não muda o sinal de nenhum dia
  apurado aqui.

## Verificação

- Unit: `TipoJustificativaTest` (`abonaSaldo` por categoria), `CalculadoraJornadaTest` (incompleto por
  forma da escala; **dia sem batida continua −carga**), `JornadaResolverTest` (`tiposEsperadosNoDia`
  nas três camadas da cascata), `FolhaPontoBuilderTest` (técnico não zera; legal/operacional continua
  zerando; parcial continua somando; dia incompleto fica em 0 mesmo com justificativa; tipo `null`
  continua zerando).
- Unit: `JustificativaPontoIndexacaoTest` — desempate por mérito, e sobretudo **o mesmo resultado com
  a ordem de entrada invertida**, que é a asserção que fecha a porta do não-determinismo.
- Functional: reprodução de 06 e 07/2026 da EDLUCIA com as batidas e justificativas exatas dos PDFs.
  É o único teste que pega os dois defeitos interagindo. ⚠️ Ele assere **+6:40** e **−30:22**, não os
  números de produção acima: o PDF só publica HH:MM e `diffMinutos` descarta os segundos (~11 min/mês),
  que não são recuperáveis do PDF. **Não "corrigir" o teste para bater com o documento.**
- **Prova por injeção:** reverter cada regra separadamente e confirmar vermelho. Teste verde não prova
  nada até a prova ser provada.
- Sem migration — o banco de horas é 100% calculado ao vivo.
