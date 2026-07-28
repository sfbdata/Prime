# Modal único de pessoa na aba Responsáveis (cobranca_objeto_show)

**Data:** 2026-07-28 · **Risco:** MÉDIO (superfície de PII e de rotas; nenhum centavo muda)
**Pedido do dono:** *"na aba Responsável, o editar e o cadastrar nova pessoa está diferente. quero que os 2 usem
o mesmo formulário em modal, como terá mais dados a serem preenchidos coloque os blocos (Qualificação,
Endereços, Telefones e E-mails) em abas diferentes no modal, apenas telefones e e-mail na mesma aba."*

## 1. O problema

Hoje as duas ações não se parecem em nada:

| | Onde abre | O que mostra | Como grava |
|---|---|---|---|
| **Cadastrar nova pessoa** | modal `#modalNovaPessoa` | 6 campos soltos (nome, vínculo, CPF, CNPJ, telefone, e-mail) | 1 POST |
| **Editar** | sai da página → `cobranca_pessoa_show` | qualificação (9 campos) + 3 listas com histórico | 1 POST por bloco |

Cadastrar não pede estado civil, RG, profissão, nascimento nem endereço — justamente o que o badge
`Qualificação incompleta` cobra depois. E editar tira o gestor da cobrança que ele está trabalhando.

## 2. Decisões do dono (2026-07-28)

1. **Cadastro grava tudo num só salvar.** As mesmas 3 abas; em modo cadastro cada lista vira UM item
   (um endereço, um telefone, um e-mail), todos opcionais. Ao editar depois, as mesmas abas mostram as
   listas com histórico.
2. **Na edição, cada bloco grava sozinho** — como a ficha faz hoje. Reusa as rotas e os UseCases que já
   existem; o histórico e a regra "um atual por lista" (SPEC de qualificação §6) ficam intactos.
3. **O modal substitui a página da ficha.** Nenhum link da aba leva mais a `cobranca_pessoa_show`.
   A rota **continua existindo** (não é removida) — vira alcançável só por URL direta.
   ⚠️ Consequência aceita pelo dono: **sem JavaScript não há caminho para editar** pela aba.

## 3. O que passa a existir

### 3.1 Modal único `#modalFichaPessoa`

Três abas, na ordem: **Qualificação** · **Endereços** · **Telefones e E-mails**.

**Modo EDITAR** (pessoa já existe) — corpo carregado por AJAX:
- `GET /cobrancas/pessoas/{id}/ficha-modal` (`cobranca_pessoa_ficha_modal`) devolve o partial
  `cobranca/pessoa/_partials/_ficha_abas.html.twig`: a casca de abas + os QUATRO partials que a ficha já
  usa (`_qualificacao`, `_lista_enderecos`, `_lista_telefones`, `_lista_emails`). **Fonte única com a
  página da ficha** — nenhuma lista ganha segunda implementação.
- Cada bloco continua postando para a rota de pessoa que já era a dele. O JS do `show` intercepta o
  submit dentro do modal, manda por `fetch` com `X-Requested-With`, e troca o corpo do modal pelo
  fragmento devolvido já atualizado.
- Ao fechar o modal **depois de ao menos uma gravação**, a página recarrega — é o que faz o nome, os
  telefones, a faixa de qualificação e o badge da aba refletirem a edição.

**Modo CADASTRAR** (pessoa nova) — conteúdo estático, um POST só, sem AJAX:
- Aba Qualificação: tipo de vínculo + nome, CPF, CNPJ, nascimento, estado civil, profissão, RG, órgão
  emissor, observação.
- Aba Endereços: um endereço (bloco inteiro opcional).
- Aba Telefones e E-mails: um telefone (com tipo WhatsApp/Fixo) + um e-mail.
- POST em `cobranca_objeto_pessoa_criar` (rota que já existe).

### 3.2 Rotas de mutação da pessoa ganham ramo AJAX

As 9 rotas POST do `PessoaController` (qualificação; endereço adicionar/atual; telefone adicionar/
editar/atual/excluir; e-mail adicionar/atual) passam a responder JSON `{ok, mensagem, html}` com o
fragmento do modal **quando a requisição é AJAX**. Sem AJAX, o PRG de hoje segue idêntico — a página da
ficha não muda de comportamento.

## 4. Regras que NÃO mudam

- **Capacidade:** tudo atrás de `resources.cobranca.gerenciar`, a mesma que a ficha exige e a mesma que
  o gate de PII da aba usa. O modal não amplia o que alguém enxerga.
- **Multi-tenant:** resolução por id + tenant (`resolverPessoa`) em toda rota; pessoa de outro escritório
  é 404. O fragmento do modal passa pela mesma guarda.
- **Histórico:** "adicionar" nunca sobrescreve; "marcar atual" só troca a flag; corrigir/excluir seguem
  sendo as ações explícitas de sempre. Nenhum caminho novo de gravação em lote foi criado.
- **Coluna-sombra** `Pessoa::telefone`/`email`: continua sincronizada pelos UseCases de lista.
- **B5** (reidratar modal com erro de validação): o cadastro continua reabrindo o modal com o digitado.

## 5. Camadas tocadas

| Camada | Arquivo | O quê |
|---|---|---|
| DTO | `CriarPessoaVinculadaInput` | + campos de qualificação, bloco de endereço, tipo do telefone; validação condicional do endereço |
| DTO | `CriarPessoaInput` | + campos de qualificação (opcionais; não quebra importador nem criação de objeto) |
| UseCase | `CriarPessoaUseCase` | grava a qualificação nova |
| UseCase | `CriarPessoaVinculadaAoObjetoUseCase` | orquestra endereço/telefone/e-mail pelos UseCases de lista |
| Form | `CriarPessoaVinculadaType` | campos novos |
| Controller | `PessoaController` | + `cobranca_pessoa_ficha_modal` (GET) e o ramo AJAX das 9 POST |
| Twig | `pessoa/_partials/_ficha_abas.html.twig` | NOVO — casca de abas reusando os 4 partials |
| Twig | `objeto/show.html.twig` | `#modalFichaPessoa` (cadastro + casca de edição) e o JS |
| Twig | `objeto/_partials/_responsaveis.html.twig` | os 3 gatilhos (Editar da cobrada, Editar de cada vinculado, badge) abrem o modal |
| Twig | `objeto/_partials/_telefones_cobrada.html.twig` | só os `id` dos widgets, com prefixo `cobResp` — obrigatório, ver §8 |

## 6. Fora de escopo (dito, para ninguém "consertar" depois)

- A **página** `cobranca_pessoa_show` não é redesenhada nem removida; segue como está.
- O bloco §2.3 de telefones **da aba** e suas rotas no objeto não são desta frente. Ele é tocado em UM
  ponto só, e por obrigação: os `id` dos widgets ganharam prefixo `cobResp` (ver §8).
  ⚠️ A árvore de trabalho já continha, ANTES desta frente e não commitada, outra rodada daquele bloco
  (rota `cobranca_objeto_telefone_atual`, reordenação "atual no topo", `addOrderBy` no
  `PessoaTelefoneRepository`) e a frente inteira do **Ponto**. Nada disso é deste trabalho — são três
  frentes convivendo na mesma árvore, e o commit precisa separá-las.
- Os modais "Vincular pessoa existente" e "Trocar responsável" não mudam.
- Nenhuma regra de dedução/dinheiro é tocada.

## 7. Prova

- Funcional novo (`FichaPessoaModalControllerTest`): o fragmento sai com as 3 abas; exige a capacidade;
  404 cross-tenant; cada bloco grava por AJAX e devolve o fragmento atualizado; sem AJAX o PRG antigo
  continua.
- Funcional do cadastro estendido: qualificação completa grava; endereço parcial recusa; endereço vazio
  não cria endereço; telefone entra na lista já como atual e com o tipo escolhido.
- Ajuste dos testes da aba que hoje afirmam que "Editar" é link para a ficha.
- `FichaPessoaModalControllerTest::testFragmentoNaoColideDeIdComAPagina`: compara TODOS os `id` da página
  do objeto com os do fragmento e exige interseção vazia — é o que impede a volta do bloqueante do §8.
- `CriarPessoaVinculadaAoObjetoUseCaseTest` (KernelTestCase, banco real): atomicidade (falha no vínculo
  não deixa resíduo), listas nascendo `atual` com o tipo escolhido, e blocos vazios não criando nada.
  Fica em `Functional/` porque precisa de kernel e banco — mesmo lugar do `ImportarRelatorioCarteiraTest`,
  que também testa um UseCase orquestrador com transação.

## 8. Correções da revisão (2026-07-28)

A revisão adversarial reprovou a primeira versão. O que mudou:

| Achado | Correção |
|---|---|
| **Colisão de `id`** — aba e modal renderizam o mesmo `AdicionarTelefoneType`; o `for` do label do modal marcava o rádio da aba, então pelo modal só se cadastrava `Fixo` | `id` próprio (`cobResp*`) nos widgets da aba. O NOME do campo não muda — a rota lê o mesmo POST. Travado por teste que compara os `id` da página com os do fragmento |
| **Erro na edição apagava o digitado** — o JS trocava o fragmento mesmo com `ok: false`, e ele é remontado do banco | Só troca quando a gravação deu certo; na recusa, o que está na tela fica e o rodapé diz o que corrigir |
| **`form.submit()` no `catch`** podia repetir um POST já gravado (sessão expirada, JSON quebrado) | Reenvia só quando o servidor NÃO respondeu (rede fora). Respondeu e falhou depois → avisa e não duplica |
| **Cadastro sem atomicidade** — falha no vínculo deixaria pessoa + endereço + telefone + e-mail órfãos | `wrapInTransaction` no UseCase. Provado por teste que reintroduziu o defeito |
| **`addFlash` no ramo AJAX** se acumulava e aparecia todo junto no recarregamento | Flash só no caminho sem AJAX, centralizado no `respostaFicha` |
| **Complemento sozinho** era descartado em silêncio | Passou a contar como "encostou no bloco": o gestor recebe a lista do que falta |
| **UseCase sem teste** | `CriarPessoaVinculadaAoObjetoUseCaseTest` (KernelTestCase — a regra da casa proíbe mockar o EM) |
| PII do fragmento ficava no DOM depois de fechar | O corpo do modal volta ao estado de espera ao fechar sem gravação |

**Aceito conscientemente:** o UseCase decide gravar o endereço por `temAlgumDadoDeEndereco()` sem
revalidar completude. Quem garante o endereço inteiro é o `#[Assert\Callback]` do Input, e o único
chamador é o controller, que valida pelo Form. Chamador futuro que pule o Form precisa saber disso.

**Incidente de ambiente:** o primeiro teste do UseCase subiu com o trait `ResetDatabase` do Foundry, que
recriou o `saas_test` pelo mapeamento e derrubou 322 testes — a armadilha já documentada (o que vem de
SQL cru não volta). Reparado com precisão: `unaccent`, `uniq_cobranca_obrigacao_ref_externa` e
`doctrine_migration_versions`. Suíte completa verde depois (2800/2800). **Não use `ResetDatabase` aqui.**
