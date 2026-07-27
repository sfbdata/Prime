# UX rápida da página de cobrança — handoff

Reorganização de interface de `cobranca_objeto_show`, executada em 2026-07-26 a partir da SPEC
[cobranca-ux-rapida-1-dia.md](cobranca-ux-rapida-1-dia.md), mais um delta incremental pedido depois.

**Nada publicado.** Sem push, merge, PR ou deploy. **Sem migration** — o banco não muda.

## Estado

| | |
|---|---|
| Branch | `feature/cobranca-ux-rapida` |
| Worktree | `.claude/worktrees/cobranca-ux-rapida` |
| Base | `origin/master` `0bb1f29` |
| HEAD | topo de `feature/cobranca-ux-rapida` (6 commits sobre a base) |
| Suíte | **2601/2601** (8209 asserções), no banco da frente |
| Árvore | limpa |

Commits, na ordem:

| Commit | O quê |
|---|---|
| `2ad9a42` | Cabeçalho compacto, barra de atividades, abas Cobrança/Dívida/Honorários, confirmação de exclusão |
| `b9782f5` | Reverte o editor rico do relato do contato; fecha achados da 1ª revisão |
| `856133a` | Contém o escopo na cobrança (desfaz a liberação global de link) e prova a segurança da anotação |
| `8e5af79` | Aba Responsáveis, remoção do trilho direito, largura total, próxima ação compacta |
| `7b48a52` | Documenta a entrega |
| _HEAD_ | **Homônimos: opção por id com rótulo desempatado; tira a mitigação da UI** |

## O que a página virou

- **Cabeçalho** — identificação, carteira, status, Total em aberto, Total vencido, alertas existentes,
  `Judicializar` e `Encerrar cobrança`.
- **Barra de atividades** (largura total, acima do conteúdo) — 7 opções: Cobrança · Documentos ·
  Histórico · Responsáveis · Registrar contato · Dívida · Honorários. Abaixo de **1200px** as menos
  frequentes recolhem em `Mais ações`; ficam visíveis as 4 que a SPEC §6.4 exige.
- **Cobrança** — próxima ação em faixa compacta, editor de anotação (primeiro elemento útil) e a lista
  de **Anotações** com editar/excluir: aparecem **todas**, com contador no cabeçalho e rolagem dentro
  do bloco (22rem, a mesma altura da lista de eventos da Central). Substituiu o corte nas 10 mais
  novas — procurar uma anotação antiga não deve obrigar a ir ao Histórico, onde ela some no meio dos
  eventos automáticos. Sem custo de consulta: `EventoHistoricoRepository::doCaso` sempre trouxe o
  histórico inteiro, o corte era só de exibição.
- **Responsáveis** — responsável atual no topo e expandido, demais em accordion; `Trocar responsável`,
  `Adicionar pessoa`, `Definir como atual`, `Editar` e `Encerrar vínculo`.
- **Dívida** — composição inteira (principal, juros, multa, correção, honorários, total) e
  `Editar configuração de encargos`.
- **Honorários** — só o que o sistema já calcula.
- **Sem trilho direito**: o card da pessoa virou a aba Responsáveis e a próxima ação virou a faixa.

## Ambiente da frente (o `saas` do dev NÃO serve)

O banco `saas` tem 4 migrations da frente canônica que **derrubam `caso_id` e `referencia_externa`** —
ele é incompatível com o master. Por isso a frente tem ambiente próprio:

| | |
|---|---|
| Banco de dev | `saas_ux` (clone de `saas` com as 4 migrations revertidas) |
| Banco de teste | `saas_testcobranca-ux-rapida` |
| Preview | `http://localhost:8090` (container `jusprime_preview_ux`) |
| Aponta o banco | `app/.env.local` da worktree (gitignored) |

`localhost:8080` continua servindo o repositório principal. Rodar a suíte:
`scripts/frente-testar.sh cobranca-ux-rapida`.

Para derrubar o ambiente: `docker rm -f jusprime_preview_ux` e dropar `saas_ux` /
`"saas_testcobranca-ux-rapida"`.

## O que ficou DE FORA, e por quê

Três coisas foram cortadas por decisão consciente. Nenhuma é esquecimento.

### 1. Botão de link na barra de formatação (SPEC §11.1)

Exigiria liberar `<a href>` no sanitizador `textoRico`, que é **compartilhado com Pasta, Tarefa e
Meta** — mudaria o comportamento desses domínios para entregar um botão na cobrança. Revertido em
`856133a`; os 4 arquivos compartilhados voltaram byte a byte ao master. A barra mantém negrito,
itálico, sublinhado, tachado, cor, listas, recuo, alinhamento, citação e limpar formatação.

Para religar: `a: ['href','title']` + `force_https_urls: true` no alias `textoRico`, o mesmo do
`kanban` (comprovadamente seguro: `javascript:`, `data:`, `target` e `onclick` não sobrevivem), mais
`'link'` em `FORMATOS`/`BARRA` de `editor-rico.js` e o espelho em `tests/Shared/CriaSanitizadorTextoRico.php`.

### 2. Editor rico no relato do contato (SPEC §11/§12)

Dois impedimentos medidos:

- `RegistrarTentativaCobrancaUseCase` concatena a observação na descrição do evento **sem passar pelo
  `SanitizadorTextoRico::limpar()`** que a anotação usa na escrita — guardaria HTML cru no banco;
- a descrição é lida pela **Central de Acompanhamento, que está em produção** e escapa o valor: todo
  contato novo passaria a mostrar `<p>` literal numa tela fora do escopo autorizado.

Além disso, `|texto_rico` **mutila** texto legado que contenha `<`: no sanitizador real,
`"cliente diz valor < 500 nao paga"` perde o trecho a partir do sinal. O dev tem 4 contatos e nenhum
com `<` — amostra que não autoriza concluir nada sobre produção. **Meça em prod antes de decidir:**

```sql
SELECT count(*) FROM cobranca_evento_historico
WHERE tipo = 'contato_realizado' AND descricao LIKE '%<%';
```

Há teste travando o religamento acidental (`ObjetoShowUxReorganizacaoTest::testCamposNarrativosUsamEditorRico`).

### 3. Modal de pessoa com qualificação, telefones, e-mails e endereços

Pedido no delta; **é estrutural** e por isso não foi feito. O backend está 100% pronto (4 forms, 7
rotas, todos os UseCases, `PessoaFichaOutput` e 4 partials reaproveitáveis). O buraco é de composição
de tela:

- `cobranca_objeto_show` carrega só `VinculoPessoaOutput` (nome/CPF/CNPJ/telefone/e-mail) — não tem
  qualificação nem as três coleções;
- não existe endpoint que devolva a ficha em fragmento: seria rota nova, ou montar
  `MontarFichaPessoaUseCase` + 4 formulários **por pessoa** dentro do `ObjetoController::show`;
- as 7 rotas de mutação redirecionam com `cobranca_pessoa_show` fixo — o modal jogaria o usuário para
  fora da página do objeto, e mudar isso altera comportamento já entregue;
- não há form agregado: são 4 forms independentes, 4 POSTs, 4 redirects.

Enquanto isso, `Editar` (no responsável atual e em cada pessoa do accordion) leva à ficha completa —
a função continua acessível, sem perda.

## ✅ Homônimos no "Trocar responsável" — CORRIGIDO (2026-07-26)

`PessoaRepository::opcoesDoTenant` montava as opções **indexadas pelo nome**, então homônimo
sobrescrevia homônimo: **125 pessoas produziam 110 opções — 15 inalcançáveis** no modal de troca, e
escolher um nome repetido podia selecionar o **registro errado** (isso decide **quem é cobrado**).

O conserto ficou no repositório e nos seus consumidores diretos. O **valor** da opção sempre foi o id
(é o que o form submete e o UseCase resolve por id + tenant); o defeito era a **chave**. Agora nome
repetido ganha desempate — CPF, CNPJ, e-mail ou `#id`, o primeiro disponível — e um laço garante que
nenhuma opção apague outra nem no cadastro patológico. `ORDER BY nome, id` tornou a ordem estável.
Nome único continua exibido puro: o desempate não expõe documento a mais na tela.

A mitigação da UI (o `Definir como atual` desabilitado com tooltip) **saiu junto**, já sem propósito.

Medido no preview, no dado real: **125 pessoas → 125 opções, 125 valores únicos, 125 rótulos únicos**.
Troca ponta a ponta feita nos dois sentidos entre homônimos (`#23` de seis `CRUZEIRO`, e `#22` de duas
`MARIA`): gravou o id exato, conferido no banco. Evidência em
[mockups/ux-rapida/10-escuro-homonimo-definir-como-atual.png](mockups/ux-rapida/10-escuro-homonimo-definir-como-atual.png).

Prova de que os testes pegam o defeito: reintroduzindo a indexação por nome, **6 testes falham** — um
deles gravando a pessoa errada no banco (`53714` no lugar de `53715`).

### O que o conserto NÃO resolve (decisão do dono)

**Ninguém mais desaparece — mas o rótulo ainda não diz ao humano QUAL homônimo é qual.** No dado real
importado, as 24 pessoas homônimas (9 nomes) têm CPF, CNPJ, e-mail, telefone e RG **todos vazios**: o
desempate cai sempre no `#id`, e `CRUZEIRO E SOUSA IMOVEIS LTDA ME (#23)` não significa nada para o
operador. Onde o id vem do vínculo (`Definir como atual`) não há ambiguidade; onde o humano **escolhe
da lista** (`Trocar responsável`, `Vincular pessoa já cadastrada`) a escolha entre homônimos continua
sendo às cegas — e o erro fica invisível depois, porque a tela mostra o mesmo nome.

O que de fato distingue essas seis está **fora da linha da pessoa**: cada uma tem um vínculo, em
unidade diferente (`#23` → QUADRA 01 CHACARA 03/10, `#36` → QUADRA 02 CHACARA 02/08, …). Levar a
unidade para o rótulo exige o repositório passar a conhecer vínculos e objetos — e decidir o que
mostrar para quem tem vários. **Ficou fora por ser ampliação de escopo, não por esquecimento.**

### Follow-ups registrados

- `desempate()` lê a **coluna-sombra** `p.email`; quem teve e-mail cadastrado pelo
  `AdicionarEmailPessoaUseCase` (lista `cobranca_pessoa_email`) aparece com e-mail na aba Responsáveis
  e com `(#id)` no select. Inerte hoje (1 linha no preview), mas a regra diverge do resto do domínio.
- **A mesma classe de defeito segue aberta ao lado**: `ClienteRepository::opcoesDoTenant`
  (chave = nome de exibição) e `PastaRepository::opcoesDoTenant` (chave = NUP) sobrescrevem por rótulo
  exatamente como o `PessoaRepository` fazia. Inertes no dado atual; fora do escopo desta frente.
- O `addOrderBy('p.id')` não tem teste que o trave: com duas linhas recém-inseridas o Postgres devolve
  na ordem de inserção mesmo sem ele.

## Como conferir no navegador

`http://localhost:8090/cobrancas/objetos/84` (senha do dev: `Prime123!`).

1. Salvar anotação com negrito → aparece na lista de **Anotações** → editar (badge **Editada**) →
   excluir (o modal mostra um trecho). `Ctrl+Enter` salva. Com mais de ~5 anotações o bloco passa a
   rolar sozinho, sem cortar nenhuma (medido: 14 anotações = 1112px de conteúdo em 352px de janela).
2. **Dívida** e **Honorários** — a composição e as métricas.
3. **Responsáveis** — atual no topo, demais no accordion. Objeto `22` tem dois vínculos, e **os dois
   são homônimos** (`MARIA PEREIRA DA SILVA` é uma de duas; `CRUZEIRO E SOUSA IMOVEIS LTDA ME`, uma de
   seis): `Definir como atual` aparece **habilitado** e pré-seleciona o id certo. Objeto `72` tem um
   vínculo com nome único.
4. Estreitar abaixo de 1200px → `Mais ações`. Alternar tema claro/escuro.

Evidências visuais em [mockups/ux-rapida/](mockups/ux-rapida/) — documentação, não asset da aplicação.

## Invariantes desta frente (não afrouxe)

- **Nada fora da cobrança.** O diff toca só `templates/cobranca/`, `public/css/cobrancas.css`,
  `src/Cobranca/` e `tests/Cobranca/` (+ docs). Sanitizador, `editor-rico.js`, Pasta, Tarefa e Kanban
  estão **byte a byte iguais ao master** — confira antes de integrar.
- **Nenhum centavo muda.** `_divida.html.twig` e `_movimentos.html.twig` seguem intocados; a aba
  Honorários não soma, projeta nem deriva nada.
- **Motivo da troca de responsável é obrigatório** (`AlterarPessoaCobradaInput`). Por isso "Definir
  como atual" abre o modal com a pessoa escolhida em vez de trocar direto.
- **Anotação: só o autor, só em 48h, só anotação livre.** Coberto por teste, inclusive cross-tenant,
  CSRF, capacidade e sanitização na escrita.

## Lições medidas (custaram tempo)

- **`doctrine:migrations:migrate <versão-anterior>` NÃO desce** — anuncia "Migrating up to" e não faz
  nada. Reverter exige `doctrine:migrations:execute --down`, uma versão por vez.
- **Medir no dev não autoriza concluir sobre prod.** 4 registros não são amostra.
- **O achado caro estava no que a mudança arrastava para FORA da página** — o relato rico parecia
  local e quebraria a Central, que está em produção.
- **Ponto de corte responsivo é medido, não chutado.** A barra caiu de 9 para 7 opções e o corte desceu
  de 1400 para 1200px. Ao mexer nos itens, **meça de novo**.
- **Twig: `{% set %}` dentro de `{% for %}` não escapa do laço.**
- **Revisão adversarial pagou-se quatro vezes**: pegou os 2 bloqueantes do relato, a liberação global
  de link, as 2 funções que o card removido levaria junto (trocar responsável em 116 dos 121 objetos, e
  encerrar o vínculo da própria pessoa cobrada) e, no conserto dos homônimos, a diferença entre
  **unicidade e distinguibilidade** — 125 rótulos únicos não são 125 rótulos úteis.
- **O teste novo só vale se você provar que ele pega.** A prova aqui foi reintroduzir o defeito e ver
  6 falhas, uma delas gravando a pessoa errada. Sem esse passo, "suíte verde" não diria nada.
- **Métrica de smoke pode medir a coisa errada.** "125 opções, 125 valores únicos" fecha o buraco de
  quem sumia e **não diz nada** sobre o operador conseguir escolher certo entre seis nomes iguais.
