# 03. Matriz Designer × BlueJus

> Auditoria integral da `pasta_show` contra o protótipo ONLINE `02 - EXPEDIENTES 1.2.3.dc.html` (baixado em 16/10/2026), worktree `fidelidade-designer` @ `9c081725`. Somente leitura. Índice e resumo em `00-LEIAME.md`. "dc:N" = linha do protótipo online sem os blocos injetados pelo serviço de pré-visualização.

Colunas: `ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência`.
Status: Fiel, Parcial, Divergente, Ausente, Não aplicável (com justificativa), Não verificado. Prioridade: P0 segurança e integridade, P1 funcionalidade essencial, P2 visual e interação secundária; "n.a." para Fiel e Não aplicável (sem travessão, regra do dono).
Evidência `[código]` = conferido no código pelo auditor; `[inventário]` = herdado do inventário. Os IDs reaproveitam o checklist da Pasta (PZ-*); sufixo N = sem linha própria no checklist (não quer dizer novidade); `[NOVO-16/10]` = novidade do Designer online.
**Ressalva de método:** "Fiel" em aspecto VISUAL quer dizer igual por leitura estática (valores declarados); a medição na tela não foi feita nesta auditoria (ver `00-LEIAME.md`, Limitações).

# Parte A. Cabeçalho, cadastro, Dados e trilho, Metas, Processo, Financeiro



> Auditoria READ-ONLY. Worktree `fidelidade-designer` (HEAD `9c081725`). Desenho: `02 - EXPEDIENTES 1.2.3.dc.html`
> (versão online 16/10). Entradas: `inv-designer-A.md`, `inv-designer-B.md`, `inv-bluejus-tela.md`,
> `inv-bluejus-backend.md`, checklist de 13/10 (`docs/specs/fidelidade-designer-1310-checklist-pasta.md`).
>
> Legenda:
> - Status: **Fiel** (gatilho e comportamento equivalentes), **Parcial**, **Divergente**, **Ausente**,
>   **Não aplicável** (com justificativa), **Não verificado** (diz o que falta).
> - Prioridade: P0 segurança/integridade; P1 funcionalidade essencial ausente ou incompleta; P2 fidelidade
>   visual ou interação secundária; `-` para Fiel e Não aplicável (a matriz não usa travessão).
> - Evidência: `[código]` = conferido por mim no código da worktree; `[inventário]` = herdado dos inventários.
>   `dc:N` = linha do protótipo; caminhos BlueJus relativos a `app/` (`cab` = `templates/pasta/_cabecalho.html.twig`,
>   `show` = `templates/pasta/show.html.twig`).
> - Autorização: `R:view/edit/delete` = `canAccessResource('pasta', id, ação)`; `M:x` = módulo; `TF` = TenantFilter;
>   `tenant expl.` = comparação explícita do tenant; CSRF = id do token.
> - Decisões P1, P2, P3 (faixa no cabeçalho, "Como usar esta aba", Andamento) são **provisórias do dono (15/10)**
>   segundo `HANDOFF.md:81-82`; o checklist §20 e o HANDOFF §6 ainda as marcam ABERTAS. Itens que dependem delas
>   estão como Não aplicável com a dependência "decisão definitiva".
> - Padrão recorrente "erro em `alert()` nativo": o caminho feliz é fiel, mas o erro sai pelo navegador, contra
>   PZ-MOD-26. Esses itens ficam Parcial P2 com a lacuna "erro por alert()".

---

### A1. Cabeçalho

#### A1.1 Linha 1 e menu ⋮

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-CAB-N01 | Contêiner | Cartão do cabeçalho | estrutural | Cartão branco com `data-cad-area` (área do guarda do cadeado) | `header.ps-card.ps-cabecalho`; sem `data-cad-area` | Fiel | O atributo só serve ao cadeado (ver PZ-CAB-21) | nenhuma | - | [código] cab:43 |
| PZ-CAB-01 | Linha 1 | Voltar | clique | Sem handler (só visual), title "Voltar à lista de pastas" | Link GET `expediente_index`, mesmo title, rótulo "Voltar" | Fiel | Desenho omisso no destino: convenção do sistema | nenhuma | - | [código] cab:48-50 |
| PZ-CAB-02 | Linha 1 | "PASTA nnnn" | nenhum | Número da pasta | `pasta.nup` | Fiel | | nenhuma | - | [código] cab:54 |
| PZ-CAB-03 | Linha 1 | Prioridade (pílula + menu Normal/Prioridade/Urgente) | clique | Só estado React, não persiste, igual para todas as pastas | POST `pasta_alterar_prioridade` persiste; ✓ e classe trocados | Parcial | Erro por alert() | nenhuma | P2 | [código] cab:60-90; show:3942-3977. Rota R:edit, TF, CSRF `pasta_prioridade_{id}`, enum 422, Pasta Auditavel [inventário] |
| PZ-CAB-04 | Linha 1 | Etiquetas de marcador | clique | Abre modal Marcadores | Clique simula o "+" e abre o modal | Fiel | Etiquetas não se atualizam após salvar (ver PZ-MOD-11) | nenhuma | - | [código] cab:94-100; pasta-show.js:485-491 |
| PZ-CAB-05 | Linha 1 | "+" Marcadores | clique | Abre modal, foca busca | Abre `#modalMoverPara`; **renderizado sem checar `podeEditarPasta`** e o endpoint não exige R:edit | Parcial | Quem só VÊ a pasta (com M:expediente) altera marcadores; token exposto | backend (R:edit no endpoint) | P0 | [código] cab:104-111; src/Expediente/Controller/ExpedienteController.php:247-260, 355-363; src/Expediente/UseCase/SincronizarMarcadoresDaPastaUseCase.php:29 (`find` puro). Ver Achado A-02 |
| PZ-CAB-06 | Linha 1 | Chip do Responsável | clique | Na linha 1, avatar 28px, nome até 170px | Na faixa de dados (linha 2), via `_resp_chip` | Não aplicável | Posição decorre da decisão provisória P1 (faixa preservada) | decisão definitiva P1; `_resp_chip`/`_resp_estilo` (globais) | - | [código] cab:486-494 |
| PZ-CAB-07 | Linha 1 | Menu do responsável (busca, Sem responsável, ✓) | clique, digitação, ↑↓ Enter Esc | Grava só em estado; cartão de sistema no Registro | PATCH `pasta_atualizar_responsavel`, otimista com desfazer em erro; vai ao audit/Histórico | Parcial | Erro por alert(); sem cartão no Registro (ver PZ-DAD-14) | nenhuma | P2 | [inventário] `_resp_script:156-262`. Rota R:edit, TF, alvo com vínculo ativo, CSRF `pasta_responsavel_{id}` |
| PZ-CAB-08 | Linha 1 | Setas ‹ › e "N de M" | clique | Troca pasta, volta à aba Dados, desabilita nas pontas, title com cliente | Links GET das vizinhas; ponta vira span inerte; title com rótulo da vizinha | Fiel | | ordem do acervo (já existe) | - | [código] cab:122-153 |
| PZ-CAB-09 | ⋮ | Botão "Mais ações" | clique | Abre menu; com cadeado só avisa | Abre popover `#psMenuAcoes` | Fiel | Parte do cadeado: ver PZ-CAB-21 | nenhuma | - | [código] cab:178-183 |
| PZ-CAB-N02 | ⋮ | Atalhos E, H, Esc (T só no rótulo) | tecla com menu aberto | E edita, H histórico, Esc fecha; T sem atalho apesar do rótulo | E, H e T funcionam; ignora campo de texto e Ctrl/Alt/Meta | Fiel | BJ implementa o T que o rótulo promete (o desenho é que falha) | nenhuma | - | [código] pasta-show.js:470-482; pasta-timeline.js:116-127 |
| PZ-CAB-10 | ⋮ | Tarja "Pasta nnnn" | nenhum | Cabeçalho do menu em gradiente | Tarja igual (também alça de arraste) | Fiel | | nenhuma | - | [código] cab:184 |
| PZ-CAB-11 | ⋮ | Editar dados (E) | clique / E | Abre "Editar dados da pasta" | Abre `#modalEditarPasta`; só com R:edit | Fiel | | nenhuma | - | [código] cab:188-194 |
| PZ-CAB-12 | ⋮ | Histórico (H) | clique / H | Abre drawer | Alterna `#psHistorico` | Fiel | | nenhuma | - | [código] cab:197-201 |
| PZ-CAB-13 | ⋮ | Timeline inteligente (T) | clique | Abre painel da central, não fecha o ⋮ | Abre `#psTimeline` real (regras) | Fiel | | nenhuma | - | [código] cab:204-208 |
| PZ-CAB-14 | ⋮ | Duplicar pasta | clique | Janela azul com o texto; cópia "aguardando número" | Form POST com `data-ps-cf` azul, mesmo texto; cria cópia com número e abre | Fiel | Autorização só R:view + M:pastas (observação O2) | nenhuma | - | [código] cab:213-225. Rota `pasta_duplicar`: tenant expl., R:view, M:pastas, CSRF `pasta_duplicar_{id}` [inventário] |
| PZ-CAB-15 | ⋮ | Vincular processo | clique | Vai à aba Processo e abre o modal | Abre o modal direto (sem trocar de aba) | Parcial | Não troca para a aba Processo antes | nenhuma | P2 | [código] cab:227-230 |
| PZ-CAB-16 | ⋮ | Trocar responsável | clique | Abre o menu do responsável e foca a busca | Clica no chip do responsável | Fiel | | nenhuma | - | [código] cab:231-233; pasta-show.js:424-431 |
| PZ-CAB-17 | ⋮ | Mover para outra carteira | clique | Menu com 6 carteiras fixas; grava preferência | Não existe | Ausente | Conceito "carteira da pasta" não existe no domínio | backend (entidade/campo); decisão do dono | P2 | [inventário] |
| PZ-CAB-N03 | ⋮ | Rótulo "Compartilhar" | nenhum | Rótulo de seção | Igual | Fiel | | nenhuma | - | [código] cab:236-237 |
| PZ-CAB-18 | ⋮ | Copiar link da pasta | clique | Copia URL, "Link copiado" + ✓, fecha em 900 ms | Igual, URL `pasta_show`; falha "Não foi possível copiar" | Fiel | | nenhuma | - | [código] pasta-show.js:433-468 |
| PZ-CAB-19 | ⋮ | Link externo do Push + contagem | clique | Abre modal de link público | Não existe | Ausente | Função inteira (ver A1.8) | backend (link público com token, código, validade, revogação, acessos); decisão | P1 | [inventário] |
| PZ-CAB-20 | ⋮ | Imprimir resumo | clique | Monta HTML e abre prévia | Link interceptado abre `#psImpressao`; Ctrl+clique abre a folha | Fiel | | nenhuma | - | [código] cab:247-250 |
| PZ-CAB-21 | ⋮ | Seção "Acesso · Primário" + Cadeado | clique | Só o primário; abre modal Cadeado | Não existe | Ausente | Função de acesso por pasta | backend (pode reaproveitar `ResourceAccess`, `docs/AUTORIZACAO.md`); decisão sobre "primário" | P1 | [inventário] |
| PZ-CAB-22 | ⋮ | Acompanhar alterações + interruptor | clique | Preferência por usuário; avisos | Não existe | Ausente | | backend (preferência + notificações) | P2 | [inventário] |
| PZ-CAB-23 | ⋮ | Fixar nos favoritos + interruptor | clique | Preferência local, avisos | POST `pasta_favorito_alternar`; interruptor espelha a resposta | Fiel | | nenhuma | - | [código] cab:257-264. Rota tenant expl. + R:view, CSRF `pasta_favorito_{id}` [inventário] |
| PZ-CAB-24 | ⋮ | Arquivar / Desarquivar | clique | Muda situação, sem confirmação | Mesmo POST da pílula, sem confirmação | Parcial | Erro por alert() | nenhuma | P2 | [código] cab:269-276. Rota `pasta_alternar_situacao` R:edit, TF, CSRF `pasta_alternar_situacao_{id}` [inventário] |
| PZ-CAB-25 | ⋮ | Excluir pasta | clique | `prompt()` nativo pede o número; lixeira 30 dias | `prompt()` nativo pede o número; servidor confere `confirmar_nup`; texto não promete lixeira | Parcial | Copia o defeito do desenho (prompt nativo); trocar por janela própria vermelha com campo | nenhuma | P2 | [código] cab:285-295. Rota `pasta_delete` R:delete, TF, CSRF `delete_pasta_{id}` [inventário] |
| PZ-CAB-S01 | ⋮ | Rótulo "Preferências" | nenhum | Desenho não tem esse rótulo | Rótulo de seção antes de Favoritos | Divergente | Rótulo extra onde o desenho mostra sem | nenhuma | P2 | [código] cab:252-253 |

#### A1.2 Linha 2 (cliente, selos)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-CAB-26 | Linha 2 | Foto do cliente (botão 38px) | clique | Menu Carregar/Ajustar/Remover | Ícone `bi-person-fill` estático | Ausente | | backend (upload e armazenamento de imagem do cliente) | P2 | [código] cab:323 |
| PZ-CAB-N04 | Linha 2 | Menu da foto: Carregar | clique | Input file, abre editor | Não existe | Ausente | | idem | P2 | [inventário] |
| PZ-CAB-N05 | Linha 2 | Menu da foto: Ajustar | clique | Reabre editor | Não existe | Ausente | | idem | P2 | [inventário] |
| PZ-CAB-N06 | Linha 2 | Menu da foto: Remover | clique | Remove sem confirmação | Não existe | Ausente | Se implementar, confirmar (remoção sem confirmação é defeito) | idem | P2 | [inventário] |
| PZ-CAB-N07 | Linha 2 | Modal "Ajustar foto" | arrastar, roda, zoom, girar | Recorta 320×320 | Não existe | Ausente | | idem | P2 | [inventário] |
| PZ-CAB-27 | Linha 2 | Nome do cliente (h1) | botão direito | Abre "Detalhes do cliente" no cursor | Abre a janela `cliente_resumo` no cursor (salvo texto selecionado) | Fiel | | nenhuma | - | [código] cab:329-338 |
| PZ-CAB-28 | Linha 2 | Dica "Detalhes: clique com o botão direito" | hover 350 ms | Aparece após 350 ms | CSS puro, 350 ms | Fiel | | nenhuma | - | [código] cab:357-359 |
| PZ-CAB-29 | Linha 2 | "Movimentado hoje, HH:MM" | nenhum | Texto fixo | Calculado de `pasta.modificadoEm` | Fiel | | nenhuma | - | [código] cab:341-351 |
| PZ-CAB-30 | Linha 2 | Documento "CPF …" | nenhum | CPF do cadastro (sem alias) | `documento_br_rotulo` + valor; aviso "não vinculado…" | Fiel | | nenhuma | - | [código] cab:352-356 |
| PZ-CAB-31 | Linha 2 | Botão BLUEJUS Intelligence | clique | Abre painel da IA | Abre `#psIaDrawer`; title com indisponibilidade | Fiel | Foco decidido em P4 | nenhuma | - | [código] cab:368-385 |
| PZ-CAB-32 | Linha 2 | Sobreposição "Pasta trancada" + Pedir acesso | clique | Overlay, motivo, pedido 24 h | Sem overlay; sem R:view o show redireciona a `pasta_index?requestAccess=1` (modal de pedido de acesso) | Parcial | Fluxo de pedido existe por outro caminho; falta overlay na própria pasta | cadeado (PZ-CAB-21) | P1 | [inventário] backend §1 (PastaController.php:315; ResourceAccessTrait) |
| PZ-CAB-33 | Linha 2 | Faixa "só visualizar" + Pedir para editar + guarda global | clique, captura | Bloqueia edição no navegador | Sem faixa; ações de escrita somem por `podeEditarPasta` e o servidor recusa | Parcial | Falta faixa e pedido de edição; guarda de captura do desenho não deve ser copiada (a autorização é do servidor) | cadeado; AccessRequest | P2 | [inventário] |
| PZ-CAB-34 | Linha 2 | Selo ARQUIVADO | situação | Carimbo 150×104 zoom .8, -14° | Carimbo 120×83 (= 150×104 × .8), -14°, entra e sai sem recarregar | Fiel | | nenhuma | - | [código] cab:392-415 |
| PZ-CAB-35 | Linha 2 | Selo ATIVO 3,3 s | montagem, reativar, trocar de pasta | Também ao montar e ao trocar de pasta ativa | Só ao desarquivar | Parcial | Não carimba na carga nem na navegação entre pastas ativas | nenhuma | P2 | [código] cab:404-412 |
| PZ-CAB-36 | Linha 2 | Selos SUSPENSO e CANCELADO | situação | Carimbos próprios | Não existem (situação só ativo/arquivado) | Ausente | Estados não existem no domínio | backend (situação, migration, toggle binário quebra); decisão do dono | P1 | [inventário] backend §3.3 |
| PZ-CAB-37 | Linha 2 | Selo do cadeado | clique | Abre modal Cadeado | Não existe | Ausente | | cadeado | P1 | [inventário] |
| PZ-CAB-41 | Faixa | Faixa Processo · Ação · Responsável | nenhum | Ausente no desenho online | Presente | Não aplicável | Decisão provisória P1 (preservar) | decisão definitiva P1 | - | [código] cab:413-513 |
| PZ-CAB-42 | Faixa | Link "trocar" | clique | Ausente no desenho | Vai à aba Processo | Não aplicável | Parte da faixa P1 | decisão definitiva P1 | - | [código] cab:432-434 |

#### A1.3 Andamento da pasta

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-CAB-38 | Andamento | Trilha de 10 fases (ou via administrativa) | exibição | Datas, contadores, fase pulada, rolagem | Não existe | Não aplicável | Decisão provisória P3 (não implementar) | decisão definitiva P3; backend (histórico por fase) | - | [inventário] |
| PZ-CAB-39 | Andamento | Clique na fase | clique | Confirmação azul, cartão no Registro, sincroniza Contrato | Não existe | Não aplicável | P3 | idem | - | [inventário] |
| PZ-CAB-40 | Andamento | Faixa "Avançar para X?" | clique | Sugestão por Push/documentos | Não existe | Não aplicável | P3 | idem | - | [inventário] |
| PZ-CAB-N08 | Registro | Pergunta "Atualizar andamento?" após registro | evento | Confirmação azul | Não existe | Não aplicável | P3 | idem | - | [inventário] |
| PZ-CAB-N09 | Andamento | Mudança automática de fase | eventos | Vincular, arquivar, contrato | Não existe | Não aplicável | P3 | idem | - | [inventário] |

#### A1.4 Linha 3 (abas, Cadastro, Situação)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-CAB-43 | Linha 3 | Abas com indicador deslizante e modo compacto | clique | 7 abas, indicador com mola, compacto quando transborda | 7 abas (Bootstrap), indicador em `shown.bs.tab`/resize, compacto; `#aba` na URL | Fiel | | nenhuma | - | [código] cab:529-563 |
| PZ-CAB-44 | Linha 3 | Selos numéricos | nenhum | Documentos "224" fixo; Push por regex fixa | Contagens reais; some com 0 | Fiel | | nenhuma | - | [código] cab:529-537 |
| PZ-CAB-45 | Linha 3 | Linha vermelha de pendência | automático | Animação 7 s; forte 3,5 s por 30 s após criar meta; indicador 3px vermelho na aba ativa | Animação 7 s; indicador 3px `--ps-pend` na aba ativa com pendência | Parcial | Falta o pulso forte de 30 s após criar meta | nenhuma | P2 | [código] pasta-show.css:1165, 1219-1235 |
| PZ-CAB-46 | Linha 3 | Botão direito na aba: ocultar para equipe/pessoa | botão direito | Só primário; grava visibilidade e log | Não existe | Ausente | | backend (visibilidade de abas por pessoa); decisão "primário" | P2 | [inventário] |
| PZ-CAB-47 | Linha 3 | Botão "Cadastro" com ponto verde/âmbar | clique | Abre o modal "Cadastro e qualificação" | Abre a janela "Detalhes do cliente"; ponto e dicas com o mesmo texto | Divergente | Destino diferente; só aparece com cliente principal | PZ-MOD-17 | P1 | [código] cab:576-590 |
| PZ-CAB-48 | Linha 3 | Pílula e menu de Situação | clique | 4 estados, sem confirmação, cartão no Registro | 2 estados (Ativo, Arquivado), POST, sem confirmação, aciona selo | Parcial | Erro por alert(); estados faltantes em PZ-CAB-36 | nenhuma (estados: PZ-CAB-36) | P2 | [código] cab:599-641. Rota R:edit, TF, CSRF `pasta_alternar_situacao_{id}` [inventário] |
| PZ-CAB-N10 | Linha 3 | Efeitos colaterais da situação | automático | Cancelada/arquivada bloqueia link do Push; excluir força cancelado | Só troca o estado | Ausente | Dependem de itens inexistentes | PZ-CAB-19, PZ-CAB-36 | P2 | [inventário] |

#### A1.5 Busca global e componentes compartilhados

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MOD-10 | Global | Busca rápida Ctrl+K | Ctrl/Cmd+K, ↑↓ Enter Esc | Pasta, cliente, CPF, processo (até 8) | Não existe em lugar nenhum do sistema | Ausente | | `base.html.twig` (global, decisão do dono); endpoint de busca com tenant | P2 | [código] grep sem ocorrência em public/js e templates |
| PZ-MOD-09 | Global | Janela de confirmação própria | clique, Esc, Enter | Cabeçalho #0b6a96, Cancelar, botão com ícone | `#psConfirmar` com Esc, Enter, Tab, foco inicial no Cancelar | Fiel | | nenhuma | - | [inventário] (E7, checklist §20) |
| PZ-MOD-25 | Global | Variantes azul e vermelha | programático | Azul sem perigo, vermelha no perigo | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-MOD-26 | Global | "Nenhum confirm/prompt nativo" | verificação | O próprio desenho viola (confirm em Remover réu, prompt em Excluir) | prompt no Excluir; alert() em ~12 erros; confirm só como recuo | Parcial | Trocar prompt e alert por janela/aviso da tela | nenhuma | P2 | [inventário] tela "Confirmações e alertas nativos" |
| PZ-CAB-N11 | Global | Menu de contexto compartilhado `gm` | clique/botão direito | Um componente | Um menu por área, mesmos gatilhos | Não aplicável | Componente interno do protótipo; a equivalência é medida item a item | nenhuma | - | [inventário] |
| PZ-GLB-12 | Global | Toast padrão | programático | #12242f, ✓ #5fd39a, 2,6 s | `#psAviso` com os mesmos valores | Fiel | | nenhuma | - | [inventário] |

#### A1.6 Modal "Editar dados da pasta"

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MOD-15 | Editar dados | Modal: título, sub, pílula de estado, fundo/Esc fecham, Enter salva, foco no número com seleção | abertura, teclado | Foco automático no número com seleção | Cabeçalho, sub, pílula; Esc e Enter nativos; **sem foco automático no número** | Parcial | Falta foco + seleção do número ao abrir | nenhuma | P2 | [código] show:3586-3608 (sem `focus`/`select()` no script do modal) |
| PZ-MOD-N01 | Editar dados | Abas internas Dados/Cliente/Processo/Histórico | clique | Atalhos para Cadastro, aba Processo e drawer | Atalhos iguais; "Cliente" abre Detalhes (não Cadastro); pede descarte se houver alteração | Parcial | Destino Cliente depende do modal de Cadastro | PZ-MOD-17 | P2 | [código] show:3609-3614 |
| PZ-MOD-N02 | Editar dados | Número da pasta * | digitação | Só dígitos até 8; erro de vazio e de número já existente | Obrigatório; erro de vazio; **número repetido aceito** (regra "NUP pode repetir (sync Drive)") | Divergente | Unicidade do desenho contraria regra de domínio vigente | decisão do dono; backend (EditarPastaUseCase) | P1 | [código] show:3619-3625; src/Pasta/UseCase/EditarPastaUseCase.php:25. Rota `pasta_edit` R:edit, TF, CSRF `edit_pasta_{id}` |
| PZ-MOD-N03 | Editar dados | Nome do cliente * | digitação | Obrigatório, mínimo 3 | Opcional (é o identificador da pasta); só leitura quando derivado da cobrança | Divergente | Obrigatoriedade divergente | decisão do dono | P2 | [código] show:3633-3663 |
| PZ-MOD-16 | Editar dados | Tipo CPF/CNPJ + documento * | clique, digitação | Valida dígito do CPF | Não existe (documento é do Cliente, editado em `cliente_edit`) | Divergente | Modelo diferente: no BJ o documento não é da pasta | decisão do dono | P2 | [código] show:3586-3720 |
| PZ-MOD-N04 | Editar dados | Responsável só leitura + Trocar | clique | Fecha e abre o menu do responsável | Igual (nome relido do chip) | Fiel | | nenhuma | - | [código] show:3674-3682 |
| PZ-MOD-N05 | Editar dados | Ação só leitura "vem do menu Processo" + Abrir | clique | Leva à aba Processo | Campo editável `nome_acao` | Divergente | Desenho manda só leitura; código cita D-META | decisão do dono (D-META) | P2 | [código] show:3684-3689 |
| PZ-MOD-N06 | Editar dados | Faixa "Pendências" | automático | Só com alteração e erro | Igual | Fiel | | nenhuma | - | [código] show:3703-3706 |
| PZ-MOD-N07 | Editar dados | Salvar/Fechar/Cadastro do cliente; troca de número confirma | clique | Grava, aviso, confirmação azul ao trocar número | Form POST com recarga; mesma confirmação azul | Fiel | | nenhuma | - | [código] show:3709-3715; [inventário] show:3776-3799 |

#### A1.7 Modal Cadeado

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MOD-12 | Cadeado | Modal "Cadeado da pasta N" | clique | Só primário | Não existe | Ausente | | backend (`ResourceAccess`/tabela de acesso aplicada no servidor); decisão "primário" | P1 | [inventário] |
| PZ-MOD-N08 | Cadeado | Modos por pessoa / ocultar para todos | clique | | Não existe | Ausente | | idem | P1 | [inventário] |
| PZ-MOD-N09 | Cadeado | Aplicar a todos e por colaborador | clique | Resumo de níveis | Não existe | Ausente | | idem | P1 | [inventário] |
| PZ-MOD-N10 | Cadeado | Trancar / Remover / Cancelar | clique | Remover sem confirmação | Não existe | Ausente | Ao implementar, Remover deve confirmar | idem + auditoria | P1 | [inventário] |

#### A1.8 Link externo do Push

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MOD-14 | Link Push | Modal gerar link (código, nome, análises, 30 dias) | clique | Token local | Não existe | Ausente | | backend (serviço público, token, código, domínio curto) | P1 | [inventário] |
| PZ-MOD-N11 | Link Push | Estado após criar (copiar, ver como terceiro, criar outro) | clique | | Não existe | Ausente | | idem | P2 | [inventário] |
| PZ-MOD-N12 | Link Push | Lista de links, renovar, revogar | clique | Revogar com confirmação azul | Não existe | Ausente | Revogação é controle de segurança | idem | P1 | [inventário] |
| PZ-MOD-13 | Link Push | Painel de acessos | clique | Só leitura | Não existe | Ausente | | idem (contagem de acessos) | P2 | [inventário] |

#### A1.9 Histórico, Detalhes do cliente, Impressão, Marcadores

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MOD-20 | Histórico | Drawer "Histórico do sistema" | ⋮, H, aba do Editar | Conteúdo fixo (6 eventos, "10") | Dados reais do audit_log agrupados por dia; overlay, ×, Esc | Parcial | Vincular/desvincular processo e cadastro de cliente não deixam linha (PastaProcesso e Cliente não são Auditavel) | backend (tornar Auditavel ou evento próprio) | P1 | [inventário] backend §2 (fim) e §0 Audit |
| PZ-MOD-18 | Detalhes do cliente | Janela arrastável, copiar nome/documento, números, pastas do mesmo CPF, cliente desde | botão direito, clique, Esc, fora | Igual | `cliente_resumo` com os mesmos blocos; pastas filtradas por R:view | Fiel | | nenhuma | - | [código] templates/cliente/_resumo.html.twig:25-86; src/Cliente/Controller/ClienteResumoController.php:58-78 |
| PZ-MOD-19 | Detalhes do cliente | Contatos: copiar, editar, remover, + Telefone, + E-mail | clique, Enter, Esc | Só estado | POST `cliente_contatos` persiste e re-renderiza | Fiel | Cliente não é Auditavel (ver PZ-MOD-20) | nenhuma | - | [código] ClienteResumoController.php:91-131: tenant expl. (404), R:edit **do cliente**, CSRF `cliente_contatos_{id}`, 422 |
| PZ-MOD-06 | Impressão | Prévia com opções, tamanho, Imprimir, PDF, Copiar | clique | html2pdf no navegador | PDF do servidor (`pasta_resumo_pdf`), mesmas opções | Fiel | | nenhuma | - | [inventário] pasta-impressao.js |
| PZ-MOD-11 | Marcadores | Modal: busca, contagem, árvore, "Criar marcador X", Cancelar, Salvar (Ctrl+Enter), Esc | clique, teclado | Salva e as etiquetas refletem | Busca, árvore, contador, Salvar via fetch; **sem callback: etiquetas do cabeçalho só mudam ao recarregar**; sem "Criar marcador" | Parcial | Atualizar etiquetas sem recarga; criar marcador inline; permissão (PZ-CAB-05) | backend para criar marcador no modal (rota de catálogo existente a conferir) | P1 | [código] show:3981-3985 (sem `onSucessoCallback`); templates/_partials/modal_mover_marcador.html.twig:233, 468 |

---

### A2. Cadastro do cliente

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MOD-17 | Cadastro | Modal "Cadastro e qualificação do cliente" (720px, pílula de pendências) | botão Cadastro, nome no trilho, Cadastrar cliente, Editar dados | Clona o cadastro central e edita na pasta | Não existe na pasta; há o modal "Adicionar cliente" (PF/PJ) para criar e vincular, e `cliente_edit` em outra tela | Parcial | O modal da pasta com as 7 abas não existe | backend (rotas na pasta para editar cliente com R:edit do cliente); decisão | P1 | [inventário] show:552-1694 |
| PZ-MOD-N14 | Cadastro | Fechar com alterações (duplo clique) | Fechar, ×, fundo, Esc | Mensagem e 2º clique | Não existe | Ausente | | PZ-MOD-17 | P2 | [inventário] |
| PZ-MOD-N15 | Cadastro | Ctrl+S salva, Esc fecha | teclado | | Não existe | Ausente | | PZ-MOD-17 | P2 | [inventário] |
| PZ-MOD-N16 | Cadastro | Abas Cliente, Réu, Endereço, Contato, Documentos, Qualificação, Histórico | clique | Ponto âmbar em aba com pendência | "Adicionar cliente" tem Dados Pessoais/Contato/Endereço (PF) | Parcial | Faltam Réu, Documentos, Qualificação, Histórico | PZ-MOD-17 | P2 | [inventário] |
| PZ-MOD-N17 | Cadastro · Cliente | Campos pessoais com máscara e checagem de CPF | digitação, blur | Duplicidade no blur | Campos no "Adicionar cliente"; duplicidade conferida no servidor ao criar | Parcial | Sem alerta de duplicidade no blur | PZ-MOD-17 | P2 | [inventário] backend §2.2 (PastaController.php:671/681) |
| PZ-MOD-N18 | Cadastro · Endereço | CEP com preenchimento automático | blur | Consulta viacep | Campos existem; sem CEP automático em cliente (viacep só em tenant) | Parcial | Falta autopreenchimento | serviço de CEP | P2 | [código] grep `viacep`: só templates/tenant/* |
| PZ-MOD-N19 | Cadastro · Contato | Telefone, WhatsApp, E-mail; pendência sem contato | digitação | | No "Adicionar cliente" e na janela Detalhes | Parcial | Sem WhatsApp separado (não verificado) | PZ-MOD-17 | P2 | [inventário] |
| PZ-MOD-N20 | Cadastro · Réu | Texto explicativo do réu da pasta | nenhum | Réu é da pasta | Não existe | Ausente | | backend (parte adversa da pasta) | P1 | [inventário] backend §3.2 |
| PZ-MOD-N21 | Cadastro · Réu | Campos nome e CPF/CNPJ | digitação | Só tamanho do documento | Não existe | Ausente | Ao implementar, validar dígito verificador | idem | P1 | [inventário] |
| PZ-MOD-N22 | Cadastro · Réu | Adicionar réu | clique | Grava na hora, MAIÚSCULAS, duplicado limpa sem aviso, sem registro | Não existe | Ausente | Ao implementar: avisar duplicado, registrar no histórico, Enter adiciona | idem; entidade Auditavel | P1 | [inventário] |
| PZ-MOD-N23 | Cadastro · Réu | Lista com Principal e documento | nenhum | Ordem define o principal | Não existe (réu só via `ParteProcesso` do processo) | Ausente | Coluna "Réu" da Lista do Expediente também depende | idem | P1 | [inventário] backend §3.2 |
| PZ-MOD-N24 | Cadastro · Réu | Tornar principal / subir | clique | Troca ordem | Não existe | Ausente | | idem | P1 | [inventário] |
| PZ-MOD-N25 | Cadastro · Réu | Remover réu | clique | `confirm()` nativo | Não existe | Ausente | Ao implementar: janela vermelha própria (ver defeitos) | idem | P1 | [inventário] dc:3542 conferido no bruto |
| PZ-MOD-N26 | Cadastro · Documentos | Identidade e comprovante: anexar, ver, baixar, substituir, excluir | clique, input file | Excluir sem confirmação | Documentos do cliente ficam na ficha `cliente_show` (ícone "Abrir ficha… documentos e anexos ficam lá") | Parcial | Não estão na pasta; excluir deve confirmar | PZ-MOD-17 | P2 | [código] templates/pasta/_cliente_linha.html.twig:80-87 |
| PZ-MOD-N27 | Cadastro · Documentos | OCR da identidade | automático | Sem serviço mostra "não configurado" | Não existe | Ausente | | backend (OCR) | P2 | [inventário] |
| PZ-MOD-N28 | Cadastro · Documentos | Inteligência cadastral (sugestões, confiança, validar) | clique, teclado | EXTRACOES de referência (simulação) | Não existe | Ausente | Sem lastro: não copiar a simulação | backend (extração por IA) | P2 | [inventário] |
| PZ-MOD-N29 | Cadastro · Qualificação | Texto jurídico gerado | nenhum | Flexão de gênero | Serviço `QualificacaoDoCliente` existe; "Copiar qualificação" na janela Detalhes | Parcial | Falta a aba que mostra o texto | PZ-MOD-17 | P2 | [código] src/Cliente/Service/QualificacaoDoCliente.php; _resumo.html.twig:121-124 |
| PZ-MOD-N30 | Cadastro · Histórico | Lista de alterações do cadastro | nenhum | Até 40 | Não existe; Cliente não é Auditavel | Ausente | | backend (auditoria do cliente) | P2 | [inventário] |
| PZ-MOD-N31 | Cadastro | Bloco "Pendências" com atalhos às abas | clique | | Regra `cliente_pendencias` existe (ponto e title com a lista); sem bloco clicável | Parcial | | PZ-MOD-17 | P2 | [código] _cliente_linha.html.twig:24-30 |
| PZ-MOD-N32 | Cadastro | Alerta "Este CPF já possui cadastro" com Abrir/Utilizar/Cancelar | blur, Salvar | | Recusa de duplicidade no servidor ao criar; sem fluxo "Utilizar cadastro existente" | Parcial | | PZ-MOD-17 | P2 | [inventário] backend §2.2 |
| PZ-MOD-N33 | Cadastro | Rodapé: estado, Fechar, Copiar qualificação, Salvar | clique | Valida nome e CPF; registro no histórico | Salvar no "Adicionar cliente"; Copiar qualificação só na janela Detalhes | Parcial | | PZ-MOD-17 | P2 | [inventário] |
| PZ-MOD-N13 | Cadastro | Alias de CPF ao trocar documento | Salvar | Alias incompleto (cabeçalho, botão e Detalhes usam o CPF antigo) | Cliente é identificado por `cliente.id` | Não aplicável | Defeito do desenho: chave por CPF. Comportamento correto já é o do BJ (id estável) | nenhuma | - | [código] cab:330 (`data-cliente-id`); [inventário] dc:3499 |

---

### A3. Aba Dados da pasta e trilho

#### A3.1 Registro dos expedientes

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-DAD-01 | Registro | Título + contador | nenhum | Registros principais | Igual, atualizado no envio | Fiel | | nenhuma | - | [inventário] anot:106-110 |
| PZ-DAD-02 | Registro | Compositor "Adicionar…" | Enviar / Ctrl+Enter | Grava, aviso, pergunta de andamento | POST `pasta_enviar_mensagem`, insere sem recarregar, @menções | Fiel | Pergunta de andamento é P3. Autorização só R:view (ver Achado A-05) | nenhuma | - | [código] PastaController.php:1044-1053: tenant expl., **R:view**, CSRF `pasta_mensagem_{id}` |
| PZ-DAD-03 | Registro | Linha do tempo com pílula do dia | nenhum | Ordenação pelo principal | 4 visíveis + "ver anteriores" | Fiel | | nenhuma | - | [inventário] anot:149-187 |
| PZ-DAD-04 | Cartão | Avatar, autor, hora, megafone, corpo rico | nenhum | | Igual | Fiel | | nenhuma | - | [inventário] anot:248-327 |
| PZ-DAD-05 | Cartão | Presença do autor | clique | Abre Chat | Não existe | Ausente | | backend (presença em tempo real) | P2 | [inventário] |
| PZ-DAD-06 | Cartão | Etiqueta (Combinado, Ligação…) | nenhum | Cores por etiqueta | Não existe | Ausente | | backend (tipo do registro) | P2 | [inventário] |
| PZ-DAD-07 | Cartão | "editado" com dica | hover | Também "Editado por X · N versões guardadas" | Só publicado/última edição | Parcial | Sem versões guardadas | backend (versões do registro) | P2 | [inventário] anot:264-267 |
| PZ-DAD-08 | Cartão | Lápis "Editável por mais N min" | clique | 15 min; primário e master sem limite | Autor dentro da janela; POST `pasta_editar_mensagem` | Parcial | Regra do primário/master ausente | decisão "primário" | P2 | [inventário] PastaController.php:1108-1131 (tenant, R:view, CSRF `pasta_mensagem_editar_{msgId}`) |
| PZ-DAD-09 | Cartão | Responder | clique | Resposta a resposta prefixa "@Nome" | Um nível, sempre à raiz | Parcial | Sem "@Nome" ao responder resposta | nenhuma | P2 | [inventário] anot:294-306 |
| PZ-DAD-10 | Cartão | Encaminhar via Chat I.A | clique | `despachar` | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-DAD-11 | Cartão | Caixa de resposta | Ctrl+Enter, Esc | Notifica o autor pelo Chat | POST com `resposta_a`; Ctrl+Enter, Esc | Fiel | Notificação ao autor não conferida | nenhuma | - | [inventário] show:2409-2560 |
| PZ-DAD-12 | Cartão | Resposta recuada "Resposta a NOME" | nenhum | | Igual | Fiel | | nenhuma | - | [inventário] anot:252-258 |
| PZ-DAD-13 | Cartão | Destaque de cor (1 por aba) | menu | 15 cores | Não existe | Ausente | | backend (destaque por mensagem) | P2 | [inventário] |
| PZ-DAD-14 | Registro | Cartões de sistema (Andamento, Responsável, Situação, Contrato, Pró-bono, Prioridade, Dados, Cadastro) | automático | Cartões no Registro com "de → para" | Eventos vão ao drawer Histórico, não ao Registro | Divergente | Andamento é P3; os demais estão em outro lugar | decisão do dono; P3 | P2 | [inventário] |
| PZ-DAD-15 | Registro | "Ver anotações anteriores (N)" | clique | Sem handler, "6" fixo | Funciona; `#pasta-msg-N` abre e rola | Fiel | | nenhuma | - | [inventário] pasta-show.js:409-419 |
| PZ-DAD-16 | Cartão | Menu botão direito (Responder, Copiar, Encaminhar, Destacar, Editar, Excluir) | botão direito | Excluir: janela vermelha; primário até 60 min, master sempre | Responder, Copiar, Editar/Excluir (com bloqueio e motivo); Excluir com janela vermelha igual | Parcial | Faltam Encaminhar e Destacar; regra do primário | Chat I.A; destaque; decisão | P2 | [inventário] show:2600-2705; rota `pasta_excluir_mensagem` tenant + R:view, CSRF `pasta_mensagem_excluir_{msgId}`, delete real |
| PZ-DAD-17 | Registro | @menção com notificação | digitação | Módulo global | `pasta-mencoes.js` + `pasta_mencionaveis` | Fiel | | nenhuma | - | [inventário] |

#### A3.2 Trilho: Ação e Processo vinculado

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-TRI-01 | Trilho | Cartão "Ação" | hover | No trilho | Na faixa do cabeçalho (mesmos textos de vazio e "Extraído do processo vinculado") | Não aplicável | Decisão provisória P1 | decisão definitiva P1 | - | [código] cab:471-484 |
| PZ-TRI-02 | Trilho | Cartão "Processo vinculado" com chip de apensos | clique | Apensos com tipo | Na faixa: número, copiar, "trocar", chip "N vinculado(s)" (sem tipo) | Não aplicável | P1; tipo de apenso em PZ-PRO-08 | decisão definitiva P1 | - | [código] cab:413-470 |

#### A3.3 Trilho: Próximos prazos e audiências

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-TRI-03 | Prazos | Título + contagem + chevron | clique | Contagem = total da lista unificada; abre Metas em Abertas | Contagem = itens exibidos (máx. 3); chevron vai à aba Metas | Parcial | Contagem limitada a 3 em vez do total | nenhuma | P2 | [código] _dados_trilho.html.twig:22-31; PastaController.php:343; PastaPrazoOutput.php:42 (`$limite = 3`) |
| PZ-TRI-N01 | Prazos | "+" Adicionar prazo ou audiência | clique | Abre modal (responsável pré-preenchido com o CPF: defeito) | Não existe | Ausente | Não existe entidade de prazo manual | backend (entidade TenantAware + Auditavel, rotas R:edit, CSRF, lápide) | P1 | [inventário] backend §3.1; dc:6900 conferido no bruto |
| PZ-TRI-N02 | Prazos | Vazio | automático | Menciona "+" e Push | "Nenhum prazo aberto / Metas com prazo aparecem aqui…" | Divergente | Texto do desenho prometeria funções ausentes; trocar junto com N01/N04 | PZ-TRI-N01, N04 | P2 | [código] _dados_trilho.html.twig:32-37 |
| PZ-TRI-04 | Prazos | Linha do prazo (pip, título, meta, selo, ⋮) | botão direito, ⋮ | Até 8; "Audiência · "; meta com origem | Até 3; meta "RESP · dd/mm" sem hora e sem origem; título é link para a meta | Parcial | Limite 3 x 8; sem origem e sem hora | origens: PZ-TRI-N04 | P2 | [código] _dados_trilho.html.twig:57-72; PastaPrazoOutput.php:99-108 |
| PZ-CTX-03 | Prazos | Botão direito na linha | botão direito | Abre o ⋮ | Abre o mesmo ⋮ | Fiel | | nenhuma | - | [inventário] pasta-clientes.js:415-422 |
| PZ-TRI-N03 | Prazos | Selos e tons | automático | "Hoje", "Faltam N dia(s)", "Vencido há N dia(s)"; vermelho ≤3, âmbar ≤7 | "Vence hoje", "Falta 1 dia"/"Faltam N dias", "Vencido há N dias"; mesmos tons | Divergente | "Vence hoje" onde o desenho diz "Hoje" | nenhuma | P2 | [código] PastaPrazoOutput.php:84, 110-136 |
| PZ-TRI-N04 | Prazos | Origens: manual, Push, registros, metas | automático | Lista unificada e deduplicada | Só metas abertas com prazo | Ausente | Push só tem regra parcial (`DeterminacoesDoJuizo`, sem data final); registros sem parser | backend (motor de prazos, dias úteis) | P1 | [inventário] backend §3.1 |
| PZ-TRI-N05 | Prazos | Leitor de datas `BJDatas` | programático | Regex no navegador; FERIADOS sobrescrito | Não existe | Ausente | Motor deve ser do servidor | backend; `Feriado` existe no Ponto | P2 | [inventário] |
| PZ-TRI-N06 | Prazos | Publicação para Lista e Agenda | automático | localStorage | Não existe | Ausente | | backend (endpoint de prazos/atividade por pasta) | P2 | [inventário] |
| PZ-TRI-N07 | ⋮ prazo | Marcar como cumprido | clique | Sem confirmação e sem desfazer | Não existe | Ausente | Ao implementar: aviso com Desfazer | PZ-TRI-N01 | P1 | [inventário] |
| PZ-TRI-N08 | ⋮ prazo | Editar | clique | Modal "Editar prazo" | Não existe | Ausente | | PZ-TRI-N01 | P1 | [inventário] |
| PZ-TRI-N09 | ⋮ prazo | Excluir | clique | Sem confirmação, sem estilo de perigo | Não existe | Ausente | Ao implementar: janela vermelha | PZ-TRI-N01 | P1 | [inventário] |
| PZ-TRI-05 | ⋮ prazo | Ver nas metas | clique | Aba Metas | Aba Metas | Fiel | | nenhuma | - | [código] _dados_trilho.html.twig:77-79 |
| PZ-TRI-06 | ⋮ prazo | Encaminhar via Chat I.A | clique | | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-TRI-07 | ⋮ prazo | Alertar PRIMEIRONOME | clique | Notifica e mostra aviso | Form POST `tarefa_alertar` com recarga; um item por responsável | Parcial | Recarrega a página em vez de aviso | nenhuma | P2 | [código] _dados_trilho.html.twig:56-89; MetaNaListaController (M:tarefas + R:edit), CSRF `alertar_tarefa_{id}` |
| PZ-TRI-08 | ⋮ prazo | Adicionar à agenda (.ics) | clique | Ano corrente (defeito) | Dia com ano da meta | Fiel | BJ corrige o ano | nenhuma | - | [código] _dados_trilho.html.twig:90-99 |
| PZ-TRI-09 | ⋮ prazo | Copiar | clique | "título · selo · meta · Pasta N" | Igual, "Prazo copiado" | Fiel | | nenhuma | - | [código] _dados_trilho.html.twig:100-104 |
| PZ-TRI-N10 | Modal prazo | Janela "Novo prazo ou audiência" | clique | Sem Esc e sem Enter | Não existe | Ausente | Ao implementar: Esc fecha, Enter salva | PZ-TRI-N01 | P1 | [inventário] |
| PZ-TRI-N11 | Modal prazo | Seletor Prazo / Audiência | clique | | Não existe | Ausente | | backend (tipo audiência; `Evento` da Agenda não liga a pasta) | P1 | [inventário] backend §3.1 |
| PZ-TRI-N12 | Modal prazo | Descrição, data, hora, responsável (texto livre) | digitação | Responsável texto livre | Não existe | Ausente | Ao implementar: responsável = colaborador do escritório | PZ-TRI-N01 | P1 | [inventário] |
| PZ-TRI-N13 | Modal prazo | Nota informativa | nenhum | | Não existe | Ausente | | PZ-TRI-N01 | P2 | [inventário] |
| PZ-TRI-N14 | Modal prazo | Erro, Cancelar, Salvar | clique | Grava e registra | Não existe | Ausente | | PZ-TRI-N01 | P1 | [inventário] |

#### A3.4 Trilho: Clientes e Documentos

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-TRI-N15 | Clientes | Contagem | automático | Conta duplicados | Contagem do servidor | Fiel | | nenhuma | - | [inventário] trilho:142-149 |
| PZ-TRI-10 | Clientes | "+" Vincular cliente, busca, selos, Cadastrar cliente | clique, Enter, Esc | Busca no cadastro central e vincula | Painel inline, `pasta_clientes_buscar` + `pasta_cliente_vincular`; "Cadastrar" abre Adicionar cliente | Fiel | | nenhuma | - | [código] _dados_trilho.html.twig:126-140; [inventário] rota vincular R:edit, TF, CSRF `pasta_cliente_vincular_{id}` |
| PZ-TRI-11 | Clientes | Linha (iniciais, nome que abre Cadastro, ícone, Principal, CPF, pasta) | clique no nome | Nome abre Cadastro; pasta sem handler; sem botão direito | Ícone e botão direito abrem Detalhes; nome não é botão; pasta abre `cliente_show` | Parcial | Nome não clicável; destino Detalhes no lugar do Cadastro | PZ-MOD-17 | P2 | [código] _cliente_linha.html.twig:39-87 |
| PZ-TRI-12 | Clientes | Selo "CPF duplicado" | automático | Por CPF | Não existe | Ausente | Cálculo simples no servidor | nenhuma | P2 | [inventário] |
| PZ-TRI-13a | Clientes | Estrela (tornar principal) | clique | Não existe no desenho | POST `pasta_cliente_principal`, atualiza Média | Não aplicável | [SÓ-BLUEJUS] a preservar (divergência deliberada, reportada) | nenhuma | - | [código] _cliente_linha.html.twig:65-79 |
| PZ-TRI-13b | Clientes | Lixeira "Desvincular cliente" | clique | Não existe no desenho | POST `pasta_cliente_desvincular` **sem nenhuma confirmação**; promove outro principal | Não aplicável | [SÓ-BLUEJUS] a preservar, mas ação destrutiva sem confirmação (Achado A-04) | nenhuma | P0 | [código] _cliente_linha.html.twig:89-96; show:1483-1520. Rota R:edit, TF, CSRF `pasta_cliente_desvincular_{id}_{cli}` |
| PZ-TRI-14 | Documentos | Cartão com 3 documentos, chevron | clique, Enter/Espaço | Contagem fixa "6" | Contagem real, abre visualizador | Fiel | | nenhuma | - | [inventário] trilho:229-268 |

---

### A4. Aba Metas

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-MET-01 | Metas | Título | nenhum | | Igual | Fiel | | nenhuma | - | [inventário] metas:101 |
| PZ-MET-02 | Metas | Filtros com contagem | clique | Padrão Abertas | Filtro no navegador, contagem do servidor | Fiel | | nenhuma | - | [inventário] |
| PZ-MET-03 | Metas | Nova meta | clique | Abre janela | Abre `#modalCriarTarefa` | Fiel | Prazo padrão em PZ-MOD-02 | nenhuma | - | [inventário] metas:118-123 |
| PZ-MET-04 | Linha | Cartão da meta | clique | Abre relatório | Link `tarefa_show`; com JS abre o drawer | Fiel | | nenhuma | - | [inventário] metas:136-181 |
| PZ-MET-05 | Linha | Nº global "Nº 0042" | hover | Sequência global | Só nº local | Ausente | | backend (MM-LIS-37, sequência global) | P2 | [inventário] |
| PZ-MET-06 | Linha | Editar nome inline | ⋮, Enter, Esc | Sem recarga | Form POST com recarga; item aparece para quem só vê e o servidor recusa | Parcial | Recarga; visibilidade usa `metaEditavel` sem `podeEditarPasta` | nenhuma | P2 | [código] _metas.html.twig:86-91, 238-243. Rota `tarefa_renomear`: M:tarefas + R:edit, CSRF `renomear_tarefa_{id}` |
| PZ-MET-07 | Linha | Sino "Alertar para verificar" | clique | Abre menu | Popover; só com `podeAlertar` | Fiel | | nenhuma | - | [código] _metas.html.twig:95 |
| PZ-MET-08 | Menu do sino | Responsáveis | clique | Aviso | POST `tarefa_alertar` com recarga | Parcial | Recarga em vez de aviso | nenhuma | P2 | [inventário] metas:200-209 |
| PZ-MET-09 | Menu do sino | Demais usuários | clique | | Só responsáveis | Ausente | | decisão do dono; backend (regra do alerta) | P2 | [inventário] |
| PZ-MET-10 | ⋮ meta | Abrir relatório (⋮ e botão direito) | clique, botão direito | | Igual | Fiel | | nenhuma | - | [inventário] metas:219-221 |
| PZ-MET-11 | ⋮ meta | Concluir / Reabrir com Desfazer | clique | Toast com Desfazer | XHR, aviso com Desfazer 4,2 s; **concluir aparece e funciona para quem só VÊ a pasta** | Parcial | Autorização (Achado A-01); reabrir exige R:edit e concluir não (assimetria) | backend (R:edit em `tarefa_concluir`) | P0 | [código] _metas.html.twig:86-91, 222-237; TarefaController.php:434-445, 612-632; MetaNaListaController.php:160-171 |
| PZ-MET-12 | ⋮ meta | Editar nome | clique | | Liga PZ-MET-06 | Fiel | | nenhuma | - | [código] _metas.html.twig:238-243 |
| PZ-MET-13 | ⋮ meta | Duplicar | clique | Cópia com Desfazer | Não existe | Ausente | | rota de criação existe; precisa endpoint de duplicar | P2 | [inventário] |
| PZ-MET-14 | ⋮ meta | Encaminhar via Chat I.A | clique | | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-MET-15 | ⋮ meta | Copiar link | clique | `/tarefas/equipe?…` | `tarefa_show` | Fiel | URL pela convenção do sistema | nenhuma | - | [inventário] metas:244-248 |
| PZ-MET-16 | ⋮ meta | Abrir em Metas | clique | Só aviso (não navega) | Navega a `tarefa_show` | Fiel | BJ cumpre o que o rótulo promete | nenhuma | - | [inventário] metas:253-255 |
| PZ-MET-17 | ⋮ meta | Excluir | clique | Sem confirmação, Desfazer 4,2 s | Janela vermelha; sem Desfazer (exclusão real; só criador em 24 h) | Divergente | Desfazer exige exclusão lógica | backend (lixeira de meta) ou decisão do dono | P2 | [código] _metas.html.twig:256-268. Rota `tarefa_excluir` M:tarefas + `verificarAcessoTarefa` + UseCase só criador, CSRF `excluir_tarefa_{id}` |
| PZ-CTX-06 | Linha | Botão direito na linha | botão direito | Menu no cursor | Igual | Fiel | | nenhuma | - | [inventário] pasta-metas.js:791-845 |
| PZ-MET-18 | Metas | Aviso com Desfazer | clique | Concluir, reabrir, duplicar, excluir | Só concluir e reabrir | Parcial | Duplicar e excluir sem desfazer | PZ-MET-13, PZ-MET-17 | P2 | [inventário] |
| PZ-MET-19 | Metas | Drawer "Relatório da meta" | clique | Esc prometido e não implementado | Esc e fundo fecham; histórico real | Fiel | | nenhuma | - | [inventário] metas:361-432 |
| PZ-MET-20 | Metas | Vazio do filtro | automático | | Mesmo texto | Fiel | | nenhuma | - | [inventário] |
| PZ-MET-21 | Trilho | Andamento das metas | nenhum | | Do servidor | Fiel | | nenhuma | - | [inventário] |
| PZ-MET-22 | Trilho | Precisa de atenção | nenhum | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-MET-23 | Trilho | Responsáveis nas metas | nenhum | Valores fixos | Valores reais | Fiel | | nenhuma | - | [inventário] |
| PZ-MET-24 | Trilho | Chat por pessoa | clique | `conversar` | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-MET-25 | Metas | Meta nova realçada + alerta forte da aba | automático | Animação 4 s e aba 30 s | Realce por `?meta=` | Parcial | Sem pulso forte da aba | nenhuma | P2 | [código] pasta-metas.js:846-882 |
| PZ-MET-N01 | Metas | Persistência das metas | automático | Não indexada por pasta (mesmas metas em todas) | Do banco, por pasta | Não aplicável | Defeito do desenho | nenhuma | - | [inventário] |
| PZ-MET-N02 | Metas | Deep link {pasta, aba, meta} | evento/URL | "Meta não encontrada…" se não existe | `?meta=&acao=renomear`; sem mensagem para meta inexistente | Parcial | Falta o aviso de meta excluída | nenhuma | P2 | [código] pasta-metas.js:846-882 (sem texto de não encontrada) |
| PZ-MOD-01 | Nova meta | Janela não modal arrastável, recolher | ponteiro, duplo clique | | Igual (48px visíveis) | Fiel | | nenhuma | - | [inventário] show:4142-4160 |
| PZ-MOD-02 | Nova meta | Campos, calendário, atalhos, responsáveis, aviso de repetida | clique, teclado | Descrição "/600"; prazo padrão hoje+7 | Descrição "/5000"; prazo começa vazio; aviso de título parecido equivalente | Parcial | Limite e prazo padrão divergentes | decisão do dono (limite) | P2 | [inventário] show:4168-4232; [código] pasta-metas.js (sem prazo padrão +7) |
| PZ-MOD-03 | Nova meta | Análise da I.A. do prazo jurídico | clique, hover | Simulação; FERIADOS sobrescrito | Não existe | Ausente | Não copiar a simulação | backend (motor de prazos/IA) | P2 | [inventário] |
| PZ-MOD-04 | Nova meta | Obrigatórios | automático | Título, descrição, prazo, responsável | Só título e descrição | Divergente | | decisão do dono (D-META) | P2 | [inventário] pasta-metas.js:883-916 |
| PZ-MOD-05 | Nova meta | Notificação dos responsáveis | criar | Evento `meta_nova` | `notificarTarefaCriada` no servidor | Fiel | | nenhuma | - | [código] TarefaController.php:210 (`notificacaoService->notificarTarefaCriada`). Rota `tarefa_criar_para_pasta` M:tarefas, tenant expl., R:view, CSRF `tarefa_criar_{pastaId}` |

---

### A5. Aba Processo

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-PRO-01 | Cabeçalho | Contagem | nenhum | Fixa "1" | Real | Fiel | | nenhuma | - | [inventário] proc:68-70 |
| PZ-PRO-02 | Cabeçalho | Interruptor Administrativo sem processo | clique | Confirma quando há processo | XHR, confirmação azul igual; desfaz em falha | Fiel | Frase final do andamento omitida (P3) | nenhuma | - | [código] pasta-processo.js:302-309; rota tenant + R:edit, CSRF `pasta_administrativa_{id}` [inventário] |
| PZ-PRO-03 | Cabeçalho | ⋮ do cabeçalho | clique, botão direito | | Igual | Fiel | | nenhuma | - | [inventário] proc:107-130 |
| PZ-PRO-04 | ⋮ cabeçalho | Relatório geral de processos | clique | Prévia | Não existe | Ausente | | backend (relatório consolidado) | P2 | [inventário] |
| PZ-PRO-05 | ⋮ cabeçalho | Enviar à Controladoria | clique | Chat | Não existe | Ausente | | backend (notificação à controladoria) | P2 | [inventário] |
| PZ-PRO-06 | ⋮ cabeçalho | Marcar/Desmarcar administrativo | clique | Sem a confirmação do interruptor | Submete o mesmo form (com confirmação) | Fiel | BJ corrige a inconsistência do desenho | nenhuma | - | [inventário] proc:126-128 |
| PZ-PRO-07 | Cabeçalho | Vincular processo | clique | Modal | Modal | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-08 | Cabeçalho | Vincular apensado | clique | Modal modo apenso | Não existe | Ausente | Vínculo só tem `principal`, sem tipo de apenso | backend (tipo de apenso em PastaProcesso, migration) | P1 | [inventário] backend §2.3 |
| PZ-PRO-09 | Cabeçalho | Peticionar na pasta | clique | Navega na mesma aba | Abre em nova aba | Parcial | Destino igual, alvo diferente | nenhuma | P2 | [inventário] proc:117-121 |
| PZ-PRO-10 | Cartão | Pílula, nº CNJ, copiar, Principal, ⋮, botão direito | clique | Número sem ação | Número abre `processo_show` | Fiel | | nenhuma | - | [inventário] proc:168-194 |
| PZ-PRO-11 | Cartão | Selo do apenso + X desvincular | clique | Janela vermelha | Não existe | Ausente | | PZ-PRO-08 | P1 | [inventário] |
| PZ-PRO-12 | Cartão | Ação, Classe, Tribunal, Situação | nenhum | Ponto sempre verde (defeito) | Ponto verde só "em andamento" | Fiel | | nenhuma | - | [inventário] proc:281-305 |
| PZ-PRO-13 | Cartão | Órgão, Valor da causa, Última distribuição | nenhum | Manual prevalece sobre PJe | Valor da causa vem da pasta; "Distribuição" | Parcial | Fonte do valor e rótulo divergentes | decisão do dono | P2 | [inventário] proc:310-329 |
| PZ-PRO-14 | Cartão | Marcas (justiça gratuita, tutela…) | nenhum | Só as verdadeiras | Não existe | Ausente | | backend (flags do processo) | P2 | [inventário] |
| PZ-PRO-15 | Cartão | Linha de fonte PJe + Atualizar | clique | Integração opcional | Não existe | Ausente | | backend (PJe/MNI) | P2 | [inventário] |
| PZ-PRO-16 | Cartão | Edição inline de 11 campos | menu | Grava só o que difere | Link para `processo_edit` (sai da pasta) | Divergente | | backend (dados manuais do processo); R:edit do processo | P2 | [inventário] |
| PZ-PRO-17 | Cartão | Ver todas / Mostrar menos | clique | | Igual (mais campos) | Fiel | | nenhuma | - | [inventário] proc:330-437 |
| PZ-PRO-18 | ⋮ processo | Adicionar nota técnica | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-19 | ⋮ processo | Abrir no PJe | clique | Copia número; abre se configurado | Não existe ("Ver processo completo" no lugar) | Ausente | | backend (URL do PJe por tribunal) | P2 | [inventário] |
| PZ-PRO-20 | ⋮ processo | Ver todas as informações | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-21 | ⋮ processo | Editar dados do processo | clique | Edição inline | Link `processo_edit` | Divergente | Ver PZ-PRO-16 | idem | P2 | [inventário] proc:223-227 |
| PZ-PRO-22 | ⋮ processo | Atualizar dados do PJe | clique | | Não existe | Ausente | | backend (PJe) | P2 | [inventário] |
| PZ-PRO-23 | ⋮ processo | Ver movimentações (Push) | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-24 | ⋮ processo | Copiar número | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-25 | ⋮ processo | Copiar resumo | clique | | Igual (mais linhas) | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-26 | ⋮ processo | Compartilhar | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-27 | ⋮ processo | Encaminhar via Chat I.A | clique | | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-PRO-28 | ⋮ processo | Relatório geral de processos | clique | | Não existe | Ausente | | PZ-PRO-04 | P2 | [inventário] |
| PZ-PRO-29 | ⋮ processo | Imprimir resumo | clique | | Janela de impressão + PDF | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-30 | ⋮ processo | Desvincular da pasta | clique | Só estado; no apenso esconde o principal (defeito) | XHR com janela vermelha igual, por id do processo | Fiel | Sem linha no Histórico (PZ-MOD-20) | nenhuma | - | [inventário] show:1559-1595; rota R:edit, `findOneByIdDoTenant`, CSRF `pasta_desvincular_processo_{id}` |
| PZ-PRO-31 | Notas | Nota técnica sob o cartão | clique, botão direito | | Igual (E3) | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-32 | ⋮ nota | Responder com outra nota | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-33 | ⋮ nota | Copiar texto | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-34 | ⋮ nota | Encaminhar via Chat I.A | clique | | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-PRO-35 | ⋮ nota | Editar / bloqueado | clique | Autor 15 min | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-36 | ⋮ nota | Excluir / bloqueado | clique | Janela vermelha | Janela vermelha igual | Fiel | Erro por alert() (menor) | nenhuma | - | [código] nota-tecnica.js:345; rota tenant expl. + `temProcesso` + R:edit, CSRF `processo_nota_tecnica_excluir_{notaId}` [inventário] |
| PZ-PRO-37 | Notas | Editor de nota | Ctrl+Enter, Esc | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-PRO-38 | Notas | Primário sem limite | regra | | Ausente (15 min para todos) | Ausente | | decisão "primário" | P2 | [inventário] |
| PZ-PRO-39 | Notas | Faixa "Notas técnicas N" sob todo cartão | nenhum | Não existe no desenho online | Existe, com aviso "A nota é do processo…" | Divergente | Desenho mostra só a lista quando há notas | decisão do dono | P2 | [inventário] |
| PZ-PRO-40 | Aba | Nota de rodapé do principal | nenhum | | Igual | Fiel | | nenhuma | - | [inventário] proc:470-473 |
| PZ-PRO-41 | Cartão | Situação legível | nenhum | | Filtro `situacao_processo` (E4) | Fiel | | nenhuma | - | [inventário] |
| PZ-MOD-08 | Vincular | Modal Buscar existente / Cadastrar novo / partes / movimentações / Buscar no CNJ / tipo de apenso | clique, Esc | CNJ simulado com catálogo e Push | Busca Select2, DataJud real, partes e movimentações | Parcial | Sem modo apenso e sem catálogo do Push na busca | PZ-PRO-08 | P2 | [inventário] show:1696-1906; rota R:edit, `findOneByIdDoTenant`, CSRF `vincular_processo_{id}` |
| PZ-PRO-N01 | bj-processo.js | Motor `BJProcesso` | programático | Leitura de autos no navegador | Não existe | Ausente | Motor deve ser do servidor | backend | P2 | [inventário] |
| PZ-PRO-N02 | bj-processo.js | `analisar` | programático | | Não existe (`DeterminacoesDoJuizo` é regra parcial) | Ausente | | backend | P2 | [inventário] |
| PZ-PRO-N03 | Processo | Uso da análise (prazos no trilho, relatório) | automático | | Não existe | Ausente | | PZ-PRO-N02, PZ-TRI-N04 | P2 | [inventário] |
| PZ-PRO-N04 | bj-processo.js | Dias úteis e feriados | programático | | Não existe na pasta (`Feriado` do Ponto) | Ausente | | backend | P2 | [inventário] |

---

### A6. Aba Financeiro (bj-contas como nota)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-FIN-01 | Faixa | Contrato Pendente ⇄ Assinado | clique | Sem confirmação; sincroniza fase | POST, sem confirmação | Parcial | Erro por alert(); fase é P3 | nenhuma | P2 | [inventário] fin:60-92; rota R:edit, TF, CSRF `pasta_situacao_contrato_{id}` |
| PZ-FIN-02 | Faixa | Pró-bono | clique | Sem confirmação | POST, sem confirmação | Parcial | Erro por alert() | nenhuma | P2 | [inventário] fin:94-128; rota R:edit, TF, CSRF `pasta_pro_bono_{id}` |
| PZ-FIN-03 | Faixa | Valor da causa (soma dos lançamentos) | nenhum | Calculado | "Honorários contratuais" editável | Não aplicável | Decisão do dono (renomeou o que a tela mostra) | nenhuma | - | [código] _financeiro.html.twig:120-170 (comentário "o dono renomeou") |
| PZ-FIN-04 | Faixa | Média por CPF · "4 clientes vinculados" | nenhum | Valores fixos | Média do cliente principal com o nome | Não aplicável | Decisão do dono (28/08) | nenhuma | - | [código] _financeiro.html.twig:170-186 |
| PZ-FIN-05 | Relatório | Contador + compositor | Enviar | Contador fixo "2" | Contador real, POST | Fiel | | nenhuma | - | [inventário]; rota tenant expl. + R:edit, CSRF `pasta_financeiro_obs_{id}` |
| PZ-FIN-40 | Relatório | Lista sem rolagem interna | nenhum | | Igual (E4) | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-06 | Cartão | editado, lápis, Responder | clique, Ctrl+Enter, Esc | | Igual; editar/excluir só autor 15 min | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-07 | Cartão | Presença | clique | | Não existe | Ausente | | backend (presença) | P2 | [inventário] |
| PZ-FIN-08 | Cartão | Encaminhar | clique | | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-FIN-09 | Relatório | Cartão "Andamento" | nenhum | | Não existe | Não aplicável | Decisão provisória P3 | decisão definitiva P3 | - | [inventário] |
| PZ-FIN-10 a 15 | Menu do registro | Responder, Copiar, Encaminhar, Destacar, Editar, Excluir | botão direito | | Sem Encaminhar e Destacar; Excluir com janela vermelha igual | Parcial | | Chat I.A; destaque | P2 | [código] _financeiro_obs_script.html.twig:525-527 |
| PZ-FIN-16 | Arquivos | Adicionar | clique | Sem handler | Upload real (allowlist, 10 MB) | Fiel | | nenhuma | - | [inventário]; rota R:edit, CSRF `pasta_financeiro_upload_{id}` |
| PZ-FIN-17 | Arquivos | Selo "Assinado" | nenhum | | Não existe | Ausente | | backend (flag de assinatura) | P2 | [inventário] |
| PZ-FIN-18 | ⋮ arquivo | Abrir | clique | | Visualizador | Fiel | | nenhuma | - | [inventário] |
| PZ-CTX-11 | Arquivos | Duplo clique abre | duplo clique | Provavelmente inoperante no runtime | Funciona | Fiel | BJ cumpre a intenção | nenhuma | - | [inventário] |
| PZ-FIN-19 | ⋮ arquivo | Baixar | clique | | GET download | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-20 | ⋮ arquivo | Copiar nome | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-21 | ⋮ arquivo | Encaminhar via Chat I.A | clique | | Não existe | Ausente | | backend (Chat I.A) | P2 | [inventário] |
| PZ-FIN-22 | ⋮ arquivo | Enviar por e-mail | clique | mailto | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-23 | ⋮ arquivo | Ver em Documentos | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-24 | ⋮ arquivo | Excluir | clique | Janela vermelha | Janela vermelha igual; lixeira | Fiel | | nenhuma | - | [código] _financeiro.html.twig:1119; rota R:edit, doc da pasta, CSRF `pasta_financeiro_excluir_{docId}` [inventário] |
| PZ-FIN-25 | Arquivos | Reduzir tamanho | clique | Estado | Liga compressão | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-26 | Pagamentos | Resumo, barra, "+ R$ Z previsto em êxito e sucumbência", próximos | clique no valor | | Sem a linha de previstos (êxito/sucumbência desabilitados) | Parcial | | PZ-MOD-24 | P2 | [inventário] |
| PZ-FIN-27 | ⋮ Pagamentos | Adicionar pagamento | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-28 | ⋮ Pagamentos | Editar ou corrigir / Concluir | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-29 | ⋮ Pagamentos | Ver todos / Mostrar só os próximos | clique | Alterna | Só "Ver todos" | Parcial | Sem a volta "Mostrar só os próximos" (não confirmado no script) | nenhuma | P2 | [inventário] fin:547-549 |
| PZ-FIN-30 | ⋮ Pagamentos | Copiar resumo | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-31 | ⋮ Pagamentos | Imprimir extrato | clique | | Igual (PDF) | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-32 | ⋮ Pagamentos | Botão direito no ⋮ | botão direito | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-33 | Pagamentos | Concluir edição | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-34 | Pagamentos | Correção inline com confirmação | clique, Enter, Esc | Janela azul | Janela azul com o mesmo texto; trava otimista (409) | Fiel | | nenhuma | - | [código] _financeiro_pagamentos_script.html.twig:189; rota R:edit, `findByIdAndPastaAndTenant`, CSRF `pasta_pagamento_corrigir_{pagId}` [inventário] |
| PZ-FIN-35 | Linha | Corrigir valor (botão direito) | botão direito | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-36 | Linha | Marcar recebido / Desfazer | clique | Sem confirmação | Sem confirmação, POST | Parcial | Erro por alert() | nenhuma | P2 | [inventário]; rota R:edit, `findByIdAndPastaAndTenant`, CSRF `pasta_pagamento_quitacao_{pagId}` |
| PZ-FIN-37 | Linha | Excluir lançamento | clique | Janela vermelha | Janela vermelha igual; exclusão real | Fiel | | nenhuma | - | [código] _financeiro_pagamentos_script.html.twig:264-266; rota R:edit, CSRF `pasta_pagamento_excluir_{pagId}` [inventário] |
| PZ-FIN-38 | Pagamentos | Sufixo de juros | nenhum | | Igual (servidor) | Fiel | | nenhuma | - | [inventário] |
| PZ-FIN-39 | Pagamentos | Vazio + Adicionar | clique | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-MOD-23 | Adicionar pagamento | Modal de parcelamento | clique, Esc | "Entrada já recebida hoje" | Igual menos "Entrada já recebida" (removido pelo dono 07/10) e com Descrição (P16a) | Parcial | Desvios decididos; restam Êxito/Sucumbência (PZ-MOD-24) | nenhuma | P2 | [inventário] show:353-460 |
| PZ-MOD-24 | Adicionar pagamento | Êxito e Sucumbência (percentual, previsto) | clique | Lançamento previsto sem vencimento | Desabilitados "Ainda não disponível." | Ausente | | backend (lançamento previsto) | P1 | [inventário] show:398-401 |
| PZ-FIN-N01 | Financeiro | Persistência local com semente | automático | localStorage | Banco | Não aplicável | Simulação de demo; BJ tem backend real | nenhuma | - | [inventário] |
| PZ-FIN-N02 | Impressão | Financeiro na impressão | opção | | Igual | Fiel | | nenhuma | - | [inventário] |
| PZ-GLB-17 | bj-contas.js | Multiconta simulada (nota) | tela própria | Prefixo no localStorage; confirm() nativo | Tenants reais com isolamento no servidor | Não aplicável | Simulação sem lastro; confirm() nativo é defeito | nenhuma | - | [inventário] bj-contas.js:84-86 |

---

### Itens BlueJus destas áreas sem par no Designer

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prio | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-CAB-S02 | Cabeçalho (lápide) | Restaurar | clique | Não existe | POST `pasta_restaurar` com janela azul | Não aplicável | [SÓ-BLUEJUS] preservar | nenhuma | - | [código] cab:156-171; rota R:delete, CSRF `restaurar_pasta_{id}` |
| PZ-CAB-S03 | Cabeçalho (lápide) | "Histórico" rotulado no lugar do ⋮ | clique | Não existe | Abre o drawer | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [código] cab:172-176 |
| PZ-CAB-S04 | Cabeçalho (lápide) | Aviso "Pasta excluída…" | nenhum | Não existe | Faixa de status | Não aplicável | [SÓ-BLUEJUS]; o texto contém travessão legado (cab:311) | varredura autorizada | - | [código] cab:300-314 |
| PZ-CAB-S05 | Cabeçalho | Timeline inteligente real | ⋮, T | Painel da central | Visita, resumo, atenção, antes/depois | Não aplicável | [SÓ-BLUEJUS] (o desenho tem o gatilho, PZ-CAB-13) | nenhuma | - | [inventário] |
| PZ-CAB-S06 | Faixa | Nº CNJ com copiar | clique | Não existe no cabeçalho | Copia | Não aplicável | P1 | decisão definitiva P1 | - | [código] cab:414-417 |
| PZ-CAB-S07 | Faixa | Classe · tribunal | nenhum | | | Não aplicável | P1 | idem | - | [código] cab:418-424 |
| PZ-CAB-S08 | Faixa | Chip "N vinculado(s)" | clique | | Lista os outros processos | Não aplicável | [SÓ-BLUEJUS] + P1 | idem | - | [código] cab:431-458 |
| PZ-CAB-S09 | Faixa | "Nenhum processo. vincular" | clique | | Abre modal (só R:edit) | Não aplicável | P1 | idem | - | [código] cab:461-466 |
| PZ-CAB-S10 | Faixa | Ação | nenhum | No trilho | Na faixa | Não aplicável | P1 | idem | - | [código] cab:471-484 |
| PZ-CAB-S11 | Faixa | Bloco Cobrança | clique | | Link à unidade cobrada (M:cobrancas) | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [código] cab:501-512 |
| PZ-MOD-S02 | Editar dados | Situação (select) | change | Não existe no modal | Vai no POST | Não aplicável | [SÓ-BLUEJUS] (confirmar com o dono: o desenho mostra o modal sem o campo) | decisão do dono | - | [código] show:3690-3696 |
| PZ-MOD-S03 | Editar dados | Nome derivado da cobrança (só leitura) | nenhum | | Texto explicativo | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [código] show:3639-3650 |
| PZ-MOD-S04 | Detalhes do cliente | Copiar qualificação + Editar cadastro | clique | No modal de Cadastro | Na janela Detalhes | Não aplicável | [SÓ-BLUEJUS] (substituto parcial de PZ-MOD-17) | nenhuma | - | [código] _resumo.html.twig:121-127 |
| PZ-MOD-S05 | Nova meta | Aviso de título parecido + Abrir meta existente | input | Equivalente ao aviso de repetida | Não bloqueia | Não aplicável | Coberto por PZ-MOD-02 | nenhuma | - | [inventário] |
| PZ-MOD-S06 | Nova meta | Seletor de responsáveis com chips | clique, teclado | Chips com busca | Igual (select oculto) | Não aplicável | Coberto por PZ-MOD-02 | nenhuma | - | [inventário] |
| PZ-MOD-17b | Clientes | Modal "Adicionar cliente" PF/PJ, vínculo múltiplo | clique | Não existe | Cria e vincula | Não aplicável | [SÓ-BLUEJUS] preservar | nenhuma | - | [inventário] show:552-1694; rota `pasta_cliente_novo` R:edit, CSRF `pasta_cliente_novo_{id}` |
| PZ-TRI-S01 | Clientes | Pasta "Abrir ficha do cliente" | clique | Ícone sem handler | GET `cliente_show` | Não aplicável | [SÓ-BLUEJUS] na função | nenhuma | - | [código] _cliente_linha.html.twig:80-87 |
| PZ-TRI-S02 | Clientes | Placeholder "não vinculado" e vazio | nenhum | | | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] trilho:206-221 |
| PZ-DAD-S01 | Registro | Lixeira oculta acionada pelo menu | menu | | | Não aplicável | Detalhe de implementação | nenhuma | - | [inventário] |
| PZ-MET-S01 | Metas | Estilo próprio do aviso (tema escuro) | nenhum | | | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] |
| PZ-PRO-S01 | Processo | Tornar processo principal no ⋮ | clique | | XHR, sem confirmação | Não aplicável | [SÓ-BLUEJUS]; erro por alert() | nenhuma | - | [inventário] proc:259-267; rota R:edit, CSRF `pasta_definir_principal_processo_{id}` |
| PZ-PRO-S02 | Processo | Vazios "Administrativo sem processo" / "Nenhum processo judicial" | nenhum | | | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] |
| PZ-PRO-S03 | Processo | "Ver processo completo" | clique | | GET `processo_show` | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] proc:210-212 |
| PZ-FIN-S01 | Financeiro | 3 arquivos + "ver os outros N" | clique | | Vai à aba Documentos | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] fin:462-468 |
| PZ-FIN-S02 | Financeiro | Renomear arquivo | clique | | POST, sem confirmação | Não aplicável | [SÓ-BLUEJUS]; erro por alert() | nenhuma | - | [inventário]; rota R:edit, CSRF `pasta_financeiro_renomear_{docId}` |
| PZ-GLB-13 | Global | X automático em popovers | abertura | | | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] |
| PZ-GLB-14 | Global | Arraste de menus e janelas | ponteiro | | | Não aplicável | [SÓ-BLUEJUS] | nenhuma | - | [inventário] |

---

### Contagem por status e por área

Contagem por linha da matriz (cada linha = um ID; "PZ-FIN-10 a 15" conta como 1).

| Área | Fiel | Parcial | Divergente | Ausente | Não aplicável | Não verificado | Total |
|---|---|---|---|---|---|---|---|
| A1 Cabeçalho (A1.1 a A1.9) | 34 | 16 | 6 | 22 | 9 | 0 | 87 |
| A2 Cadastro do cliente | 0 | 10 | 0 | 11 | 1 | 0 | 22 |
| A3 Dados e trilho | 15 | 8 | 3 | 18 | 4 | 0 | 48 |
| A4 Metas | 17 | 7 | 2 | 6 | 1 | 0 | 33 |
| A5 Processo | 24 | 3 | 3 | 16 | 0 | 0 | 46 |
| A6 Financeiro | 24 | 7 | 0 | 5 | 5 | 0 | 41 |
| Sem par no Designer (SÓ-BLUEJUS) | 0 | 0 | 0 | 0 | 27 | 0 | 27 |
| **Total** | **114** | **51** | **14** | **78** | **47** | **0** | **304** |

Prioridades por linha: **P0 = 3** (PZ-CAB-05, PZ-TRI-13b, PZ-MET-11) mais o Achado A-03 (stack trace) e o A-05 (anotações com R:view, decisão), que não têm linha própria; **P1 = 34**; **P2 = 107**; `-` = 160.
Por área (P0/P1/P2): A1 1/15/28 · A2 0/7/14 · A3 1/9/20 · A4 1/0/14 · A5 0/2/20 · A6 0/1/11.

---

### Defeitos do protótipo

Registrados como Não aplicável (ou citados na lacuna de itens Ausentes) e com o comportamento correto a adotar.

| # | Item | Defeito no desenho | Comportamento correto |
|---|---|---|---|
| D1 | PZ-TRI-N01 | "+" de prazo pré-preenche o Responsável com `PASTAS[i][2]`, que é o CPF do cliente (dc:6900, conferido no bruto) | Responsável vazio ou o responsável da pasta, escolhido da lista de colaboradores |
| D2 | PZ-MOD-03, PZ-TRI-N05 | `static FERIADOS` redeclarado em `MM-DD` (dc:6365) depois da lista com anos (dc:3962); a análise de prazo deixa de ver feriados nacionais e móveis | Um único calendário no servidor (feriados nacionais, móveis e do tribunal), reaproveitando `Feriado` |
| D3 | PZ-MOD-N13 | Alias de CPF incompleto: cabeçalho, botão Cadastro e Detalhes usam o CPF antigo e caem em "Novo cliente" | Cliente identificado por id estável (como o BJ já faz) |
| D4 | PZ-MOD-N25, PZ-MOD-26 | `confirm()` nativo em Remover réu (dc:3542, conferido) | Janela própria vermelha "Remover NOME dos réus desta pasta?" |
| D5 | PZ-CAB-25, PZ-MOD-26 | `prompt()` nativo em Excluir pasta (dc:4581, conferido); promete "lixeira por 30 dias" | Janela própria vermelha com campo para digitar o número; texto fiel ao que o sistema faz (lápide ou remoção) |
| D6 | PZ-GLB-17 | `confirm()` nativo em remover pessoa e excluir conta (bj-contas.js:84, 86) | Janela própria vermelha |
| D7 | PZ-CAB-N02 | Rótulo "T" na Timeline sem atalho | Atalho T (BJ já tem) |
| D8 | PZ-CAB-13 | Timeline não fecha o ⋮ | Fechar o menu ao abrir o painel |
| D9 | PZ-CAB-03, PZ-CAB-48, PZ-FIN-01/02 | Prioridade, situação, contrato e pró-bono só em estado React (não persistem; prioridade igual em todas as pastas) | Persistir no servidor com auditoria (BJ já faz) |
| D10 | PZ-MET-N01, PZ-MOD-11 | Metas e seleção de marcadores não indexadas por pasta | Dados por pasta (BJ já faz) |
| D11 | PZ-CAB-N07 | Foto da primeira pasta permanece ao navegar | Foto por cliente, relida na troca |
| D12 | PZ-PRO-30 | No cartão de apenso, "Desvincular da pasta" esconde o principal (chave é a pasta) | Desvincular pelo id do processo (BJ já faz) |
| D13 | PZ-PRO-06 | Item do ⋮ marca administrativo sem a confirmação que o interruptor pede | Mesma confirmação nos dois caminhos (BJ já faz) |
| D14 | PZ-PRO-12 | Ponto da situação sempre verde, mesmo "Suspenso" | Cor pela situação (BJ já faz) |
| D15 | PZ-MET-19 | `title="Fechar (Esc)"` sem handler de Esc | Esc fecha (BJ já faz) |
| D16 | PZ-MET-16 | "Abrir em Metas" só mostra aviso, não navega | Navegar (BJ já faz) |
| D17 | PZ-TRI-08 | .ics com o ano corrente (erra prazo de dezembro visto em janeiro) | Ano da data do prazo (BJ já faz) |
| D18 | PZ-TRI-N07, N09, PZ-MET-17, PZ-CAB-N06, PZ-MOD-N26, PZ-MOD-N10 | Cumprir/excluir prazo, excluir meta, remover foto, excluir documento do cliente, remover cadeado: sem confirmação; excluir prazo sem estilo de perigo | Destrutivo: janela vermelha; reversível: aviso com Desfazer |
| D19 | PZ-TRI-N10 | Modal de prazo sem Esc e sem Enter | Esc fecha, Enter salva |
| D20 | PZ-TRI-N12 | Responsável do prazo em texto livre | Seleção de colaborador do escritório |
| D21 | PZ-MOD-N21, N22 | Documento do réu só por tamanho; duplicado limpa sem aviso; Enter não ligado; sem registro | Validar dígito verificador, avisar duplicado, Enter adiciona, registrar no histórico |
| D22 | PZ-TRI-N04 | Data base do leitor usa campo inexistente (`r.em`), "em 15 dias" conta de hoje | Base = data do registro |
| D23 | PZ-DAD-14 | Tipo `prazo` fora de `SYS`: cartão sai como texto sem faixa de sistema | Tipo de evento declarado |
| D24 | PZ-CAB-33 | Guarda de "somente visualizar" por captura de cliques no navegador | Autorização no servidor (BJ já faz por `podeEditarPasta` + rotas) |
| D25 | PZ-CAB-29, PZ-CAB-44, PZ-MET-23, PZ-PRO-01, PZ-FIN-04/05, PZ-MOD-20 | Valores fixos no template (Movimentado, "224", responsáveis, "1", "R$ 3.215,00", "2", "10 alterações") | Valores do dado real (BJ já faz) |
| D26 | PZ-CTX-11 | `onDblClick` provavelmente inoperante no runtime | Duplo clique abre (BJ já faz) |
| D27 | PZ-MOD-N28, PZ-FIN-N01, PZ-GLB-17, PZ-TRI-N06 | Simulações sem lastro (EXTRACOES de referência, localStorage, multiconta, publicação local) | Não copiar; só com backend real |

---

### Achados P0

| # | Achado | Estado | Evidência (arquivo:linha) | Efeito | Correção sugerida |
|---|---|---|---|---|---|
| A-01 | Metas: concluir (e trocar responsáveis, comentar, abrir) sem acesso à pasta. Na pasta_show o item "Marcar como concluída" e o token `concluir_tarefa_{id}` aparecem para quem tem M:tarefas e só **vê** a pasta (`metaEditavel` não usa `podeEditarPasta`) | CONFIRMADO | src/Controller/TarefaController.php:434-445 (concluir só `assertAccess` + `verificarAcessoTarefa`), :612-632 (sem `canAccessResource`); src/Tarefa/Controller/MetaNaListaController.php:160-171 (R:edit em renomear/reabrir/alertar); templates/pasta/_metas.html.twig:86-91, 222-237 | Usuário sem permissão de editar a pasta conclui a meta; reabrir é recusado (assimetria) | R:edit da pasta em `concluir`, `updateResponsaveis`, `mensagem`, `editarMensagem`; R:view em `show`; `metaEditavel` com `podeEditarPasta`; teste de "M:tarefas sem acesso à pasta" |
| A-02 | Marcadores da pasta: endpoint exige só M:expediente, sem R:edit; o "+" e as etiquetas são renderizados sem checar `podeEditarPasta`, expondo o token | CONFIRMADO | src/Expediente/Controller/ExpedienteController.php:247-260 e :355-363 (`assertAccess` só módulo); src/Expediente/UseCase/SincronizarMarcadoresDaPastaUseCase.php:29 (`find` sem tenant, só TF); templates/pasta/_cabecalho.html.twig:104-111 | Quem só vê a pasta altera os marcadores (dentro do escritório; o TF segura entre escritórios) | `canAccessResource('pasta', id, 'edit')` no endpoint; "+" e clique nas etiquetas só com `podeEditarPasta` |
| A-03 | Mesmo endpoint devolve `trace` (stack trace) no JSON de erro 500 | CONFIRMADO | src/Expediente/Controller/ExpedienteController.php:267 | Exposição de caminhos internos e estrutura do código a qualquer usuário com M:expediente | Remover `trace` da resposta; registrar no log |
| A-04 | Desvincular cliente (lixeira do trilho) sem nenhuma confirmação; ao sair o principal o servidor promove outro e muda a Média por CPF | CONFIRMADO | templates/pasta/_cliente_linha.html.twig:89-96 (form sem `data-ps-cf`); templates/pasta/show.html.twig:1483-1520 (handler não pergunta); erro por `alert()` | Ação destrutiva por um clique; troca de principal silenciosa | `data-ps-cf` + `data-ps-cf-perigo` ("Desvincular NOME desta pasta?") ou `confirmarUIPs(…, true)` |
| A-05 | Anotações do Registro (enviar, editar, excluir) exigem só R:view, enquanto Detalhes e Financeiro exigem R:edit | CONFIRMADO o comportamento; HIPÓTESE que seja defeito (pode ser intencional: "comentar é conversa") | src/Controller/PastaController.php:1044-1050 (enviar, R:view); :1160-1166 (excluir, R:view); backend §2.6 (editar :1120) | Quem só vê a pasta escreve e exclui (as próprias) anotações | Decisão do dono; se for defeito, R:edit nas três rotas e compositor só com `podeEditarPasta` |

Fora de P0, mas vizinho de integridade (P1): PZ-MOD-20 (vincular/desvincular processo e cadastro de cliente sem linha no Histórico, porque PastaProcesso e Cliente não são Auditavel) e PZ-MOD-N02 (número da pasta repetível por regra de domínio).

---

### Itens [SÓ-BLUEJUS] a preservar (áreas A)

1. Lápide: Restaurar (janela azul), "Histórico" rotulado, aviso "Pasta excluída…" (texto com travessão legado), escrita escondida (cab:156-176, 300-314).
2. Excluir pasta conferido no servidor por `confirmar_nup` (cab:285-295), independentemente da janela.
3. Faixa Processo · Ação · Responsável com copiar CNJ, "trocar", chip "N vinculado(s)", "vincular" (P1 provisória) e bloco Cobrança (cab:413-512).
4. Seção "Preferências" + Fixar nos favoritos ligado ao servidor (rótulo diverge do desenho: PZ-CAB-S01).
5. Timeline inteligente real (visita, resumo, atenção, antes/depois).
6. Impressão com PDF do servidor (resumo, processo, extrato).
7. Modal "Adicionar cliente" PF/PJ com vínculo múltiplo; painel de vínculo inline.
8. Janela "Detalhes do cliente" com contatos persistidos (`cliente_contatos`, R:edit do cliente), Copiar qualificação e Editar cadastro.
9. Linha do cliente: estrela (tornar principal) e "Abrir ficha"; a lixeira fica, mas precisa de confirmação (A-04).
10. Editar dados: Situação no modal (confirmar com o dono), nome derivado da cobrança só leitura, descarte com confirmação nas abas internas.
11. Processo: Tornar principal no ⋮, Ver processo completo, detalhe ampliado, vazios próprios, DataJud real no Vincular.
12. Notas técnicas com o aviso "A nota é do processo" (divergência visual PZ-PRO-39 a decidir).
13. Metas: drawer com histórico real, aviso de título parecido, `?meta=&acao=renomear`, Responsáveis reais.
14. Financeiro: "Honorários contratuais" editável (decisão do dono), Média do cliente principal (decisão 28/08), Renomear arquivo, limite de 3 + "ver os outros N", trava otimista na correção, descrição no Adicionar pagamento (P16a).
15. X automático e arraste em popovers e janelas.

---

### Não verificados e o que falta

Nenhuma linha ficou com status Não verificado; os pontos abaixo são subitens que afetam a classificação e não foram medidos.

| Item | O que não foi verificado | O que falta |
|---|---|---|
| Todo o visual (tamanhos, cores, espaçamento) | A matriz compara comportamento e gatilho; medidas finas vêm dos inventários | Medir na tela (o smoke é do dono) com `design_to_tokens` do desenho |
| PZ-DAD-11 | Se o servidor notifica o autor ao receber resposta | Ler `EnviarMensagemPastaUseCase` (parte de notificação) |
| PZ-MOD-N19 | Se há campo WhatsApp separado no "Adicionar cliente" | Ler show:552-1694 (aba Contato PF) |
| PZ-FIN-29 | Se "Ver todos" alterna de volta para "Mostrar só os próximos" | Ler `_financeiro_pagamentos_script` no handler de `ver-todos` |
| PZ-MOD-06 | Faixa de tamanho de fonte (9 a 16 pt no desenho) | Ler `pasta-impressao.js` (limites do −/+) |
| PZ-CAB-05 / A-02 | Impacto real (quantos perfis têm M:expediente sem R:edit) | Consulta somente leitura a perfis e `resource_access` (MCP prod) |
| A-01 | Impacto real (perfis com M:tarefas sem acesso à pasta) | Idem |
| PZ-MOD-11 | Se existe rota de catálogo de marcadores reaproveitável para "Criar marcador" no modal | `debug:router` filtrando "marcador" |
| Decisões P1, P2, P3 | Estado definitivo (HANDOFF diz provisórias de 15/10; checklist e HANDOFF §6 dizem ABERTAS) | Confirmação do dono e atualização do checklist §20 |


# Parte B. Detalhes, editor, Documentos, Push, IA e Chat, globais, índices



Auditoria somente leitura em 16/10/2026. Protótipo oficial: `proto-online/02 - EXPEDIENTES 1.2.3.dc.html` (16/10) e dependências `bj-*.js`. BlueJus: worktree `fidelidade-designer`, HEAD `9c081725`, caminhos relativos a `app/`. Nada foi executado (sem navegador, servidor, banco ou testes).

**Legenda**

- Status: **Fiel**, **Parcial**, **Divergente**, **Ausente**, **Não aplicável** (com justificativa), **Não verificado** (com o que falta).
- Prioridade: **P0** segurança/integridade; **P1** funcionalidade essencial ausente ou incompleta; **P2** visual ou interação secundária; **n.a.** para Fiel e Não aplicável (substitui o traço pedido, por causa da regra do dono contra travessão).
- Evidência: `[código]` = conferido no código nesta auditoria (arquivo:linha); `[inventário]` = herdado de `inv-designer-B.md`, `inv-bluejus-tela.md` ou `inv-bluejus-backend.md`; `VIS-nn` = `visual/RELATORIO-VISUAL.md`.
- Abreviações: `dc:N` = linha do protótipo; `det` = `templates/pasta/_detalhes_obs.html.twig`; `show` = `templates/pasta/show.html.twig`; `push` = `templates/pasta/_push_processual.html.twig`; `teor` = `templates/pasta/_push_teor.html.twig`; `pex.js` = `public/js/pasta-explorador.js`; `ia-push` = `templates/inteligencia/_analises_push.html.twig`; `PC` = `src/Controller/PastaController.php`.
- Autorização (backend): `R:view` / `R:edit` = `canAccessResource('pasta', id, ação)`; `TF` = TenantFilter; "tenant explícito" = comparação `pasta.tenant !== tenant` com 404.
- Decisões aprovadas aplicadas: **P1** faixa Processo · Ação · Responsável preservada (parte A); **P2** "Como usar esta aba" preservada em Detalhes; **P3** Andamento da pasta em fases não implementado.
- Regra: onde o desenho mostra, o desenho manda; onde é omisso, a convenção do sistema; desvio exige perguntar. Simulação do protótipo em `localStorage` nunca vale como implementação: exige backend.

---

### 1. Aba Detalhes (PZ-DET)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-DET-01 | Detalhes | Painel "Relatório inicial de Atendimento" + contador | renderização | Contador = registros principais (respostas não contam) | Contador `#detalhes-obs-count` com dica "Observações principais (respostas não contam)"; JS conta as raízes | Fiel | | | n.a. | [código] det:73, det:203; dc:2433-2437 |
| PZ-DET-02 | Detalhes | Compositor "Adicionar observação…" + Enviar (Ctrl+Enter) | clique ou Ctrl+Enter | Grava em `bj-registros-v1` (localStorage), aviso "Registro adicionado" 3 s, apaga rascunho | `fetch POST pasta_detalhes_observacao_enviar` (tenant explícito + R:edit, CSRF `pasta_detalhes_obs_{id}`, Auditavel); Ctrl/Cmd+Enter envia; some para quem só vê e na lápide | Parcial | Sem rascunho automático (ver PZ-EDT-14); texto do aviso a conferir | backend já existe | P2 | [código] det:215, det:25; [inventário] backend 2.6 |
| PZ-DET-S01 | Detalhes | Vazio "Nenhuma observação registrada / Registre acima o relato do cliente…" [SÓ-BLUEJUS] | sem observações | Desenho mostra demonstração, sem estado vazio | Estado vazio próprio | Não aplicável | [SÓ-BLUEJUS] a preservar (desenho omisso; convenção do sistema) | | n.a. | [código] det:112 |
| PZ-DET-N01 | Detalhes | Janela "Atualizar andamento da pasta?" após cada registro | 350 ms depois de salvar | `cf` azul "Avançar para Y" / "Manter em X" | Inexistente | Não aplicável | Decisão aprovada P3 (fases não implementadas) | decisão do dono | n.a. | [inventário] dc:6342 |
| PZ-DET-N02 | Detalhes | Linha do tempo padrão Push/PJe com bolinha por etiqueta | renderização | Ordena pela criação; respostas em thread recuada 36px; cor da bolinha por tag (Combinado, Ligação, Atendimento, Observação) | Linha do tempo com pílula do dia e respostas em thread; ponto sempre azul (sem etiqueta) | Parcial | Etiqueta/tag do registro não existe; cor única | backend (campo de etiqueta) | P2 | [inventário] VIS-18; dc:2444-2448 |
| PZ-DET-03a | Detalhes | Cartão: "editado" com dica, lápis com "Editável por mais N minutos", Responder, ⋮ | hover, clique, botão direito | Lápis se `ehReg && editavel && pode`; dica com versões anteriores | Lápis com dica "Editável por mais 15 minutos"; "editado" com dica publicado/última edição; ⋮ "Mais ações da observação"; botão direito abre o ⋮ no cursor | Parcial | Sem contagem de versões anteriores na dica (não há histórico de versões; só audit_log) | backend (versões) se o dono quiser | P2 | [código] det:321, det:340; [inventário] det:778-800 |
| PZ-DET-03b | Detalhes | Caixa de resposta | Responder (hover ou ⋮) | "Respondendo a X", autofocus, Ctrl/Cmd+Enter envia, Esc cancela, "@Nome " ao responder resposta, notifica o autor pelo Chat I.A | Caixa com Cancelar e Responder (apagado vazio); Ctrl/Cmd+Enter envia; Esc fecha e devolve o foco; "@PrimeiroNome " ao responder resposta | Parcial | Notificação ao autor via Chat I.A inexistente (Chat ausente); texto do aviso a conferir | Chat I.A (backend) | P2 | [código] det:421-424, det:504-505, det:536 |
| PZ-DET-04 | Detalhes | Presença do autor (ponto + rótulo, abre Chat) | renderização | Status em tempo real (`BJCentral.presenca`) | Ausente | Ausente | Sem presença em tempo real | backend (presença, tempo real) | P2 | [inventário] checklist PZ-DET-04 |
| PZ-DET-05 | Detalhes | Encaminhar no hover | hover/foco | `despachar` para "Despachar via Chat I.A" | Ausente (comentário confirma) | Ausente | Depende do Chat I.A | Chat I.A (backend) | P2 | [código] det:1115 |
| PZ-DET-06 | ⋮ / botão direito | "Editar · N min" / "Editar · bloqueado" | item do menu | Master sem restrição; primário sem limite; autor 15 min; demais não; motivo no aviso | Autor 15 min (`JanelaDeEdicaoDeComentario` PT15M, servidor); minutos recalculados a cada abertura; bloqueado `aria-disabled` com motivo "O prazo de 15 minutos…" / "Só o autor pode editar…"; rota `pasta_detalhes_observacao_editar` (tenant + R:edit + `findOneBy(id,pasta,tenant)`, CSRF `_editar_{obsId}`) | Divergente | Faltam as exceções Master (sem restrição) e primário (sem limite), pedidas no Farlei §3.3/§3.12 | backend (papel primário/Master por pasta); decisão do dono (D-MASTER) | P1 | [código] det:61-62, det:607-649; [inventário] backend 2.6 |
| PZ-DET-07 | ⋮ | Copiar texto | item | Copia sem espaços duplos; aviso "Texto copiado" | Copia o texto como se lê, `bi-clipboard` | Fiel | | | n.a. | [código] det:357, det:680 |
| PZ-DET-08 | ⋮ | Encaminhar via Chat I.A | item | `despachar` | Ausente | Ausente | Depende do Chat I.A | Chat I.A | P2 | [código] det:1115 |
| PZ-DET-09 | ⋮ | Citar no novo registro (`bi-quote`) | item | `<blockquote>` no fim do editor; aviso "Citação inserida no editor" | Escreve a citação no compositor de Detalhes; só com `podeEscreverObs` | Fiel | Conferir texto do aviso na tela | | n.a. | [código] det:360, det:706 |
| PZ-DET-10 | ⋮ | Criar meta a partir deste registro | item | Aba Metas, Nova meta com 600 caracteres e prazo +7 dias | Abre `#modalCriarTarefa` com descrição (600) e prazo +7 dias | Fiel | Desenho troca para a aba Metas; BlueJus abre o modal na aba atual (a conferir na tela) | | n.a. | [código] det:363, det:718 |
| PZ-DET-11 | ⋮ | "Enviar no Chat I.A" | inalcançável no desenho | Código morto, evento sem ouvinte | Ausente | Não aplicável | Defeito do desenho (código morto, ver Defeitos 6) | | n.a. | [inventário] dc:6862-6870 |
| PZ-DET-12 | ⋮ | Imprimir | item | `gmImprimir('Registro do expediente', …)` | Janela de impressão "Registro do expediente" com PDF do servidor (`secao=observacao`) | Fiel | Título da janela "Imprimir" x "Imprimir · título" (VIS-17) | | n.a. | [código] det:369; [inventário] pasta-impressao.js:388-416 |
| PZ-DET-13 | ⋮ | "Excluir comentário · N min" / "Excluir · permanente" | item | Janela de 60 min (autor ou primário), Master sempre; exclusão LÓGICA com log; texto "Registro permanente: passaram 60 minutos…"; `cf` vermelha "Excluir o comentário de X?" | Janela de 15 min, só autor; exclusão REAL (`ExcluirObservacaoDetalhesUseCase:66`, trilha no audit_log); rótulo "Excluir · bloqueado"; `confirmarPs` vermelho "Excluir o comentário de NOME?" com data e trecho | Divergente | Prazo 60 x 15 min; rótulo "permanente" x "bloqueado"; Master/primário ausentes; exclusão real x lógica | backend (regra de exclusão, papéis); decisão do dono | P1 | [código] det:173-184, det:380, det:646-649; [inventário] backend O6 |
| PZ-DET-14 | ⋮ | Destacar / Remover destaque (popover 15 cores) | item | 1 destaque por pasta e aba; `PATCH /mensagens/:id/destaque`; auditoria; contraste WCAG | Ausente | Ausente | Sem campo nem endpoint de destaque (Farlei §3.7 pede) | backend (campo, PATCH com tenant, auditoria) | P2 | [inventário] VIS-18; dc:258-267, 4589-4606 |
| PZ-DET-15 | Cartão | "continuar lendo (N parágrafos)" / "mostrar menos" | clique | Só em demonstração com mais de 3 parágrafos | `pasta-ler-mais.js` corta em 3 parágrafos, N = total menos 3 | Fiel | | | n.a. | [código] public/js/pasta-ler-mais.js:2-6 |
| PZ-DET-16 | Trilho | Registro da pasta: Criada em, Modificada em, Criada por | renderização | Valores fixos de demonstração | Valores reais da pasta, com avatar 24 | Fiel | | | n.a. | [código] show:293 |
| PZ-DET-17 | Trilho | Ver histórico do sistema | clique; H com o ⋮ aberto | Drawer 430px com 6 eventos estáticos | Drawer `#psHistorico` com audit_log real; Esc, overlay e X fecham; foco volta | Fiel | Dia `sticky` (VIS-11); travessão no detalhe do histórico (VIS-12) | | n.a. | [código] show:309; [inventário] pasta-show.js:162-201; VIS-11, VIS-12 |
| PZ-DET-18 | Trilho | "Pontos do relatório inicial" + "Completar no relatório" | renderização; clique | 3 pontos por expressão regular, selo, pré-preenche o editor, notifica o responsável | Ausente | Ausente | Sem detecção nem notificação | backend (detecção, notificação) | P2 | [código] grep sem ocorrência |
| PZ-DET-19 | Trilho | "Como usar esta aba" | renderização | Retirado do desenho | Presente | Não aplicável | Decisão aprovada P2 (preservada) | | n.a. | [inventário] show:313-316; VIS-24 |
| PZ-DET-N03 | Detalhes | Datas escritas nos registros viram prazos | automático | `lerDatas` sobre todos os registros; entra em Próximos prazos | Inexistente | Ausente | Parser de datas e prazo manual não existem | backend (parser, entidade de prazo); cruza com a parte A (Próximos prazos) | P2 | [inventário] backend 3.1 |
| PZ-DET-N04 | Global | `window.BJDatas` (dias úteis, feriados, recesso) | montagem | Calendário com 9 feriados fixos e recesso | Inexistente na pasta (há `Feriado` no Ponto) | Ausente | Sem calendário forense | backend (calendário forense) | P2 | [inventário] backend 3.1 |
| PZ-DET-N05 | Detalhes | Aviso de limite de armazenamento do navegador | falha de `localStorage` | Aviso de 6 s | Não se aplica (grava no servidor) | Não aplicável | Simulação de demonstração sem lastro | | n.a. | [inventário] dc:6221 |
| PZ-DET-N06 | Detalhes | Contagem regressiva dos minutos | intervalo de 20 s | Re-renderiza "N min" | Minutos recalculados a cada abertura do ⋮; vencida a janela vira "bloqueado" | Parcial | Sem atualização periódica do lápis/dica fora do ⋮ | | P2 | [código] det:607-649 |
| PZ-DET-N07 | Detalhes | Versões anteriores (até 10) | sem tela | Só contagem na dica | Inexistente (só audit_log) | Ausente | Sem versões guardadas por comentário | backend (versões) | P2 | [inventário] |

### 2. Editor rico (PZ-EDT), usado em Detalhes, Registro, Financeiro e notas

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-EDT-01 | Editor | Barra: desfazer/refazer, estilos, B/I/U/S, cores, listas, recuo, alinhamento, citação, símbolo, limpar | clique | `execCommand`, estado em `selectionchange` | `editor-rico.js` (Quill) com os mesmos grupos; símbolo, data, revisão e localizar presentes | Fiel | | | n.a. | [código] editor-rico.js (grep "Inserir símbolo", "data de hoje", "Revisar", "Localizar"); [inventário] checklist PZ-EDT-01 |
| PZ-EDT-02 | Editor | Inserir link (Ctrl+K) | clique ou atalho | Popover URL e texto | Ausente (comentário: "a barra não tem link") | Ausente | Sanitizador precisa aceitar `<a>` | decisão do dono (EDT-025); sanitizador | P2 | [código] editor-rico.js:989-990 |
| PZ-EDT-03 | Editor | Imagem (botão, colar, arrastar, 7 layouts, redimensionar) | clique, Ctrl+V, soltar | Comprime e insere | Ausente (imagem colada ignorada) | Ausente | Upload de imagem no editor | backend (armazenamento, sanitizador) | P2 | [código] editor-rico.js:1109, 1461 |
| PZ-EDT-04 | Editor | Inserir tabela | clique | Grade, redimensionar coluna | Ausente | Ausente | Sanitizador | decisão do dono (EDT-027) | P2 | [código] grep "tabela" sem ocorrência |
| PZ-EDT-05 | Editor | Quebra de página, linha horizontal | clique | `<hr data-quebra>` | Ausente | Ausente | Sanitizador | decisão do dono | P2 | [código] grep sem ocorrência |
| PZ-EDT-06 | Editor | Fonte e tamanho | clique | Popover | Ausente | Ausente | Farlei §3.6 pede | decisão do dono (EDT-030) | P2 | [inventário] checklist PZ-EDT-06 |
| PZ-EDT-07 | Editor | Espaçamento de linha e parágrafo | clique | Popover | Ausente | Ausente | | decisão do dono | P2 | [código] grep sem ocorrência |
| PZ-EDT-08 | Editor | Modo documento (A4, margens, régua, cabeçalho/rodapé) | clique | `<div data-doc …>` | Ausente | Ausente | | decisão do dono | P2 | [código] grep sem ocorrência |
| PZ-EDT-09 | Editor | Ditar por voz | clique | `SpeechRecognition` do navegador | Ausente | Ausente | Envia áudio a terceiro (LGPD) | decisão do dono (EDT-033) | P2 | [inventário] checklist PZ-EDT-09 |
| PZ-EDT-N01 | Editor | Inserir data de hoje | clique | `dd/mm/aaaa` | Presente | Fiel | | | n.a. | [código] editor-rico.js (grep "data de hoje": 4) |
| PZ-EDT-N02 | Editor | Revisar texto | clique | Popover de problemas | Presente | Fiel | | | n.a. | [código] editor-rico.js (grep "Revisar": 13) |
| PZ-EDT-N03 | Editor | Localizar e substituir (Ctrl+H) | clique ou atalho | Barra com contagem, Enter, Esc | Presente | Fiel | | | n.a. | [código] editor-rico.js (grep "Localizar": 17) |
| PZ-EDT-10 | Editor | Autocorreção com Desfazer e "Você quis dizer" | digitação | Chips de 3,6 s e 6 s | Presente | Fiel | Tempos não medidos | | n.a. | [inventário] checklist PZ-EDT-10; [código] grep "Autocorre" |
| PZ-EDT-11 | Editor | Sugestão de palavras (Tab, setas, Enter, Esc) | digitação | Até 4 opções a partir de 3 letras | Presente | Fiel | | | n.a. | [inventário] checklist PZ-EDT-11 |
| PZ-EDT-12 | Editor | Autoformatação "1. ", "* ", "> " com chip | espaço | Chip com Desfazer | Presente | Fiel | | | n.a. | [inventário] checklist PZ-EDT-12 |
| PZ-EDT-13 | Editor | Colar: Manter, Mesclar, Somente texto | Ctrl+V com HTML | Chip de 7 s; recupera imagens do Word | Chip presente; imagens coladas não entram | Parcial | Imagens coladas descartadas (depende de PZ-EDT-03) | backend (imagem) | P2 | [código] editor-rico.js (grep "Mesclar": 5; :1109) |
| PZ-EDT-14 | Editor | Rascunho automático por contexto | 600 ms após digitar | `localStorage bjed-rascunho:<ctx>:<path>`; "Rascunho salvo/recuperado" | Ausente | Ausente | Rascunho por navegador é aceitável (conveniência), ou no servidor | decisão do dono (EDT-024) | P2 | [código] grep "Rascunho" sem ocorrência |
| PZ-EDT-15 | Editor | Menu do botão direito no padrão do Word | contextmenu | Mini barra, correção, Recortar/Copiar/Colar, Parágrafo, Sinônimos, Definir, Traduzir, Pesquisar, qualificação, Hiperlink, Localizar, Revisar, Selecionar tudo; Shift+botão direito nativo | Presente sem Definir, Traduzir, Pesquisar na web e Hiperlink (de propósito: mandariam texto a terceiros) | Parcial | 4 itens fora por privacidade | decisão do dono (EDT-013/025) | P2 | [código] editor-rico.js:989-990 |
| PZ-EDT-16 | Editor | Teclado: Ctrl+Enter, Tab 4 espaços, Ctrl+Y, Esc, Enviar desabilitado vazio | teclado | `bj-enviar`; modo edição com Cancelar | Presente | Fiel | | | n.a. | [inventário] checklist PZ-EDT-16; [código] det:215 |
| PZ-EDT-17 | Global | @menção em editores e campos | "@" | Até 7 pessoas + "Todos"; notifica no Chat I.A | `pasta-mencoes.js` em todos os compositores; `GET pasta_mencionaveis` (tenant + R:view); notificação pelo sino | Parcial | Sem "Todos"; destino da notificação é o sino, não o Chat; lista sem par no tema escuro (VIS-16) | Chat I.A para o destino | P2 | [inventário] tela A1, backend 2.8; VIS-16 |
| PZ-EDT-18 | Global | Citar vira `<blockquote>` | `bj-editor-inserir` | Ver PZ-DET-09 | Presente | Fiel | | | n.a. | [código] det:706 |
| PZ-EDT-N04 | Editor | Anexo de arquivo no editor | (ausente no desenho) | Não existe | Não existe | Fiel | Ambos sem anexo | | n.a. | [inventário] bj-editor.js:323-325 |

### 3. Aba Documentos (PZ-DOC, checklist, sugeridos, visor PZ-VIS)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-DOC-00 | Documentos | Moldura da aba | renderização | Um cartão, sem respiro externo | Cartão `.pex` dentro do cartão do `#documentos` (padding 18px 20px): moldura dupla | Divergente | Excluir `#documentos` da regra `.ps-paineis > .tab-pane:not(...)` | | P2 | [inventário] VIS-02 (CONFIRMADO estático; ver na tela) |
| PZ-DOC-01 | Documentos | Cabeçalho "Documentos" + contagem; rodapé | renderização | Contagem fixa "224"; rodapé com arquivos, pastas e subpastas | Contagem e rodapé reais | Fiel | | | n.a. | [inventário] expl:68 |
| PZ-DOC-02 | Documentos | Busca "Buscar arquivos e pastas…" + limpar (Esc) | digitação, Esc, × | Normaliza acentos; casa pelo fim do nome; realce `<mark>` | Filtro no navegador; Esc limpa; busca em toda a árvore, não só no nível aberto | Parcial | Escopo da busca diverge (global x nível); realce a conferir na tela | | P2 | [código] pex.js:1669; [inventário] checklist PZ-DOC-02 (DOC-125 C) |
| PZ-DOC-03 | Documentos | "Nova pasta" e "Anexar" | clique | Botões INERTES (sem `onClick`) | Nova pasta: `POST pasta_secao_criar` (R:edit, CSRF `pasta_secao_criar_{id}`); Anexar: upload múltiplo com progresso, `POST pasta_peticionar_upload` (R:edit, CSRF `peticionar_upload_{id}`); some sem `podeEditar` | Fiel | Desenho com defeito (botões inertes, ver Defeitos 7); BlueJus mantém a função real | | n.a. | [inventário] tela A8, backend 2.7 |
| PZ-DOC-03S | Documentos | Aviso "Arquivo idêntico já existe no escritório." persistente [SÓ-BLUEJUS] | upload duplicado | Não existe | Aviso que sobrevive à recarga (sessionStorage) | Não aplicável | [SÓ-BLUEJUS] a preservar | | n.a. | [inventário] show:2150-2320 |
| PZ-DOC-04 | Documentos | Organizar: ordem das colunas, Classificar por, Tipo de documento, Restaurar padrão, selo | clique | Colunas em `bj-docs-colunas`; filtro e ordem só no estado | Mesmo conjunto + "Manual" e coluna Categoria; preferências por navegador; item "Lixeira (N)" a mais | Fiel | Lixeira é extra real (ver PZ-DOC-23) | | n.a. | [inventário] expl:86-140 |
| PZ-DOC-05 | Documentos | Visualizar: 8 modos + Painel de detalhes | clique | Estado não persiste | 8 modos + painel | Fiel | | | n.a. | [inventário] expl:142-165 |
| PZ-DOC-06 | Checklist | Faixa, ok/total, Sugerir, editar, adicionar (Enter/Esc), interruptor com motivo (4 motivos, "Atualizar o checklist", "Manter ativo", "Desativar"), "Reativar", marcar item | clique, Enter, Esc | `localStorage bj-ck-cfg-v1`; notifica Controladoria e lembrete de 30 dias | Rotas `pasta_checklist_adicionar/toggle/editar/excluir/reordenar` (R:edit, CSRF) e `pasta_checklist_estado` (tenant + R:edit, motivo obrigatório); painel de motivo com "Atualizar o checklist"/"Manter ativo"; "Reativar"; Modelos [SÓ-BLUEJUS]; excluir item pede confirmação | Parcial | Notificação à Controladoria e lembrete de 30 dias ausentes (ver PZ-DOC-07) | backend (notificação, agendador) | P2 | [código] _documentos_checklist.html.twig:63-232, pasta-checklist.js:752-778; [inventário] backend 2.6 |
| PZ-DOC-06S | Checklist | Modelos de checklist do escritório (listar, salvar, aplicar, renomear, excluir) [SÓ-BLUEJUS] | clique | Não existe | `pasta_checklist_modelo_*` com R:edit DA PASTA ATUAL (`negar`), `buscarDoTenant`, CSRF `checklist_modelo_{id}`; confirmações "Substituir modelo" e "Excluir o modelo X?" | Não aplicável | [SÓ-BLUEJUS] a preservar; ver Achados P0 A2 (modelo do escritório alterável com permissão de uma pasta) | decisão do dono (quem administra modelos) | n.a. | [código] src/Pasta/Controller/PastaChecklistModeloController.php:137-198 |
| PZ-DOC-07 | Checklist | Selo "cobrado Nx", cobrança automática, aviso à Controladoria | automático | Plano diário em dias úteis; vermelho a partir de 3 | Ausente | Ausente | Rotina agendada e Chat | backend (agendador, notificações) | P2 | [inventário] checklist PZ-DOC-07 |
| PZ-DOC-08 | Sugeridos | Painel de análise documental (contexto, chips, grupos, Origem, "+ Checklist", duplicados, organização, "Adicionar N faltantes") | clique | `BJDocSug.analisar` com contexto do `BJProcesso` | Painel por catálogo e regras (`SugestorDeDocumentos`, `DeterminacoesDoJuizo`); adicionar ao checklist por `fetch`; Atualizar recarrega a aba | Fiel | Motor é de regras, não IA (ver PZ-DOC-09) | | n.a. | [inventário] tela A8, backend 2.7 |
| PZ-DOC-09 | Sugeridos | Camada de IA e leitura da capa do PDF | automático | `refinarIA` com `window.claude.complete` | Ausente; texto declara "o conteúdo do processo não é lido" | Ausente | IA e leitura de PDF | backend (IA, OCR) | P2 | [inventário] tela A8 |
| PZ-DOC-10 | Documentos | "Mostrando somente <tipo>" + × | clique | Remove o filtro | Igual | Fiel | | | n.a. | [inventário] expl:193 |
| PZ-DOC-11 | Documentos | Barra de seleção (Baixar/.zip, Copiar, Recortar, Renomear, Selecionar tudo, Excluir, Limpar seleção Esc) | clique | Mesmas ações do menu | Igual; rótulo "Baixar N itens (.zip)"/"Baixar"/"Baixar (.zip)"; ZIP por `POST pasta_documentos_zip` (lote com R:view, CSRF `pex_lote_{id}`) | Fiel | | | n.a. | [código] pex.js:1428 |
| PZ-DOC-12 | Documentos | Trilha com ↑ e migalhas (Backspace, Alt+←, Alt+↑) | clique, teclado | Volta um nível | Igual; Backspace ignorado dentro do checklist | Fiel | | | n.a. | [código] pex.js:1641-1655 |
| PZ-DOC-13 | Documentos | Faixa "Ganhe espaço" (`bi-stars`) e exclusão sugerida | clique | Hash SHA-256 na sessão; `cf` vermelha "Excluir N arquivo(s) sugerido(s)? Você pode desfazer logo em seguida." | Faixa "Sugestões de limpeza por regras" (`bi-hdd`), mesma confirmação, exclusão em lote para a lixeira | Parcial | Título e ícone diferentes do desenho | | P2 | [código] _documentos_explorador.html.twig:246-250; [inventário] checklist PZ-DOC-13 (DOC-104 C) |
| PZ-DOC-14 | Lista | Seleção (clique, Ctrl, Shift, laço), arrastar para pasta, estrela de favorito, colunas | mouse | Favoritos em `localStorage` por usuário e pasta; sobem ao topo | Favoritos no servidor (`POST pasta_documentos_favorito`, tenant + R:view, CSRF `pex_favorito_{id}`); mover por `pasta_documentos_mover_lote`/`pasta_documento_mover_secao` (R:edit); ordem manual | Fiel | Ordem dos favoritos no topo a conferir na tela | | n.a. | [código] _documentos_explorador.html.twig:29-30; [inventário] backend 2.7 |
| PZ-DOC-15 | Botão direito no item | Abrir, Visualizar, Baixar/.zip, Compartilhar link, Recortar, Copiar, Colar (pasta), Copiar caminho, Encaminhar via Chat I.A, Favoritar, Renomear, Excluir, Propriedades | contextmenu | Seleciona o item | Abrir, Visualizar, Baixar/.zip, Copiar link, Recortar, Copiar, Colar (pasta), Mover para…, Copiar caminho, Favoritar, Renomear, Editar…, Excluir, Propriedades | Parcial | Falta Encaminhar (PZ-DOC-17); "Compartilhar" virou "Copiar link" (PZ-DOC-16); Mover para… e Editar… são extras reais | Chat I.A | P2 | [código] pex.js:1973-2001 |
| PZ-DOC-16 | Botão direito | "Compartilhar link(s)" | item | Copia `/pastas/<n>/documentos/<nome>`; aviso "Link copiado" | "Copiar link(s)" com link autenticado (`?pex=`) | Divergente | Rótulo diferente do desenho (desvio sem registro de pergunta) | decisão do dono (rótulo) | P2 | [código] pex.js:1957, 1977, 1981 |
| PZ-DOC-17 | Botão direito | Encaminhar via Chat I.A | item | `despachar` | Ausente (comentário: item não renderizado) | Ausente | Chat I.A | Chat I.A | P2 | [código] pex.js:1918 |
| PZ-DOC-18 | Botão direito com vários | Baixar .zip (N), Compartilhar links, Recortar, Copiar, Copiar caminhos, Excluir N itens, Propriedades | contextmenu | | Igual + Mover para… e Favoritar; "Copiar links" | Fiel | Mesma troca de rótulo de PZ-DOC-16 | | n.a. | [código] pex.js:1957-1968 |
| PZ-DOC-19 | Botão direito no fundo | Nova pasta, Colar, Selecionar tudo, Classificar por nome/data/tamanho/tipo, Mostrar/Ocultar painel | contextmenu | Nova pasta não persiste | Igual (Nova pasta persiste no servidor) + "Lixeira (N)" para quem pode ver | Fiel | | | n.a. | [código] pex.js:1938-1952 |
| PZ-DOC-20 | Documentos | Aviso de 4,2 s com Desfazer | após excluir, mover, copiar, colar | Desfazer só restaura a lista de excluídos | `TOAST_MS = 4200`; Desfazer restaura da lixeira (`pasta_documentos_restaurar`); erro sem toast cai em `alert()` | Fiel | `alert()` de reserva contra PZ-MOD-26 (ver PZ-MOD-26) | | n.a. | [código] pex.js:247, 2111-2128 |
| PZ-DOC-21 | Documentos | Painel de propriedades | seleção | Tipo, Categoria, Tamanho, Modificado | Igual + Adicionado em, Enviado por, Descrição, Nome parecido, Local | Fiel | Extras reais | | n.a. | [inventário] pex.js:1070-1105 |
| PZ-DOC-22 | Documentos | Atalhos (Ctrl+A, Del, F2, Enter, Espaço, Esc, Backspace, Alt+←/↑, Ctrl+C/X/V, setas com Shift) | keydown | Ignora em renomeação ou input | Igual + Home/End; escrita desligada para quem só lê | Fiel | | | n.a. | [código] pex.js:1641-1669, 2075; [inventário] pex.js:1647-1720 |
| PZ-DOC-23 | Documentos | Lixeira (N) [SÓ-BLUEJUS] | Organizar ou fundo | Não existe | `GET pasta_documentos_lixeira` (R:edit) e restaurar | Não aplicável | [SÓ-BLUEJUS] a preservar (desenho omisso) | | n.a. | [código] pex.js:1949-1952 |
| PZ-DOC-24 | Documentos | Escrita bloqueada para quem só lê | captura global | Por texto do botão, com cadeado "ver" | Escrita escondida no servidor (`explorador.podeEditar = !lápide && podeEditarPasta`) e recusada nas rotas (R:edit) | Fiel | Desenho usa cadeado por colaborador (ver PZ-MOD-12) | | n.a. | [inventário] backend 1 (PC:389-390) |
| PZ-DOC-25 | Documentos | Vazio de subpasta | renderização | "Pasta vazia. Arraste arquivos para cá ou use Colar (Ctrl+V)." | Igual + `<noscript>` com a lista | Fiel | | | n.a. | [inventário] expl:350-358 |
| PZ-DOC-N01 | Lista | Renomear no lugar (F2) | F2, menu, barra | Nome só no estado | Renomear inline (Enter/Esc) persistido (`pasta_documento_edit` R:edit / `pasta_secao_renomear`) | Fiel | | | n.a. | [código] pex.js:1995 |
| PZ-DOC-N02 | Lista | Mover (arrastar ou Recortar + Colar) | soltar | Único efeito persistido no `localStorage`; Desfazer duplica | Mover no servidor (lote com posse de todos os ids, R:edit, CSRF `pex_lote_{id}`) | Fiel | Defeito do desenho no Desfazer não reproduzido (ver Defeitos 4) | | n.a. | [inventário] backend 2.7 |
| PZ-DOC-N03 | Lista | Copiar + Colar | Ctrl+C/V, menu | Cópia só no estado | `pasta_documentos_copiar` (R:edit, destino da mesma pasta); Copiar desabilitado só com pastas | Fiel | | | n.a. | [código] pex.js:2008 |
| PZ-DOC-N04 | Lista | Baixar (1 arquivo ou .zip) | menu, barra | ZIP montado no navegador | Download por rota; ZIP no servidor com vagas por escritório | Fiel | | | n.a. | [inventário] backend 2.7 |
| PZ-DOC-N05 | Lista | Documentos do Peticionar na raiz | automático | Lê `bj-pasta-docs-v1` | Peças do Peticionar são `PastaDocumento` e aparecem no explorador | Fiel | | | n.a. | [inventário] backend 2.7 |
| PZ-DOC-N06 | Lista | Persistência efêmera do protótipo | recarregar | Excluir, renomear, nova pasta e colar se perdem | Tudo persistido no servidor | Não aplicável | Simulação de demonstração sem lastro | | n.a. | [inventário] dc:2932-2934 |
| PZ-DOC-EXC | Lista | Excluir item | Del, menu, barra | SEM confirmação; Desfazer no aviso | `confirmarUIPs` vermelho "Excluir "NOME"?" (pasta: "e todo o conteúdo dela?") + lixeira + Desfazer | Divergente | Desenho omite a confirmação; BlueJus confirma (mais seguro) | decisão do dono (recomendação: manter a confirmação) | P2 | [inventário] tela A8 (pex.js:1970-2002, 2545-2590); dc:5031 |
| PZ-VIS-01 | Visor | Tela cheia, cabeçalho, "i de N", ← →, Esc | duplo clique, Enter, Espaço, Visualizar | Navega na lista filtrada | `#pexVisor` com "i de N", ←/→, Esc | Fiel | | | n.a. | [código] public/js/pasta-explorador-visor.js:168 |
| PZ-VIS-02 | Visor | Zoom de 50 a 200%, passo 10 | clique | Grupos suportados pelo `BJVisor` | `ZOOM_MIN 50`, `ZOOM_MAX 200`, passo 10, 12 tipos | Fiel | | | n.a. | [código] pasta-explorador-visor.js:72-75 |
| PZ-VIS-03 | Visor | Baixar, Imprimir com tarja, Abrir em nova aba | clique | Tarja "BlueJus · Pasta N · nome · impresso em …" | Igual (tarja 9pt cinza) | Fiel | | | n.a. | [código] pasta-explorador-visor.js:85-86, 238-240 |
| PZ-VIS-04 | Visor | Formatos no navegador | render | pdf.js, mammoth, SheetJS, JSZip, UTIF | PDF, imagem, DOCX, planilha, ODT, RTF, PPTX, EML, ZIP, TIFF, P7S | Fiel | DOC formatado, PPT, MSG, RAR/7Z ausentes nos dois (exigem servidor) | backend (conversão) para os ausentes | n.a. | [inventário] checklist PZ-VIS-04/05 |
| PZ-VIS-06 | Visor | Soltar arquivo no visor | drop | Insere depois do atual e abre | `drop` bloqueado (`dropEffect = 'none'`) | Ausente | Upload já existe; falta ligar o soltar ao upload | | P2 | [código] pasta-explorador-visor.js:438-439 |
| PZ-VIS-07 | Visor | Aviso do visor | ações | Toast de 2,6 s | Toast do visor | Fiel | | | n.a. | [inventário] checklist PZ-VIS-07 |

### 4. Aba Push processual (PZ-PUSH)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-PUSH-01 | Faixa | "Movimentações recebidas" + contagem | renderização | Total do principal e apensos | `push.total` | Fiel | | | n.a. | [inventário] push:29-32; VIS 1.12 SIM |
| PZ-PUSH-S01 | Faixa | Link "ver no módulo" [SÓ-BLUEJUS] | clique | Não existe | `push_processual_index?busca=` com `can_access_module('djen')` | Não aplicável | [SÓ-BLUEJUS] a preservar | | n.a. | [inventário] push:38-41 |
| PZ-PUSH-02 | Faixa | "Resumir com IA" / "Gerar nova análise" / "Analisando…" | clique | Prompt com regras, JSON `{resumo, pontos, quem}`, etapas a cada 1,3 s | `POST inteligencia_push_solicitar` (tenant + R:view + M:inteligencia, CSRF `inteligencia_push_{id}`); polling; indisponível desabilitado com motivo | Fiel | | | n.a. | [código] push:54; [inventário] backend 2.8 |
| PZ-PUSH-03 | Faixa | Filtros Todas · Novas · Geram prazo | clique | "Novas" = não lidas E datas fixas de demonstração; "Geram prazo" = Intimação ou Decisão | "Novas" = não lidas (dado real); "Geram prazo" pela regra fixa do desenho | Fiel | Regra de data fixa do desenho é simulação (ver Defeitos 9) | | n.a. | [código] push:84-85 |
| PZ-PUSH-04 | Faixa | Filtros por processo (Todos, Principal, apensos) | clique | Só com apenso | Ausente | Ausente | Possível já para processos vinculados; tipo de apenso não existe | backend (apenso) só para o rótulo de apenso | P2 | [inventário] tela A9; backend 2.3 |
| PZ-PUSH-05 | Análise IA | Cartão "Análise IA" (aviso, editado, lido, cadeado, recolher, pontos, "Quem deve agir", "Base") | clique | Cadeado alterna `privada`; recolher em `localStorage` | Cartão com "Gerado por IA · não é movimentação oficial"; cadeado "Interna do escritório" (`inteligencia_analise_interna`); recolher (`inteligencia_analise_recolher`); "Quem deve agir"; ações com R:view + M:inteligencia | Parcial | Sem "editado" (não há edição, PZ-PUSH-06); dica do cadeado cita "link compartilhado com o cliente", que não existe (PZ-PUSH-N05) | backend (link externo) para o texto fazer sentido | P2 | [código] ia-push:63, 69-72 |
| PZ-PUSH-06 | ⋮ análise | Editar (resumo, pontos, nota "Conteúdo originalmente gerado por IA…") | menu | Salva `editado:true` | Ausente | Ausente | Endpoint de edição da análise | backend (edição + auditoria) | P2 | [inventário] checklist PZ-PUSH-06 |
| PZ-PUSH-07 | ⋮ análise | Marcar como lido / não lido | menu | Alterna `lido` (82% de opacidade) | `inteligencia_analise_lida` / `_nao_lida` | Fiel | | | n.a. | [inventário] tela A9; backend 2.8 |
| PZ-PUSH-08 | ⋮ análise | Criar tarefa da providência | menu | Nova meta com 1º ponto (90 caracteres), prazo +7 | Abre `#modalCriarTarefa` pré-preenchido, só com R:edit e pasta viva (`iaPodeCriarTarefa`) | Fiel | | | n.a. | [código] ia-push:34, 107 |
| PZ-PUSH-09 | ⋮ análise | Excluir análise | menu | `cf` vermelha "Excluir esta análise da IA? As movimentações oficiais não são alteradas." | Mesmo texto (`data-ia-confirmar`); `ExcluirAnaliseUseCase` faz exclusão LÓGICA escopada a tenant e pasta; exige só R:view + M:inteligencia; botão visível para quem só vê | Parcial | Quem só vê a pasta exclui (oculta para todos) a análise; ver Achados P0 A3 | decisão do dono (R:view x R:edit) | P1 | [código] ia-push:119-120, 164-165; src/Inteligencia/Controller/AnalisePushController.php:259-271, 313-330; src/Inteligencia/UseCase/ExcluirAnaliseUseCase.php:26-35 |
| PZ-PUSH-10 | Análise IA | "Enviar ao cliente" / "Reenviar" + histórico | clique | Abre PZ-PUSH-33 ou formulário de e-mail | Ausente | Ausente | E-mail transacional, cadastro de e-mails do cliente | backend (e-mail, cadastro) | P1 | [código] ia-push:11 (comentário) |
| PZ-PUSH-11 | Cartão | Pílula do dia e ponto colorido com dica | hover | Juízo, polo ativo, polo passivo, cartório, sem documento | Pílula do dia e ponto juízo/cartório com dica | Parcial | Sem distinção de polo ativo/passivo | backend (polo da parte) | P2 | [inventário] tela A9; checklist PZ-PUSH-11 |
| PZ-PUSH-12 | Cartão | Título, selo "NOVO", megafone | renderização | | Assunto com `bi-megaphone-fill`, "Novo", tipo, "TRIBUNAL · DJEN"; acordeão carrega o teor (`GET pasta_push_teor`) | Fiel | Arranjo acordeão (BlueJus) x ações à vista (desenho): ver PZ-PUSH-13/15 | | n.a. | [inventário] push:206-230 |
| PZ-PUSH-13 | Cartão | "ID - Tipo" com Abrir documento, Copiar ID, Abrir no PJe | clique | À vista no cartão | Copiar ID e Abrir no PJe DENTRO do teor (acordeão) | Divergente | Posição diferente do desenho | | P2 | [código] teor:59, 65 |
| PZ-PUSH-14 | Cartão | Encaminhar (`bi-send`) | clique | `despachar` | Ausente | Ausente | Chat I.A | Chat I.A | P2 | [código] push:265-267 (comentário) |
| PZ-PUSH-15 | Cartão | Ações à vista (Criar tarefa, Encaminhar, Marcar como lida, hora) | clique | À vista | Dentro do teor | Divergente | Posição diferente; Encaminhar ausente | Chat I.A para Encaminhar | P2 | [código] teor:82, 90 |
| PZ-PUSH-16 | Cartão | "Criar tarefa" em 1 clique / "Tarefa criada" | clique | Cria meta direto ("Providência: tipo de data"), marca lida; estado não persiste | Abre o modal Nova meta pré-preenchido (tipo, data, órgão, processo, documento, link) | Divergente | 1 clique x modal; sem "Tarefa já criada" | backend (vínculo publicação x meta) para "já criada" | P2 | [código] teor:15-16, 82 |
| PZ-PUSH-17 | Cartão | "Marcar como lida" / "Lida" | clique | `pushLidas` no estado (por sessão) | `POST pasta_push_lida` (tenant + R:view, CSRF `pasta_push_lida_{id}`, publicação do tenant e do número da pasta); a flag é da PUBLICAÇÃO, vale para todo o escritório; alterna "Marcar como lida"/"Marcar como não lida" | Parcial | Leitura por escritório, não por usuário (desenho aponta "leitura por usuário"); rótulo "Lida" do desenho x "Marcar como não lida" | decisão do dono (leitura por usuário exigiria backend) | P2 | [código] src/Pasta/Controller/PastaPushProcessualController.php:203-231; teor:90; pasta-push.js:167 |
| PZ-PUSH-18 | Notas | Notas técnicas penduradas sob o cartão | renderização | `localStorage bj-push-notas-v1` | Notas técnicas do processo penduradas na movimentação; somem se vazias | Fiel | | | n.a. | [inventário] push:244-255 |
| PZ-PUSH-19 | Notas | Editor de nota ("Explique o despacho…"), Salvar/Cancelar, Ctrl+Enter, Esc | clique, teclado | Versões; auditoria | `processo_nota_tecnica_criar/editar` (pasta: tenant + `temProcesso` + R:edit; CSRF), Ctrl+Enter/Esc; Auditavel | Fiel | Sem versões guardadas (só audit_log) | | n.a. | [inventário] backend 2.3; nota-tecnica.js:552 |
| PZ-PUSH-20 | Notas | ⋮ da nota: Responder, Copiar, Encaminhar, Editar/bloqueado, Excluir/bloqueado; `cf` "Excluir esta nota técnica?" | ⋮ ou botão direito | Autor 15 min, primário sem limite | Autor 15 min (`JanelaDeEdicaoDeComentario`); sem Encaminhar; exclusão real com audit | Parcial | Sem exceção do primário; sem Encaminhar | backend (papel primário); Chat I.A | P2 | [código] src/Processo/UseCase/EditarNotaTecnicaUseCase.php:25-61, ExcluirNotaTecnicaUseCase.php:22-50 |
| PZ-PUSH-21 | Botão direito no cartão | Adicionar nota técnica | contextmenu | Abre o editor com foco | Item do `#pushMenuContexto`, só com `pushPodeEscrever` | Fiel | | | n.a. | [código] push:271-274 |
| PZ-PUSH-22 | Botão direito no cartão | Criar tarefa / Marcar como lida / Abrir documento | contextmenu | Inclui "Tarefa já criada", "Já lida" | Criar tarefa (escrita), Marcar como lida/não lida (viva), Abrir documento | Fiel | Sem estados "Tarefa já criada"/"Já lida" (ver PZ-PUSH-16) | | n.a. | [código] push:276-288 |
| PZ-PUSH-23 | Botão direito no cartão | Encaminhar via Chat I.A | contextmenu | `despachar` | Ausente | Ausente | Chat I.A | Chat I.A | P2 | [código] push:265-267 |
| PZ-PUSH-24 | Botão direito no cartão | Copiar texto | contextmenu | Aviso "Texto copiado" | `GET pasta_push_texto` | Fiel | | | n.a. | [código] push:290-292 |
| PZ-PUSH-N01 | Linha do tempo | Itens vindos do PDF do processo | após leitura | Fonte "PDF do processo · pág." | Ausente | Ausente | Leitura de PDF | backend (leitura, OCR) | P2 | [inventário] |
| PZ-PUSH-25 | Aba | Textos dos vazios | renderização | "Vincule um processo na aba Processo…", "Nenhuma movimentação neste filtro.", "Nenhuma movimentação recebida ainda." | Filtro vazio igual; sem processo e sem publicação com textos próprios | Parcial | 2 dos 3 textos diferem | | P2 | [código] push:296; [inventário] push:107-120 |
| PZ-PUSH-26 | Aba | "Exibindo as N mais recentes" + "Ver todas no módulo" [SÓ-BLUEJUS] | teto de 100 | Ausente no desenho online | Presente com teto real | Não aplicável | [SÓ-BLUEJUS] a preservar (limite técnico real) | | n.a. | [código] push:297-305 |
| PZ-PUSH-S03 | Aba | Selo e traço de pendência acompanham as linhas novas [SÓ-BLUEJUS na mecânica] | MutationObserver | Traço vermelho "N movimentação(ões) nova(s) sem leitura" (PZ-PUSH-N03) | Selo da aba acompanha | Parcial | Texto do traço do desenho a conferir na tela | | P2 | [inventário] pasta-push.js |
| PZ-PUSH-27 | Trilho | Monitoramento (ponto pulsante, interruptor, principal, apensos, última verificação) | clique | `pushOff` só no estado | A aba Push não tem trilho | Ausente | Robô de captura e preferência persistida | backend (captura, preferência) | P1 | [código] push:126 (comentário) |
| PZ-PUSH-28 | Trilho | Buscar agora, Cancelar, "Atualizar com arquivos do processo", soltar | clique, drop | Leitura em 12 etapas, ZIP, PDF, OCR simulado | Ausente | Ausente | Captura sob demanda e leitura no servidor | backend (captura PJe, OCR) | P1 | [inventário] tela A9 |
| PZ-PUSH-29 | Trilho | Previsão (próximo ato, data, confiança, "Ver análise completa") | renderização | Usa `BJProcesso` | Ausente | Ausente | Motor processual | backend (motor processual, IA) | P2 | [inventário] |
| PZ-PUSH-30 | Trilho | Modal "Análise do processo" (Fato, Inferência, Previsão) | Ver análise completa | 16 seções | Ausente | Ausente | Idem | backend | P2 | [inventário] |
| PZ-PUSH-N02 | Trilho | Cartão "Situação atual" | monitoramento ativo | Regras CPC, dias úteis | Ausente | Ausente | Motor processual e calendário | backend | P2 | [inventário] |
| PZ-PUSH-31 | Trilho | "Avisar quando chegar" (responsável, e-mail, IA) | clique | Só alterna estado, sem efeito | Ausente | Ausente | Notificações configuráveis | backend (notificações) | P2 | [inventário] |
| PZ-PUSH-32 | Trilho | "Avisar cliente" (até 5 e-mails, principal, pausar, cópia Controladoria "Sempre", envio automático, histórico); `cf` "Remover <e-mail> dos avisos ao cliente?" | clique, Enter/Esc | `localStorage bj-cli-emails-v1` | Ausente | Ausente | Cadastro de e-mails e envio | backend (e-mail transacional, CC obrigatório) | P1 | [inventário] |
| PZ-PUSH-33 | Trilho | Modal "Enviar ao cliente" (Para, CC travado, Assunto, Mensagem, "Revisei e corrigi…") | Enviar ao cliente | `BJMail.enviar` ou simula 700 ms | Ausente | Ausente | Serviço de e-mail com auditoria | backend | P1 | [inventário] |
| PZ-PUSH-N03 | Aba | Traço vermelho da aba Push | renderização | "N movimentação(ões) nova(s) sem leitura" | Ver PZ-PUSH-S03 | Parcial | Ver PZ-PUSH-S03 | | P2 | [inventário] |
| PZ-PUSH-N04 | Aba | Prazos do Push em "Próximos prazos" | automático | Origem "Push processual (estimado)" | Ausente (`DeterminacoesDoJuizo` não grava nem conta dias) | Ausente | Motor de prazos | backend; cruza com a parte A | P2 | [inventário] backend 3.1 |
| PZ-PUSH-N05 | Aba | Link externo do Push (gerar, validade, código, revogar, acessos); `cf` azul "Revogar este link?…" | botão direito na aba; ⋮ da pasta | Página pública e tokens em `localStorage` | Ausente | Ausente | Página pública com token, revogação, registro de acessos | backend (tokens, página pública, auditoria); decisão do dono | P2 | [inventário] checklist PZ-MOD-13/14 |

### 5. BLUEJUS Intelligence e Chat I.A / Central (PZ-IA, só o que a pasta usa)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-IA-01 | Drawer | Abrir/fechar "BLUEJUS IA · Assistente jurídica do escritório" | botão; × ou fundo | Sem Esc; cadeado "oculto" bloqueia | Abre com `GET inteligencia_agentes_painel`; X, fundo e Esc fecham; só com `can_access_module('inteligencia')` | Fiel | Esc é extra (desenho omisso no teclado) | | n.a. | [código] templates/inteligencia/_agentes_drawer.html.twig:35-36; pasta-ia-agentes.js:119 |
| PZ-IA-01V | Drawer | Camada do drawer | abertura | Drawer de altura total, cabeçalho e X visíveis | `.ia-drawer` z 96 e fundo z 95 com `top: 0`, abaixo da navbar fixa z 1050: cabeçalho e X provavelmente cobertos | Divergente | Subir z-index (o Histórico usa 1056) ou descontar a navbar, em `pasta-ia-agentes.css` (não é arquivo global) | | P1 | [código] public/css/pasta-ia-agentes.css (bloco do drawer: z 95/96, top 0), public/css/app.css:110-116; VIS-01 (efeito PRECISA MEDIR NA TELA) |
| PZ-IA-02 | Drawer | Chips de contexto | renderização | Dica "Contexto carregado automaticamente desta tela" | Igual | Fiel | | | n.a. | [código] _agentes_drawer.html.twig:42 |
| PZ-IA-03 | Drawer | "O que a IA sabe desta pasta" (5 contadores) | renderização | Documentos, tarefas, prazos, anotações, fontes | 4 contadores, sem "fontes" | Parcial | Falta o contador "fontes" | backend | P2 | [código] templates/inteligencia/_agentes_painel.html.twig:19-24; VIS-27 |
| PZ-IA-04 | Drawer | Abas Análise, Documentos, Relatórios, Perguntar | clique | Trocar limpa o resultado | Só a lista de agentes | Divergente | Estrutura por abas ausente | backend (IA) | P1 | [inventário] VIS-27 |
| PZ-IA-05 | Análise | Fase identificada, "Fase errada?", Inconsistência, "Sugerir atualização" | clique | Confirmação interna; envio para aprovação | Ausente | Ausente | Depende de fases (P3) e fluxo de aprovação | decisão aprovada P3; backend | P2 | [inventário] |
| PZ-IA-06 | Análise | Providências marcáveis + "Criar N tarefa(s) em Metas" | clique | Confirmação interna; cria metas (prazo +3) | Ausente | Ausente | IA estruturada + criação em lote de metas | backend (IA) | P2 | [inventário] |
| PZ-IA-07 | Análise | Situação da pasta + "Como cheguei a esta conclusão?" | clique | Lista de fontes | Ausente | Ausente | | backend (IA) | P2 | [inventário] |
| PZ-IA-08 | Análise | Achados e fontes | renderização | | Ausente | Ausente | | backend (IA) | P2 | [inventário] |
| PZ-IA-09 | Análise | Memória de aprendizado | renderização | Só da sessão | Ausente | Ausente | Memória persistente | backend | P2 | [inventário] |
| PZ-IA-10 | Análise | 10 comandos (agentes Gestor, Processual, Documental, Prazos, Relatórios, Cliente, Jurídico) | clique | `iaOrquestrar`; fallback local com rodapé | 7 agentes (`ia_agentes()`), "Gerar análise" com `POST inteligencia_agente_solicitar` (tenant + R:view + M:inteligencia, CSRF `inteligencia_agentes_{id}`); polling honesto | Parcial | 7 agentes x 10 comandos | backend (IA) | P2 | [inventário] tela A10, backend 2.8 |
| PZ-IA-11 | Resultado | Copiar, Baixar .txt, Imprimir | clique | Auditoria local | Copiar, Baixar .txt (`inteligencia_analise_txt`), Imprimir; baixar e imprimir registram (`inteligencia_analise_registro`) | Fiel | | | n.a. | [código] templates/inteligencia/_analises_agente.html.twig:16-19, 62-65 |
| PZ-IA-S01 | Resultado | ⋮ do cartão: lida/não lida, interna, Criar tarefa, Excluir [SÓ-BLUEJUS na forma] | clique | Não existe no drawer | XHR com recarga; Excluir com `cfUI` vermelho | Não aplicável | [SÓ-BLUEJUS] a preservar; Excluir com R:view (ver Achados P0 A3) | | n.a. | [inventário] pasta-ia-agentes.js:400-450 |
| PZ-IA-12 | Resultado | Criar tarefa, Criar prazo, Enviar para revisão | clique | Só confirmam e avisam (não criam nada) | Ausente (Criar tarefa existe pelo ⋮) | Não aplicável | Defeito do desenho: botões simulam sucesso sem efeito (ver Defeitos 10); comportamento correto exige backend | backend (prazo, revisão) | n.a. | [inventário] dc:1103-1105, 5980-5993 |
| PZ-IA-13 | Perguntar | "Pergunte sobre esta pasta…" + 4 sugestões | Enter; clique | Últimas 8 mensagens; fallback local | Ausente | Ausente | Conversa com IA sobre a pasta | backend (IA) | P2 | [inventário] |
| PZ-IA-14 | Documentos | Ler N documentos, Índice inteligente | clique | Agente Documental | Ausente | Ausente | OCR e IA | backend | P2 | [inventário] |
| PZ-IA-N01 | Relatórios | 6 modelos de relatório | clique | Resumo para o cliente em linguagem leiga | Ausente | Ausente | | backend (IA) | P2 | [inventário] |
| PZ-IA-15 | Drawer | "Registro de auditoria (N)" | clique | Últimas 50 da sessão (estado) | `GET inteligencia_auditoria_da_pasta`, persistente, com downloads/impressões | Fiel | | | n.a. | [código] templates/inteligencia/_auditoria.html.twig:30; _agentes_drawer.html.twig:59 |
| PZ-IA-N02 | Agente | `bj-agente.js` "Assistente de pastas" | não carregado pelo 02 | Só na Lista 03 | Fora da pasta | Não aplicável | Não carregado pelo protótipo da pasta (pertence à Lista do Expediente) | | n.a. | [inventário] dc grep |
| PZ-IA-N03 | Agente | Ferramenta `prazos_pastas` | idem | Lê `bj-prazos-pasta-v1` | Fora da pasta | Não aplicável | Idem; contrato entre telas exigiria endpoint de prazos agregados | backend | n.a. | [inventário] |
| PZ-IA-N04 | Agente | Lançador que esmaece, `bj-ai-fx` | idem | | Fora da pasta | Não aplicável | Idem | | n.a. | [inventário] |
| PZ-IA-N05 | Pasta para agente | `pzPublicar` grava prazos e atividade agregados | 1,2 s após montar | `localStorage` | Inexistente | Não aplicável | Simulação de contrato entre telas via `localStorage`; o correto é endpoint de leitura | backend (endpoint de prazos agregados) | n.a. | [inventário] dc:6412-6424 |
| PZ-IA-16 | Chat | Botão flutuante, selos, janela "Central de Comunicação" | central | `bluejus-central.js` | Ausente na pasta | Ausente | Central inteira | backend (Chat, tempo real) | P2 | [inventário] checklist PZ-IA-16 a 28 |
| PZ-IA-25 | Chat | Comandos "/" (302 em 49 grupos na contagem estática) | digitação | Catálogo `COMANDOS_IA` | Ausente | Ausente | | backend | P2 | [inventário] |
| PZ-IA-29 | Chat | Destino de todo "Encaminhar via Chat I.A" (despacho com prioridade, destinatário, instrução) | `despachar` | Envia "URGENTE · Despacho…"; auditoria | Ausente | Ausente | Bloqueia PZ-DET-05/08, DOC-17, PUSH-14/20/23 | backend (Chat) | P2 | [inventário] |
| PZ-IA-N06 | Chat | Contexto da pasta exposto ao Chat (`__bjContexto`, `__bjIAFontes` com cadeado…) | ganchos globais | API de contexto no navegador | Ausente | Ausente | API de contexto filtrada por permissão | backend | P2 | [inventário] |
| PZ-IA-N07 | Chat | Navegação vinda do Chat (`bj-navegar`) | evento | Troca pasta, aba e meta | Parcial equivalente: `#aba` e `#pasta-msg-N` na URL abrem aba e registro [SÓ-BLUEJUS] | Parcial | Sem barramento de eventos do Chat | Chat | P2 | [inventário] pasta-show.js:558-602 |
| PZ-IA-31 | Chat | Alertas de meta e prazo | sino da meta; "Alertar <nome>" | `BJCentral.alertar` | `tarefa_alertar` (R:edit) com notificação no sino do topo | Parcial | Destino sino x Chat | Chat | P2 | [inventário] checklist PZ-IA-31; backend 2.4 |
| PZ-IA-N08 | Relatórios | Relatórios mensais à Controladoria (pastas paradas, sem processo) | 1ª segunda do mês, 10h | `setInterval` no navegador | Ausente | Ausente | Agendador no servidor | backend (agendador) | P2 | [inventário] |

### 6. Elementos globais da página (PZ-GLB, PZ-MOD, PZ-CTX globais)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| PZ-GLB-01a09 | Topo | Menu, logo, "Pastas", escritório, sino "9+", tela cheia, nome/avatar, navegação | estáticos no desenho | Sem handler; até 640px esconde escritório e nome | Navbar AdminLTE global fixa (58px + 44px, z 1050), todos funcionais; "Instalar App" a mais | Parcial | Navegação sem item ativo na pasta (GLB-09); barra fixa afeta camadas (PZ-IA-01V) | arquivo global proibido (`base.html.twig`, `app.css`) | P2 | [inventário] checklist PZ-GLB-01 a 09; VIS 1.0 |
| PZ-GLB-10 | Dicas | Todo `title` vira dica azul-clara (`bj-dica.js`, 280 ms) | mouseover | Fundo #e5f1f7, texto #0b4f73 600 12px, máx. 300px | Dica nativa do navegador | Ausente | Componente de dica global | arquivo global proibido (`base.html.twig`); decisão do dono (spec §3.1) | P2 | [código] grep "bj-dica" sem ocorrência; VIS-05 |
| PZ-GLB-12 | Aviso | Toast padrão `gmAviso` | ações | #12242f, ✓ #5fd39a, 13px, inferior central, 2,6 s, entrada pcIn .25s | `#psAviso` com o mesmo visual, 2,6 s, regiões vivas, variante de erro | Parcial | Sem animação de entrada (VIS 1.17) | | P2 | [código] public/js/pasta-dialogos.js:597-601; [inventário] show:4363-4376 |
| PZ-GLB-12b | Aviso | `BJCentral.aviso` (toast do central) | e-mail, checklist, análise | Estilo próprio | Não se aplica (sem central) | Não aplicável | Pertence à Central (PZ-IA-16) | | n.a. | [inventário] |
| PZ-GLB-13 | Janelas | X em toda janelinha [SÓ-BLUEJUS na forma] | abertura | Desenho tem X em parte das janelas | X automático em `.ps-pop` e menus | Fiel | | | n.a. | [inventário] pasta-show.js:261-343 |
| PZ-GLB-14 | Janelas | Janelas arrastáveis | pointerdown | Nova meta e Detalhes do cliente arrastáveis | `PsArrastar` em menus, drawer, Timeline, modais | Fiel | Extensão aos menus é extra | | n.a. | [inventário] pasta-show.js:27-86 |
| PZ-MOD-09 | Confirmação | Janela `cf` (alertdialog 420px, cabeçalho #0b6a96, Cancelar, ação) | `confirmarUI`/`confirmar` | Enter confirma, Esc fecha, foco automático na janela, fundo fecha | `#psConfirmar` com valores medidos na E7; Esc e fundo cancelam; Tab circula; foco inicial no CANCELAR, então o Enter inicial cancela; foco volta ao gatilho | Divergente | Foco inicial e Enter: desvio deliberado (PAS-034c, Enter perdido confirmava exclusão), sem registro de aprovação do dono | decisão do dono (recomendação: manter) | P2 | [código] pasta-dialogos.js:371-401; VIS 1.15 |
| PZ-MOD-25 | Confirmação | Variante azul (#0a6fa0, ✓) e vermelha (#b3262e, lixeira) | `variante` | | `variante: 'azul'`; padrão vermelho com `bi-trash3` "Excluir" | Fiel | | | n.a. | [código] pasta-dialogos.js:385-390 |
| PZ-MOD-26 | Confirmação | Nenhum `confirm()`/`prompt()`/`alert()` nativo | | Desenho online quebra a regra em 2 pontos (Defeitos 8) | Áreas B: `confirm()` só como reserva quando `#psConfirmar` falta; `alert()` de reserva no explorador sem toast e em erros de Detalhes sem `avisarPs`; nativos das áreas A listados na matriz A | Parcial | Reservas nativas nas áreas B (pex.js:2120; det:170, 184) | | P2 | [código] det:170, 184; pasta-dialogos.js:379-381; [inventário] tela "Confirmações nativas" |
| PZ-MOD-S01 | Confirmação | Moldura "alerta" [SÓ-BLUEJUS] | `moldura: 'alerta'` | Vem do dc 03 (Lista) | Sem uso na pasta | Não aplicável | [SÓ-BLUEJUS] a preservar (usado no Expediente) | | n.a. | [código] pasta-dialogos.js:367-370 |
| PZ-MOD-10 | Busca rápida | Ctrl+K "Buscar pasta, cliente, CPF ou processo" | Ctrl/Cmd+K | Até 8 resultados; ↑/↓, Enter, Esc | Ausente | Ausente | Busca global entre módulos | backend (busca global); arquivo global se for atalho do layout | P2 | [código] grep sem handler de Ctrl+K |
| PZ-GLB-15 | Atalhos | E, H, T com o ⋮ da pasta aberto; Esc fecha | keydown | T anunciado e não tratado | E e H (`pasta-show.js`) e T (`pasta-timeline.js`); ignoram Ctrl/Alt/Meta e foco em campo | Fiel | BlueJus trata o T (correção do defeito 5 do desenho) | | n.a. | [código] pasta-show.js:470-480; pasta-timeline.js:115-125 |
| PZ-GLB-N01 | Duplo clique | Inventário de duplo clique | dblclick | Nova meta recolhe; alça de coluna; item do explorador abre; arquivo do Financeiro (provável inoperante) | Item do explorador, cabeçalho de coluna, árvore do Mover para, Nova meta recolhe, arquivo financeiro abre, item do checklist renomeia em edição | Fiel | Arquivo financeiro funciona no BlueJus (desenho com `onDblClick` provavelmente inoperante) | | n.a. | [inventário] tela "Duplo clique" |
| PZ-GLB-N02 | Botão direito | Botão direito fora fecha o menu aberto (PZ-CTX-18) | contextmenu no fundo | | Por área | Fiel | Sem menu `gm` único (ver PZ-CTX-GM) | | n.a. | [inventário] checklist PZ-CTX-18 (pasta-processo.js:239) |
| PZ-CTX-GM | Menus | Menu genérico `gm` (230px, título, separador, perigo) | `gmAbrir` | Componente único | Um menu por área (registro, observação, processo, metas, financeiro, explorador, push) com valores por token | Parcial | Larguras por área (220/240/250/260px) a medir | | P2 | [inventário] VIS 1.14 ("precisa medir na tela") |
| PZ-GLB-N03 | Permissões | Perfis Master, primário, autor, secundário | `BJCentral.ehMaster/ehPrimario`; `?como=` | Master sem restrição; primário sem limite de edição, exclui em 60 min, oculta abas, tranca a pasta | Modelo de 4 camadas (TenantRole, ResourceAccess, fallback, ROLE_SUPER_ADMIN); sem "primário" por pasta | Divergente | Papel primário por pasta não existe; Master global = ROLE_SUPER_ADMIN (regra do dono 15/10) | backend (papel por pasta); decisão do dono (D-MASTER) | P1 | [inventário] backend legenda; docs/AUTORIZACAO.md |
| PZ-MOD-12 | Cadeado | Níveis por colaborador (oculto, ver, editar) | ⋮ Cadeado (primário) | `localStorage bj-pasta-cadeado-v1`; "ver" bloqueia escrita; "oculto" bloqueia IA | Ausente (há `ResourceAccess` por item, sem tela na pasta) | Ausente | ACL por pasta com tela | backend (ACL por pasta, auditoria); decisão do dono | P1 | [inventário] checklist PZ-MOD-12 |
| PZ-CTX-04 | Abas | Ocultar aba por pessoa (botão direito na aba, primário) | contextmenu na aba | `bj-abas-visib-v1` + log | Ausente | Ausente | Permissão por aba | backend; decisão do dono; cruza com cabeçalho (parte A) | P2 | [inventário] checklist PZ-CTX-04 |
| PZ-GLB-17 | Contas | Multiconta (`bj-contas.js`) | carregado antes do central | Isola `localStorage` por conta; tela sem gatilho no 02 | Multi-tenant real no servidor (troca de escritório) | Não aplicável | Simulação de tenant no navegador sem lastro; o BlueJus já resolve por tenant no servidor | | n.a. | [inventário] bj-contas.js:1-33 |
| PZ-GLB-N04 | Impressão | Janela "Imprimir · <título>" (incluir na folha, tamanho 9 a 16 pt, Imprimir, Baixar PDF, Copiar texto) | Imprimir | html2pdf no navegador | `#psImpressao` com folha do servidor (`pasta_resumo_imprimir`, R:view), PDF do servidor (`pasta_resumo_pdf`), "Incluir na folha", tamanho, Copiar | Parcial | Título "Imprimir" sem "· título" (VIS-17) | | P2 | [código] templates/pasta/_impressao_modal.html.twig:29; VIS-17 |
| PZ-GLB-N05 | Tema | Tema escuro | | Inexistente no desenho | `[data-bs-theme="dark"]` com tokens; lista de @menção sem par escuro | Parcial | `.ps-mencao-lista` com cores cravadas (VIS-16) | | P2 | [inventário] tela A11; VIS-16 |
| PZ-GLB-N06 | Responsivo | Media queries | CSS | hover:none, reduced-motion, 980px 1 coluna, 640px topo compacto, `overflow-x: clip` | 980px 1 coluna, 767.98px cabeçalho compacto, hover:none, reduced-motion, explorador estreito, drawer 520px; sem `overflow-x: clip` | Não verificado | Precisa medir na tela: rolagem lateral a 390px (VIS-15) e abas a 1024px (VIS-04) | | P2 | [inventário] VIS-04, VIS-15 |
| PZ-GLB-N07 | Notificações | Notificações recebidas (menções, respostas, despachos) | Chat I.A | Sino estático; tudo no Chat | Sino do topo funcional com `Notificacao` | Parcial | Canal Chat ausente; sino cumpre o papel | Chat; arquivo global (`base.html.twig`) | P2 | [inventário] checklist PZ-IA-31 |
| PZ-GLB-N08 | Favoritos | "Fixar nos favoritos" e "Acompanhar alterações" da pasta; favoritos de documentos | ⋮ da pasta; estrela | `bj-pasta-prefs-v1` | Fixar nos favoritos real (`pasta_favorito_alternar`, tenant explícito + R:view); favoritos de documentos reais; "Acompanhar" ausente | Parcial | "Acompanhar alterações" ausente (VIS-26) | backend (acompanhamento); cruza com ⋮ da pasta (parte A) | P2 | [inventário] backend 2.1, 2.7; VIS-26 |
| PZ-GLB-N09 | Eventos | Barramento de eventos (`bj-pasta-*`, `bj-evidencia`…) | dispatchEvent | Entre telas no navegador | Inexistente | Não aplicável | Mecânica interna do protótipo; o BlueJus integra pelo servidor | | n.a. | [inventário] |
| PZ-GLB-N10 | Sincronização | Ouvintes `storage` entre abas | outra aba grava | Re-renderiza | Inexistente | Não aplicável | Simulação; tempo real no servidor seria outra frente | backend (tempo real) se pedido | n.a. | [inventário] |
| PZ-GLB-S01 | Global | `#aba` e `#pasta-msg-N` na URL abrem a aba e o registro [SÓ-BLUEJUS] | carregar | Não existe | Presente | Não aplicável | [SÓ-BLUEJUS] a preservar | | n.a. | [inventário] pasta-show.js:558-602 |
| PZ-GLB-S02 | Global | Timeline inteligente real (visita, "Enquanto você estava fora", resumo) [SÓ-BLUEJUS] | ⋮ ou T | Desenho só anuncia o item | `pasta_timeline_visita` (tenant explícito, CSRF) + `pasta_timeline` | Não aplicável | [SÓ-BLUEJUS] a preservar | | n.a. | [inventário] backend 2.8 |
| PZ-GLB-VT | Visual | Travessão em texto visível | | Regra do dono: sem travessão | Travessão no detalhe do histórico e na faixa da lápide | Divergente | Trocar por ponto, dois-pontos ou vírgula | | P2 | [inventário] VIS-12 (`_historico_drawer.html.twig:73`) |

### 7. Índices transversais (modais, menus, atalhos, confirmações) não cobertos pela parte A

Itens dos índices do inventário B (§6 a §9) que já têm linha acima apontam para ela; os de cabeçalho, cadastro, Dados/trilho/prazos, Metas, Processo e Financeiro ficam na `matriz-A.md`. Linhas novas só para o que não tem linha em lugar nenhum.

#### 7.1 Modais e janelas

| Índice B | Janela | Linha | Status |
|---|---|---|---|
| 3 | "Imprimir · título" | PZ-GLB-N04 | Parcial |
| 4 | "Análise do processo" | PZ-PUSH-30 | Ausente |
| 5 | "Documento" (texto lido) | MB-IDX-01 (abaixo) | Ausente |
| 6 | Popover "Destacar comentário" | PZ-DET-14 | Ausente |
| 7 | "Ocultar aba" | PZ-CTX-04 | Ausente |
| 9 | Confirmação `cf` | PZ-MOD-09 / PZ-MOD-25 | Divergente / Fiel |
| 10 | Menu genérico `gm` | PZ-CTX-GM | Parcial |
| 11 | Visor | PZ-VIS-01 a 07 | Fiel (exceto VIS-06) |
| 12 | Busca rápida | PZ-MOD-10 | Ausente |
| 16 | Cadeado da pasta | PZ-MOD-12 | Ausente |
| 17, 18 | Acessos ao link; Link externo do Push | PZ-PUSH-N05 | Ausente |
| 22 | Drawer BLUEJUS IA | PZ-IA-01 / PZ-IA-01V | Fiel / Divergente |
| 30, 31 | Menus Organizar/Visualizar e contexto do explorador | PZ-DOC-04, 05, 15 a 19 | Fiel/Parcial |
| 32 | ⋮ da análise IA do Push | PZ-PUSH-06 a 09 | misto |
| 33 | "Enviar ao cliente" | PZ-PUSH-33 | Ausente |
| 34 | Drawer "Histórico do sistema" | PZ-DET-17 | Fiel |
| 36 | Despacho via Chat I.A | PZ-IA-29 | Ausente |
| 37 | Tela de contas | PZ-GLB-17 | Não aplicável |
| 38 | Janela do Assistente de pastas | PZ-IA-N02 | Não aplicável |
| 39 | Menus e popovers do editor | PZ-EDT-02 a 15 | misto |
| 40 | Lista de @menção | PZ-EDT-17 | Parcial |
| 1, 2, 8, 13 a 15, 19 a 21, 23 a 29, 35 | Nova meta, calendário, Vincular processo, Marcadores, foto, Editar dados, Cadastro, Detalhes do cliente, menus do cabeçalho, metas, pagamento, Novo prazo | parte A | (matriz-A) |

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| MB-IDX-01 | Push | Modal "Documento" (título, sub, texto lido) | origem na análise; "Abrir documento" | Mostra o texto lido do PDF | "Abrir documento" do menu do Push abre o teor da publicação (acordeão/`pasta_push_texto`); não há modal com texto de PDF lido | Parcial | Sem leitura de PDF; o teor DJEN cumpre o papel para publicações | backend (leitura de PDF) para itens do PDF | P2 | [código] push:286-288; [inventário] dc:250-256 |

#### 7.2 Menus ⋮ e de botão direito

| Índice B | Menu | Linha | Status |
|---|---|---|---|
| Registro (Detalhes) | ⋮ ou botão direito na observação | PZ-DET-06 a 14 | misto (3 itens ausentes: Encaminhar, Destacar, Remover destaque) |
| Botão direito na aba | Ocultar / link do Push | PZ-CTX-04, PZ-PUSH-N05 | Ausente |
| Nota técnica (Push) | ⋮ ou botão direito | PZ-PUSH-20 | Parcial |
| Cartão do Push | botão direito | PZ-PUSH-21 a 24 | Fiel (sem Encaminhar) |
| ⋮ da análise IA do Push | clique | PZ-PUSH-06 a 09 | misto |
| Explorador (item, vários, fundo) | botão direito | PZ-DOC-15, 18, 19 | Parcial/Fiel |
| Organizar / Visualizar | clique | PZ-DOC-04, 05 | Fiel |
| Editor rico | botão direito | PZ-EDT-15 | Parcial |
| ⋮ da pasta, Mover carteira, cliente, prazos, meta, sino, Processos, lançamento, pagamentos, arquivo financeiro, foto, situação | | parte A | (matriz-A) |

#### 7.3 Atalhos de teclado

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| MB-ATL-01 | Global | Ctrl/Cmd+K busca rápida | tecla | Abre/fecha | Ausente | Ausente | ver PZ-MOD-10 | backend | P2 | [código] grep |
| MB-ATL-02 | Global | E, H (T) com ⋮ aberto | tecla | T não tratado | E, H e T tratados | Fiel | | | n.a. | [código] ver PZ-GLB-15 |
| MB-ATL-03 | Confirmação | Enter confirma, Esc cancela | tecla | Enter confirma de cara | Enter confirma só com o foco fora do Cancelar; Esc cancela; Tab circula | Divergente | ver PZ-MOD-09 | decisão do dono | P2 | [código] pasta-dialogos.js:395-401 |
| MB-ATL-04 | Global | Esc fecha destaque, visor, menus, drawers, janelas, editor, menções | tecla | Fecha (exceto drawer IA, sem Esc) | Esc fecha popovers, Histórico, Timeline, menus, visor, drawer IA, confirmação | Fiel | Destaque inexistente | | n.a. | [código] pasta-ia-agentes.js:119; pex.js:2075; pasta-timeline.js:113 |
| MB-ATL-05 | Visor | ← → anterior/próximo; − + zoom | tecla | ← → | ← → − + Esc | Fiel | | | n.a. | [inventário] pasta-explorador-visor.js:449 |
| MB-ATL-06 | Explorador | Atalhos do explorador; Enter/Esc no renomear; Esc na busca | tecla | ver PZ-DOC-22 | ver PZ-DOC-22 | Fiel | | | n.a. | [código] pex.js:1641-1669 |
| MB-ATL-07 | Checklist | Enter adiciona, Esc cancela | tecla | | Presente | Fiel | | | n.a. | [inventário] pasta-checklist.js (4 keydown) |
| MB-ATL-08 | Push | Enter/Esc no cadastro de e-mail do cliente | tecla | | Ausente (cadastro inexistente) | Ausente | ver PZ-PUSH-32 | backend | P1 | [inventário] |
| MB-ATL-09 | Detalhes/Push | Ctrl/Cmd+Enter envia, Esc cancela (resposta e nota técnica) | tecla | | Presente | Fiel | | | n.a. | [código] det:504-505; [inventário] nota-tecnica.js:552 |
| MB-ATL-10 | IA | Enter envia a pergunta | tecla | | Ausente (Perguntar inexistente) | Ausente | ver PZ-IA-13 | backend | P2 | [inventário] |
| MB-ATL-11 | Editor | Ctrl+Enter, Ctrl+K (link), Ctrl+H, Ctrl+Y/Z, Ctrl+B/I/U, Tab, Shift+Tab, Esc, Ctrl+Shift+V | tecla | | Presente, exceto Ctrl+K (sem link) | Parcial | Ctrl+K do editor ausente (PZ-EDT-02) | decisão do dono | P2 | [código] editor-rico.js:989-990 |
| MB-ATL-12 | Editor | Tab, ↑ ↓, Enter, Esc em sugestões e @menção | tecla | | Presente | Fiel | | | n.a. | [inventário] pasta-mencoes.js; editor-rico.js |
| MB-ATL-13 | Agente | Enter envia pedido | tecla | Só na Lista 03 | Fora da pasta | Não aplicável | ver PZ-IA-N02 | | n.a. | [inventário] |

#### 7.4 Confirmações (texto exato)

| ID | Área | Elemento | Gatilho | Comportamento Designer | Comportamento BlueJus | Status | Lacuna | Dependências | Prioridade | Evidência |
|---|---|---|---|---|---|---|---|---|---|---|
| MB-CF-01 | Detalhes | Excluir comentário | ⋮ Excluir | `cf` vermelha "Excluir o comentário de X?" / "<quando> · "<trecho>". Ele some para toda a equipe e a exclusão fica registrada no histórico." / "Excluir" | `confirmarPs` vermelho "Excluir o comentário de NOME?" com data e trecho | Parcial | Conferir o texto do corpo na tela (frase final "fica registrada no histórico"); regra de exclusão em PZ-DET-13 | | P2 | [código] det:173-184 |
| MB-CF-02 | Detalhes | Atualizar andamento da pasta | após registro | `cf` azul | Inexistente | Não aplicável | Decisão aprovada P3 | | n.a. | [inventário] dc:6342 |
| MB-CF-03 | Documentos | Excluir arquivos sugeridos | faixa de limpeza | "Excluir N arquivo(s) sugerido(s)? Você pode desfazer logo em seguida." | Mesmo texto (`cfUI` vermelho) | Fiel | | | n.a. | [inventário] pex.js:1280-1320 |
| MB-CF-04 | Push | Remover e-mail do cliente | × na lista | `cf` vermelha | Inexistente | Ausente | ver PZ-PUSH-32 | backend | P1 | [inventário] |
| MB-CF-05 | Push | Excluir análise IA | ⋮ | "Excluir esta análise da IA? As movimentações oficiais não são alteradas." | Mesmo texto | Fiel | Autorização em PZ-PUSH-09 | | n.a. | [código] ia-push:120 |
| MB-CF-06 | Push | Excluir nota técnica | ⋮ da nota | "Excluir esta nota técnica?" | Confirmação na janela própria (nota-tecnica.js); erro com `alert()` | Parcial | `alert()` em erro (nota-tecnica.js:360, 373); texto a conferir | | P2 | [inventário] tela "Confirmações nativas" |
| MB-CF-07 | Push | Revogar link | lista de links | `cf` azul | Inexistente | Ausente | ver PZ-PUSH-N05 | backend | P2 | [inventário] |
| MB-CF-08 | IA | Ações da IA ("Confirma esta ação?") | providências, fase, tarefa, prazo, revisão | Confirmação interna do drawer | Inexistente (ações inexistentes) | Ausente | ver PZ-IA-05, 06, 12 | backend | P2 | [inventário] |
| MB-CF-09 | Checklist | Desativar checklist (motivo obrigatório) | interruptor | Painel embutido | Painel embutido com motivo obrigatório no servidor | Fiel | | | n.a. | [código] _documentos_checklist.html.twig:84-121 |
| MB-CF-10 | Agente | Alteração em lote ("Confirmo a alteração de N pasta(s)") | plano | Caixa obrigatória | Fora da pasta | Não aplicável | ver PZ-IA-N02 | | n.a. | [inventário] |
| MB-CF-11 | Documentos | Excluir item do explorador | Del, menu | SEM confirmação | Com confirmação | Divergente | ver PZ-DOC-EXC | decisão do dono | P2 | [inventário] |
| MB-CF-12 | Checklist | Excluir item, substituir e excluir modelo [SÓ-BLUEJUS] | lixeira, modelos | Sem confirmação no desenho | `cfUI` vermelho "Excluir este item do checklist?", "Substituir modelo…", "Excluir o modelo X? Os checklists já aplicados nas pastas não mudam." | Não aplicável | [SÓ-BLUEJUS] a preservar | | n.a. | [inventário] tela A8 |

---

### Contagem por status e por área

| Área | Fiel | Parcial | Divergente | Ausente | Não aplicável | Não verificado | Total |
|---|---|---|---|---|---|---|---|
| 1. Detalhes | 8 | 5 | 2 | 8 | 5 | 0 | 28 |
| 2. Editor rico | 10 | 3 | 0 | 9 | 0 | 0 | 22 |
| 3. Documentos (com checklist, sugeridos, visor) | 26 | 4 | 3 | 4 | 4 | 0 | 41 |
| 4. Push | 11 | 8 | 3 | 16 | 2 | 0 | 40 |
| 5. IA e Chat | 4 | 4 | 2 | 13 | 6 | 0 | 29 |
| 6. Globais | 6 | 8 | 3 | 4 | 7 | 1 | 29 |
| 7. Índices (linhas próprias) | 10 | 4 | 2 | 6 | 4 | 0 | 26 |
| **Total** | **75** | **36** | **15** | **60** | **28** | **1** | **215** |

(Contagem feita sobre a coluna Status das tabelas de linhas; as tabelas-índice de §7.1 e §7.2 só remetem a linhas existentes e não entram na soma.)

### Defeitos do protótipo (com o comportamento correto a adotar)

1. **dc:2164-2165 "Nova pasta" e "ANEXAR" sem handler.** Correto: criar pasta e enviar arquivo de verdade (o BlueJus já faz, PZ-DOC-03).
2. **dc:5034 + dc:5185 Desfazer de "mover" duplica o item** (volta à raiz sem sair do destino). Correto: Desfazer devolve o item à origem e o tira do destino (o BlueJus move no servidor; Desfazer a conferir na tela).
3. **dc:6395-6397 origem dos prazos de Detalhes sai "Registro"** (chave `detalhes` x contexto `observacoes`). Correto, se o recurso vier: origem "Detalhes".
4. **dc:6403-6404 + dc:6166 `autoReg('prazo', …)` com tipo inexistente.** Correto: registro de sistema com tipo próprio "Prazo cumprido".
5. **dc:6922 atalho "T" anunciado e não tratado.** Correto: T abre a Timeline (o BlueJus já trata, PZ-GLB-15).
6. **dc:6862-6870 "Enviar no Chat I.A" do registro é código morto** e dispara evento sem ouvinte. Correto: um único "Encaminhar via Chat I.A" quando houver Chat (PZ-DET-08).
7. **dc:3542, dc:4581 `confirm()` e `prompt()` nativos** contra a PZ-MOD-26. Correto: janela própria (`confirmarPs`; para excluir pasta, campo de número dentro da janela). Área da parte A, registrado aqui por ser regra global.
8. **dc:6950 exclusão de arquivo do Financeiro nunca esconde o arquivo** (trecho colado no índice). Área da parte A.
9. **dc:5667-5668 "Novas" no Push = não lidas E datas fixas 01 ou 02/10.** Correto: só "não lidas" (o BlueJus já faz, PZ-PUSH-03).
10. **dc:1103-1105, 5980-5993 "Criar tarefa", "Criar prazo", "Enviar para revisão" do resultado da IA só confirmam e avisam sucesso, sem criar nada.** Correto: só exibir quando houver backend, e o aviso só depois da criação real (nunca simular operação concluída).
11. **dc:2879-2907 "Histórico do sistema" diz "10 alterações" e lista 6 eventos estáticos.** Correto: contagem e lista do audit_log (o BlueJus já faz, PZ-DET-17).
12. **dc:5031 excluir item do explorador sem confirmação.** Ação destrutiva sem pergunta; correto: confirmar e mandar para a lixeira com Desfazer (o BlueJus já faz; mudar exige decisão do dono, PZ-DOC-EXC).
13. **PZ-IA-01 drawer da IA sem Esc.** Correto: Esc fecha (o BlueJus já faz).
14. **PZ-PUSH-31 "Avisar quando chegar" alterna interruptores sem efeito algum.** Correto: só exibir com notificação real.

### Achados P0

Nenhum vazamento entre escritórios encontrado nas áreas B: todas as rotas de escrita usadas por Detalhes, Documentos, Push e IA resolvem a pasta com TenantFilter e, nas filhas, buscam por pasta e tenant (`findOneBy(['id','pasta','tenant'])`, `findByIdAndPastaAndTenant`, `buscarDoTenant`, `findOneDoTenantEAlvo`, `findOneByIdENumerosDoTenant`). Os achados abaixo são de autorização DENTRO do mesmo escritório; entram aqui porque a regra da matriz põe "permissão" em P0, e a classificação final (defeito x decisão) é do dono.

| # | Achado | Classe | Evidência | Leitura |
|---|---|---|---|---|
| A1 | Anotações do Registro (`pasta_enviar_mensagem`, `pasta_editar_mensagem`, `pasta_excluir_mensagem`) exigem só **R:view**, enquanto observações de Detalhes e Financeiro exigem **R:edit**. | CONFIRMADO (comportamento) | `PC:1048`, `PC:1120`, `PC:1166` (`ACTION_VIEW`); `PC:2219`, `PC:2069` (R:edit, conforme backend); a tela é coerente com cada rota: o compositor do Registro aparece para quem vê (`_dados_anotacoes.html.twig` só testa `pasta.estaExcluida`) e o de Detalhes some sem `podeEditarPasta` (det:25) | **Decisão do dono, não defeito**: UI e servidor concordam em cada área, editar/excluir continuam presos ao autor e à janela de 15 min, e não cruza escritório. A inconsistência é de política (anotar = conversa x observar = cadastro). Pergunta ao dono: quem só vê pode anotar no Registro? Prioridade real P1 (política), não P0. |
| A2 | Modelos de checklist são do ESCRITÓRIO, mas salvar/renomear/excluir exigem só **R:edit da pasta aberta** (`negar`). Quem edita qualquer pasta altera ou apaga um modelo usado por todos. | CONFIRMADO (comportamento) | `src/Pasta/Controller/PastaChecklistModeloController.php:72-77, 137-176, 182-198`; modelo por `buscarDoTenant` (sem cruzar escritório); exclusão pede confirmação na tela e não muda checklists já aplicados | **HIPÓTESE de defeito** (escopo de autorização errado para recurso compartilhado) ou decisão consciente; sem perda de dado das pastas, mas apaga patrimônio do escritório com permissão de uma pasta. Sugestão: exigir permissão de módulo/administração para renomear e excluir. Prioridade P0 pela regra (permissão), impacto moderado. |
| A3 | Ações sobre análises de IA (`inteligencia_analise_excluir`, `_interna`, `_lida`, `_nao_lida`, `_recolher`) exigem só **R:view + M:inteligencia**; o botão Excluir aparece para quem só vê. | CONFIRMADO (comportamento) | `src/Inteligencia/Controller/AnalisePushController.php:259-271, 313-330`; `ExcluirAnaliseUseCase.php:26-35` (exclusão LÓGICA escopada a tenant e pasta, com usuário registrado); `ia-push:119-120` (formulário sem condição de edição), em contraste com `iaPodeCriarTarefa` (ia-push:34, exige R:edit) | **HIPÓTESE de defeito leve**: quem só vê oculta a análise para toda a equipe e alterna "interna do escritório". Sem perda de dado (soft delete com rastro). Recomendo R:edit para excluir e interna. Prioridade P1. |
| A4 | Marcar publicação do Push como lida (`pasta_push_lida`) exige **R:view** e grava flag da PUBLICAÇÃO (vale para o escritório inteiro), não por usuário. | CONFIRMADO (comportamento) | `src/Pasta/Controller/PastaPushProcessualController.php:203-231` (`setLida`) | **Decisão do dono**: leitura por usuário exigiria backend; não é segurança. Prioridade P2. |
| A5 | Exclusão de observação de Detalhes é REAL (sem lixeira), com trilha só no audit_log; o desenho faz exclusão lógica com log. | CONFIRMADO | backend O6 (`ExcluirObservacaoDetalhesUseCase:66`) | Integridade: o texto some de vez; recuperar só pelo audit_log. Janela de 15 min e só autor reduzem o risco. Decisão do dono junto com PZ-DET-13. Prioridade P1. |

Nenhum dos cinco cruza escritório; nenhum é ação destrutiva sem confirmação na tela.

### [SÓ-BLUEJUS] a preservar (áreas B)

1. Estado vazio de Detalhes (PZ-DET-S01).
2. "Como usar esta aba" em Detalhes (P2, PZ-DET-19).
3. Explorador com persistência real: Nova pasta e Anexar funcionais, Mover para…, Editar… (nome, tipo, descrição, número), ordem manual, Copiar link autenticado (PZ-DOC-03, 15, N01 a N04).
4. Lixeira com restauração e Desfazer real (PZ-DOC-20, 23).
5. Confirmação antes de excluir no explorador (PZ-DOC-EXC).
6. Aviso de arquivo idêntico no escritório que sobrevive à recarga (PZ-DOC-03S).
7. Modelos de checklist do escritório e confirmações do checklist (PZ-DOC-06S, MB-CF-12), revendo a autorização (A2).
8. Painel de propriedades com Adicionado em, Enviado por, Descrição, Nome parecido, Local (PZ-DOC-21).
9. Push: "ver no módulo", "Abrir no módulo", teto com "Ver todas no módulo", teor DJEN por acordeão (PZ-PUSH-S01, 26, 12).
10. Análise IA do Push com cadeado "Interna do escritório", recolher persistente e ⋮ de ações (PZ-PUSH-05, PZ-IA-S01).
11. IA: agentes por pasta com polling honesto e auditoria persistente com downloads/impressões (PZ-IA-10, 11, 15); Esc no drawer.
12. Editor: privacidade (sem Definir, Traduzir, Pesquisar na web) (PZ-EDT-15).
13. Janela de confirmação com foco inicial no Cancelar (PZ-MOD-09) e moldura "alerta" (PZ-MOD-S01).
14. X automático e arraste nos menus e janelas (PZ-GLB-13, 14).
15. Timeline inteligente real e atalho T funcional (PZ-GLB-S02, 15).
16. `#aba` e `#pasta-msg-N` na URL (PZ-GLB-S01).
17. Tema escuro por `data-bs-theme` (PZ-GLB-N05).

### Não verificados e o que falta

| Item | O que falta |
|---|---|
| PZ-GLB-N06 Responsivo | Medir na tela: rolagem lateral da página a 390px (cabeçalho, registro com recuo de 46px, explorador) e abas a 1024/1100/900px (VIS-04, VIS-15). |
| PZ-IA-01V Camada do drawer IA | Ordem de camadas confirmada no código; falta ver na tela se cabeçalho e X ficam sob a navbar (VIS-01). |
| PZ-DOC-00 Moldura dupla | Cascata confirmada; ver o respiro de 18/20px na tela (VIS-02). |
| PZ-CTX-GM Menus por área | Medir largura e sombra de cada menu (220, 240, 250, 260px) contra os 230px do `gm`. |
| Textos de aviso (PZ-DET-02, 03b, 09; MB-CF-01, MB-CF-06) | Conferir na tela o texto exato dos toasts e do corpo das confirmações; o código foi lido só nos títulos. |
| PZ-DOC-02 realce `<mark>` e PZ-DOC-14 favoritos no topo | Conferir na tela. |
| PZ-DOC-N02 Desfazer de mover | Confirmar na tela que o Desfazer devolve o item à origem sem duplicar. |
| PZ-EDT-10 a 13 tempos dos chips | Medir 3,6 s, 6 s e 7 s na tela. |
| PZ-GLB-12 animação do aviso | Ver na tela (falta `pcIn`). |
