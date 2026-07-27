# HANDOFF — Cabeçalho e aba Responsáveis do `cobranca_objeto_show`

Redesenho pedido pelo dono do sistema a partir de uma montagem visual (2026-07-27).
**Contrato:** [`docs/specs/cobranca-objeto-show-cabecalho-responsaveis.md`](../specs/cobranca-objeto-show-cabecalho-responsaveis.md).
Leia a spec antes de escrever qualquer linha — ela registra o que foi cortado da maquete e por quê.

## Estado

| | |
|---|---|
| Branch | **`master`**, HEAD da Etapa 6 sobre `origin/master` `6e93b43` — **não publicado** |
| Worktree | nenhuma — trabalho direto no checkout principal |
| Migration | **nenhuma prevista**, e é decisão de projeto (ver §3.1 da spec) |
| Suíte | `tests/Cobranca` **1226/1226** verde ao fim da Etapa 6 (+21 testes novos) |
| Publicado | **nada** |

⚠️ **Duas decisões do dono estão abertas na Etapa 2** — o código segue a spec literal nas duas, e as
duas estão travadas por teste. Ver "O que a Etapa 2 devolveu para o dono", no fim deste documento.

### Por que master, e não uma branch própria

O hook `pre-commit` amarra **pasta ↔ frente**: o checkout principal só aceita commit no `master`, e
frente nova mora em worktree (`scripts/frente-abrir.sh`). Tentei abrir a branch
`cobranca-objeto-show-cabecalho` aqui e o hook recusou, corretamente.

Worktree não serve para esta frente: o `nginx.conf` fixa `root /var/www/app/public` e só a 8080 é
publicada, então **`localhost:8080` sempre serve o checkout principal** — e é lá que o dono faz o
smoke desta tela. Trabalhar em worktree exigiria subir um container de preview só para isso.

### Banco do dev — ✅ já resolvido em 2026-07-27

`app/.env.local` (gitignored) foi criado no checkout principal apontando para **`saas_ux`**, e o cache
foi limpo. `localhost:8080` está servindo o master contra um banco compatível. Nada a fazer — o texto
abaixo fica só como registro do diagnóstico.

Isso **não afeta os testes**: o Symfony ignora `.env.local` quando `APP_ENV=test`, então a suíte segue
no `saas_test`. Confirmado rodando `tests/Cobranca/Unit` depois da troca (652/652).

<details>
<summary>Por que o <code>saas</code> não servia</summary>

#### ⚠️ O banco `saas` do dev NÃO serve para o smoke

`saas` está **à frente do master**: carrega as 4 migrations da frente canônica
(`Version20260725120000` … `180000`), que renomeiam `caso_id → objeto_id` em `cobranca_liquidacao`,
`cobranca_proxima_acao`, `cobranca_documento` e `cobranca_secao`, criam
`cobranca_obrigacao_identidade_externa` e derrubam `cobranca_vinculo_pessoa_objeto.principal`. O
mapeamento do master espera `caso_id`. Medido em 2026-07-27 com `doctrine:schema:update --dump-sql`.

**O banco compatível é `saas_ux`** (clone com as 4 revertidas, sobrou da frente de UX rápida).
Confirmado: `cobranca_liquidacao.caso_id` presente, `identidade_externa` ausente.

Para o dono conseguir smoke em `localhost:8080`, o checkout principal precisa de um `app/.env.local`
(gitignored) apontando para `saas_ux`:

```bash
# Execute manualmente no terminal externo
echo 'DATABASE_URL="pgsql://symfony:symfony@db:5432/saas_ux"' > app/.env.local
docker exec jusprime_php_dev bash -c 'cd app && php bin/console cache:clear'
```

(A credencial é a mesma do `app/.env`; só o nome do banco muda.) Para voltar ao `saas`, apague o
`.env.local`.

</details>

⚠️ **Duas worktrees vivas no repositório** (`cobranca-acompanhamento-canonico`, `cobranca-ux-rapida`).
Rode `git worktree list` antes de qualquer integração — worktree abandonada segurando uma branch faz
`git switch` recusar e os comandos seguintes rodarem em silêncio no lugar errado.

⚠️ `docs/gestao-cobrancas/cobranca-acompanhamento-canonica.md` está **untracked e é de outra frente**.
Não commite junto.

## Etapas

- [x] **1 — Fundação** (enum, tipo de evento, DTOs de leitura, calculadora de prescrição) — `4f3b594`
- [x] **2 — UseCases de qualificação** (registrar + desfazer + exceção + query da 4ª condição)
- [x] **3 — Leitura da página** (totais do cabeçalho, prescrição, ficha da cobrada, vizinhos na carteira) — `29c4cbf`
- [x] **4 — Rotas** (registrar / desfazer qualificação) — `fd9b2b5`
- [x] **5 — Template do cabeçalho** (duas colunas, cards, prescrição, ações, setas) — `4ae2fee`
- [x] **6 — Template da aba Responsáveis + painel de qualificação**
- [ ] **7 — CSS**
- [ ] **8 — Testes** (unitários e funcionais) e suíte completa

O dono faz o smoke no navegador dele. **Não abra o Playwright.**

---

## Antes de commitar qualquer etapa

**Nunca inclua** `docs/gestao-cobrancas/cobranca-acompanhamento-canonica.md`: é untracked e pertence a
outra frente. `git add` explícito por arquivo, nunca `git add .`.

O hook `pre-commit` amarra pasta↔frente e recusa commit fora do `master` nesta pasta. Se você criar
uma branch aqui, fica preso: `git switch` é do humano, o Claude não pode voltar sozinho. Já aconteceu
uma vez nesta frente — não repita.

---

## Etapa 1 — FEITA E COMMITADA (`4f3b594`)

Arquivos novos:

| Arquivo | O quê |
|---|---|
| `app/src/Cobranca/Enum/QualificacaoContato.php` | 3 cases + `label()`, `icone()`, `doPainel()`, `tentarDe()` |
| `app/src/Cobranca/DTO/QualificacaoContatoOutput.php` | uma linha da listinha; `podeDesfazer` decidido no servidor |
| `app/src/Cobranca/DTO/PrescricaoOutput.php` | 4 severidades como constantes |
| `app/src/Cobranca/Service/CalculadoraPrescricao.php` | `PRAZO_PADRAO_ANOS = 5`, faixas 90/180 dias |

Arquivos alterados:

| Arquivo | O quê |
|---|---|
| `app/src/Cobranca/Enum/TipoEventoHistorico.php` | case `QualificacaoContato`; `ehTrabalhoDeCobranca() => true` |
| `app/src/Cobranca/Entity/EventoHistorico.php` | `JANELA_DESFAZER_QUALIFICACAO = 'PT5M'` + `podeSerDesfeitaPor()` |
| `app/tests/Cobranca/Unit/TipoEventoHistoricoTrabalhoTest.php` | o tipo novo na lista literal da spec |
| `docs/specs/cobranca-central-acompanhamento.md` | idem, na §5.1 |

### O que a Etapa 1 já ensinou

**O teste guardião funcionou.** `TipoEventoHistoricoTrabalhoTest` quebrou sozinho ao ver o case novo —
é literalmente o defeito que ele existe para pegar (tipo novo entrando na Central sem classificação).
Corrigir era atualizar **as duas** listas: a do teste e a da spec da Central, que é a fonte que o teste
copia de propósito para poder discordar do código.

**`podeSerDesfeitaPor` cobre 3 das 4 condições, não as 4.** A quarta — ser a qualificação mais recente
do caso — não cabe na entidade, porque um evento não conhece os irmãos. Ela é da Etapa 2, no UseCase.
Quem esquecer disso vai deixar desfazer qualquer qualificação dos últimos 5 minutos, não só a última.

---

## Etapa 2 — FEITA

Arquivos novos:

| Arquivo | O quê |
|---|---|
| `app/src/Cobranca/Exception/QualificacaoNaoDesfazivelException.php` | mensagem única para os 4 motivos |
| `app/src/Cobranca/UseCase/RegistrarQualificacaoContatoUseCase.php` | um clique → evento; sem DTO nem Form |
| `app/src/Cobranca/UseCase/DesfazerQualificacaoContatoUseCase.php` | as 4 condições da §3.5 |
| `app/tests/Cobranca/Unit/QualificacaoContatoUseCaseTest.php` | 14 testes dos dois UseCases |
| `app/tests/Cobranca/Functional/UltimaQualificacaoRepositoryTest.php` | 6 testes da query, contra o banco |

Alterado: `EventoHistoricoRepository::ultimaQualificacaoDoCaso` (a 4ª condição é uma consulta).

### Contratos que a Etapa 3 e a 4 consomem

```php
RegistrarQualificacaoContatoUseCase::executar(
    int $casoId, QualificacaoContato $qualificacao, Tenant $tenant, User $usuario
): EventoHistorico

DesfazerQualificacaoContatoUseCase::executar(
    int $eventoId, Tenant $tenant, User $usuario
): int   // id do caso, para o redirect
```

Sem Input DTO nos dois, de propósito: não há formulário a validar — o registrar recebe um enum vindo de
um botão, e o desfazer recebe só o id. O gate `resources.cobranca.gerenciar` e o CSRF são do controller
(Etapa 4); os UseCases não conhecem request.

### O que a Etapa 2 ensinou

**O relógio injetado tem de carimbar os DOIS lados da mesma regra.** O registrar recebe `ClockInterface`
e passa `ocorridoEm` explicitamente — sem isso o marco viria do `new \DateTimeImmutable()` do construtor
da entidade, enquanto a janela de 5 minutos seria medida pelo relógio injetado. Os dois lados andariam
separados e o funcional de "passou dos 5 minutos" (Etapa 8) seria intestável de forma determinística.
`RegistrarAnotacaoUseCase` ainda tem esse desalinhamento; aqui ele não nasceu.

**A revisão pegou 7 pontos que a suíte verde não pegava** — entre eles o relógio acima, o fail-open da
comparação de ids (dois `null` são "iguais" para o `!==`, e a guarda liberava), e o fato de que
**nenhum teste executava a query nova**: o repositório era mock nos 14 unitários, então filtro de tenant,
filtro de tipo e desempate por id não eram provados por nada. Daí o teste de integração.

**Injetar defeito com `perl -0pi` casa a PRIMEIRA ocorrência do arquivo, não a do método que você quer.**
Três injeções "não pegaram" e o alarme era falso: as regex bateram no `doCaso()`, que tem linhas
idênticas às da query nova. Refeitas mirando o bloco do método certo, as três derrubaram o teste
esperado. **Injeção de defeito que passa verde exige conferir se o defeito foi mesmo aplicado onde você
pensa** — senão a prova vira teatro. 12 defeitos foram injetados no total; cada um derrubou o teste
correspondente.

### O que a Etapa 2 devolveu para o dono

Duas decisões que a spec não resolve. O código segue a spec literal nas duas, e **cada uma está travada
por um teste** — se a decisão mudar, é o teste que quebra, não o comportamento em silêncio.

**1. Desfazer em cascata dentro dos 5 minutos.** A condição 4 ("é a mais recente") ordena as remoções,
não as limita: desfeita a última, a penúltima vira a mais recente e — se ainda dentro dos 5 minutos
dela — também pode ser desfeita. Em cliques sucessivos o autor limpa tudo que qualificou nos últimos
5 minutos. É coerente com a finalidade (mesmo engano, mesma janela, mesma pessoa), mas cada remoção
desconta 1 do trabalho de cobrança dele na Central. Limitar a UMA remoção por janela é decisão sua.
Teste que trava: `cascataDentroDaJanela`.

**2. Caso encerrado NÃO impede desfazer** — e isso diverge do vizinho: `ExcluirAnotacaoUseCase` bloqueia
exclusão em caso encerrado, com teste. Aqui não bloqueia, porque em caso encerrado nem a qualificação
corretiva por cima seria possível (§17 recusa novos lançamentos): bloquear o desfazer tornaria o engano
permanente e sem saída, e a janela aqui é de 5 minutos, não 48 horas. O mesmo argumento serviria para a
anotação, onde o projeto escolheu o contrário — **o domínio ficou com duas regras opostas para "remover
evento por engano"**. Uniformizar é decisão sua. Teste que trava: `casoEncerradoAindaDesfaz`.

## Etapa 3 — FEITA (`29c4cbf`)

Nenhum arquivo novo de produção; a Etapa 1 já tinha criado os DTOs e a calculadora.

| Arquivo | O quê |
|---|---|
| `DTO/CasoDetalheOutput.php` | +6 campos: `totalPrincipalEmAberto`, `totalEncargosEmAberto`, `totalAtualizadoEmAberto`, `totaisAtualizadosEm`, `obrigacoesEmAbertoQtd`, `prescricao`, `qualificacoes` |
| `DTO/ObjetoDetalheOutput.php` | +3 campos: `fichaCobrada`, `objetoAnteriorId`, `objetoProximoId` |
| `UseCase/MontarDetalheCasoUseCase.php` | soma os cards no mesmo laço dos totais de honorários; `montarQualificacoes()`; +`CalculadoraPrescricao` no construtor |
| `UseCase/MontarDetalheObjetoUseCase.php` | +`MontarFichaPessoaUseCase` e `ObjetoCobrancaRepository` no construtor |
| `Repository/ObjetoCobrancaRepository.php` | `vizinhosNaCarteira()` + o privado `vizinho()` |

Testes: `Unit/CalculadoraPrescricaoTest` (novo, 10), `Unit/MontarDetalheCasoUseCaseTest` (+8),
`Functional/MontarDetalheObjetoUseCaseTest` (+5, contra o banco).

### Contratos que a Etapa 5 e a 6 consomem

```
caso.totalPrincipalEmAberto · caso.totalEncargosEmAberto · caso.honorariosEmAberto
caso.totalAtualizadoEmAberto   // = a soma dos três acima, por construção
caso.totaisAtualizadosEm       // data do relógio, para o "atualizado em" do card
caso.obrigacoesEmAbertoQtd     // a linha meta "N obrigações em aberto"
caso.prescricao                // ?PrescricaoOutput — null = a caixa não é renderizada
caso.qualificacoes             // list<QualificacaoContatoOutput>, mais recente primeiro
objeto.fichaCobrada            // ?PessoaFichaOutput — telefones/e-mails/endereços da cobrada
objeto.objetoAnteriorId · objeto.objetoProximoId   // null = seta desabilitada
```

**O card `Honorários` do cabeçalho é o `honorariosEmAberto` que já existia** — não há um segundo campo
com o mesmo número. O conjunto e a regra são idênticos aos da aba Honorários; duplicar só criaria dois
lugares para divergir. Quem mexer num tem de lembrar que mexe nos dois.

⚠️ `totalAtualizadoEmAberto` é o **bruto** (principal + encargos + honorários das em aberto).
**Não é** o `saldoExigivel`, que é líquido de pagamentos e é quem governa o encerramento. Os dois ficam
na tela de propósito (§1.2). Trocar um pelo outro no Twig faz o gestor conferir o número errado.

### O que a Etapa 3 ensinou

**Injeção de defeito: 11 rodadas, 10 falharam como esperado — e a que passou verde achou um teste que
mentia.** O `aListinhaTrazSoAsQualificacoes` afirmava que o filtro por TIPO excluía a anotação, mas quem
a excluía era o payload vazio (`dados['qualificacao'] ?? null`). Removi o filtro de tipo e o teste
continuou verde. Corrigido com um evento `ContatoRealizado` carregando um payload com a chave
`qualificacao` — aí o filtro de tipo vira a única defesa, e o teste passou a pegar. **A asserção que você
escreve e a causa que a faz passar podem ser coisas diferentes; só a injeção mostra qual é qual.**

**O filtro de `tenant` na consulta dos vizinhos é comprovadamente redundante** (a carteira já é
tenant-bound): removê-lo sozinho deixa a suíte verde. Ele fica como defesa em profundidade, e o docblock
diz isso — mas **nenhum teste prova esse filtro**, e prometer o contrário seria falso. O que o teste
prova é o filtro de **carteira** (removê-lo derruba).

**`Doctrine\ORM\Query\Expr` não tem `comparison()`** — é `gt()`/`lt()`. E a injeção de defeito que
remove um `andWhere` tem de remover o `setParameter` correspondente, senão o erro é
"Too many parameters" e você prova o seu próprio bug de injeção, não o do código (aconteceu uma vez
aqui — refeita mirando a DIREÇÃO do desempate, com os parâmetros intactos, o teste caiu de verdade).

**`willReturn` no `setUp` vence qualquer stub do corpo do teste.** Tirei o
`eventoRepository->method('doCaso')->willReturn([])` do `setUp` do `MontarDetalheCasoUseCaseTest` para os
testes da listinha conseguirem devolver eventos concretos — o arquivo já documentava essa armadilha para
os outros `doCaso`, e ela mordeu de novo.

**`ehMaisRecente` é decidido pela POSIÇÃO entre os eventos do tipo, antes de o payload ser lido.**
`ultimaQualificacaoDoCaso` (a guarda do servidor) não olha o payload; se uma linha quebrada sumisse da
lista e o `true` escorregasse para a seguinte, a tela ofereceria um botão que a rota vai recusar.

## Etapa 4 — FEITA (`fd9b2b5`)

| Arquivo | O quê |
|---|---|
| `Controller/QualificacaoContatoController.php` (novo) | as duas rotas POST da §3.5 |
| `tests/.../QualificacaoContatoControllerTest.php` (novo) | 14 funcionais |
| `tests/.../CobrancaWebTestCase.php` | `tokenCsrf()` passou a **salvar a sessão** |

### Contrato que a Etapa 6 (template do painel) consome

O painel **não usa Symfony Form** — são três `<form method="post">` crus, um por botão, mais o do
desfazer. Os campos e as intenções de CSRF:

```twig
{# registrar — um form por botão do painel #}
<form method="post" action="{{ path('cobranca_qualificacao_registrar', {id: caso.id}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('registrar_qualificacao_' ~ caso.id) }}">
    <input type="hidden" name="qualificacao" value="{{ q.value }}">   {# valor do enum #}

{# desfazer — só na linha que o servidor marcou com podeDesfazer #}
<form method="post" action="{{ path('cobranca_qualificacao_desfazer', {eventoId: linha.eventoId}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('desfazer_qualificacao_' ~ linha.eventoId) }}">
```

⚠️ **Estas duas intenções são STATEFUL**, ao contrário de todo Form do projeto: `config/packages/csrf.yaml`
põe `submit`/`authenticate`/`logout` em `stateless_token_ids`, e o Form usa `token_id: submit`. Como aqui
não há Form, o token vai pela sessão. Consequência prática: `csrf_token()` no Twig é obrigatório — não dá
para reaproveitar o mecanismo stateless que o resto da página usa.

Ambas as rotas voltam para `cobranca_objeto_show` com `?aba=responsaveis` (o mecanismo genérico de
ativar aba que o `show` já tem). Sem objeto resolvível, caem em `cobranca_caso_index`.

### O que a Etapa 4 ensinou

**O helper `tokenCsrf()` estava quebrado, e o estrago era só em testes que esperavam RECUSA.** Ele gerava
o token e não salvava a sessão; quem persiste a sessão é o `kernel.response`, que não roda quando você
gera o token entre requisições. Resultado: a requisição seguinte carregava a sessão anterior, **sem** o
token, e o POST era recusado por CSRF **haja o que houver**. Todo teste que afirmava "a regra X recusa"
passava porque o CSRF recusava antes — a regra X nunca chegava a ser exercida. Com o `save()`, dois
funcionais antigos de anotação (`testEventoAutomaticoNaoPodeSerExcluido` e
`testEdicaoEExclusaoExigemCapacidadeDeGerenciar`) passaram a exercitar de verdade o que dizem provar:
injetei defeito nos dois e os dois caíram — antes não cairiam.

**Dois testes desta etapa nasceram com o mesmo defeito, e só a injeção mostrou.** Os de capacidade
postavam sem token: rebaixar o gate de `tenantComCapacidade` para `tenantComModulo` deixava os dois
VERDES. Corrigidos passando o CSRF certo (o operador tem o módulo de leitura, então consegue abrir a
página e obter o token) e assertando o **destino** do redirect — `semAcesso()` vai para `/`, e é esse
destino que separa "barrado pelo gate" de "barrado pelo CSRF". `assertResponseRedirects()` sem alvo
aceita os dois e não prova nada.

**A recusa por TIPO no desfazer tem defesa dupla, e nenhum teste separa as duas.** Além do
`podeSerDesfeitaPor`, a consulta da 4ª condição já filtra por tipo — para uma anotação ela devolve `null`
e a remoção cai ali de qualquer jeito. Medido: remover UMA das duas deixa o teste verde; só removendo AS
DUAS ele cai. O teste prova que a **rota** não apaga evento de outro tipo, não que a checagem da entidade
sozinha funciona; o comentário no teste diz isso, para ninguém prometer mais do que há.

**18 injeções de defeito, todas derrubaram o teste esperado** (`tmp` do scratchpad, não versionado). Entre
elas as duas que provam a ORDEM das guardas: reordenar o CSRF para antes da resolução tenant-safe faz o
cross-tenant responder 302 em vez de 404, e o teste cai.

**Refutado por medição:** a revisão levantou que o `TenantFilter` global tornaria redundante o
`findOneByIdDoTenant` do controller (o `find()` cru também esconderia o registro alheio). Não é o que
acontece: trocar por `find()` derruba os dois testes cross-tenant com "Failed asserting that the Response
status code is 404". O filtro do controller é o que responde ali.

### O que a Etapa 4 devolveu para o dono / para a Etapa 8

1. **`DesfazerQualificacaoContatoUseCase::executar` devolve o id do CASO e ninguém usa.** O handoff da
   Etapa 2 anunciou esse retorno "para o redirect", mas o redirect vai para a página do **objeto**, e o
   controller resolve o objeto pelo evento antes de remover. O retorno virou peso morto. Não mexi no
   UseCase (é da Etapa 2, já revisado e testado); tirar ou manter é decisão de quem fechar a etapa 8.
2. **As duas actions passam de ~20 linhas** da heurística 5-10-20 do `criar-controller`. O excedente é
   guarda e comentário, não lógica; registrado por honestidade, não como pendência.

## Etapa 5 — FEITA (`4ae2fee`)

| Arquivo | O quê |
|---|---|
| `templates/cobranca/objeto/show.html.twig` | cabeçalho da §1 inteiro, no lugar do bloco antigo |
| `tests/.../CabecalhoObjetoShowTest.php` (novo) | 11 funcionais de renderização |
| `tests/.../ObjetoShowControllerTest.php` | a barra de abas perdeu 1 item (ver abaixo) |

Estrutura entregue, em `.cob-cab` (grade `row`/`col-12 col-lg-*`, duas colunas só a partir de 992px):

```
esquerda (.cob-cab-identidade)          direita (.cob-cab-lateral)
  .cob-cab-trilha    Carteira › Unidade   .cob-presc      caixa de prescrição
  h1 + badge de status + .cob-cab-nav       [data-severidade] + aviso + "Ver competência"
  .cob-cab-meta      descrição · N …     .cob-cab-acoes  os 5 botões da §1.4
  .cob-cab-cards     4× [data-card]
  .cob-resumo        Total em aberto / Total vencido
```

Ganchos que os testes (e a Etapa 7) usam: `[data-card=principal|encargos|honorarios|total]`,
`[data-meta=obrigacoes-em-aberto]`, `[data-nav=anterior|proximo]`, `[data-acao=simular-acordo|planilha-atualizada]`,
`.cob-presc[data-severidade]`. **Só o CSS de acabamento falta** (Etapa 7): a estrutura já se sustenta na
grade do Bootstrap, para não existir uma janela em que a página fique quebrada.

### A armadilha, agora travada por teste

`totalAtualizadoEmAberto` (card **Total atualizado**) é o BRUTO; `saldoExigivel` (linha fina, **Total em
aberto**) é o LÍQUIDO de pagamentos. `testTotalAtualizadoNaoEOSaldoExigivel` monta uma obrigação de
R$ 1.000,00 com R$ 400,00 pagos e exige **1000,00 no card e 600,00 na linha fina** — trocar um pelo outro
no Twig derruba o teste (medido).

### Duas decisões tomadas aqui (o dono pode reverter)

1. **`Registrar contato` saiu da barra de abas** e virou o primeiro botão da barra de ações do cabeçalho,
   porque a §1.4 o lista lá. Mantê-lo nos dois lugares poria o MESMO gatilho do MESMO modal duas vezes na
   mesma dobra. `ObjetoShowControllerTest::testBarraDeAtividadesReuneConteudoEAcoes` mudou de 8 para 7
   itens e passou a assertar as **duas** pontas (saiu da barra **e** chegou ao cabeçalho) — sozinha, a
   segunda asserção aceitaria o botão duplicado.
2. **A seta solta de "voltar para a carteira" saiu**, porque o primeiro nível da trilha
   (`Carteira <nome>`) leva ao mesmo lugar e fica a dois centímetros dela.

### O que a Etapa 5 ensinou

**13 injeções de defeito, todas derrubaram o teste alvo** (script no scratchpad, não versionado). As que
valeram mais: o card `Juros e multa` lendo `saldoVencido` (campo de nome parecido e ordem de grandeza
próxima — só a asserção de que **os três cards somam o quarto** pega isso), o `Ver competência` sem o
`data-abrir-aba` (vira clique morto, porque o alvo mora num pane oculto) e o aviso de estimativa removido
da caixa de prescrição.

**A faixa de severidade da prescrição é `≤ 90` crítica e `≤ 180` atenção — 150 dias é ATENÇÃO.** O
primeiro cenário do teste nasceu com `-5 years +150 days` esperando `critica` e falhou; o certo para a
faixa crítica é `+60 days`. Errar isso no teste é o mesmo erro que erraria na tela.

**Injeção que derruba por 500 também é prova, desde que você saiba disso.** Trocar o guard
`{% if caso.prescricao %}` por `{% if caso.obrigacoes|length > 0 %}` faz a caixa tentar ler propriedade de
`null` e a página estourar — o teste cai por `assertResponseIsSuccessful`, não por asserção de conteúdo.
Registrado para ninguém ler a linha `PEGOU` como se o teste tivesse pego a **ausência da caixa**: o que
ele prova é que **o guard é o que a mantém fora da tela**.

## Etapa 6 — FEITA

| Arquivo | O quê |
|---|---|
| `_partials/_responsaveis.html.twig` | REESCRITO: a §2 inteira (cabeçalho+badge, card, telefones da ficha, faixa, accordion, rodapé) |
| `_partials/_painel_qualificacao.html.twig` (novo) | a §3 inteira: 3 botões de um clique, listinha, desfazer |
| `templates/cobranca/objeto/show.html.twig` | handler anti duplo-submit (ver "Correções da revisão", achado 1) |
| `Controller/ObjetoController.php` | `formTelefoneCobrada` + `qualificacoesDoPainel` + helper `telefoneCobradaView` |
| `tests/.../AbaResponsaveisTest.php` (novo) | 21 funcionais de renderização |
| `tests/.../ObjetoShowUxReorganizacaoTest.php` | 1 asserção remanejada (o `Encerrar vínculo` mudou de lugar) |

Ganchos que os testes (e a Etapa 7) usam: `.cob-resp-contagem`, `[data-badge=qualificacao-incompleta]`,
`[data-meta=papel-desde]`, `[data-selo=vinculo-encerrado|telefone-atual]`, `.cob-resp-telefone[data-telefone]`,
`[data-acao=marcar-telefone-atual|editar-telefone|excluir-telefone|editar-ficha|desfazer-qualificacao]`,
`[data-form=adicionar-telefone]`, `[data-faixa=qualificacao|qualificacao-restrita]`, `[data-qualif=documento|email|estado-civil|endereco]`,
`[data-rodape=voltar|proximo]`, `[data-painel=qualificacao]`, `[data-qualificacao=<valor do enum>]`,
`.cob-qualif-item[data-qualif-linha]`.

### Duas coisas que o controller precisou ganhar (e por quê)

1. **`formTelefoneCobrada`** — o mini-form da §2.3 reusa `AdicionarTelefoneType` e a rota da ficha. Sem o
   `FormView` não há como renderizar o campo com o CSRF que aquela rota espera (é Symfony Form, token
   `submit` stateless — nada a ver com as duas intenções do painel).
2. **`qualificacoesDoPainel`** — a ordem dos três botões é fixa e mora em `QualificacaoContato::doPainel()`.
   Twig não chama método estático; reordenar no template criaria uma segunda fonte para a mesma decisão.

### Decisões tomadas aqui (o dono pode reverter)

1. **`Editar` e `Encerrar vínculo` saíram do card da pessoa e foram para o cabeçalho da aba**, porque a
   §2.1 os lista lá, junto de `Trocar responsável`. `ObjetoShowUxReorganizacaoTest::testFuncoesDoCardRemovidoSobrevivemComUmVinculoSo`
   foi remanejado e ficou MAIS forte: agora exige **exatamente um** botão na aba e ancora pela **identidade
   do vínculo** (`/cobrancas/vinculos/<id>/encerrar`), não pelo prefixo da URL.
2. **`Adicionar pessoa` desceu** do cabeçalho da aba para junto de "Outras pessoas vinculadas", que é onde
   ele age (§2.5).
3. **`Marcar como atual` e o mini-form de telefone levam o usuário PARA FORA da aba**, para a ficha da
   pessoa — as duas rotas de `PessoaController` redirecionam para `cobranca_pessoa_show` e a spec §2.3 manda
   reusar as rotas que já existem. Mudar o destino exigiria um `?voltar=` nas duas rotas, o que sai do
   escopo desta etapa. **Se incomodar no smoke, é frente de 20 minutos — mas é decisão sua.**

### Correções da revisão (3 IMPORTANTES + 5 MENORES, todas aplicadas)

**1. `data-disable-on-submit` era INERTE nesta página.** O handler existe só em
`cobranca/pessoa/show.html.twig`, e ele varre só o DOM daquela página. Os quatro forms novos carregavam um
atributo que ninguém lia: **duplo clique gravaria DUAS qualificações**, cada uma contando como trabalho de
cobrança na Central, com o desfazer removendo uma por vez. O handler foi copiado para
`objeto/show.html.twig`, **antes** do guard `typeof bootstrap === 'undefined'` — a proteção não depende do
bundle e não pode cair junto com ele. ⚠️ **Isto não tem teste automatizado** (é JS); confira no smoke.

**2. GATE DE PII — a aba mostrava mais do que a ficha protege.** `cobranca_objeto_show` exige só o
**módulo**; `cobranca_pessoa_show` exige a capacidade `resources.cobranca.gerenciar`. Sem gate, quem não
pode abrir a ficha leria na aba um **superconjunto** do que ela protege: lista inteira de telefones,
endereço completo com CEP e estado civil. A lista e a faixa passaram para trás de `podeAbrirFicha`; o ramo
`{% else %}` mantém **paridade exata com a aba anterior à Etapa 6** (documento, telefone e e-mail da
cobrada, que já eram visíveis sem gate). **Nada foi tirado de quem só lê — apenas não foi ampliado em
silêncio.** A spec §2.3/§2.4 não decide gate; **abrir isso de novo é decisão sua.**

**3. Faltavam testes de permissão.** Os 17 testes iniciais usavam só `criarAdminLogado` — `podeAbrirFicha` e
`podeQualificarDesfazer` eram código sem prova. Entraram 4 testes com `criarOperadorSemCapacidade`.

Menores aplicados: asserção remanejada agora ancora por identidade (não por prefixo de URL); o comentário do
mini-form parou de citar um registro que não existia (é este parágrafo); `$podeGerenciar` do controller
ganhou o aviso de que é **só a capacidade**, enquanto o `podeGerenciar` do Twig é capacidade **e** caso
aberto; o `include` do painel passa `podeQualificarDesfazer`/`qualificacoesDoPainel` **explicitamente**,
porque `strict_variables` só está ligado em `test` e um rename faria o desfazer sumir em silêncio na
produção.

Menor **não** aplicado, de propósito: o badge `Qualificação incompleta` continua calculado no Twig. Hoje há
um consumidor só e as três pernas do OU estão travadas por teste, uma a uma. **No dia em que aparecer o
segundo consumidor (um filtro ou relatório de "qualificação incompleta"), o cálculo tem de subir para o
`PessoaFichaOutput`** — senão nascem duas fontes para o mesmo termo de negócio.

### O que a Etapa 6 ensinou

**36 injeções de defeito, 35 derrubaram o teste alvo — e a 36ª foi medida de propósito.** O gate interno do
`Marcar como atual` (`{% elseif podeAbrirFicha %}`) virou **defesa em profundidade** depois que o gate de PII
passou a esconder a lista inteira: removê-lo deixa o teste VERDE. O comentário no teste diz isso — ele prova
que a **tela** não oferece a mutação, não que o gate do botão sozinho funcione. Mesma classe do achado da
Etapa 4 sobre a recusa por TIPO no desfazer.

**Injeção que "não pega" pode ser culpa da injeção, não do teste.** A primeira tentativa de derrubar o rodapé
trocou `d-inline-block` por `d-none`: o botão continuava no DOM, o crawler continuava achando, e a leitura
apressada seria "o teste não pega a ausência". Refeita removendo o ramo `{% else %}` inteiro, caiu na hora.
Esconder por CSS não é remover.

**A revisão pegou 3 IMPORTANTES que a suíte verde não pegava**, e os três eram invisíveis por natureza: um
atributo JS sem handler, uma ampliação de PII (que nenhum teste de renderização acusaria, porque renderizar
é justamente o que ela faz) e a ausência de qualquer teste de permissão — que é o que permitiu os outros dois
passarem despercebidos.

### Aviso para o smoke (medido no `saas_ux`, o banco que serve a `:8080`)

125 pessoas · **123 sem CPF e sem CNPJ** · **123 sem estado civil** · **3 endereços** e **3 telefones no
total**. A tela nova vai nascer com `Qualificação incompleta` e faixa quase toda "não informado" em ~98% das
unidades, e "Nenhum telefone cadastrado" na quase totalidade. **O sinal nasce saturado** — não é bug da aba,
é o estado do cadastro. Vale decidir se o badge deve mesmo aparecer nesse volume ou se ele só faz sentido
depois de uma campanha de qualificação.

## Comandos

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca/Unit'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'   # suíte completa, só na Etapa 8
```
