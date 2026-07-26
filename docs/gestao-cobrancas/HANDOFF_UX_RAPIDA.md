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
| HEAD | `8e5af79` |
| Diff | 25 arquivos, +1980 −396 |
| Suíte | **2593/2593** (8175 asserções), no banco da frente |
| Árvore | limpa |

Commits, na ordem:

| Commit | O quê |
|---|---|
| `2ad9a42` | Cabeçalho compacto, barra de atividades, abas Cobrança/Dívida/Honorários, confirmação de exclusão |
| `b9782f5` | Reverte o editor rico do relato do contato; fecha achados da 1ª revisão |
| `856133a` | Contém o escopo na cobrança (desfaz a liberação global de link) e prova a segurança da anotação |
| `8e5af79` | Aba Responsáveis, remoção do trilho direito, largura total, próxima ação compacta |

## O que a página virou

- **Cabeçalho** — identificação, carteira, status, Total em aberto, Total vencido, alertas existentes,
  `Judicializar` e `Encerrar cobrança`.
- **Barra de atividades** (largura total, acima do conteúdo) — 7 opções: Cobrança · Documentos ·
  Histórico · Responsáveis · Registrar contato · Dívida · Honorários. Abaixo de **1200px** as menos
  frequentes recolhem em `Mais ações`; ficam visíveis as 4 que a SPEC §6.4 exige.
- **Cobrança** — próxima ação em faixa compacta, editor de anotação (primeiro elemento útil) e
  "Anotações recentes" com editar/excluir.
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

## 🐛 Achado aberto: homônimos somem do "Trocar responsável"

`PessoaRepository::opcoesDoTenant` (`app/src/Cobranca/Repository/PessoaRepository.php:60-76`) monta as
opções **indexadas pelo nome**:

```php
$opcoes[$linha['nome']] = (int) $linha['id'];   // homônimo sobrescreve homônimo
```

Medido no dev: **125 pessoas produzem 110 opções — 15 ficam inalcançáveis** no modal de troca. Pior:
escolher um nome repetido seleciona o **registro errado**, e isso decide **quem é cobrado**.

Não foi corrigido aqui: mexe no repositório e afeta o modal já entregue. A mitigação dentro do escopo
é que `Definir como atual` aparece **desabilitado**, explicando a condição, quando a pessoa não está
nas opções — em vez de abrir um modal que não pode ser enviado.

O conserto é dar desempate à chave (o valor precisa ser o id, com o nome só como rótulo) e revisar o
`ORDER BY`, que hoje não desempata entre homônimos — qual deles sobrevive é **indefinido**.

## Como conferir no navegador

`http://localhost:8090/cobrancas/objetos/84` (senha do dev: `Prime123!`).

1. Salvar anotação com negrito → aparece em "Anotações recentes" → editar (badge **Editada**) →
   excluir (o modal mostra um trecho). `Ctrl+Enter` salva.
2. **Dívida** e **Honorários** — a composição e as métricas.
3. **Responsáveis** — atual no topo, demais no accordion. Objeto `22` tem dois vínculos e um homônimo
   (botão desabilitado); objeto `72` tem um vínculo com nome único (o `Definir como atual` funciona e
   abre o modal já com a pessoa selecionada).
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
- **Revisão adversarial pagou-se três vezes**: pegou os 2 bloqueantes do relato, a liberação global de
  link e as 2 funções que o card removido levaria junto (trocar responsável em 116 dos 121 objetos, e
  encerrar o vínculo da própria pessoa cobrada).
