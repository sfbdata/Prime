# HANDOFF — Cabeçalho e aba Responsáveis do `cobranca_objeto_show`

Redesenho pedido pelo dono do sistema a partir de uma montagem visual (2026-07-27).
**Contrato:** [`docs/specs/cobranca-objeto-show-cabecalho-responsaveis.md`](../specs/cobranca-objeto-show-cabecalho-responsaveis.md).
Leia a spec antes de escrever qualquer linha — ela registra o que foi cortado da maquete e por quê.

## Estado

| | |
|---|---|
| Branch | **`master`**, HEAD da Etapa 8 sobre `origin/master` `6e93b43` — **não publicado** |
| Worktree | nenhuma — trabalho direto no checkout principal |
| Migration | **nenhuma**, e é decisão de projeto (ver §3.1 da spec) |
| Suíte | **completa 2705/2705** · `tests/Cobranca` **1228/1228** |
| Etapas | **as 8 fechadas** |
| Publicado | **nada** |

### 👉 Chat novo começa por aqui

O código está **fechado e verde**; o que resta é smoke e ajuste. Não releia o documento inteiro — as
etapas 1 a 7 são histórico e só interessam se o ajuste tocar uma delas. Leia, nesta ordem:

1. este `## Estado`;
2. **`## Roteiro de smoke`** — o que o dono vai conferir, incluindo o cenário de acordo rompido que
   **precisa ser provocado** (ele não existe no banco);
3. **`## Para o próximo chat (ajustes pós-smoke)`** — o mapa de "se o dono pedir X, mexe em Y", os
   ajustes já levantados e não feitos, e as travas de git desta pasta;
4. **`## Etapa 8`** — só se o ajuste tocar dinheiro, testes ou o desfazer.

⚠️ **Uma mudança de número já em PRODUÇÃO** saiu do BLOQUEANTE da revisão final (o `A receber` da aba
Honorários). O dono tem de estar ciente antes de publicar — detalhe na Etapa 8.

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
- [x] **6 — Template da aba Responsáveis + painel de qualificação** — `a8619c2`
- [x] **7 — CSS de acabamento** — `10e8864`
- [x] **8 — Fechamento de testes**, revisão da frente inteira e correções

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
): void  // era `: int` (id do caso); virou void na Etapa 8 — ver "O resíduo da Etapa 4, resolvido"
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

## Etapa 7 — FEITA (`10e8864`)

**Um arquivo só: `app/public/css/cobrancas.css` (+246 / −5). Nenhum Twig, nenhum PHP.** Todos os ganchos
que a etapa precisava já existiam — nada faltou, nada foi acrescentado ao HTML.

O bloco novo fica no fim do arquivo, sob o cabeçalho `CABEÇALHO + ABA RESPONSÁVEIS do objeto — Etapa 7`,
com as três regras que valem para tudo (cor só por variável · as colunas continuam sendo da grade do
Bootstrap · nada estoura no celular) escritas lá em cima do bloco.

| Região | O que ganhou |
|---|---|
| `.cob-cab-trilha` | flex que quebra, links discretos com hover no accent, nível atual em destaque |
| `.cob-cab-nav` | glifo maior, ponta desabilitada apagada, hover no accent (vence o `btn-outline-secondary`) |
| `.cob-cab-cards` | grade `auto-fit` de piso 9.5rem: 4 → 2 → 1 pela largura do CONTAINER, sem media query |
| `.cob-cab-card.is-total` | tint translúcido do accent + valor maior — diz "leia este" sem um quinto elemento |
| `.cob-cab-card-nota` | menor que o rótulo, sem caixa-alta: é contexto do total, não um segundo dado |
| `.cob-presc` | caixa com topo/destaque/detalhe/aviso e as 3 severidades |
| `.cob-resp-qualif` | faixa em flex: rótulo pequeno em cima, valor embaixo; `endereco` com base maior |
| `.cob-resp-telefone` | número tabular, hover no accent, quebra de linha garantida |
| `.cob-resp-rodape` | só o respiro de quando os dois botões quebram em linhas diferentes |
| `.cob-qualif` | vira cartão próprio; botões em coluna e largura cheia; listinha com rolagem |
| `.cob-qualif-item` | `desfazer` cinza → vermelho no hover (nunca o índigo padrão do `btn-link`) |

### A única mudança que não é "só somar CSS": `.cob-resumo`

O bloco da UX rápida (`§6.1`, já em produção) foi **editado no lugar**, não duplicado: rótulo e valor
passaram para a **mesma linha** e o valor caiu de `1.5rem` para `1.05rem`. Motivo: aquele bloco nasceu
sendo o dinheiro do cabeçalho, e a Etapa 5 pôs **quatro cards acima dele**. Mantido o tamanho antigo, o
`saldoExigivel` gritaria mais alto que o `Total atualizado` — invertendo exatamente a hierarquia que a
§1.2 fixa. O código de cor (vencido em `danger`, zerado em `success`) **fica**: é ele que separa os dois
números de relance. O elemento só existe nesta página (conferido por grep), então a mudança não vaza.

### ⚠️ O que ficou SEM prova automatizada — que é tudo

**A Etapa 7 não acrescentou nenhum teste, e isso é um fato a assumir, não uma pendência disfarçada.**
Nada da etapa mudou estrutura: nenhum elemento nasceu, sumiu ou trocou de classe/atributo, então não há
asserção de renderização que pudesse ficar vermelha antes e verde depois. PHPUnit não lê `.css`, e o
projeto não tem teste de regressão visual. Consequência prática: **o smoke do dono é a única prova desta
etapa.** Vale olhar, nos dois temas e em pelo menos uma largura de celular:

1. os 4 cards em 4 → 2 → 1 colunas conforme a largura, com `Total atualizado` destacado;
2. a caixa de prescrição nas severidades que o dado do `saas_ux` produzir (a `sev-info` é neutra **de
   propósito**: pintar de verde um prazo que ainda corre sugere "resolvido");
3. a linha fina `Total em aberto / Total vencido` **menor** que os cards, com o vencido em vermelho;
4. a faixa de qualificação e a lista de telefones sem estourar a largura no celular;
5. o painel de qualificação como cartão, com os três botões em coluna e largura cheia.

Segue também sem prova o que a Etapa 6 já havia registrado: o handler anti duplo-submit (é JS).

### O que a Etapa 7 ensinou

**Duas regras minhas nasceram mortas e foram removidas antes do commit.** A primeira sublinhava o
`desfazer` no hover — o botão carrega `.text-decoration-none`, que é `!important`, então a declaração
nunca ia valer (ficou só a cor, que funciona). A segunda apertava o `mb-3` do mini-form de telefone com
`!important` — arquivo que não usa `!important` em lugar nenhum não ganha o primeiro por acabamento;
virou uma `margin-bottom: -.5rem` no container, que recolhe o mesmo vão sem brigar com a utilitária.

**Divisor de lista: duplicar ou não, depende de a utilitária ser condicional.** O `border-top` entre
telefones e entre linhas da listinha vem do Twig com `{{ not loop.first ? ' border-top' : '' }}` —
condicional, logo **não** repeti no CSS: duas fontes divergiriam no dia em que uma mudasse. Já o
`width: 100%` dos botões do painel repete o `w-100` do Twig de propósito, porque é incondicional e
idêntico, e assim o empilhamento é do componente. O critério está escrito no próprio CSS.

**`minmax(9.5rem, 1fr)` sem o `min()` estoura a tela.** Numa grade `auto-fit`, o piso é piso mesmo: se o
container for menor que ele, a coluna sobra para fora e a página inteira entra em rolagem horizontal.
`minmax(min(9.5rem, 100%), 1fr)` é o que impede isso — e é a razão de a grade dos cards não precisar de
nenhuma media query.

## Etapa 8 — FEITA

### A §5 da spec, conferida item a item — e o resultado incômodo

Os **oito** itens da §5 (quatro unitários, quatro funcionais) **já estavam cobertos** pelas Etapas 1–6.
A conferência foi feita contra os arquivos, não contra o handoff:

| Item da §5 | Onde já estava |
|---|---|
| Registrar: tipo, rótulo, payload, autor; recusa caso encerrado | `QualificacaoContatoUseCaseTest` (`registraComTodoOMetadado`, `payloadGuardaOValorDoEnum`, `casoEncerradoNaoQualifica`) |
| Desfazer: janela, 5 min, outro usuário, não-mais-recente, outro tipo | idem (`autorDesfazDentroDaJanela`, `foraDaJanelaNaoDesfaz`, `limiteExatoAindaDesfaz`, `outroUsuarioNaoDesfaz`, `penultimaNaoDesfaz`, `outroTipoNaoDesfaz`) |
| `CalculadoraPrescricao`: faixas, esgotado, sem obrigação, mais antiga | `CalculadoraPrescricaoTest` (provider `faixas` cobre as 4 severidades, uma linha de cada lado de cada fronteira) |
| Totais do cabeçalho: mesmo conjunto da aba Dívida, ignoram quitada | `MontarDetalheCasoUseCaseTest::osTotaisDoCabecalhoSomamSoAsObrigacoesEmAberto` + `…IgnoramObrigacaoSubstituidaPorAcordoVigente` |
| Registrar (funcional): happy, CSRF, cross-tenant 404, encerrado | `QualificacaoContatoControllerTest` (6 testes) |
| Desfazer (funcional): happy, 4 recusas, cross-tenant | idem (8 testes) |
| Navegação: seta desabilitada na primeira e na última | `CabecalhoObjetoShowTest::testSetasDeNavegacaoEntreUnidades` (assere as DUAS pontas) |
| Aba Responsáveis: telefones da ficha + faixa de qualificação | `AbaResponsaveisTest` (`testListaDeTelefonesVemDaFicha`, `testFaixaDeQualificacaoUsaOsItensAtuais`) |

**Nada foi recriado.** A §5 estar coberta ANTES da etapa que se chama "testes" é consequência de cada
etapa ter escrito os próprios; o que a Etapa 8 acrescentou é o que a §5 **não** pedia e ninguém cobria.

### O teste novo: anti duplo-submit (`AbaResponsaveisTest::testFormsDaAbaEstaoProtegidosContraDuploSubmit`)

A Etapa 6 registrou duas vezes que o handler anti duplo-submit "não tem teste automatizado (é JS)".
**Meio verdade.** PHPUnit não executa JS, mas `ObjetoShowContratoJsTest` já provava que dá para travar
o *contrato* — e sem isso nada impedia que o handler fosse apagado outra vez, devolvendo o defeito
original (duplo clique gravando DUAS qualificações, cada uma contando na Central).

O teste assere as **duas pontas**, porque cada uma sozinha passa verde no defeito da outra:

1. **markup** — todo `<form>` de `#tab-responsaveis` (são 6: 3 de qualificação, desfazer, marcar
   telefone atual, adicionar telefone) carrega `data-disable-on-submit`, e cada um tem um
   `button[type="submit"]` — que é literalmente o que o handler procura;
2. **handler** — a página carrega o `querySelectorAll` que lê esse atributo, **antes** do guard
   `typeof bootstrap === 'undefined'`.

A varredura é sem lista de exceções: um form novo na aba sem o gancho derruba o teste.

**6 injeções de defeito, 6 derrubaram o teste** — gancho fora do form de registrar · handler apagado ·
handler movido para depois do guard de bootstrap · `type="submit"` removido do botão do desfazer ·
gancho fora do mini-form de telefone · gancho fora do "Marcar como atual".

⚠️ A sétima tentativa "não pegou" e **o culpado era o `sed`**: mirei a linha 69 e o botão estava na 68
— o `sed` acertou a linha do texto `desfazer`, não a do `<button>`. Refeita com `perl -0` ancorado na
intenção de CSRF do desfazer, caiu na hora. É a **terceira vez** nesta frente que uma injeção "que não
pega" é culpa da injeção. **Sempre confira que o defeito foi aplicado onde você pensa** — o `grep`
depois do `sed` custa 2 segundos e evita um alarme falso.

### O resíduo da Etapa 4, resolvido: `executar` virou `void`

`DesfazerQualificacaoContatoUseCase::executar` devolvia o id do caso e **ninguém consumia**. Removido.

O motivo não é higiene, é que o retorno **mentia**: o docblock dizia "o chamador redireciona para lá",
e o chamador redireciona para a página do **objeto** — que ele resolve *antes* de remover, justamente
porque depois da remoção o evento não leva a lugar nenhum. Quem herdasse esse contrato mandaria o
usuário para o lugar errado. As 3 asserções `assertSame(42, …)` viraram chamadas simples; o happy path
continua provado por `expects($this->once())->method('remover')->with($evento, true)`, que é **mais
forte** que o retorno era (exige que o evento removido seja o resolvido, e com flush).

### A revisão da frente inteira achou 1 BLOQUEANTE que 7 revisões por-etapa não viram

É a mesma lição da frente de encargos, e ela se repetiu literalmente: **o achado caro estava na costura
entre etapas**, não dentro de nenhuma.

**BLOQUEANTE — os cards contavam o mesmo dinheiro duas vezes em caso com acordo rompido.** Romper ou
cancelar um acordo faz duas coisas: **devolve a obrigação original ao exigível** e deixa as **parcelas
mortas** na lista solta (`agruparPorAcordo`, caso 3). O laço dos totais filtrava só por `quitada()` —
e parcela morta sem pagamento não está quitada. Resultado: original **mais** parcelas somando juntas
nos quatro cards, na linha `N obrigações em aberto` e na escolha da competência da prescrição. Pior:
a própria aba Dívida, três centímetros abaixo, rotula essa linha como *"histórico, fora do total em
aberto"* — a página se contradizia. E como `EncargosVivos` só hidrata as exigíveis, os encargos dessas
parcelas eram snapshot velho, misturando datas dentro do card `Juros e multa`.

Correção em `MontarDetalheCasoUseCase`: `if ($listada->parcelaDeAcordoDesfeito || $listada->quitada())`.
Com ela o conjunto somado passa a ser **idêntico** ao de `doCasoExigiveis` — que é quem governa o
`saldoExigivel` —, e a explicação que a §1.2 dá ao gestor ("um é bruto, o outro é líquido de
pagamentos") volta a ser verdadeira: a diferença entre os dois vira **só** o que já foi recebido.

O rodapé da aba Honorários (`honorariosDasObrigacoes`) **continua somando tudo que a aba lista** — ele
existe para fechar com as linhas visíveis, e a parcela morta aparece lá rotulada. A soma dele ficou
**antes** do `continue`, de propósito.

Travado por `MontarDetalheCasoUseCaseTest::osTotaisIgnoramParcelaDeAcordoRompido`, com os dois defeitos
injetados e derrubados: remover a exclusão (o defeito original) e mover o `honorariosDasObrigacoes`
para depois do `continue` (que quebraria o rodapé).

⚠️ **Isto muda um número já em produção.** O `A receber` da aba Honorários (`honorariosEmAberto`) é o
mesmo campo do card `Honorários` do cabeçalho — decisão da Etapa 3, para não existirem dois números com
o mesmo nome. Corrigir o cabeçalho corrige a aba junto. **É correção de dobra, não regressão**, mas é
mudança visível em qualquer caso com acordo rompido/cancelado e parcela não quitada. Medido no
`saas_ux` (o banco da `:8080`): **11 acordos, todos ativos, zero rompidos** — ou seja, **o smoke não
vai mostrar nem o defeito nem a correção**. Em produção não foi medido.

### Menores da revisão, aplicados

- **Spec §1.3 corrigida**: dizia `≤ 0 esgotado`, o que poria o **dia exato do prazo** na faixa
  "esgotado". No dia do prazo ainda dá para ajuizar; dizer `Prazo esgotado em <hoje>` faria o gestor
  abandonar cobrança viável — o erro mais caro que essa caixa pode cometer. O código sempre fez `< 0`;
  **o texto é que estava errado**, e o teste já travava o comportamento certo.
- **Spec §3.3 corrigida**: atribuía o gate `resources.cobranca.gerenciar` ao UseCase; ele é do
  **controller** (padrão do projeto — UseCase não conhece request). Registrada a consequência: um
  segundo chamador nasceria sem gate.
- **`PrescricaoOutput::$obrigacaoId`**: o docblock prometia que o `Ver competência` apontava para ele;
  o link vai para a aba Dívida inteira, porque não existe âncora por obrigação. Docblock corrigido, o
  campo fica (é por ele que o teste prova qual competência foi eleita).
- **Comentário no teste novo** sobre a dependência do literal de JS (falso-vermelho, nunca
  falso-verde — e afrouxar para regex tolerante devolveria o risco que o teste existe para pegar).

### O que a revisão levantou e NÃO foi mexido (decisão sua)

1. **O badge `Qualificação incompleta` fica fora do gate de PII.** Ele é renderizado para todos; o
   `podeAbrirFicha` só decide se vira link ou `<span>`. Para quem não tem a capacidade, ele revela
   *metadado* da ficha protegida ("esta pessoa não tem CPF, ou estado civil, ou endereço") e é
   **inacionável** — essa pessoa não pode abrir a ficha para corrigir. Não é vazamento de dado (é
   ausência de dado), e a §2.1 pede o badge no cabeçalho da aba sem condicionar. Deixei como está.
   Gatear é uma linha; a decisão é sua.
2. **Sem índice `(carteira_id, identificacao, id)` para as setas de navegação.** As duas consultas de
   vizinho fazem o Postgres ordenar a carteira inteira **duas vezes por page-load**. Irrelevante hoje
   (121 objetos), relevante numa carteira de milhares. Índice é migration, e esta frente decidiu não
   ter nenhuma — fica como follow-up.
3. **Risco a medir em PRODUÇÃO, não em dev.** A lista de telefones passou a vir de `PessoaTelefone` e a
   faixa §2.4 do e-mail/endereço **marcado como atual**. Pessoa com a coluna-sombra
   `cobranca_pessoa.telefone` preenchida mas **sem** linha correspondente passaria a exibir "Nenhum
   telefone cadastrado" para quem tem a capacidade — informação que a aba mostrava antes. Medido:
   **0 divergências** no `saas` e no `saas_ux`. Na produção, rode antes de publicar:
   ```sql
   select count(*) from cobranca_pessoa p
   where coalesce(p.telefone,'') != ''
     and not exists (select 1 from cobranca_pessoa_telefone t where t.pessoa_id = p.id);
   ```

### O que segue SEM prova automatizada (a lista honesta)

- **Etapa 7 (CSS) inteira** — PHPUnit não lê `.css` e o projeto não tem regressão visual. O smoke é a
  única prova. Confirmado pela revisão.
- **O comportamento do anti duplo-submit** — o teste novo prova que o gancho e quem o lê existem no
  mesmo documento, na ordem certa. Que um duplo clique *de fato* não grave duas vezes, só o navegador
  diz.
- **Duas defesas em profundidade**, medidas na Etapa 6 e reconfirmadas: o filtro de `tenant` em
  `vizinhosNaCarteira` (o teste põe o objeto alheio em outra carteira, então o filtro de carteira já
  basta) e o `{% elseif podeAbrirFicha %}` do "Marcar como atual" (o gate de PII já esconde a lista
  inteira). Removê-las sozinhas deixa a suíte verde — os comentários nos testes dizem isso.

---

## O que falta para publicar

1. **O smoke do dono** em `localhost:8080` — roteiro completo na seção seguinte.
2. **Duas decisões abertas desde a Etapa 2** (cascata do desfazer dentro dos 5 minutos · caso encerrado
   não impedir desfazer) — cada uma travada por um teste nomeado, então mudar de ideia quebra teste em
   vez de mudar comportamento em silêncio.
3. **Ciente da mudança no `A receber` da aba Honorários** (BLOQUEANTE acima) — é o único número já
   publicado que esta frente altera.
4. **Rodar a query do item 3 de "não foi mexido"** na produção, antes do deploy.
5. **Publicar**: `push` é do humano. Sem migration.

---

## Roteiro de smoke (`localhost:8080`, banco `saas_ux`)

O dono faz no navegador dele. **Não abra o Playwright.**

### A. O que a Etapa 7 (CSS) precisa que se olhe — a única prova que ela tem

Nos **dois temas** e em pelo menos **uma largura de celular**:

1. os 4 cards em 4 → 2 → 1 colunas conforme a largura, com `Total atualizado` destacado;
2. a caixa de prescrição nas severidades que o dado produzir (a `sev-info` é neutra **de propósito**:
   pintar de verde um prazo que ainda corre sugere "resolvido");
3. a linha fina `Total em aberto / Total vencido` **menor** que os cards, com o vencido em vermelho;
4. a faixa de qualificação e a lista de telefones sem estourar a largura no celular;
5. o painel de qualificação como cartão, com os três botões em coluna e largura cheia.

### B. O clique duplo — o handler que só o navegador prova

Qualquer cobrança → aba **Responsáveis** → **dois cliques rápidos** num dos três botões de
qualificação. Tem de nascer **uma** linha só na listinha, e o botão fica cinza depois do primeiro
clique. Repetir no `Marcar como atual` de um telefone.

### C. O acordo rompido — precisa ser provocado

**O cenário não existe no `saas_ux`**: medido em 2026-07-27, são 11 acordos e **todos ativos**. Sem
provocar, nem o defeito nem a correção aparecem na tela.

**Melhor cobrança para o teste: objeto 84 — `QUADRA 07 CHACARA 02/03`, acordo nº 9** (5 parcelas e
4 obrigações substituídas — é onde a dobra seria mais gritante).

```
http://localhost:8080/cobrancas/objetos/84
```

1. Anote o card **Total atualizado** e a linha `N obrigações em aberto`.
2. Aba **Dívida** → **romper o acordo**.
3. Volte ao cabeçalho.

**O que tem de acontecer:** as 5 parcelas passam a aparecer marcadas como *"parcela de acordo
desfeito"* e as 4 originais voltam para a lista. O card **`Total atualizado` NÃO pode incluir aquelas
5 parcelas**. Conferência a olho: some as linhas que **não** estão marcadas como "parcela de acordo
desfeito" — tem de bater com o card. Card muito maior que essa soma = o conserto não pegou.

**Para desfazer depois** (é banco de teste, mas fica limpo):

```bash
# Execute manualmente no terminal externo
docker exec jusprime_db_dev psql -U symfony -d saas_ux -c "update cobranca_acordo set status='ativo' where id=9;"
```

### D. O que o smoke NÃO consegue mostrar

O **"antes"**. O código servido já está corrigido — vê-se o resultado certo, não a diferença. Para ver
o erro seria preciso reverter o código, o que não vale o risco. Nesse ponto a prova é a injeção de
defeito registrada na Etapa 8.

### E. O sinal nasce saturado (não é bug)

Medido no `saas_ux`: 125 pessoas · **123 sem CPF e sem CNPJ** · **123 sem estado civil** · **3
endereços** e **3 telefones no total**. A aba vai nascer com `Qualificação incompleta` e faixa quase
toda "não informado" em ~98% das unidades, e "Nenhum telefone cadastrado" na quase totalidade. **É o
estado do cadastro, não defeito da tela.** Vale decidir se o badge deve mesmo aparecer nesse volume.

---

## Para o próximo chat (ajustes pós-smoke)

**Estado de partida:** `master` local em `c29692a`, 12+1 commits à frente de `origin/master` `6e93b43`,
**nada publicado**, suíte completa **2705/2705**, sem migration. Leia esta seção, o `## Estado` do topo
e a `## Etapa 8` — o resto é histórico e só interessa se o ajuste tocar aquela etapa.

**Antes de tocar em qualquer coisa:**

```bash
git status && git branch -vv | grep '^\*' && git worktree list
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
```

Duas worktrees vivas (`cobranca-acompanhamento-canonico`, `cobranca-ux-rapida`) e o untracked
`docs/gestao-cobrancas/cobranca-acompanhamento-canonica.md` são de **outras frentes** — nunca commite
junto, sempre `git add` explícito por arquivo.

**Continua valendo:** trabalho direto no `master` do checkout principal (o hook `pre-commit` amarra
pasta↔frente e a `:8080` sempre serve este checkout — ver "Por que master, e não uma branch própria",
no topo).

### Onde cada tipo de ajuste provavelmente cai

| Se o dono pedir… | Mexe em |
|---|---|
| trocar texto, ordem, tamanho ou cor de algo do cabeçalho | `templates/cobranca/objeto/show.html.twig` (bloco `.cob-cab`) + `public/css/cobrancas.css` (bloco da Etapa 7, no fim) |
| trocar algo da aba Responsáveis | `templates/cobranca/objeto/_partials/_responsaveis.html.twig` |
| trocar algo do painel de qualificação | `_partials/_painel_qualificacao.html.twig` (+ `Enum/QualificacaoContato.php` se for rótulo/ordem/opção) |
| mudar **um número** de dinheiro | `UseCase/MontarDetalheCasoUseCase.php` — **nunca no Twig**, e o teste unitário anda junto |
| mudar a regra do desfazer | `UseCase/DesfazerQualificacaoContatoUseCase.php` + `Entity/EventoHistorico.php` |
| mudar quem vê o quê (gates) | `Controller/ObjetoController.php` (`podeAbrirFicha`/`podeQualificarDesfazer`) + os `{% if %}` do Twig |
| mudar as setas / ordem das unidades | `Repository/ObjetoCobrancaRepository.php::vizinhosNaCarteira` |

### Ajustes que já têm dono conhecido (levantados e não feitos)

1. **Badge `Qualificação incompleta` fora do gate de PII** — decisão do dono, uma linha de Twig.
2. **`Marcar como atual` e o mini-form de telefone levam para FORA da aba** (rotas da ficha
   redirecionam para `cobranca_pessoa_show`). Trazer de volta exige um `?voltar=` nas duas rotas —
   frente de ~20 minutos, registrada na Etapa 6.
3. **Índice `(carteira_id, identificacao, id)`** para as setas — é migration, e esta frente decidiu
   não ter nenhuma.
4. **Duas decisões da Etapa 2** (cascata do desfazer · caso encerrado) — travadas pelos testes
   `cascataDentroDaJanela` e `casoEncerradoAindaDesfaz`.
5. **`Simular acordo` e `Planilha atualizada`** estão desabilitados com tooltip, à espera de o dono
   dizer o que fazem (§1.4 / §6 da spec).

### Regra que esta frente aprendeu e o próximo chat deve manter

**Todo teste novo tem de ser provado por injeção de defeito** — e **conferir com `grep` que o defeito
foi mesmo aplicado onde você pensa**. Três alarmes falsos nesta frente foram culpa da injeção, não do
teste. Se um teste passar verde dos dois lados, ou ele não prova o que diz, ou há defesa dupla — nos
dois casos o comentário no teste tem de dizer isso, em vez de prometer mais do que há.

## Comandos

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca/Unit'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'   # suíte completa
```
