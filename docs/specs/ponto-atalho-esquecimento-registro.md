# Atalho "+" para justificar Esquecimento de Registro na folha do `ponto_index`

**Risco:** ALTO (ponto eletrônico).
**Data:** 2026-07-28.
**Status:** implementado e revisado (`feature-review-agent`); **não commitado**. Sem migration.

## Problema

Na tela `/ponto/` o colaborador que esquece uma batida (ex.: bateu a entrada e esqueceu a saída)
precisa hoje abrir o modal grande **"Justificar Falta"**, escolher a data num calendário, achar
"Esquecimento de Registro" numa lista agrupada de 6 categorias, escolher de novo qual batida
esqueceu e só então digitar a hora. Quatro decisões para um caso em que **a tabela já sabe** o dia
e a batida faltante — a célula está vazia bem na frente dele.

## O que muda

Na célula vazia da folha aparece um **"+"**. Clicando, abre um modal enxuto que pede **só a hora**.
Data, tipo (`esquecimento_registro`) e qual batida (`entrada`/`repouso`/`retorno`/`saida`) saem da
própria célula.

Nada muda no backend de cálculo, aprovação ou criação de `RegistroPonto`. O atalho **reaproveita
integralmente** o fluxo que já existe: mesma rota, mesmo Form, mesmo status inicial (`pendente`),
mesma notificação ao gestor, mesma aprovação (que já cria o `RegistroPonto` a partir de
`data + horaRegistroEsquecido`).

## Decisões do dono (2026-07-28)

1. **Dias sem nenhuma batida continuam fora.** Esse caso continua pelo botão "Justificar Falta".
   *Não* vamos passar a listar dias vazios.

   ⚠️ **Correção da premissa (achada na revisão).** A spec original dizia que esses dias "não têm
   linha na tabela". **Falso.** O filtro de `FolhaPontoBuilder.php:184-193` descarta o dia quando
   não há batida **e** não há justificativa — ou seja, **dia sem batida nenhuma mas com
   justificativa aparece na folha**, com as 4 células vazias. No dump de dev isso são 20 dias reais
   (13 atestados abonados, folga de aniversário, emergência familiar). Sem guard, cada um desses
   dias exibiria 4 botões "+" e o colaborador poderia, em dois cliques, pedir batidas num dia já
   fechado por abono — que, aprovado, faria o dia render **saldo positivo** (o abono só zera saldo
   negativo, `FolhaPontoBuilder.php:161-171`). Por isso existe a **condição 9** abaixo.
2. **O "+" só aparece em dia útil e não-futuro.** Fim de semana, feriado e data futura ficam sem o
   botão — o backend já recusa data futura, então ali o botão só frustraria.
3. **Sem duplicata.** Se já existe justificativa de esquecimento `pendente` ou `abonado` para
   aquele **mesmo dia + mesma batida**, a célula mostra um indicador de "aguardando análise" em vez
   do "+".
4. **POST normal** (sem AJAX), pela rota que já existe, preservando a competência selecionada no
   redirect.

## Regra de exibição do "+" (célula a célula)

Mostra o "+" quando **todas** valerem:

| # | Condição | Origem |
|---|---|---|
| 1 | a célula está vazia (`tipoInfo.id` nulo) | `folhaRows` |
| 2 | o modo admin de editar batidas está **desligado** (`editarBatidasHabilitado = false`) | parâmetro do partial |
| 3 | o atalho está habilitado na tela (`justificarFaltanteHabilitado = true`) | parâmetro novo |
| 4 | não é fim de semana (`linha.fimSemana`) | `folhaRows` |
| 5 | não é feriado (`linha.isFeriado`) | `folhaRows` |
| 6 | não é dia futuro (`linha.chaveDia <= hoje`) | parâmetro novo `dataHoje` |
| 7 | não é dia anterior ao início da contagem (`linha.antesDoPrimeiroRegistro`) | `folhaRows` |
| 8 | não há esquecimento `pendente`/`abonado` para `dia + batida` | mapa novo |
| 9 | **o dia tem ao menos uma batida** (`entradaId`/`repousoId`/`retornoId`/`saidaId`) | `folhaRows` |

Todos os defaults dos parâmetros novos são **fail-closed**: sem `dataHoje`, a condição 6 não some —
o "+" simplesmente não aparece. Esquecer de passar um parâmetro tira o atalho, nunca afrouxa a regra.

Sobre a **condição 7**: dia anterior ao início da contagem não entra no saldo; pior, uma batida
retroativa criada ali **moveria o início da contagem do colaborador** (ver
`InicioContagemResolver`), mudando a folha inteira. O atalho não é lugar para isso.

Sobre a **condição 8**: hoje nada no sistema impede o colaborador de mandar a mesma justificativa
duas vezes (`findOneByUserAndData` existe no repositório mas não é chamado por nenhum controller).
O atalho não vai criar um caminho ainda mais fácil para gerar fila duplicada no gestor. Note que
`abonado` importa de fato: a **aprovação em lote não cria o `RegistroPonto`** (lacuna conhecida),
então existe justificativa aprovada convivendo com célula vazia.

## Contrato do POST

Rota existente `ponto_justificativa_nova` (`POST /ponto/justificativa/nova`), Form
`JustificativaPontoType` (`csrf_token_id: justificativa_ponto`):

```
justificativa_ponto[_token]                 = csrf_token('justificativa_ponto')
justificativa_ponto[datas]                  = "YYYY-MM-DD"        (linha.chaveDia — uma única data)
justificativa_ponto[tipo]                   = "esquecimento_registro"
justificativa_ponto[tipoRegistroEsquecido]  = "entrada"|"repouso"|"retorno"|"saida"
justificativa_ponto[horaRegistroEsquecido]  = "HH:MM"             (único campo digitado)
competencia                                 = "YYYY-MM"           (fora do form; só para o redirect)
```

Resultado: `JustificativaPonto` com `status = pendente`, notificação aos gestores e — na aprovação
individual — criação automática do `RegistroPonto`. **Nenhuma regra nova de negócio.**

## Arquivos alterados

| Arquivo | Mudança |
|---|---|
| `app/src/Ponto/Controller/PontoController.php` (`index`) | monta o mapa `dia\|batida ⇒ true` dos esquecimentos pendentes/abonados a partir da lista de justificativas **já carregada** (sem query nova) e passa `dataHoje` ao template |
| `app/src/Ponto/Controller/PontoController.php` (`novaJustificativa`) | preserva a competência no redirect quando o POST envia o campo `competencia` (formato validado `^\d{4}-\d{2}$`) |
| `app/templates/ponto/_folha_table.html.twig` | novos parâmetros `justificarFaltanteHabilitado` / `justificarFaltanteEsquecimentos` / `dataHoje` (todos com default inerte) e o "+" na célula vazia |
| `app/templates/ponto/index.html.twig` | liga o atalho no include e inclui o modal novo |
| `app/templates/ponto/_justificativa_esquecimento_modal.html.twig` | **novo** — modal enxuto + o listener delegado que o abre |
| `app/src/Ponto/Form/JustificativaPontoType.php` | corrige o deprecation do constraint `File` (array de opções → argumentos nomeados). Não é escopo da feature: o form não tinha teste nenhum, e o teste novo passou a construí-lo, derrubando a suíte por `failOnDeprecation` |
| `app/tests/Ponto/Functional/JustificativaEsquecimentoControllerTest.php` | **novo** — ver abaixo |

**Sem migration. Sem alteração de entidade, enum, cálculo de saldo ou fluxo de aprovação.**

### Compatibilidade

`_folha_table.html.twig` tem exatamente dois includes: `ponto/index.html.twig` e a tela do admin
`tenant/edit_user_role.html.twig`. As exportações **não** usam este partial (o PDF renderiza
`ponto/folha_pdf.html.twig` e o XLSX é montado em PHP). Todos os parâmetros novos nascem com
`|default()` inerte — quem não os passa
renderiza exatamente o mesmo HTML de hoje. O `editarBatidasHabilitado` (admin) tem precedência: o
"+" novo só existe no ramo em que hoje se imprime o horário puro.

O `competencia` no redirect só age para quem envia o campo; o modal grande atual não envia, então
seu comportamento fica idêntico.

## Testes

Não existe **nenhum** teste funcional do `POST /ponto/justificativa/nova` hoje — o happy path da
criação de justificativa está descoberto. Como o atalho passa exatamente por ali, a spec inclui
fechar essa lacuna:

1. **Happy path do atalho** — POST com o contrato acima cria `JustificativaPonto` com
   `tipo = esquecimento_registro`, `status = pendente`, `tipoRegistroEsquecido` e
   `horaRegistroEsquecido` corretos; redirect 302 **preservando `?competencia=`**.
2. **Hora ausente** — POST sem `horaRegistroEsquecido` não cria nada e devolve o flash de aviso.
3. **CSRF inválido** — não cria nada.
4. **O "+" aparece** — `GET /ponto/?competencia=<mês anterior>` num dia útil passado com entrada
   registrada e saída faltando: botão presente na célula de saída, ausente na de entrada.
5. **O "+" some com esquecimento pendente** — mesmo cenário, com justificativa pendente de
   `saida` naquele dia: botão ausente.
6. **Dia sem batida não oferece atalho** (condição 9) — dia com abono de dia inteiro e nenhuma
   batida: zero botões, provando antes que a linha **está** renderizada (senão a asserção passaria
   à toa).
7. **Fim de semana e feriado não oferecem atalho** (condições 4 e 5), com dia útil comum como
   controle positivo.

Todos os guards foram provados por **injeção de defeito**: desligar a condição 8 derruba o teste 5;
desligar as condições 4, 5 e 9 derruba os testes 6 e 7; tirar a competência do redirect derruba o
teste 1.

Datas dos testes: dia útil do **mês anterior** (determinístico, sempre passado, tenant novo não tem
feriado) — evita o teste quebrar quando rodar no dia 1º.

## Aberto para o dono decidir (levantado na revisão, não alterado)

**O "+" aparece no dia de HOJE, com a jornada em curso.** A condição 6 corta o futuro, não o dia
corrente. Isso é proposital — "esqueci de bater a entrada hoje de manhã" é o caso mais comum do
atalho, e o modal grande atual já permite justificar hoje. O risco é outro: se o colaborador
justificar "esqueci a saída" e depois bater a saída de verdade, a aprovação cria um segundo
`RegistroPonto`; para entrada/repouso/retorno vale o **primeiro** registro do tipo
(`FolhaPontoBuilder.php:54-65`), então a hora declarada pode ganhar da hora realmente batida.
Não é regressão (o caminho já existia), mas se o dono preferir, basta trocar `<=` por `<` na
condição 6.

## Fora de escopo (registrado, não feito)

- Listar dias vazios na folha (decisão 1).
- Corrigir a **aprovação em lote** que não cria o `RegistroPonto` para esquecimento.
- Impedir justificativa duplicada no **backend** (o atalho só esconde o botão; o POST direto ainda
  aceita). Vale como follow-up: `findOneByUserAndData` já existe e não é usado por ninguém. **O
  mesmo vale para as condições 7 e 9**: são guards de exibição, não regras de servidor — quem
  postar direto na rota continua conseguindo.
- Enviar `competencia` também pelo modal grande "Justificar Falta".
