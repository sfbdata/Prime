# Reauditoria C6 — Pasta: Processo · Metas · Clientes (prefixo PMC-)

Auditoria somente leitura, master `4a98cc6b`, 12/10/2026. Nada foi editado além deste arquivo; nenhuma
suíte, container, Playwright ou produção foi tocado. O visual (cor, raio, posição) foi conferido **lendo
CSS e template**, não na tela.

## Fontes lidas

**Desenho** (`docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html`)
- Modal Nova meta 74-193 (moldura 75-76, corpo 77-188, rodapé 189-192) · `nmVals`/`janSt` 3766-3772 ·
  `pastaTxt` 3889.
- Aba Processo 1578-1592 (cabeçalho, interruptor, ⋮ do cabeçalho, cartão com `onContextMenu`) ·
  `PJE_CAMPOS`/`pjeVals` 2850-2912 · `procMenu` 2914-2934 · `procHdMenu` 6588.
- `pushNotaVals` (⋮ da nota, bloqueado com aviso) 5390-5407.
- `metaVals` (⋮ da meta, aviso, Desfazer, drawer) 3544-3600 · renomear na linha 6268-6269.
- Clientes: botão "+" 1357, linhas 1372-1389.

**Ledger/fila**: `docs/specs/engineering-loop-fidelidade-designer.md` §3.1 (linhas 110, 115, 129-130, 162-163, 173-174,
177, 180, 190, 198, 201, 206, 213, 215, 218) e notas 246-270; `docs/specs/engineering-loop/fila-a-restante.md` §0, §1, M5, M8;
inventário do ciclo 0 `docs/specs/engineering-loop/inventario-processo-metas-clientes.md`.

**Código**
- `app/templates/pasta/_processos_vinculados.html.twig` 1-473 · `app/templates/processo/_notas_tecnicas.html.twig` 1-88 ·
  `app/templates/processo/_nota_tecnica_item.html.twig` 1-124 · `app/templates/pasta/_metas.html.twig` 1-429 ·
  `app/templates/pasta/_cliente_linha.html.twig` 1-89 · `app/templates/pasta/_dados_trilho.html.twig` 125-200 ·
  `app/templates/cliente/_resumo.html.twig` (grep) · `app/templates/pasta/_cabecalho.html.twig` 310-313 ·
  `app/templates/pasta/show.html.twig` 3951-4290 (modal Nova meta).
- JS: `pasta-processo.js` 1-337 · `pasta-metas.js` 18-60, 600-835, 860-960 · `nota-tecnica.js` (grep 208, 323, 414, 439) ·
  `pasta-dialogos.js` (grep 4, 29-31, 465).
- CSS: `pasta-show.css` 1003, 2058-2075, 3698-3768, 3931-3949, 4286-4319.
- PHP: `Processo/Controller/ProcessoController.php` 104-190 · `Processo/UseCase/EditarNotaTecnicaUseCase.php` 11-99 ·
  `Processo/Entity/NotaTecnicaVersao.php` · `migrations/Version20261007221253.php` ·
  `Controller/TarefaController.php` 438-479 · `Tarefa/Controller/MetaNaListaController.php` 32, 78-100 ·
  `Tarefa/UseCase/RenomearMetaUseCase.php` 24, 49 · `Pasta/Twig/ExclusaoDaMetaExtension.php` 16-35 ·
  `Controller/PastaController.php` 725-745, 787-800 · `Pasta/EventListener/PastaSomenteLeituraListener.php` 33-104 ·
  `Pasta/Controller/PeticionarController.php` 54-97 · `Pasta/Entity/Pasta.php` 43, 241.
- Testes (nomes/grep): `Tarefa/Functional/ExcluirTarefaControllerTest` (159, 199 cross-tenant),
  `Pasta/Functional/MetaNaListaTelaTest` (381), `MetaCopiarLinkExcluirTelaTest`.
- `git merge-base --is-ancestor` de todos os commits citados no ledger para a área: **todos estão no HEAD**.

## Conferência dos feitos

| ID | Item | Commit | Veredito | Evidência |
|---|---|---|---|---|
| PMC-056 | CSRF em `processo_new`/`processo_edit` | `38df20f0` | ✓ | `ProcessoController.php:108` (`processo_new`), `:162` (`processo_edit_{id}`); token no form `processo/new.html.twig:36` |
| PMC-004 | "Editar dados do processo" no ⋮ | `c3942091` | ✓ | `_processos_vinculados.html.twig:223-227` (só com EDIT no processo e pasta viva); ordem igual a dc:2919-2921 |
| PMC-001 | Botão direito no cartão do processo | `0e889337` | ✓ | `_processos_vinculados.html.twig:173-174` (`data-ps-menu-contexto`, title do desenho); `pasta-processo.js:229-248` |
| PMC-027 | Botão direito na linha da meta | `0e889337` | ✓ | `_metas.html.twig:136-137`; `pasta-metas.js:818-833` (delegado no cartão, sobrevive à troca por XHR: `aplicarAbaNova` mantém o elemento, `:648-651`) |
| PMC-002 | Compartilhar sem Web Share | `13916732` | ✓ | `_processos_vinculados.html.twig:243-248` (sem `hidden`); `pasta-processo.js:140-150` |
| PMC-003 | Imprimir resumo | `01f8a4e1`/`8ef83fb9` | ✓ | `_processos_vinculados.html.twig:252-256`; `pasta-processo.js:156-175` (DOM + `textContent`) |
| PMC-007 | Órgão · Valor · Distribuição sempre, "Não informado" | `9da44bef` | ✓ | `_processos_vinculados.html.twig:309-321`; `pasta-show.css:4319` (13.5px/500 itálico, token) |
| PMC-025 | Rótulos no <768px | L7 | ✓ | `pasta-show.css:2074-2075` (Processo e Metas) |
| PMC-026 | Interruptor com tokens e par escuro | `9e9f9426` | ✓ | `pasta-show.css:4286-4308` (claro e escuro); comentário do template `_processos_vinculados.html.twig:64` |
| PMC-017 | ⋮ da nota técnica + botão direito, bloqueado fora da janela | `e0f8f131` | ✓ (micro: C6-PMC-05) | `_nota_tecnica_item.html.twig:155-205`; `nota-tecnica.js:439` |
| PMC-057 | "Responder com outra nota" | `2a46c739` | ✓ | `_nota_tecnica_item.html.twig:162-166` |
| PMC-058L | "Adicionar nota" só para quem edita | `30b83bc8` | ✓ | `_notas_tecnicas.html.twig:37-39, 56-61, 71-87` |
| PMC-019 | @menção nas notas | `05d7f470` | ✓ | placeholder `_notas_tecnicas.html.twig:80`; `data-url-mencionaveis` `:48`; `EditarNotaTecnicaUseCase.php:83, 99` |
| PMC-020 | Versões da nota (só gravar) | `97b0bc45` | ✓ | `EditarNotaTecnicaUseCase.php:86`; `NotaTecnicaVersao.php`; `Version20261007221253.php` |
| PMC-028 | ⋮ meta → Copiar link | `5da8b263` | ✓ | `_metas.html.twig:244-248` (URL absoluta, aviso "Link da meta copiado" = dc:3553) |
| PMC-030 | ⋮ meta → Excluir | `5da8b263` | ✓ | `_metas.html.twig:256-265` (`meta_pode_excluir`, nunca na lápide); teste cross-tenant `ExcluirTarefaControllerTest.php:159, 199` |
| PMC-029 | "Abrir em Metas" no ⋮ | `fd9d5987` | ✓ | `_metas.html.twig:253-255` (ordem = dc:3559-3561) |
| PMC-036 | Todos os responsáveis na linha | `9e0a0ae4` | ✓ | `_metas.html.twig:154-157` |
| PMC-031 | Concluir/Reabrir por XHR + Desfazer | `910575f0`, `2349cd44` | ✓ | `pasta-metas.js:547-586, 697-765`; `MetaNaListaController.php:100`; aviso 4,2 s = dc:3546 |
| PMC-060 | Lápide sem Concluir/Reabrir/Editar nome/sino | `a9d22090` | ✓ | `_metas.html.twig:92, 96, 222-243, 411-423` |
| PMC-061 | Desfazer do reabrir com a data original | `ceea2f82` | ✓ | `TarefaController.php:451-479`; `pasta-metas.js:706-710` |
| PMC-062 | "Nova meta" fora da lápide | `871c4ab3` | ✓ | `_metas.html.twig:118-123` |
| PMC-044 | Fica na aba Metas com a meta realçada | `27d3972b` | ✓ | `show.html.twig:4269-4271`; `pasta-metas.js:18, 227, 843` |
| PMC-039 | Contador da descrição | `fd011225` | ✓ | `show.html.twig:4125` (`data-ps-nm-contador`); `pasta-dialogos.js:29-31`. Limite 5000 × 600 segue C |
| PMC-040 | Calendário próprio | L6 | ✓ | `show.html.twig:4143-4173` (`#tarefaPrazo` continua o campo enviado) |
| PMC-041 | Seletor de responsáveis (chips + lista) | L6 | ✓ | `show.html.twig:4191-4228` (o `<select>` segue como fonte; função do colaborador não existe, não entra) |
| PMC-038 + PMC-059 | Moldura + corpo da Nova meta | `d8b7a015`, `fd011225` | ◐ | Moldura, rótulos, "Criar meta", "Preencha: …", arrastar e recolher: `show.html.twig:4095-4107, 4232-4237`; `pasta-metas.js:860-960`. **Falta** a janela **não modal** que a própria nota do ledger (l.253) incluía no PMC-059: ver C6-PMC-01 |
| PMC-052 / PMC-058i | Foto do cliente | — (M5 → C) | n/a | Reclassificado C pelo ledger (l.215, depende de D-CLI-AUDIT). O ícone segue inerte: `_cabecalho.html.twig:313`, `cliente/_resumo.html.twig:26` |

**Contagem:** 26 ✓ · 1 ◐ (PMC-038/059, contado como um item) · 0 ✗.

## Regressões

Nenhuma encontrada. Os 33 commits do loop que tocam a área (`git log 607f2c29..HEAD -- <arquivos da área>`) foram
conferidos pelo efeito no código atual. Pontos que podiam regredir e não regrediram:
- A troca da aba Metas por XHR (`aplicarAbaNova`, `pasta-metas.js:640-672`) mantém o elemento `[data-ps-metas]`.
  Por isso o botão direito, o renomear e os popovers das linhas novas (`popoversDasLinhasNovas`, `:611-638`) continuam ligados.
- O item "Ver todas as informações" do ⋮ acompanha "Mostrar menos" (`pasta-processo.js:65-76`), como o desenho dc:2920.

## Pendências A (do inventário)

**Nenhuma acionável.** Todos os 24 A do inventário foram feitos, exceto:
- **PMC-052** e **PMC-058i** (foto do cliente). Viraram C junto com o M5, por gravarem PII sem auditoria (D-CLI-AUDIT).
  O PMC-058i não tem como ser feito sem o PMC-052. Fica **C (dependente)**, não A pendente.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-PMC-01 | A | P4 | M | não | Nova meta: janela **sem fundo** (o "Recolher" promete "continue vendo a lista") | dc:75 (dialog sem fundo), dc:3768-3769 (`janSt` fixo, sem overlay), dc:3772 (`recDica`) | `show.html.twig:4092` (`.modal` Bootstrap com backdrop padrão e trava de foco); recolher só esconde o corpo (`pasta-show.css:3767-3768`), o fundo escurecido continua bloqueando a lista | `data-bs-backdrop="false"` + `data-bs-focus="false"` no `#modalCriarTarefa`. CSS escopado `#modalCriarTarefa{pointer-events:none}` com `.modal-content{pointer-events:auto}`, para o `.modal` de tela cheia não engolir os cliques. Soltar o `overflow:hidden` do `body.modal-open` só enquanto ESTE modal estiver aberto (classe no `show.bs.modal`). O Esc continua fechando: o desenho tem `nm.tecla`. Toca `show.html.twig` (compartilhado) e `pasta-metas.js` | Estático: o modal declara `data-bs-backdrop="false"`. Smoke: com a janela recolhida, rolar a lista e abrir o drawer de uma meta |
| C6-PMC-02 | A | P5 | P | não | Faixa da Nova meta: "Pasta N" | dc:3889 (`pastaTxt: 'Pasta ' + cur[0]`) | `show.html.twig:4099` `Pasta {{ pasta.nup }}` sem fallback; `nup` é anulável (`Pasta.php:43`), e aí sai só "Pasta " | `{{ pasta.nup ?? ('#' ~ pasta.id) }}`, o mesmo padrão de `_processos_vinculados.html.twig:125` | Functional de tela: pasta sem NUP mostra "Pasta #<id>" no `#modalCriarTarefa` |
| C6-PMC-03 | A | P3 | P | não | Cartão Clientes: "+" (vincular), estrela (tornar principal) e lixeira (desvincular) oferecidos a quem só vê a pasta e na lápide | dc:1357, 1372-1389 (o desenho é omisso sobre permissão; a convenção do sistema é PAS-049/PMC-058L/PMC-060: a tela não oferece o que o servidor recusa) | `_dados_trilho.html.twig:145-146` (o "+" sem condição); `_cliente_linha.html.twig:58-87` (forms sem condição). O servidor exige EDIT (`PastaController.php:732`, `:797`) e o `PastaSomenteLeituraListener` recusa POST na lápide | Calcular `podeMexerNoCliente = (podeEditarPasta ?? can_access_resource('pasta', pasta.id, 'edit')) and not pasta.estaExcluida` (a resposta fica guardada por requisição). Envolver o "+", o bloco `.ps-vinc` e os dois forms da linha. Na linha, a estrela cheia do principal pode ficar (é leitura). O parcial também volta por XHR (`PastaController::htmlLinhaCliente`), então o cálculo mora no parcial. Toca `_dados_trilho.html.twig`, que é da aba Dados: **combinar com o auditor PAS** | Functional de tela, 3 casos: só VIEW (sem `.js-ps-vinc-abrir`, `.js-ajax-cliente-principal`, `.js-ajax-desvincular-cliente`), lápide (idem) e EDIT (os três presentes). Mutação: tirar a condição derruba os dois primeiros |
| C6-PMC-04 | A | P5 | P | não | Avisos de cópia no ⋮ do processo | dc:2926 "Número do processo copiado", dc:2927 "Resumo do processo copiado" | `_processos_vinculados.html.twig:234` "Número copiado", `:238` "Resumo copiado" | Trocar os dois `data-aviso` pelo texto do desenho | `PastaProcessoCartaoTelaTest`: exige os dois `data-aviso` |
| C6-PMC-05 | A | P5 | P | não | Nota técnica: "Editar · bloqueado" / "Excluir · bloqueado" | dc:5405-5406: o item é clicável e mostra o aviso "Só o autor edita/exclui a nota, em até 15 minutos" | `_nota_tecnica_item.html.twig:195-202`: `disabled` com o texto só no `title`, que não aparece no toque nem no teclado | Tirar o `disabled`, manter `aria-disabled="true"` e, no `nota-tecnica.js`, mostrar o aviso da tela com o `title` (`avisarPs`, `pasta-dialogos.js`) e fechar o menu. O complemento do desenho "(o usuário primário não tem limite)" fica de fora: é D-MASTER (PMC-021, C) | Tela: item bloqueado sem `disabled`, com `aria-disabled` e sem `data-url`. Estático de JS: o handler chama o aviso |
| C6-PMC-06 | A | P4 | P | não | "Editar nome" da meta na lista: limite do campo | dc:6268-6269 (campo sem `maxlength`) | `_metas.html.twig:276` `maxlength="160"`; a criação aceita 255 (`show.html.twig:4118`) e o servidor também (`RenomearMetaUseCase.php:24, 49`). Numa meta com título de 161 a 255, qualquer edição no campo fica acima de 160 e o navegador recusa o envio (`tooLong`) | `maxlength="{{ constant('App\\Tarefa\\UseCase\\RenomearMetaUseCase::TAMANHO_MAXIMO') }}"` | `MetaNaListaTelaTest`: o campo `.ps-meta-ren-campo` tem `maxlength="255"` |
| C6-PMC-07 | C | P5 | — | não | Limite do título da Nova meta | dc:77 `maxlength="120"` | `show.html.twig:4118` `maxlength="255"` | Mudar o limite é regra de negócio. Decidir junto com o 600 × 5000 da descrição (PMC-039) | — |

**Observações (sem ID, fora da classe A):**
1. **"Peticionar na pasta"** aparece para quem só vê a pasta e também na lápide (`_processos_vinculados.html.twig:117-121`).
   A página é GET com VIEW (`PeticionarController.php:54-63`), mas os envios dela (upload, texto, imagem: `:158`, `:262`, `:335`)
   escrevem. Esconder ou não o botão depende de quem decide o que a página é para quem só lê. É assunto da área
   Documentos/Editor; não registrei como A.
2. O texto do "Copiar resumo" do desenho (dc:2916) põe "Não informado" nas linhas vazias e não tem "Distribuição". O código
   omite as linhas vazias e acrescenta a Distribuição (`_processos_vinculados.html.twig:155-163`). O inventário já tinha
   aceitado isso como F ("só dado real"); mantenho.
3. O painel "Ver todas as informações" do desenho mostra o conjunto fixo `PJE_CAMPOS`, com "Não informado" (dc:2900-2904).
   O código mostra só os campos reais preenchidos (`_processos_vinculados.html.twig:338-435`). O conjunto do desenho
   depende de PMC-005 (C) e PMC-010 (B). Não é novo.

## Matriz atualizada

Método: parti da matriz do ciclo 0 (145/217). Cada A confirmado acima soma 1 ao "OK" da dimensão em que o inventário
o registrou. Os V/F contam nas duas dimensões. Pela natureza do item, PMC-001/002/003/004/017/027/028/029/031/040/044
ficaram em Interações e PMC-019/020/030/056 em Funções. O denominador ganhou os requisitos novos deste ciclo:
C6-PMC-02 (V), C6-PMC-03 e C6-PMC-04 (I), C6-PMC-06 e C6-PMC-07 (F). C6-PMC-01 e C6-PMC-05 são parte de requisitos
que já estavam contados (PMC-038/059 e PMC-017). PMC-038/059 segue fora do "OK" em Interações por causa do C6-PMC-01.

| Dimensão | Conferidos OK | Total de requisitos |
|---|---|---|
| Visual | 64 | 79 (Processo 17/22 · Metas 28/33 · Clientes 19/24) |
| Interações | 64 | 85 (Processo 22/33 · Metas 23/28 · Clientes 19/24) |
| Funções (persistência/backend) | 26 | 42 (Processo 9/14 · Metas 10/15 · Clientes 7/13) |
| Responsivo (<768px) | 8 | 8 |
| Dark mode | 8 | 8 |
| **Total** | **170** | **222 (76,6%)** — era 145/217 (66,8%) |

O que falta para 100% é quase todo C/B/D/E: PJe (PMC-010..013, 023), edição manual do processo (005), "Ação" e
"Última distribuição" (006, 008, 009), Chat I.A (014, 016, 018, 033, 045, 046), leitura inteligente (022, 024),
relatório geral (015), duplicar/obrigatórios/link da meta (032, 037, 042), IA no prazo (043), modal de cadastro do
cliente, contatos, histórico e PJ (047-050, 054), linha do cliente (051), foto (052, 058i), sino para não responsáveis
(034) e os E (035, 053). Dentro da área, só C6-PMC-01..06 são A.

## Resumo

- **Feitos do ledger:** 26 confirmados · 1 parcial (PMC-038/059: falta a janela não modal, C6-PMC-01) · 0 regredidos.
- **Regressões:** 0.
- **Pendências A do inventário:** 0. PMC-052 e PMC-058i são C/dependentes (D-CLI-AUDIT).
- **Novas:** 7, sendo 6 A (C6-PMC-01..06) e 1 C (C6-PMC-07). Nenhuma exige migration e nenhuma é P0.
- **Arquivos compartilhados:** C6-PMC-01 e 02 em `show.html.twig`. C6-PMC-03 em `_dados_trilho.html.twig` (aba Dados):
  combinar com quem tem a área PAS.
- **Matriz:** 170/222 (76,6%), era 145/217.
