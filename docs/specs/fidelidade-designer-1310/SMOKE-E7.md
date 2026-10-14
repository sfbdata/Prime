# Smoke manual da E7 — janela de confirmação da Pasta

> **Para quem:** o dono, no smoke da frente `fidelidade-designer` (commits `a5362fad` código, `8c019bca` docs).
> **Onde:** dev (`saas_ux`), pelo servidor da worktree (`http://172.20.0.3:8000`, comandos no HANDOFF §12) ou, depois de
> integrado, `localhost:8080`. Login dev: escritório "Farlei Assis da Rocha Sociedade Individual".
> **Tempo estimado:** 20–25 min. Marque ✓/✗ em cada linha; um ✗ em "Não pode acontecer" bloqueia a integração.

## O que vale para TODA pergunta (conferir em pelo menos 3 delas)

| # | Faça | Esperado |
|---|---|---|
| G1 | Abra a pergunta | Janela no centro, cabeçalho azul-escuro com ⚠ e X; o **foco** está no **Cancelar** (contorno visível) |
| G2 | Aperte **Enter** logo ao abrir | Fecha **sem fazer nada** (Enter no Cancelar cancela) |
| G3 | Abra de novo e aperte **Esc** | Fecha sem fazer nada |
| G4 | Abra de novo e clique **fora** da janela | Fecha sem fazer nada |
| G5 | Abra de novo e clique no **X** | Fecha sem fazer nada |
| G6 | Depois de cada fechamento acima | O foco volta ao botão que abriu (ou ao ⋮ do menu, se a pergunta veio de um item de menu) — dá para continuar no teclado |
| G7 | Abra de novo e aperte **Tab** várias vezes | O foco gira só entre Confirmar → X → Cancelar; não sai da janela |
| G8 | Abra de novo e confirme | A ação acontece **uma vez** |

**Não pode acontecer em nenhuma:** a ação acontecer antes do "Confirmar"; acontecer duas vezes; aparecer a caixa
cinza do navegador ("localhost diz…") em vez da janela; a janela ficar presa atrás de outro modal.

## 1. Editar dados (pasta 304) — o bloco que estava morto

Abra a pasta 304 → ⋮ do cabeçalho → **Editar dados**.

| # | Faça | Esperado |
|---|---|---|
| 1.1 | Só abra o modal | **Salvar desabilitado**; pílula "Dados da pasta" (sem alteração) |
| 1.2 | Mude o nome do cliente (um caractere) | Pílula **"Alterações não salvas"**; Salvar **habilita** |
| 1.3 | Desfaça a mudança | Salvar volta a **desabilitar** |
| 1.4 | Apague o número da pasta | Aparece a **pendência** do número; Salvar desabilitado |
| 1.5 | Atalho **Cliente** (com alteração não salva) | Pergunta **vermelha** "Descartar as alterações?"; Esc → continua no modal, com o que você digitou |
| 1.6 | Atalho **Cliente** → Descartar | O modal fecha **e só depois** abre o Cadastro do cliente (sem fundo escuro sobrando) |
| 1.7 | Atalho **Processo** (sem alteração) | Fecha o modal e abre a aba Processo da pasta, sem perguntar |
| 1.8 | Atalho **Histórico** | Fecha o modal e abre o histórico da pasta |
| 1.9 | **Trocar** (responsável) | Fecha o modal e abre o menu de troca de responsável |
| 1.10 | Mude o **número** (ex.: 1160 → 1160A) e Salvar | Pergunta **azul** "Alterar número da pasta" com os dois números; **Cancelar** → nada gravado, modal aberto |
| 1.11 | Salvar de novo → **Confirmar** | Grava; a pasta passa a mostrar o número novo. **Volte o número para 1160** depois |

## 2. Perguntas que foram medidas, para confirmar no seu navegador

| # | Onde | Faça | Esperado |
|---|---|---|---|
| 2.1 | Pasta 304, ⋮ do cabeçalho | **Duplicar pasta** → Cancelar; depois Confirmar | Azul, título "Confirmar", texto "Duplicar a pasta 1160? …". Confirmar cria a cópia (**exclua a cópia depois**) |
| 2.2 | Pasta 588, aba Processo, ⋮ do processo | **Desvincular** → Cancelar | Vermelha: "Desvincular o processo … da Pasta 526?" / "Os dados do processo continuam salvos…". **Não confirme** (ou revincule depois) |
| 2.3 | Pasta 588, aba Processo | Ligar **"Administrativo sem processo"** (há processo vinculado) → Cancelar | Azul, título "Marcar como administrativo"; o interruptor **não** muda |
| 2.4 | Uma pasta excluída (lápide) | Botão **Restaurar** no cabeçalho dela → Cancelar | Azul, "Restaurar a pasta N?", rótulo "Restaurar" |

## 3. Perguntas que NÃO puderam ser exercitadas na tela (o dev não tinha o dado)

Crie o dado, exclua pela pergunta. Para cada uma: G1–G8 + o esperado da linha.

| # | Onde | Como preparar | Pergunta esperada |
|---|---|---|---|
| 3.1 | Aba Metas, ⋮ de uma meta | Crie uma meta nova (você é o criador; a exclusão só vale logo depois de criar) | **Vermelha**, "Confirmar exclusão" / "Excluir esta meta? Esta ação não pode ser desfeita." |
| 3.2 | Aba Documentos → Checklist (pastas **1170** ou **1171**) | Entre no modo de edição (✎) | Excluir item → **vermelha** "Excluir este item do checklist?" |
| 3.3 | Mesmo checklist → Modelos | Salve o checklist como modelo com o **nome de um modelo que já existe** | **Vermelha**, título "Substituir modelo" / "Já existe o modelo "X". Substituir…?" |
| 3.4 | Mesmo checklist → Modelos | Excluir um modelo de teste (crie um antes) | **Vermelha**, "Excluir o modelo "X"? Os checklists já aplicados nas pastas não mudam." |
| 3.5 | Aba Financeiro → comentários (pasta **433**) | Exclua um comentário seu (ou crie um) | **Vermelha "Excluir"** com 🗑, título "Excluir o comentário de …?" e o trecho do comentário |
| 3.6 | Aba Financeiro → pagamento | Lance um pagamento de teste e **corrija o valor** | **Azul**, "Corrigir "X" de R$ a para R$ b?" / "A alteração fica registrada no histórico…" |
| 3.7 | Aba Financeiro → pagamento | Exclua o pagamento de teste | **Vermelha**, título "Excluir lançamento" / "Excluir "X" (R$ v)?" |
| 3.8 | Aba Financeiro → arquivos | Envie um arquivo e exclua pelo ⋮ dele | **Vermelha**, "Excluir o arquivo "nome" do Financeiro?" |
| 3.9 | Aba Documentos (explorador) | Envie um arquivo de teste e exclua | **Vermelha**, "Excluir "nome"? Os itens vão para a lixeira…"; depois aparece **Desfazer** |
| 3.10 | Aba Processo → nota técnica | Escreva uma nota técnica e exclua pelo ⋮ | **Vermelha**, "Excluir esta nota técnica?" |
| 3.11 | Análise da IA (Push e drawer de agentes) | Só onde houver análise (o dev não tem; depende do provedor de IA) | **Vermelha**, "Excluir esta análise da IA? …"; ao cancelar, o foco volta ao ⋮ da análise |

## 4. Aparência (uma vez)

- Tema escuro: abra qualquer pergunta azul — botão azul legível sobre a caixa escura.
- Celular (ou janela estreita, ~390px): a janela cabe com margem dos dois lados, sem rolagem lateral.

---

## Anexo — testes comportamentais que poderiam substituir as verificações só de texto

Hoje a E7 é coberta por testes de **folha** (leem o código-fonte) e pela medição na tela. Teste de folha não pega o
que pegou o "Editar dados morto". Candidatos, **sem ampliar o escopo agora** (decisão do dono):

| Hoje (folha) | Comportamento a provar | Onde caberia |
|---|---|---|
| `PastaConfirmacaoDoDesenhoJsTest::testEnvioSeguroAteOSim` | Clicar em Duplicar com a rede interceptada → 0 POST; Esc/Enter no Cancelar/fundo/X → 0 POST; Confirmar → exatamente 1 POST | `e2e/tests/` (Playwright, já usado pelo projeto: `expediente-tabela-ordenacao.spec.js`) com `page.route` abortando o POST |
| `testEditarDadosNaoMorreAntesDoBootstrap` | Ao abrir o Editar dados sem mudança, Salvar **desabilitado**; mudar número → janela azul; 0 POST até o "sim" | e2e, mesma técnica |
| `testFocoVoltaAoGatilhoDoMenu` | Depois de Esc, `document.activeElement` é o ⋮ (`data-ps-pop`, `aria-controls` e menu sem id) | e2e |
| `testCadaPerguntaNaJanelaComAVarianteDoDesenho` (18 casos) | Para cada pergunta: janela aberta, classe `is-azul` certa, título/rótulo do dc — sem `dialog` nativo disparado | e2e parametrizado por pasta de fixture |
| `PastaDialogosJsTest` (Tab preso, Enter no Cancelar) | Sequência de teclas e o foco resultante | e2e, ou unitário de JS com jsdom (o projeto **não** tem runner de JS hoje — seria dependência nova) |
| `testSemConfirmNativoDireto` | Em cada fluxo, `page.on('dialog')` **nunca** dispara | e2e |

Pré-requisitos que custam e por isso ficam para decisão: fixture de dados de teste para o e2e (pasta com processo,
checklist, Financeiro, nota técnica); o e2e roda contra `localhost:8080` (checkout principal) — rodar contra a worktree
pede `baseURL` configurável; e o smoke em navegador é do dono por regra do projeto (o e2e automatizado precisaria dessa
autorização explícita para entrar no fluxo da frente).
