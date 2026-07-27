# HANDOFF — Cabeçalho e aba Responsáveis do `cobranca_objeto_show`

Redesenho pedido pelo dono do sistema a partir de uma montagem visual (2026-07-27).
**Contrato:** [`docs/specs/cobranca-objeto-show-cabecalho-responsaveis.md`](../specs/cobranca-objeto-show-cabecalho-responsaveis.md).
Leia a spec antes de escrever qualquer linha — ela registra o que foi cortado da maquete e por quê.

## Estado

| | |
|---|---|
| Branch | **`master`**, HEAD `29c4cbf` (Etapa 3) sobre `origin/master` `6e93b43` — **não publicado** |
| Worktree | nenhuma — trabalho direto no checkout principal |
| Migration | **nenhuma prevista**, e é decisão de projeto (ver §3.1 da spec) |
| Suíte | `tests/Cobranca` **1180/1180** verde ao fim da Etapa 3 |
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
- [ ] **4 — Rotas** (registrar / desfazer qualificação)
- [ ] **5 — Template do cabeçalho**
- [ ] **6 — Template da aba Responsáveis + painel de qualificação**
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

## Comandos

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca/Unit'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'   # suíte completa, só na Etapa 8
```
