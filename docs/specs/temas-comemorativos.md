# Temas comemorativos (desenho 05, Perfis 1.2, VS 21.10.26, F7)

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html` L352-360 (card) e L834-839
(lógica), e o motor `docs/design/perfis-1.2/vs-21-10-26/bj-tema.js`. Frente da 9b, coordenada com a b5 (dona da
tela "Telas e recursos"). Risco BAIXO. Decisões do coordenador e da b5 em 21/10/2026.

## Estado da frente

- 21/10: spec; implementação começando.
- 21/10: implementado na branch `temas-comemorativos` (domínio Master): catálogo `CatalogoDeTemas`/`TemaComemorativo`,
  `TemaDoDia`, entidade `TemaConfig` (Auditavel, escopo de plataforma), migration `Version20261020144000`,
  UseCases `DefinirModoDoTemaUseCase` e `AlternarTemaComemorativoUseCase`, rotas POST `master_temas_modo` e
  `master_tema_ligar` (CSRF `master_temas`), parciais `master/_recursos_tema.html.twig` e
  `layout/_globais/tema_comemorativo.html.twig`, CSS `tema-comemorativo.css` e `master-temas.css`, JS
  `tema-comemorativo.js` e `master/temas.js`. Prévia em localStorage `bj-tema-previa` (`{id, ate}`), só com o
  catálogo que vai para o Master. Migration aplicada no banco da frente; testes direcionados (374) verdes, qa.sh
  limpo. Suíte completa fica com o CI. Falta: smoke do dono.

## O que é

Nas datas especiais, o sistema inteiro muda de cor (a cor do cabeçalho e a primária) e mostra uma faixa no topo,
os ícones do tema flutuando no cabeçalho e uma mensagem no canto que cada pessoa pode fechar. Só o Master
controla, na aba "Telas e recursos" do painel. Vale para a plataforma inteira (todos os escritórios e usuários).

## Os 11 temas (iguais ao bj-tema.js, num catálogo PHP único)

| id | Nome | Datas | p / d / l | Ícone |
|---|---|---|---|---|
| mulher | Dia Internacional da Mulher | 08/03 | #c2457a / #9e2f61 / #fbe7f0 | 💐 |
| maes | Dia das Mães | 2º domingo de maio | #b8487e / #8f3561 / #fbe9f2 | 🌷 |
| pais | Dia dos Pais | 2º domingo de agosto | #2c5a8c / #1f4268 / #e6eef7 | 👔 |
| advogado | Dia do Advogado | 11/08 | #1d3557 / #12233b / #eef1f7 | ⚖️ |
| independencia | Independência do Brasil | 07/09 | #1e7b45 / #145a32 / #e6f4ec | texto "Brasil" |
| criancas | Dia das Crianças | 12/10 (animado) | #6b3fc5 / #4f2d97 / #efe9fb | 🎈 |
| outrosa | Outubro Rosa | 01/10 a 31/10 (fraco) | #d0558f / #a63e70 / #fcebf3 | 🎗️ |
| novazul | Novembro Azul | 01/11 a 30/11 (fraco) | #1f6fbf / #155291 / #e6f0fa | 🎗️ |
| justica | Dia da Justiça | 08/12 | #24406e / #182c4d / #eaeef6 | 🏛️ |
| natal | Natal | 18/12 a 25/12 | #b22234 / #8a1a28 / #fbeaec | 🎄 |
| reveillon | Réveillon | 26/12 a 01/01 | #8a6d1f / #5f4a12 / #f8f1de | 🥂 |

A `fita` (gradiente) e a `msg` de cada tema são as literais do bj-tema.js. A data vale no fuso de Brasília
(relógio do PHP).

## Regra do tema ativo (como o `ativo()` do desenho)

1. `desligado` → nenhum. 2. `manual` → o tema escolhido. 3. `automatico` → dos temas não desligados que caem no
dia, o primeiro não fraco; se não houver, o primeiro fraco (dia específico vence o mês inteiro).

## Onde guarda

Tabela própria `tema_config`, linha única (id 1): `modo` (automatico/manual/desligado), `manual` (id do tema ou
nulo), `desligados` (json com os ids), `alterado_por_id`, `alterado_em`. Migration `Version20261020144000`
(reservada pelo coordenador). Não usa o RecursoEstado nem o CatalogoDeRecursos (combinado com a b5: o formato é
outro). Sem a linha, vale o modo automático com todos ligados.

**Prévia:** fica só no navegador do Master que a pediu (30 s, como o código do desenho), sem gravar no banco. O
comentário do bj-tema.js fala de prévia na tabela, mas gravá-la mudaria a cor para todos os usuários por 30 s; o
visual da prévia é o do desenho.

## Aplicação no layout

- Parcial `layout/_globais/tema_comemorativo.html.twig`, incluído por uma linha no `layout/_globais.html.twig`
  (o base não muda). Só com tema ativo (ou prévia no navegador do Master).
- Cores por variáveis CSS, não pelo truque do protótipo: `--header-bg`, `--header-avatar-fg` e a primária do
  Bootstrap (`--bs-primary`, `--bs-primary-rgb`, `--bs-link-color`, botão primário) recebem p/d/l do tema.
- Faixa `#bjtFita` de 4px fixa no topo (animada no Dia das Crianças); ícones flutuando no cabeçalho (5, opacidade
  .3, somem abaixo de 640px); selo `#bjtSelo` no canto inferior esquerdo com ícone, nome em negrito, mensagem e
  "×" (title "Fechar"); fechado, vira o botão redondo `#bjtMini` (title = nome) até o fim do dia, e clicar nele
  reabre. "Visto" por pessoa e por dia no localStorage (`bj-tema-visto-{id}-{AAAA-MM-DD}`).
- Respeita `animacoes_reduzidas()` (sem flutuar nem animar).

## Controle (Master)

- Parcial `master/_recursos_tema.html.twig` (com o seu JS e CSS), incluído pela b5... a linha de inclusão é
  acrescentada por esta frente em `templates/master/painel.html.twig`, como primeiro filho de
  `<div class="mp-rec" data-painel-aba="rec" hidden>` (combinado com a b5).
- Textos e componentes do desenho L352-360: título "Temas comemorativos", descrição, status ("{ic} {r} está ativo
  agora", "Temas desligados: cores padrão do BlueJus", "Nenhum tema hoje: cores padrão do BlueJus", "Prévia: {ic}
  {r} (termina sozinha em {N} s)"), seletor "Automático pelo calendário" / "Escolher um tema" / "Desligado", dica
  "Clique num tema para deixá-lo fixo.", grade de cards (fita, quadrado na cor, nome, quando "dd/mm" ou "dd/mm a
  dd/mm" da próxima ocorrência, selo "ATIVO", "Prévia", interruptor), "Parar prévia".
- Rotas POST com `ROLE_SUPER_ADMIN` e CSRF: salvar modo/manual e ligar/desligar tema. UseCase no domínio Master.
