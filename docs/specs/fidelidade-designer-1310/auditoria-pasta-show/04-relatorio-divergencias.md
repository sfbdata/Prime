# 04. Relatório de divergências

> Auditoria integral da `pasta_show` contra o protótipo ONLINE `02 - EXPEDIENTES 1.2.3.dc.html` (baixado em 16/10/2026), worktree `fidelidade-designer` @ `9c081725`. Somente leitura. Índice e resumo em `00-LEIAME.md`.
> **Legenda de evidência:** CONFIRMADO = o auditor leu o código e cita arquivo:linha; HIPÓTESE = inferência, com o motivo; PRECISA MEDIR = só a tela resolve.

## 1. Achado zero: o Designer online mudou depois do pacote local de 13/10

A E8d (15/10) registrou o `02` online idêntico ao pacote local por SHA-256. **Em 16/10 isso deixou de ser verdade.**

| Arquivo | Pacote local (13/10) | Online (16/10, sem os blocos injetados pela pré-visualização) | Diferença |
|---|---|---|---|
| `02 - EXPEDIENTES 1.2.3.dc.html` | 1.000.776 bytes, `acc67f30…` | 1.025.734 bytes, `20597e28…` | 153 linhas trocadas (`anexos/diff-02-local-x-online.txt`) |
| `03 - EXPEDIENTE Lista 1.1.dc.html` | 102.466 bytes, `9a311984…` | 134.355 bytes, `1ec31a6f…` | 266 linhas trocadas (`anexos/diff-03-local-x-online.txt`) |
| `bj-agente.js` | 23.675 bytes | 28.883 bytes | ferramenta nova `prazos_pastas` (`anexos/diff-bj-agente.txt`) |
| Demais 13 dependências | | | idênticas por SHA-256 |

Método: `render_preview` do MCP `claude_design` + download para o scratchpad; o serviço de pré-visualização injeta um `<style>` e um `<script>` marcados `data-omelette-injected` no `<head>`, retirados antes da comparação. O `list_files` do MCP confirma os tamanhos online (1.025.732 e 134.353 bytes; os 2 bytes a mais são as quebras de linha que sobram da retirada).

**O que entrou no 02 em 16/10** (todas as linhas marcadas `[NOVO-16/10]` no inventário e na matriz):
1. **Aba "Réu" no Cadastro do cliente** (a aba "Dados pessoais" virou "Cliente"): nome/razão social obrigatório, CPF/CNPJ opcional, lista com Principal, subir e remover; grava em `bj-reus` por pasta. O texto diz que o primeiro réu aparece na coluna **Réu** da Lista do Expediente.
2. **Painel "Prazos e audiências"** no trilho de Dados: botão "+", janela "Novo prazo ou audiência" (Prazo/Audiência, descrição, data, hora, responsável), menu do prazo (Marcar como cumprido, Editar, Excluir), selos "Hoje"/"Faltam N dias"/"Vencido há N dias", até 8 itens, vazio próprio. A lista junta prazos manuais (`bj-pz-manual-v1`), prazos lidos do Push (`BJProcesso.analisar`), **datas escritas nos registros** (leitor `lerDatas`, com dias úteis, feriados e recesso, exposto como `window.BJDatas`) e prazos das Metas; publica o agregado em `bj-prazos-pasta-v1` e `bj-ativ-pasta-v1` para a Lista 03 e o agente.
3. Contagem de clientes dinâmica (`cliN`) e alias de CPF (`bj-cli-alias`).

**Consequência fora desta auditoria:** a Lista 03 também mudou (266 linhas). A E8d foi auditada contra a versão anterior. Provável relação com a coluna Réu e com os prazos publicados. **Não foi auditada aqui**: fica como pendência para o dono decidir (ver `06-plano-incremental.md`, §0).

## 2. Achados P0 (segurança e integridade)

Nenhum vazamento entre escritórios: toda rota de escrita acionável da pasta_show resolve a pasta com o TenantFilter (que vale também para `find()`, `BasicEntityPersister.php:1146`, provado em `PastaExpedienteTenantFilterTest.php:37`) e os ids filhos são buscados por pasta e tenant. Os achados são de **autorização dentro do mesmo escritório**, de **exposição de detalhe interno** e de **ação destrutiva sem confirmação**.

| # | Achado | Estado | Evidência | Efeito | Correção proposta |
|---|---|---|---|---|---|
| P0-1 | Metas: abrir, concluir, trocar responsáveis, comentar e editar comentário sem checar acesso à pasta | CONFIRMADO (orquestrador e matriz) | `app/src/Controller/TarefaController.php:225-231` (show), `:252-258` (responsáveis), `:434-445` (concluir), `:612-632` (`verificarAcessoTarefa` só confere tenant e vínculo do criador/responsável da pasta); contraste `app/src/Tarefa/Controller/MetaNaListaController.php:160-171` ("Sem isto, quem tem só o módulo Tarefas agiria sobre metas de pastas a que não tem acesso"); tela `templates/pasta/_metas.html.twig:86-91, 222-237` | Quem tem o módulo Tarefas e só vê (ou nem vê) a pasta conclui a meta; reabrir exige editar (assimetria) | `canAccessResource('pasta', id, edit)` em concluir, responsáveis, mensagem e editar mensagem; `view` em show; `metaEditavel` com `podeEditarPasta`; teste funcional "módulo Tarefas sem acesso à pasta". Impacto real depende dos perfis (consulta só leitura pendente) |
| P0-2 | Marcadores da pasta mudam com permissão só de ver | CONFIRMADO | `app/src/Expediente/Controller/ExpedienteController.php:246-260` + `assertAccess` `:355-363` (só o módulo Expediente); `templates/pasta/_cabecalho.html.twig:102-111` (o "+" e o token sem `podeEditarPasta`) | Quem só vê a pasta altera os marcadores | `canAccessResource('pasta', id, edit)` no endpoint; "+" e clique nas etiquetas só com `podeEditarPasta`; teste de recusa |
| P0-3 | Erro 500 do endpoint de marcadores devolve o stack trace no JSON | CONFIRMADO | `ExpedienteController.php:267` (`'trace' => $e->getTraceAsString()`) | Expõe caminhos e estrutura interna a qualquer usuário com o módulo | Tirar `trace` da resposta, registrar no log; teste |
| P0-4 | Desvincular cliente (lixeira do trilho) sem nenhuma confirmação; ao sair o principal, o servidor promove outro | CONFIRMADO | `templates/pasta/_cliente_linha.html.twig:89-96` (form sem `data-ps-cf`); `templates/pasta/show.html.twig:1483-1520` (handler faz o `fetch` direto, erro por `alert()`) | Ação destrutiva com um clique; troca silenciosa do cliente principal e da Média por CPF | `data-ps-cf` + `data-ps-cf-perigo` ("Desvincular NOME desta pasta?"), padrão da E7 |
| P0-5 | Modelos de checklist do ESCRITÓRIO alterados ou excluídos com permissão de editar a pasta aberta | Comportamento CONFIRMADO; ser defeito é HIPÓTESE | `app/src/Pasta/Controller/PastaChecklistModeloController.php:72-77, 137-176, 182-198` | Quem edita uma pasta qualquer apaga um modelo usado por todos (não muda checklists já aplicados; a tela confirma antes) | Decisão do dono: exigir permissão de administração/módulo para renomear e excluir modelo |

## 3. Decisões de política que a auditoria devolve ao dono

Comportamentos CONFIRMADOS em que a tela e o servidor concordam entre si, mas a regra diverge entre áreas ou do desenho. Não são defeito sem a decisão.

| # | Assunto | Hoje | Desenho / especificação | Pergunta |
|---|---|---|---|---|
| D-1 | Anotações do Registro (enviar, editar, excluir) | Exigem só VER a pasta (`PastaController.php:1044-1050, 1120, 1160-1166`); Detalhes e Financeiro exigem EDITAR (`:2219`, `:2069`) | Omisso | Quem só vê pode anotar no Registro? |
| D-2 | Excluir e marcar "interna" uma análise de IA | VER + módulo IA (`AnalisePushController.php:259-271, 313-330`); exclusão lógica com rastro | Omisso | Exigir EDITAR? (recomendação: sim) |
| D-3 | Marcar publicação do Push como lida | Vale para o escritório inteiro, exige VER (`PastaPushProcessualController.php:203-231`) | Leitura por usuário | Manter por escritório ou criar leitura por usuário (backend)? |
| D-4 | Excluir observação de Detalhes | Exclusão real em 15 min, trilha só no audit_log (`ExcluirObservacaoDetalhesUseCase:66`) | Exclusão lógica em 60 min, edição em 15, Master e primário com exceções (Farlei §3.3, §3.12) | Qual regra vale (tempo, lógica x real, papéis)? |
| D-5 | Número da pasta | Pode repetir (`EditarPastaUseCase.php:25`) | Número único | Tornar único? |
| D-6 | P1, P2, P3 | O HANDOFF diz provisórias de 15/10; o checklist §20 e o HANDOFF §6 dizem ABERTAS | | Confirmar como definitivas |
| D-7 | Lista 03 mudou online | E8d auditada contra a versão anterior | | Reabrir a Lista (nova auditoria curta) antes de integrar? |

## 4. Lacunas funcionais principais (P1)

48 linhas P1 na matriz. Agrupadas por tema (detalhe em `03-matriz-designer-x-bluejus.md`; dependências em `05-mapa-dependencias.md`):

| Tema | IDs | Natureza | Depende de |
|---|---|---|---|
| Prazos e audiências [NOVO-16/10] | PZ-TRI-N01, N04, N07 a N12, N14 | Ausente: não existe entidade de prazo manual nem audiência ligada à pasta; hoje o trilho mostra só prazos de metas (até 3) | Backend novo (entidade, migration, rotas), motor de dias úteis (há `Feriado` no Ponto), decisão do dono |
| Réu da pasta [NOVO-16/10] | PZ-MOD-N20 a N25 | Ausente: réu só existe como `ParteProcesso` (Datajud); pasta sem processo não tem réu | Backend novo; modal de Cadastro; coluna Réu da Lista 03 |
| Cadastro do cliente na pasta | PZ-MOD-17, PZ-CAB-47 | O botão "Cadastro" abre "Detalhes do cliente"; o modal de 7 abas não existe | Rotas de edição do cliente com permissão do cliente; decisão |
| Situação Suspenso e Cancelado | PZ-CAB-36 | Domínio só tem `ativo` e `arquivado` (`Pasta.php:30-31`) | Migration, regra de transição, decisão |
| Apensos com tipo | PZ-PRO-08, 11 (+ PZ-MOD-08, PZ-PUSH-04) | Vínculo só tem `principal` | Migration em PastaProcesso |
| Cadeado e acesso por pasta, papel primário | PZ-CAB-21, 32, 37, PZ-MOD-12, N08 a N10, PZ-GLB-N03 | Ausente | Autorização (risco MÉDIO/ALTO), D-MASTER |
| Link externo do Push | PZ-CAB-19, PZ-MOD-14, N12, PZ-PUSH-N05 | Ausente | Página pública com token, validade, revogação, registro de acessos |
| Push: enviar e avisar cliente, monitoramento, buscar agora | PZ-PUSH-10, 27, 28, 32, 33, MB-ATL-08, MB-CF-04 | Ausente | E-mail transacional, captura PJe |
| Histórico incompleto | PZ-MOD-20 | Vincular/desvincular processo e cadastro de cliente não deixam linha (`PastaProcesso`, `Processo`, `Cliente` não são Auditavel) | Backend (Auditavel ou evento) |
| Marcadores sem recarga | PZ-MOD-11 | O modal não atualiza as etiquetas (falta `onSucessoCallback`) e não cria marcador | Só tela (criar marcador depende de rota existente) |
| Drawer da IA sob a barra do topo | PZ-IA-01V (VIS-01) | Camadas z 95/96 x navbar fixa z 1050 (`pasta-ia-agentes.css:144-157` x `app.css:109-118`): ordem CONFIRMADA estática, efeito PRECISA MEDIR | Só CSS da página |
| Drawer da IA sem abas | PZ-IA-04 | Mostra 7 agentes; o desenho tem Análise, Documentos, Relatórios, Perguntar | Backend de IA |
| Êxito e Sucumbência | PZ-MOD-24 | Desabilitados | Lançamento previsto no backend |
| Regras de edição e exclusão em Detalhes | PZ-DET-06, 13 | Ver D-4 | Decisão |
| Análise de IA excluída por quem só vê | PZ-PUSH-09 | Ver D-2 | Decisão |

## 5. Divergências que os inventários não tinham e o código mostrou

- Próximos prazos: o trilho limita a 3 itens e conta só esses (`PastaPrazoOutput.php:42`); o desenho mostra até 8 e conta o total. Selo "Vence hoje" onde o desenho diz "Hoje"; "Falta 1 dia" onde diz "Faltam 1 dia" (VIS-08).
- Editar dados: sem foco e seleção automática do número ao abrir.
- Nova meta: sem aviso de "Meta não encontrada" no link direto; prazo não vem preenchido com hoje + 7.
- Seis ações que gravam sem pergunta: desvincular cliente (P0-4), marcar/desfazer recebimento, trocar situação, trocar prioridade, Contrato, Pró-bono. As cinco últimas são reversíveis e o desenho também não pergunta: ficam como estão, mas com o erro hoje mostrado por `alert()` trocado por aviso da tela (P2).
- Travessão em texto visível (regra do dono 15/10): `_historico_drawer.html.twig:74` e a faixa da lápide (`_cabecalho.html.twig:311`).

## 6. Checklist da Pasta: ✓ que eram só no papel

Cruzamento automático das marcas do checklist (`fidelidade-designer-1310-checklist-pasta.md`, 328 linhas lidas, 284 com par na matriz) contra o status da matriz nova:

| Marca no checklist | Status na matriz | Linhas |
|---|---|---|
| ✓ | Fiel | 144 |
| ✓ | **Parcial** | **37** |
| ✓ | **Divergente** | **3** |
| ✓ | Não aplicável | 3 |
| ✓ | sem linha na matriz (IDs desmembrados ou retirados do desenho) | 22 |
| ≈ | Fiel / Parcial / Divergente | 1 / 2 / 2 |
| ∅ | Ausente / Parcial / Divergente / Fiel / N.A. | 79 / 3 / 2 / 1 / 7 |

Os 40 ✓ que caíram, com o motivo, estão abaixo. A maioria cai por um detalhe de comportamento (erro por `alert()`, recarga em vez de aviso, regra de papel), não por aparência; 3 caem por segurança (PZ-CAB-05, PZ-MET-11, PZ-PUSH-09). Correção no sentido contrário: PZ-CAB-45 (≈) pode virar ✓ (no dc a linha a 45% é código morto).


| ID | Mudança | Elemento | Motivo |
|---|---|---|---|
| PZ-CAB-03 | ✓ → Parcial | Prioridade (pílula + menu Normal/Prioridade/Urgente) | Erro por alert() |
| PZ-CAB-05 | ✓ → Parcial | "+" Marcadores | Quem só VÊ a pasta (com M:expediente) altera marcadores; token exposto |
| PZ-CAB-07 | ✓ → Parcial | Menu do responsável (busca, Sem responsável, ✓) | Erro por alert(); sem cartão no Registro (ver PZ-DAD-14) |
| PZ-CAB-15 | ✓ → Parcial | Vincular processo | Não troca para a aba Processo antes |
| PZ-CAB-24 | ✓ → Parcial | Arquivar / Desarquivar | Erro por alert() |
| PZ-CAB-25 | ✓ → Parcial | Excluir pasta | Copia o defeito do desenho (prompt nativo); trocar por janela própria vermelha com campo |
| PZ-CAB-35 | ✓ → Parcial | Selo ATIVO 3,3 s | Não carimba na carga nem na navegação entre pastas ativas |
| PZ-TRI-04 | ✓ → Parcial | Linha do prazo (pip, título, meta, selo, ⋮) | Limite 3 x 8; sem origem e sem hora |
| PZ-TRI-11 | ✓ → Parcial | Linha (iniciais, nome que abre Cadastro, ícone, Principal, C | Nome não clicável; destino Detalhes no lugar do Cadastro |
| PZ-DAD-07 | ✓ → Parcial | "editado" com dica | Sem versões guardadas |
| PZ-DAD-08 | ✓ → Parcial | Lápis "Editável por mais N min" | Regra do primário/master ausente |
| PZ-DAD-09 | ✓ → Parcial | Responder | Sem "@Nome" ao responder resposta |
| PZ-DAD-16 | ✓ → Parcial | Menu botão direito (Responder, Copiar, Encaminhar, Destacar, | Faltam Encaminhar e Destacar; regra do primário |
| PZ-PRO-09 | ✓ → Parcial | Peticionar na pasta | Destino igual, alvo diferente |
| PZ-MET-06 | ✓ → Parcial | Editar nome inline | Recarga; visibilidade usa `metaEditavel` sem `podeEditarPasta` |
| PZ-MET-08 | ✓ → Parcial | Responsáveis | Recarga em vez de aviso |
| PZ-MET-11 | ✓ → Parcial | Concluir / Reabrir com Desfazer | Autorização (Achado A-01); reabrir exige R:edit e concluir não (assimetria) |
| PZ-MET-17 | ✓ → Divergente | Excluir | Desfazer exige exclusão lógica |
| PZ-MET-18 | ✓ → Parcial | Aviso com Desfazer | Duplicar e excluir sem desfazer |
| PZ-MET-25 | ✓ → Parcial | Meta nova realçada + alerta forte da aba | Sem pulso forte da aba |
| PZ-FIN-01 | ✓ → Parcial | Contrato Pendente ⇄ Assinado | Erro por alert(); fase é P3 |
| PZ-FIN-02 | ✓ → Parcial | Pró-bono | Erro por alert() |
| PZ-FIN-26 | ✓ → Parcial | Resumo, barra, "+ R$ Z previsto em êxito e sucumbência", pró |  |
| PZ-FIN-29 | ✓ → Parcial | Ver todos / Mostrar só os próximos | Sem a volta "Mostrar só os próximos" (não confirmado no script) |
| PZ-FIN-36 | ✓ → Parcial | Marcar recebido / Desfazer | Erro por alert() |
| PZ-DOC-06 | ✓ → Parcial | Faixa, ok/total, Sugerir, editar, adicionar (Enter/Esc), int | Notificação à Controladoria e lembrete de 30 dias ausentes (ver PZ-DOC-07) |
| PZ-DOC-15 | ✓ → Parcial | Abrir, Visualizar, Baixar/.zip, Compartilhar link, Recortar, | Falta Encaminhar (PZ-DOC-17); "Compartilhar" virou "Copiar link" (PZ-DOC-16); Mover para…  |
| PZ-DET-02 | ✓ → Parcial | Compositor "Adicionar observação…" + Enviar (Ctrl+Enter) | Sem rascunho automático (ver PZ-EDT-14); texto do aviso a conferir |
| PZ-DET-06 | ✓ → Divergente | "Editar · N min" / "Editar · bloqueado" | Faltam as exceções Master (sem restrição) e primário (sem limite), pedidas no Farlei §3.3/ |
| PZ-DET-13 | ✓ → Divergente | "Excluir comentário · N min" / "Excluir · permanente" | Prazo 60 x 15 min; rótulo "permanente" x "bloqueado"; Master/primário ausentes; exclusão r |
| PZ-PUSH-05 | ✓ → Parcial | Cartão "Análise IA" (aviso, editado, lido, cadeado, recolher | Sem "editado" (não há edição, PZ-PUSH-06); dica do cadeado cita "link compartilhado com o  |
| PZ-PUSH-09 | ✓ → Parcial | Excluir análise | Quem só vê a pasta exclui (oculta para todos) a análise; ver Achados P0 A3 |
| PZ-PUSH-20 | ✓ → Parcial | ⋮ da nota: Responder, Copiar, Encaminhar, Editar/bloqueado,  | Sem exceção do primário; sem Encaminhar |
| PZ-MOD-02 | ✓ → Parcial | Campos, calendário, atalhos, responsáveis, aviso de repetida | Limite e prazo padrão divergentes |
| PZ-MOD-11 | ✓ → Parcial | Modal: busca, contagem, árvore, "Criar marcador X", Cancelar | Atualizar etiquetas sem recarga; criar marcador inline; permissão (PZ-CAB-05) |
| PZ-MOD-20 | ✓ → Parcial | Drawer "Histórico do sistema" | Vincular/desvincular processo e cadastro de cliente não deixam linha (PastaProcesso e Clie |
| PZ-MOD-23 | ✓ → Parcial | Modal de parcelamento | Desvios decididos; restam Êxito/Sucumbência (PZ-MOD-24) |
| PZ-EDT-13 | ✓ → Parcial | Colar: Manter, Mesclar, Somente texto | Imagens coladas descartadas (depende de PZ-EDT-03) |
| PZ-EDT-17 | ✓ → Parcial | @menção em editores e campos | Sem "Todos"; destino da notificação é o sino, não o Chat; lista sem par no tema escuro (VI |
| PZ-GLB-12 | ✓ → Parcial | Toast padrão `gmAviso` | Sem animação de entrada (VIS 1.17) |

## 7. Divergências visuais (análise estática)

Relatório completo da comparação visual, valores declarados no desenho contra o CSS do BlueJus. **A medição na tela não foi feita** (ver `00-LEIAME.md`, Limitações): as linhas "PRECISA MEDIR NA TELA" seguem abertas.


> Levantamento READ-ONLY, sem navegador, sem servidor, sem container, sem banco.
> Desenho: `scratchpad/proto-online/02 - EXPEDIENTES 1.2.3.dc.html` (referência `dc:LINHA`).
> BlueJus: worktree `fidelidade-designer`, HEAD `9c081725`. Referências relativas a `app/`:
> `ps` = `public/css/pasta-show.css`, `pex` = `public/css/pasta-explorador.css`, `iag` = `public/css/pasta-ia-agentes.css`,
> `psi` = `public/css/pasta-impressao.css`, `cf` = `templates/_partials/_janela_confirmacao.html.twig`,
> `cab` = `templates/pasta/_cabecalho.html.twig`, `tri` = `templates/pasta/_dados_trilho.html.twig`.
> Evidência histórica: `docs/specs/fidelidade-designer-1310.md` §5 (E2), §7 (E3), §8 (E4), §12 (E7), medições a 1440x900 em 14/10
> contra o pacote de 13/10; checklist `docs/specs/fidelidade-designer-1310-checklist-pasta.md`.
>
> Ponto de partida importante: o diff 13/10 x 16/10 (`diff-02-local-x-online.txt`) só traz, no visual da Pasta, a aba Réu do
> cadastro, o "+" e o vazio de Próximos prazos, a contagem dinâmica de Clientes e a janela "Novo prazo ou audiência". O resto do
> template visual do 02 é o mesmo de 13/10, então as medições de E2 a E8b continuam valendo como evidência para esses blocos.
>
> Legenda da coluna "Igual?": SIM (valor declarado idêntico), NÃO, ≈ (mesmo valor com diferença menor), n/c (não comparado).
> A coluna de cascata diz se alguma regra pode anular o declarado. "precisa medir na tela" quando a leitura estática não basta.

---

### 1. Tabelas por bloco

#### 1.1 Página, cartão do cabeçalho e barra do topo

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Página | fundo | #eaeef1 (dc:34, 6904) | `--ps-bg` #eaeef1 em `.ps-body`, `.app-content` (ps:365-371) | SIM | Pintado no body da tela; AdminLTE `bg-body-tertiary` vencido por `.ps-body .app-content` (0,2,0). |
| Página | largura, recuo | máx. 1500px, padding 20px 26px 60px (dc:1140) | `.ps-page` máx. 1500px, padding 20px 26px 60px (ps:381) | SIM | `.container-fluid` zerado só em `.ps-body` (ps:379). |
| Página | fonte | Arial (dc:34) | `--jp-font-sans` (ps:386; comentário diz Arial) | ≈ | Depende do valor de `--jp-font-sans` em app.css; não conferido aqui. |
| Cartão cabeçalho | fundo, borda, raio, sombra | #fff, 1px #dde5eb, 4px, 0 1px 2px rgba(16,42,58,.04) (dc:1142) | `.ps-card` mesmos valores (ps:407) | SIM | |
| Cartão cabeçalho | padding | 20px 24px 0 (dc:1142) | `.ps-cabecalho` 20px 24px 0 (ps:463) | SIM | ≤767.98px vira 16px 16px 0 (ps:2088), desenho sem regra. |
| Barra do topo (global) | menu, logo, escritório, sino, avatar, navegação | estáticos, sem handler; ≤640px esconde escritório e nome (dc:73-78, 844-869) | navbar AdminLTE global `nav.app-header.navtop` fixa, z 1050 (app.css:109-116) | n/c | Fora do escopo de mudança (global). Só registro: a barra é `position: fixed` com 58px + 44px; isso afeta camadas (ver VIS-01). |

#### 1.2 Cabeçalho, linha 1 (voltar, número, prioridade, marcadores, setas, ⋮)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Linha 1 | gap, margem | flex gap 12px, margin-bottom 10px (dc:1143) | `.ps-cab-linha1` gap 12px, mb 10px (ps:465) | SIM | |
| Voltar | altura, padding, fonte, cor | 30px, 0 11px 0 9px, 12.5px/500 #455c6b, ícone 13px, sem borda (dc:1145) | `.ps-btn--voltar` 30px, 0 11px 0 9px, 12.5px; `.ps-cab-voltar` borda e fundo transparentes, #455c6b (ps:441, 467-469) | SIM | Peso 500 vem de `.ps-btn` (ps:427). |
| Voltar | hover | fundo #e5f1f7, cor #0a7aad, transição .18s (dc:1145) | fundo `--ps-accent-bg`, cor accent (ps:468); transição de `.ps-btn` só em border-color e color (ps:431) | ≈ | O fundo troca sem transição no BlueJus (ver VIS-09). |
| PASTA nnnn | fonte | 15px/700, .06em, #12242f, número tabular, gap 6px baseline (dc:1147) | `.ps-cab-nup` 15px/700 .06em, inline-flex baseline gap 6px (ps:691-703); número com `letter-spacing:0` (ps:704) | SIM | No dc só o span "PASTA" declara .06em; o do número não declara. No BlueJus o .06em está no contêiner e o número o zera. Resultado igual. |
| Separadores | tamanho, cor | 1x16px #dde5eb (dc:1150, 1169) | `.ps-cab-sep` 1x16px `--ps-border` #dde5eb (ps:705) | SIM | |
| Prioridade | pílula | 11px/600 .05em uppercase, 3px 9px, raio 3px; Normal #4a6274/#eef2f5/#dde5eb, Prioridade #8a5a12/#fdf1dc/#f0dcb4, Urgente #a3232b/#fdeceb/#f2c9c6 (dc:6602-6609) | `.ps-prio` e variantes, mesmos valores via tokens (ps:707-724) | SIM | |
| Prioridade | menu | 176px, top 26px, borda 1px #dde5eb, sombra 0 10px 30px rgba(16,42,58,.14), padding 5px; item 13.5px 8px 10px raio 3px, 400/600 (dc:1158, 6616) | `.ps-pop` + `.ps-pop--prio` (ps:501-512, 634-641) | SIM | O BlueJus põe o X de fechar (`.ps-pop:has(> .ps-pop-x)` abre 38px no topo, ps:561) que o dc não tem neste menu: altura maior. Extra registrado (PAS-022). |
| Marcadores | etiqueta | 11px/600 .04em uppercase, 3px 9px, raio 3px, ícone 9px .75, cor do marcador (dc:6624-6626) | `.ps-etiqueta` + `--cor` com `color-mix` (ps:727-754) | ≈ | Tom calculado por `color-mix` a partir de `Marcador.cor`; o dc usa trios fixos por marcador. precisa medir na tela com marcador real. |
| "+" marcadores | caixa | 24x24, 1px dashed #cdd7de, raio 3px, #6b8494; hover #e5f1f7/#0a7aad/#9fc3e3 scale 1.08 (dc:1173) | `.ps-etiqueta-add` idem (ps:758-776) | SIM | |
| Responsável | posição | linha 1, à direita, margin-right 22px (dc:1177) | faixa de dados da linha 2 (cab:486-494) | NÃO | Decisão P1 (preservada). Ver VIS-21. |
| Responsável | chip | avatar 28px gradiente #1597bd→#0c7a9c, sombra 0 4px 10px -5px; nome 14.5px/700 máx. 170px; chevron 11px #6b8494; hover #eef5fa (dc:1180-1183, 3125-3126) | `.ps-cab-dados .pasta-resp-chip` 14.5px/700, avatar 28px `!important`, gradiente e sombra por token, nome 170px com reticências (ps:2135-2159) | SIM | `_resp_estilo` é `<style>` inline posterior à folha (compartilhado com o Expediente); o BlueJus vence por especificidade (0,2,0+) e `!important` no avatar. E4 mediu 170px e 14.5px/700 (spec §8). |
| Responsável | menu | 310px, faixa gradiente com busca 36px branca, lista máx. 300px, linha 7px 9px 13px, ativa #e3f0f8/700, sombra 0 1px 3px rgba(0,0,0,.16), animação menuIn (dc:1187-1202, 3120) | `.ps-body .pasta-resp-menu` e filhos (ps:~4395 em diante, "auditoria 2 C1") | ≈ | Valores alinhados por token; sem animação de entrada (VIS-09). precisa medir na tela (cascata com `_resp_estilo`). |
| Setas | botão | 32x32, raio 4px, #455c6b, desab. #c5d0d8, hover #e5f1f7/#0a7aad (dc:6045) | `.ps-btn--seta` 32x32, transparente, `--ps-text-2`; `.ps-btn--inerte` #c5d0d8 (ps:485-497) | SIM | Comentário em ps:484 diz "28px" (desatualizado); a regra é 32px. |
| Setas | "N de M" e divisória | 11.5px #8496a3 tabular, padding 0 4px; border-right 1px #e6edf2, pr 8px, mr 2px (dc:1209-1211) | `.ps-cab-pos` e `.ps-cab-nav` (ps:481, 492) | SIM | |
| ⋮ | botão | 34x32, 15px, #455c6b, sem borda, hover #e5f1f7/#0a7aad (dc:1215) | `.ps-cab-mais` (ps:472-474) | SIM | |
| ⋮ | menu | 260px, top 40px, máx. calc(100vh - 120px), raio 4px, sombra 0 1px 3px rgba(0,0,0,.16), padding 0 6px 6px, animação menuIn .18s (dc:1218) | `.ps-pop--menu` mesmos valores (ps:513-520); sem animação | ≈ | Sem `menuIn` (VIS-09). |
| ⋮ | tarja | margin 0 -6px 6px, padding 10px 16px, gradiente 135deg #0d80a3→#0b6688, 10.5px/700 .1em uppercase branco, ícone 13px .9 (dc:1219) | `.ps-pop-tarja` idem (ps:523-536) + X 26x26 na tarja (ps:579-581) | ≈ | X de fechar é extra do BlueJus (PAS-022, vindo do `bluejus-central.js`). |
| ⋮ | item | 13.5px #243845, 8px 10px, raio 4px, ícone 14px #5f7684 16px; atalho 11px #8496a3; hover #eef5fa (dc:1220) | `.ps-pop-item`, `.ps-pop-atalho` (ps:633, 644-660) | SIM | |
| ⋮ | seção, divisória, perigo | 10.5px/700 .08em #8496a3; 1px #eef2f5 margin 4px 8px; Excluir #a3232b hover #fdf1f0 (dc:1228, 1243) | `.ps-pop-secao`, `.ps-pop-sep`, `.ps-pop-item--perigo` (ps:624-631, 666-669) | SIM | |
| ⋮ | itens presentes | Mover, Link externo do Push, Cadeado, Acompanhar (dc:1226-1239) | ausentes (cab:213-295) | NÃO | Dado (sem backend). Menu fica mais curto. VIS-26. |
| ⋮ | interruptor | 30x17, ligado #0a7aad, desligado #cfd9e0, bola 13px (dc:6150-6151) | Favoritos 30x17, `--ps-switch-off` #cfd9e0 (cab:257-264) | SIM | E2 e checklist C7. |

#### 1.3 Cabeçalho, linha 2 (cliente, movimentação, documento, IA, selos, faixa)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Bloco cliente | margem, gap | margin 2px 0 24px, coluna gap 5px; linha gap 12px wrap (dc:1251-1252) | `.ps-cab-cliente` margin 2px 0 24px, gap 12px wrap (ps:782) | ≈ | O dc embrulha cliente e andamento numa coluna com gap 5px; sem andamento (P3) a diferença não aparece. |
| Foto | botão | 38px, gradiente #e3f1f7→#cfe6f0, ícone 21px #0c7a9c, anel interno; hover scale 1.06 + anel 3px (dc:1253) | `.ps-cab-cliente-icone` 38px, mesmo gradiente e anel (ps:783-795); não é botão, sem hover | ≈ | Sem menu de foto (dado). VIS-20. |
| Nome | h1 | 28px/600, lh 1.25, -0.005em, uppercase, máx. min(50vw,640px), animação pcIn .4s ao trocar (dc:1255) | `.ps-cab-titulo` mesmos valores (ps:798-808); sem animação | ≈ | ≤767.98px vira 22px, máx. 100% (ps:2089); o dc não tem regra (VIS-15). |
| Movimentado | texto e ponto | 12.5px/500 #6b8494, gap 7px; ponto 8px radial #3fd08a→#1f9d61, sombra 0 0 6px rgba(31,157,97,.55), movBrilho e movPulso 2.4s (dc:1255-1257) | `.ps-cab-mov`, `.ps-cab-mov-ponto` (ps:820-847) com `box-shadow` estática `--ps-ok-halo` (.16) | SIM | A animação `psMovBrilho` sobrescreve a sombra estática nos dois (animação vence declaração). Texto fixo no dc, real no BlueJus. |
| Documento | fonte | 12px/400 .02em #8496a3 tabular (dc:1259) | `.ps-cab-doc` 12px/400 .02em `--ps-faint` (ps:809-815) | ≈ | Sem `font-variant-numeric: tabular-nums` no BlueJus. Diferença mínima em CPF (largura dos dígitos). |
| Dica do nome | balão | #e3f0f8/#0b5f86 12.5px/700, padding 7px 11px, raio 4px, sombra 0 8px 18px -10px (dc:1262) | CSS puro `:has(:hover)` (cab:360-362, 657-674) | n/c | Valores não lidos linha a linha; checklist marca ✓. |
| BLUEJUS Intelligence | botão | 32px, padding 0 11px 0 8px, raio 4px, hover #e5f1f7; BLUEJUS 10.5px/700 .12em #0a6fa0; Intelligence 14.5px/700 (dc:1266-1270) | `.ps-cab-ia*` (iag:120-141) | SIM | Medido na E3 (spec §7). Foco divergente e aceito (P4). |
| Selo carimbado | ARQUIVADO / ATIVO | 150x104 com zoom .8, -14°, #5d7383 / #1f9d61 (dc:6116-6129) | `.ps-cab-selo` já multiplicado por .8 (120x83), mesmas cores (ps:865-972) | ≈ | `zoom` x medidas multiplicadas: o resultado visual deve coincidir, mas o arredondamento de máscara e borda precisa medir na tela. SUSPENSO e CANCELADO não existem (dado). |
| Faixa Processo · Ação · Responsável | bloco | ausente no online (Ação e Processo vão para o trilho) | `.ps-cab-dados` grid auto-fit min 220px, gap 4px, mb 20px (ps:852-863) | NÃO | Decisão P1 (preservada). |

#### 1.4 Cabeçalho, linha 3 (abas, Cadastro, Situação)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Faixa das abas | borda, padding | border-top 1px #eef2f5, padding 14px 0 16px, gap 10px wrap (dc:1328) | `.ps-abas-faixa` idem (ps:1120-1127) | SIM | |
| Trilho | fundo, borda, raio, padding, gap | #eef2f5, 1px #e0e7ec, 4px, 4px, gap 3px, `flex: 1 1 600px`, nowrap, sem overflow (dc:1329) | `.ps-abas` idem + `overflow-x: auto` e barra oculta (ps:1128-1143) | ≈ | O BlueJus rola quando não cabe; o dc confia no modo compacto. Ver VIS-04. |
| Aba | fonte, padding | 12.5px uppercase .03em, padding 9px 10px, ativa 600 #0a7aad, inativa 500 #4f6878, ícone 14px (ativo accent, inativo #8496a3), `min-width: 0` (dc:6502-6504) | `.ps-aba` idem, mas `min-width: max-content` (ps:1170-1199) | NÃO | Mudança deliberada (comentário ps:1173-1177) para o rótulo não vazar sobre a vizinha. Efeito: rolagem em vez de compressão entre ~768 e ~1040px. precisa medir na tela a 1024. |
| Indicador | sublinhado | border-bottom 2.5px #0a7aad; com pendência na ativa 3px #c0392f; mola .62s cubic-bezier(.22,1.2,.28,1) (dc:6906-6910) | `.ps-abas-ind` idem; vermelho por `:has(> .ps-aba--pend.active)` (ps:1145-1165) | SIM | `:has()` é aprimoramento progressivo; sem suporte fica azul. |
| Selo da aba | chip | 10.5px/600, 1px 6px, raio 3px, inativo #6b8494/#e2e8ed, ativo accent/#e5f1f7 (dc:6505-6506) | `.ps-aba-badge` idem (ps:1236-1248) | ≈ | O BlueJus esconde o selo com 0; o dc mostra "0" nas Metas. VIS-07. |
| Linha de pendência | barra | left/right 14%, bottom 2px, 2.5px (3px forte), #c0392f, metaAlerta 7s; só quando a aba NÃO está ativa (dc:6496) | `.ps-aba-pend` idem; some na ativa (ps:1206-1234) | SIM | O checklist (PZ-CAB-45) diz "no desenho fica a 45% na aba ativa", mas o `alertaSt` só é usado com `alerta = ... && !on`; o `filter: opacity(.45)` para `on` é código morto. O BlueJus está igual. Sugere corrigir o ≈ do checklist para ✓. |
| Cadastro | botão | 44px, 0 15px, 13px/700 .06em uppercase, #eef2f5/#34505f, hover #e3eff6/#0a7aad; ponto 8px #1f9d61/#d39222 com anel 2px #eef2f5 (dc:3442-3443) | `.ps-cad-btn`, `.ps-cad-ponto` (ps:4658-4693) | SIM | O destino do clique difere (abre Detalhes do cliente): comportamento, não visual. |
| Situação | pílula | 44px, 0 14px, 13.5px/700, raio 4px; Ativo #e6f4ec/#186c47, ponto 8px #1f9d61 com halo 3px e duas ondas 2.4s; Arquivado #eef2f5/#4f6878 (dc:6132-6142, 1348) | `.ps-situacao-pill` e variantes (ps:1068-1108) | SIM | Suspenso e Cancelado ausentes (dado, VIS-19). |
| Situação | menu | 236px, top calc(100% + 8px), tarja, item 9px 10px, título 13.5px/600 #243845, desc 11.5px #6b8494, ✓ 15px (dc:1354-1363) | `.ps-pop--situacao`, `.ps-pop-item--situacao` (ps:519, 1109-1113) | SIM | Sem animação de entrada. |

#### 1.5 Aba Dados: Registro dos expedientes e editor

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Grade | colunas, gap | minmax(0,1fr) 356px, gap 18px; 1 coluna ≤980px (dc:72, 1374) | `.ps-grade` idem (ps:1283-1292) | SIM | |
| Painel | cabeçalho | padding 15px 20px, #f5f6f7, borda #dfe4e8, h2 14px/600, contador 11px #7b93a2 (dc:1377-1379) | `.ps-card-cab--painel` (ps:1304-1305) + `.ps-contagem` (ps:1366) | SIM | |
| Compositor | faixa | padding 16px 20px, #fafcfd, borda inferior #eef2f5 (dc:1382) | `.ps-compositor` (ps:1376) | SIM | |
| Editor | caixa | borda 1px #d3dde5, raio 4px; foco #8fbde6 + anel 3px rgba(15,111,196,.12); barra #eef2f5; conteúdo 14.5px/1.55, padding 13px 15px, 70 a 420px (bj-editor.js:9-40) | `.ps-compositor-caixa` e regras do Quill (ps:1379-1417) | ≈ | `editor-rico.css` é carregado DEPOIS da folha (três vezes, dentro dos parciais) e traz raio 6px e 96px de altura; o BlueJus neutraliza por especificidade (ps:1408-1414). Barra do dc tem raio 4px 9px 0 0 (9px acima do teto); o BlueJus usa 3px. precisa medir na tela. |
| Linha do tempo | área | #eef2f5, padding 6px 18px 10px 46px, linha 2px #d3dbe1 em left 27px (dc:1385-1386) | `.ps-registro` e `::before` (ps:1424-1433) | SIM | |
| Pílula do dia | fonte | 13px/700 #3c4a54 sobre #dde3e8, 5px 16px, raio 4px, margem 12px 0 10px (dc:1389) | `.ps-dia`, `.ps-dia-pilula` (ps:1438-1447) | SIM | |
| Cartão | caixa | padding 12px 16px, raio 4px, sombra 0 1px 2px rgba(16,42,58,.08); hover 0 8px 20px -14px rgba(12,80,120,.45) (dc:1390) | `.ps-registro .ps-anotacao` (ps:1453-1465) | SIM | |
| Cartão | ponto e seta | ponto por etiqueta (`dotSt`); seta 7px branca em left -7px top 16px (dc:1393-1394) | ponto 13px accent com borda 2px; seta idem (ps:1467-1490) | ≈ | O dc pinta o ponto pela cor da etiqueta (Combinado, Ligação...); o BlueJus não tem etiqueta (dado), ponto sempre azul. |
| Cartão | autor, hora | avatar 24px gradiente, iniciais 9.5px/700; autor 13px/700 #12242f; hora 12px #5f7684 no fim da linha (dc:1396-1404) | `.ps-avatar--24`, `.ps-anotacao-autor`, `.ps-anotacao-data` (ps:1061, 1491-1493) | SIM | |
| Cartão | botões | lápis 28x26 #0a7aad hover #e1eef6; Responder e Encaminhar 28x26 #7fb3cf 15px, hover fundo #0a7aad texto branco, só no hover do cartão (dc:23, 1401-1403) | lápis (ps:1496-1514); Responder com opacidade 0 até hover/foco, sempre visível em `hover: none` (ps:1586-1597) | ≈ | Encaminhar ausente (dado). Excluir é botão escondido acionado pelo menu (correto). |
| Cartão | texto | megafone 13px #3c4a54 espelhado; corpo `bj-rich` 14.5px/1.6 #243845 (dc:1407) | `.ps-anotacao-icone`, `.ps-anotacao-texto` (ps:1515-1517) | SIM | |
| Resposta | faixa e caixa | faixa #e6f0f6 borda #b9d2e2 11px/700 .06em #0b5f86; recuo 36px; caixa com inset 3px #0a7aad; textarea 56px; botões 30px (dc:1392, 1410-1413) | `.ps-anotacao-resposta-rot`, `.ps-respostas`, `.ps-compositor-respondendo` (ps:1539-1625) | ≈ | O BlueJus responde no compositor de cima com faixa "Respondendo a"; o dc abre caixa sob o cartão. Arranjo diferente, valores de cor iguais. precisa medir na tela. |
| Ver anteriores | botão | 13px/600 #0a7aad, padding 13px, raio inferior 4px, hover #f4f9fd (dc:1419) | `.ps-anotacoes-mais` (ps:1656-1668) | SIM | Contagem real no BlueJus; fixa "(6)" no dc. |

#### 1.6 Trilho direito (Ação, Processo, Próximos prazos, Clientes, Documentos)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Coluna | gap | 14px (dc:1422) | `.ps-trilho` gap 14px (ps:1289) | SIM | |
| Ação | cartão | padding 12px 16px; rótulo 10.5px/700 .1em #7b93a2 ícone 12px #0c7a9c; valor 14.5px/700 ou vazio 13.5px/500 #8a9aa6 (dc:1423-1427, 6065) | ausente no trilho; os mesmos valores na faixa do cabeçalho (ps:986-992) | NÃO | Decisão P1. VIS-22. |
| Processo vinculado | cartão | número 14.5px/700, sub 12.5px #6b8494, chip apensos 22px #eaf3f8/#0b5f86 11.5px/700 raio 4px; lista com borda 2px #d7e6ef (dc:1429-1446) | na faixa do cabeçalho; `.ps-chip-vinc` raio 999px (ps:1007-1023) | NÃO | Decisão P1. O chip ficou em pílula de propósito (E2 §3.2a). |
| Cabeçalho dos cartões | faixa | min 46px, 8px 17px, #eef2f5, borda #e0e7ec, h2 13.5px/700 .01em, contagem 11px/700 #0b5f86 sobre #e5f1f7 1px 8px raio 4px (dc:1456-1458) | `.ps-trilho .ps-card-cab`, `.ps-trilho .ps-contagem` (ps:1308-1323) | SIM | |
| Próximos prazos | "+" | 30x28, #0a7aad 16px, hover #e5f1f7 [NOVO-16/10] (dc:1460) | ausente (tri:22-31) | NÃO | Dado: não há prazo manual. VIS-03. |
| Próximos prazos | chevron | 30x28, mr -8px, #7b93a2, hover #e5f1f7/#0a7aad (dc:1461) | `.ps-card-cab-link` (ps:1332-1347) | SIM | |
| Próximos prazos | vazio | uma linha: padding 16px 17px, 12.5px #7b93a2 (dc:1463) | `.ps-vazio` padding 40px 24px, ícone 30px #c3d2dd, título 14.5px/600, nota 13px (ps:1670-1674; tri:32-37) | NÃO | Visual. VIS-03. |
| Próximos prazos | linha | padding 12px 17px, borda #f4f7f9, gap 11px, hover #f7fafc; pip 7px; título 13.5px/500; meta 12px #7b93a2 (dc:1465-1469) | `.ps-linha`, `.ps-pip`, `.ps-linha-titulo/meta` (ps:1705-1725) | SIM | |
| Próximos prazos | selo | 11px/600, 3px 9px, raio 4px; vermelho ≤3 dias ou vencido, âmbar ≤7, cinza (dc:6555-6564) | `.ps-selo` (ps:1730-1741); limiares em `PastaPrazoOutput::tomDosDias` ≤3 / ≤7 | SIM | Comentário em ps:1723-1725 fala "até 2 / até 8" (desatualizado); a regra no PHP está igual ao dc. Texto do selo difere (VIS-08). |
| Próximos prazos | ⋮ | 22x22, margin -1px -8px 0 -4px, raio 3px, #8496a3, hover #e5f1f7/#0a7aad (dc:1472) | `.ps-prazo-mais` (ps:4018-4033) | SIM | |
| Clientes | "+" | 32x32 #0a7aad 16px (dc:1482) | `.ps-card-cab-add` (ps:1350-1364) | SIM | |
| Clientes | busca | faixa 10px 14px 12px #f8fbfc; campo 34px borda #d3dde4, foco #0a7aad + anel 3px (dc:1485-1488) | `.ps-vinc`, `.ps-vinc-input` (ps:1800-1836) | SIM | |
| Clientes | linha | padding 11px 17px, borda #f4f7f9, gap 10px; principal fundo #fbfdfe; avatar 28px 10px/600 (principal accent/branco, demais #e7eef4/#2a4353); nome 13.5px/500; CPF 11.5px #7b93a2; Principal 10px/700 #8a5a12/#fdf1dc (dc:1501-1510, 6543-6545) | `.ps-clientes .cliente-*` (ps:2167-2249) com `!important` no padding e gap | SIM | Os `!important` existem porque o miolo legado tem estilo próprio; precisa medir na tela se nenhum inline do JS de vincular escapa. |
| Clientes | Abrir cliente | 32x32, ícone 17px #7fa6c2; hover troca para pasta aberta, scale 1.15 -6°, drop-shadow (dc:6540-6541) | `.cliente-abrir` (ps:2252-2273) + `psPastaAbre` e filtro (ps:4470-4490) | ≈ | Valores presentes; a troca de ícone (folder-fill para folder2-open) precisa medir na tela. |
| Clientes | ações extras | nenhuma (só Abrir) | estrela e lixeira no hover; estrela cheia sempre no principal (ps:2211-2234) | NÃO | Divergência deliberada documentada (PMC-051, tri:118-127). VIS-14. |
| Documentos | linha | padding 11px 17px, borda #f4f7f9, hover #f1f7fb; nome 13px/500; meta 11.5px #7b93a2 (dc:1525-1529) | `.ps-doc`, `.ps-doc-nome/meta` (ps:1913-1958) | SIM | |
| Documentos | ícone do tipo | composto: página + contorno + selo com letra (`fBox/fPage/fOut/fBadge`, dc:1526) | ícone Bootstrap 30px colorido por `arquivo_icone` (ps:1950) | ≈ | VIS-13. precisa medir na tela. |

#### 1.7 Aba Metas

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Cabeçalho | faixa | 15px 20px #f5f6f7 gap 12px wrap (dc:1542) | `.ps-card-cab--painel` + `.ps-metas > .ps-card-cab` (ps:1304, 3319) | SIM | |
| Filtros | pílula | 12.5px, 5px 11px, raio 4px, ativo borda accent e #e5f1f7 (dc:6644 em diante) | `.ps-metas-filtro` (ps:3381-3399) | SIM | E2 mediu repouso, hover e foco (spec §5). |
| Nova meta | botão | 36px, 0 16px, #0a7aad, 13px/700, raio 4px, ícone 13px, hover #0b5f86 (dc:1550) | `.ps-btn--primario.ps-btn--alto` 36px 0 16px 700 + hover `--ps-nova-meta-hv` (ps:3075, 4316-4319) | SIM | O BlueJus tem borda 1px na cor do fundo (box-sizing): invisível. |
| Linha | cartão | 17px 22px, mt 16px, borda esquerda 3px (#1f9d61, #c0392f, #c8952a), concluída #f3faf6, atrasada #fdf2f1, hover translateY(-2px) scale(1.012) (dc:6661-6689) | `.ps-meta` e variantes (ps:3321-3342) | SIM | |
| Linha | textos | título 14.5px/600; nº local 14.5px/700 #0b6a96; dados 12.5px #6b8494; selo 11px/600 raio 999px | `.ps-meta-titulo`, `.ps-meta-num`, `.ps-meta-linha`, `.ps-meta-status` (ps:3344-3375) | SIM | Nº global (11px/700 #e9f1f6) não existe por decisão (id global de todos os escritórios). |
| ⋮ da meta | botão e menu | 30x30; menu 220px | `.ps-meta-menu-btn`, `.ps-pop--meta` (ps:3402-3425) | SIM | |
| Drawer do relatório | painel | 460px, gradiente #0d80a3→#0b5f86, título 18px/700 | `psMrel*` (ps:3537 em diante) | n/c | Não lido linha a linha. |

#### 1.8 Aba Processo e nota técnica

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Cabeçalho | interruptor e botões | interruptor 34x18 (ligado #e8873a, rótulo #b4460a); botões 32px #e5f1f7/#0b5f86 13px/500; Peticionar #0a7aad 13px/600 uppercase .03em (dc:1700 em diante) | `.ps-adm-sw*` (ps:4333-4356), `.ps-btn--suave`, `.ps-btn--caps` (ps:3064-3067, 4359-4361) | SIM | |
| Cartão | número, rótulos, valores | padding 16px 18px; número 17px/500; rótulos 10.5px/700 .1em #7b93a2; valores 14px/500; situação 14px/600 #186c47 | `.ps-processo*` (ps:3089-3140) | SIM | Situação legível desde a E4. |
| Selo Principal | chip | #eaf2fa/#2a5f8f borda #cfe0f0 | `.ps-processo-principal` (ps:3104-3117) | SIM | |
| Nota técnica | caixa | #f6fafd, borda #b9d6e8, raio 4px, selo #0a5f86 10px/800, conector 1.5px #c9dfec | `nota-tecnica.css` (tokens `--ps-nota-*`) | SIM | Medido na E3 (spec §7). |

#### 1.9 Aba Financeiro

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| KPIs | grade e cartão | minmax(200px,1fr) gap 12px; cartão 13px 16px; rótulo 10.5px/700 .1em #7b93a2 (Média #a08444); valor 24px/600 (dc:1861 em diante) | `.ps-fin-faixa`, `.ps-fin-bloco`, `.ps-fin-rotulo`, `.ps-fin-numero` (ps:2297-2352) | SIM | |
| Chips Contrato e Pró-bono | chip | 30px 12px/600 .04em uppercase (Pendente #a3232b/#fdeceb/#f2c9c6; Assinado #186c47/#e2f4ea/#bde3cd; inativo #5f7684/#eaeef1/#dde5eb) | `.ps-fin-selo*` (ps:2363-2380) | ≈ | O neutro usa `--ps-bg` #eaeef1 e `--ps-text-3` #5f7684: SIM. |
| Pagamentos | linha e selos | linha 10px 17px; selos Pago, Previsto #0b5f86/#e5f1f7, Vencida #b03a2e/#fbeceb, Pendente #8a5a12/#fdf1dc | `.ps-pag-*` (ps:2521-2697) | SIM | Valores por token ("auditoria 2" F1 a F17). |
| Relatório | observações | sem rolagem interna (dc:1890 de 13/10) | `.ps-fin-obs-rolagem` só raio (ps:2402) | SIM | Medido na E4. Placeholder diferente (FIN-05, dado/texto). |

#### 1.10 Aba Detalhes

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Painel e linha do tempo | mesmos valores do Registro (dc:2430-2524) | mesmas classes `.ps-registro` | SIM | |
| Trilho | cartões padding 17px; "Ver histórico" 32px #e5f1f7/#0b5f86 13px/500 | `.ps-btn--suave .ps-btn--largo` | ≈ | n/c linha a linha. "Como usar esta aba" existe só no BlueJus (P2). |

#### 1.11 Aba Documentos (explorador)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Moldura | cartão | UM cartão: #fff, 1px #dde5eb, 4px, sombra .04, cabeçalho 15px 20px #f5f6f7 (dc:2148-2152) | `.pex` é cartão (pex:259-262) E o `#documentos` também vira cartão com padding 18px 20px pela regra `.ps-paineis > .tab-pane:not(...)` (ps:1274-1280), que não exclui `#documentos` | NÃO | Moldura dupla com 18/20px de respiro em volta do explorador. Nenhuma regra que anule foi encontrada. VIS-02. |
| Cabeçalho e busca | valores | busca 32px #f1f5f8 min 230px, foco branco + anel 2px rgba(15,111,196,.35); Nova pasta #e5f1f7/#0b5f86 13px/500; ANEXAR #0a7aad 13px/600 uppercase (dc:2152 em diante) | tokens `--pex-*` (pex:15-62, 523) | SIM | E2 mediu busca em hover e foco (spec §5). |
| Seleção, alvo, duplicado, pasta, menu, aviso | #dcebf6 + #9fc3e3; #cfe6f7; #fffaf0 + #e9a23b; #f2b93b; 240px; "Desfazer" #7cc4ff | pex:65-195, 1316, 1378 | SIM | |

#### 1.12 Aba Push processual

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Faixa | cabeçalho | min 46px, 8px 17px, #eef2f5; título 13.5px/700 sem espaçamento; contagem 11px/700 (dc:2412-2413) | `.ps-card-cab--faixa` + `.ps-push > .ps-card-cab--faixa` (ps:3079-3081, 4375-4380) | SIM | |
| Cartões e chips | tipos | Intimação #b42318/#fdecea; Decisão #0b5f86/#e3f0f8; Juntada #4f6878/#eef2f5; Citação #1e7b45/#e6f4ec; não lida inset 3px #0a7aad | tokens `--ps-push-*` (ps:183-193) e regras (ps:2847-3058) | SIM | Análise IA (gradiente #f8f5ff→#f4f8fd) em `pasta-ia.css`. Ações à vista x dentro do teor: arranjo (PUSH-13/15, ≈ no checklist). |
| Espaço acima | margem | 16px no Push, 18px nas outras (dc:6595) | `.ps-paineis:has(> #push.active)` 16px (ps:4392) | SIM | |

#### 1.13 Drawer BLUEJUS IA e nota técnica

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Fundo | véu | rgba(10,30,45,.18) z 95 (dc:938 em diante) | `.ia-drawer-fundo` z 95 (iag:144-151) | SIM no valor | No dc a barra do topo não é fixa. No BlueJus a navbar é fixa com z 1050 (app.css:109-116): o véu z 95 fica ABAIXO dela. |
| Painel | caixa | 460px, z 96, #f7fafc, sombra -24px 0 60px -30px, entrada iaIn .38s | `.ia-drawer` 460px, z 96, top 0 (iag:152-166) | SIM no valor | Mesmo problema: z 96 < 1050 e `top: 0`. Os primeiros ~102px do painel (cabeçalho com marca e X) devem ficar sob a navbar. VIS-01, precisa medir na tela. Para comparação, o drawer do Histórico usa z 1056 e a Timeline 1058 (ps:1989, 5066), o que mostra que o problema já foi resolvido lá. |
| Cabeçalho | faixa | gradiente #0d80a3→#0b6688, padding 14px 16px 12px, "BLUEJUS IA" 10px/800 .18em | `.ia-drawer-cab`, `.ia-drawer-marca-sup` (iag:169-195) | SIM | |
| Conteúdo | abas e CTA | abas 32px 12px/700 (ativa branca #0b6688); CTA gradiente 42px; Análise/Documentos/Relatórios/Perguntar | lista de 7 agentes, 4 contadores | NÃO | Dado/funcionalidade (IA-03, IA-04, IA-10). VIS-27. |

#### 1.14 Menus ⋮ e de contexto

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Menu genérico `gm` | caixa | 230px, título 10.5px/700 .06em #7b93a2, item 14px ícone, separador 1px #eef2f5 4px 8px, perigo #a3232b, hover #eef5fa; sombra `--ps-shadow-gm` 0 10px 30px rgba(16,42,58,.2) (dc:362-371, 4607-4615) | não há componente único: cada área tem o seu (registro, observação, processo, metas, financeiro, explorador, push) | ≈ | Token `--ps-shadow-gm` existe (ps:164). Larguras por área: 220px (meta), 240px (prazo, explorador), 260px (⋮). precisa medir na tela cada menu. |
| Botão direito fora fecha | comportamento | sim (dc:363) | por área | n/c | Comportamento. |

#### 1.15 Janela de confirmação (E7)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Fundo | véu, z | rgba(16,36,48,.32), z 2147483500 (dc:354) | `.ps-cf-fundo` idem (cf:14-15) | SIM | |
| Janela | caixa | min(420px,100%), raio 4px, sombra 0 18px 50px rgba(16,42,58,.28) (dc:355) | `.ps-cf` idem (cf:24-27) | SIM | |
| Cabeçalho | faixa | 12px 14px #0b6a96, ícone 16px, título 14px/700, X 30x30 rgba(255,255,255,.16) (dc:356) | `.ps-cf-cab`, `.ps-cf-titulo`, fechar (cf:28-34) | SIM | |
| Texto | corpo | 14px 16px, 13.5px/1.55 #243845 (dc:357) | `.ps-cf-texto` (cf:35) | SIM | |
| Rodapé | botões | 10px 14px, borda #eef2f5, #fafcfd; Cancelar 34px 0 14px #eef2f5/#34505f 13px/700; OK 34px 0 16px azul #0a6fa0 ou vermelho #b3262e (dc:358, 6244) | `.ps-cf-rodape`, `.ps-cf-btn*` (cf:36-45) | SIM | O BlueJus acrescenta hover que escurece o OK (#085a82 / #921f26); o dc não define hover. Extra aceitável (desenho omisso). Medido na E7 a 1440 e 390 (spec §12). |

#### 1.16 Modais: Editar dados, Histórico, Impressão

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Editar dados | véu e posição | rgba(14,37,51,.38), alinhado ao topo com padding 4vh, entrada finFade .2s + finIn .26s (dc:633-634) | Bootstrap `.modal` sem override de backdrop em nenhuma folha (grep em `public/css`); `.modal-dialog` padrão | NÃO | Véu do Bootstrap 5.3 (#000 a .5) e margem padrão de 1.75rem; animação `fade` do Bootstrap. VIS-06. Confirmar a cor do véu na tela (o AdminLTE pode trocar a variável). |
| Editar dados | moldura | 720px, raio 4px, sombra 0 24px 60px -20px rgba(10,40,60,.45), Arial | `.ps-modal-pasta` (ps:4507-4516) | SIM | |
| Editar dados | cabeçalho | 12px 16px, gradiente 180deg #0d80a3→#0b6f8f, ícone 38px, título 15px/700 .04em uppercase, sub 12px #d7eef6, X 32px hover rgba(255,255,255,.14) (dc:635-641) | `.ps-modal-pasta-cab*` (ps:4517-4565) | SIM | |
| Editar dados | abas | faixa #eef2f5 borda #e0e7ec padding 0 10px; aba 40px 0 12px 13px/700, ativa borda 2px #0a7aad e #0a6a96, inativa #4f6878 (dc:643-644, 4477) | `.ps-modal-pasta-abas/aba` (ps:4702-4729) | SIM | |
| Editar dados | campos e rodapé | rótulo 10.5px/700 .08em #6b8494; campo 34px borda #d3dde4 13.5px, foco anel 3px; rodapé 12px 16px; botões 34px 13px/700 (Fechar #eef2f5/#34505f, Cadastro #e3eff6/#0a6a96) (dc:646-664) | `.ps-modal-pasta-rotulo`, `.form-control`, `.ps-modal-pasta-rodape`, `-btn*` (ps:4567-4619, 4730-4732) | SIM | `.form-control` do Bootstrap tem padding e line-height próprios; `min-height: 34px` pode virar 38px se o padding do Bootstrap vencer. precisa medir na tela. |
| Histórico | véu e painel | rgba(12,32,45,.36) z 60; 430px z 61, sombra -14px 0 40px rgba(12,32,45,.18) (dc:2880-2881) | `.ps-drawer-overlay` mesma cor z 1055; `.ps-drawer` 430px z 1056 (ps:1964-1995) | SIM | z mais alto de propósito (navbar fixa). |
| Histórico | cabeçalho e itens | 20px 22px, borda #e4eaef, h2 15px/600, sub 12.5px #6b8494, X com borda #dde5eb; dia 11px/500 .11em #7b93a2 (texto já em caixa alta "HOJE, 26/08"); item 9px 0, borda #f4f7f9, pip 7px #c3d2dd, 13.5px/1.45, hora 11.5px #8496a3 embaixo (dc:2882-2900, 6586-6592) | `.ps-drawer-cab`, `.ps-hist-*` (ps:2004-2081; template `_historico_drawer` move a hora para o corpo) | ≈ | O dia é `position: sticky` no BlueJus (fixo ao rolar); o dc não. VIS-11. |
| Impressão | janela | véu rgba(16,36,48,.38); 980px x min(88vh,860px); cabeçalho #0b6a96 12px 16px, ícone 17px, título 14.5px/700; palco #525659 24px 16px; folha 620px 44px 48px; lateral 260px #fafcfd (dc:203-212) | `.psi-*` (psi:28-120) | SIM | z 1065 x 2147483300 no dc: mesma razão da navbar. Título "Imprimir" x "Imprimir · título" (VIS-17). |

#### 1.17 Avisos (toasts)

| Bloco | Propriedade | Designer | BlueJus | Igual? | Observação de cascata |
|---|---|---|---|---|---|
| Aviso | caixa | fixo, centro, bottom 24px, 10px 14px, raio 4px, #12242f, 13px, ✓ #5fd39a, sombra 0 14px 30px -12px rgba(0,0,0,.5), entrada pcIn .25s, 2,6 s (dc:669, 4687) | `.ps-aviso` (show.html.twig:4363-4376) | ≈ | Sem animação de entrada no BlueJus. Erro com ícone #ff8a80 (extra). |
| Dica de `title` | balão | `bj-dica.js`: #e5f1f7, #0b4f73 600 12px/1.4, borda #b9d6e8, raio 4px, máx. 300px, 280 ms | dica nativa do navegador | NÃO | Pendente de decisão do dono (spec §3.1: mexe em `base.html.twig`). VIS-05. |

---

### 2. Responsividade

#### 2.1 @media declarados

| Fonte | Regra | Efeito declarado |
|---|---|---|
| Protótipo | `(hover: none)` (dc:23) | Responder/Encaminhar sempre visíveis no toque. |
| Protótipo | `(prefers-reduced-motion: reduce)` (dc:40, 47, 67, 69) | Desliga movimento do ponto, estrela IA, selos e ondas; abas com .01s. |
| Protótipo | `html, body { overflow-x: clip }` (dc:71) | Corta qualquer estouro horizontal da página. |
| Protótipo | `(max-width: 980px)` (dc:72) | `[data-r="grid2"]` vira 1 coluna (Dados, Metas, Detalhes). |
| Protótipo | `(max-width: 640px)` (dc:73-78) | Barra do topo compacta: some escritório e nome, navegação rolável. Nada no cabeçalho da pasta. |
| BlueJus | `ps:1290` `(max-width: 980px)` | `.ps-grade` 1 coluna. Igual ao dc. |
| BlueJus | `ps:1593` `(hover: none)` | Responder sempre visível. Igual ao dc. |
| BlueJus | `ps:2086` `(max-width: 767.98px)` | Página 14px 14px 40px; cabeçalho 16px; h1 22px máx. 100%; linha 1 quebra; setas sem divisória; botões só ícone (exceto os de rótulo obrigatório); drawer 100%; abas `flex: 0 0 auto`. Sem par no dc. |
| BlueJus | `ps:4386` `(max-width: 767.98px)` | Faixa do Push quebra; filtros em linha própria. |
| BlueJus | `ps:4696` `(max-width: 767.98px)` | Cadastro e Situação sem `margin-left: auto`. |
| BlueJus | `ps:4627` `(max-width: 420px)` | Campos duplos do Editar dados ocupam 1 coluna. |
| BlueJus | `pex:1084..1875` (hover:none, 575.98, 767.98) | Explorador: ações à vista no toque, layout estreito. |
| BlueJus | `iag:591` `(max-width: 520px)`, `pasta-checklist.css:320` (480px), `visualizador-documento.css:172` (575.98px) | Ajustes locais. |
| BlueJus | reduced-motion em ps:848, 970, 1105, 1233, 1264, 2005, 2753, 3142, 3213, 3276 | Equivalente ao dc. |
| BlueJus | `overflow-x: clip` no body | Não encontrado nas folhas da Pasta. |

#### 2.2 Comportamento comparado

| Largura | Designer (declarado) | BlueJus (declarado) | Leitura |
|---|---|---|---|
| 1440 | Grade 2 colunas (356px); abas por extenso se couberem (JS `measure` liga o compacto quando transborda) | Igual; abas com `min-width: max-content` e compacto por JS (pasta-show.js:106-159) | Medido em E2 a E4: sem divergência de arranjo além de P1. |
| 1024 | Sem sidebar no dc; trilho das abas com `min-width: 0` comprime e o JS liga o compacto | Sidebar AdminLTE é overlay retraído (app.css:309-316), conteúdo quase inteiro; com P1 a faixa ocupa altura extra; abas não encolhem abaixo do rótulo: rolam (barra oculta) até o JS ligar o compacto | precisa medir na tela: risco de rótulos cortados pela rolagem invisível (VIS-04). Grade continua 2 colunas (>980). |
| 390 | Grade 1 coluna; cabeçalho sem regra: h1 28px limitado a 195px (50vw), linha 1 com prioridade, marcadores, responsável, setas e ⋮ sem quebra garantida; `overflow-x: clip` esconde o estouro | Grade 1 coluna; h1 22px a 100%; linha 1 quebra; setas sem divisória; botões só ícone; drawer 100%; sem `overflow-x: clip` (estouro viraria rolagem lateral, se existir) | O desenho é omisso no celular para o cabeçalho; a convenção do sistema manda (VIS-15). precisa medir na tela: rolagem horizontal da página (trilho das abas tem rolagem própria; registro tem 46px de recuo). O que some no BlueJus: rótulos de botões; no dc: nada do cabeçalho some. |

---

### 3. Estados

| Elemento | Hover (dc `style-hover` x BlueJus `:hover`) | Foco | Ativo/aberto | Desabilitado | Vazio |
|---|---|---|---|---|---|
| Voltar, setas, ⋮ | #e5f1f7/#0a7aad nos dois; transição .18s no dc, sem transição de fundo no BlueJus | dc sem foco declarado; BlueJus `:focus-visible` igual ao hover | ⋮ aberto: dc sem estado; BlueJus idem | setas: #c5d0d8 nos dois, `cursor: default` | n/a |
| "+" marcadores | igual (scale 1.08) | BlueJus igual ao hover | n/a | n/a | n/a |
| Prioridade | dc sem hover; BlueJus sem hover | contorno do navegador | ✓ e 600 na atual nos dois | sem permissão vira texto sem chevron (BlueJus) | n/a |
| Itens de menu | #eef5fa nos dois; perigo #fdf1f0 | BlueJus sem `:focus-visible` próprio no `.ps-pop-item` (contorno do navegador) | n/a | Editar/Excluir "bloqueado" com cara própria (ps:2799) | n/a |
| Abas | dc sem hover; BlueJus sem hover | contorno do navegador | sublinhado deslizante igual | n/a | selo "0": dc mostra, BlueJus esconde (VIS-07) |
| Cadastro / Situação | #e3eff6/#0a7aad; brightness(.97) nos dois | Cadastro: contorno 2px accent no BlueJus (dc sem) | n/a | Situação sem permissão vira `<span>` | n/a |
| Registro | sombra de hover igual; Responder aparece no hover/foco nos dois | Responder aparece com `:focus-within` nos dois | n/a | lápis só dentro do prazo (dica com minutos) | vazio no cartão branco (BlueJus) |
| Trilho: linhas | prazos #f7fafc; documentos #f1f7fb; clientes sem fundo, nos dois | documento `:focus-visible` = hover (BlueJus) | n/a | n/a | Prazos: linha simples (dc) x bloco com ícone (BlueJus, VIS-03); contagem "0" some no BlueJus |
| Metas | translateY(-2px) scale(1.012) e sombra nos dois | E2 mediu repouso, hover e foco do filtro iguais | filtro ativo igual | n/a | `.ps-metas .ps-vazio` 52px (BlueJus); n/c |
| Busca de documentos | igual | fundo branco + anel 2px rgba(15,111,196,.35) nos dois (E2) | n/a | n/a | n/c |
| BLUEJUS Intelligence | igual | dc renderiza só o contorno do navegador; BlueJus contorno + fundo (P4, aceito) | n/a | título explica indisponibilidade (BlueJus) | n/a |
| Janela de confirmação | OK sem hover no dc; BlueJus escurece | foco nasce no Cancelar (BlueJus); dc foca a janela | n/a | n/a | n/a |
| Editar dados: Salvar | `salvarSt` no dc | `:disabled` opacidade .55 (BlueJus) | n/a | travado sem alteração nos dois | n/a |

---

### 4. Tema escuro

O protótipo não tem tema escuro (nenhum `prefers-color-scheme` nem tema no 02 e dependências, inventário B, PZ-GLB-N05).
O BlueJus declara o escuro por `[data-bs-theme="dark"]`, nunca por `prefers-color-scheme`:

| Arquivo | Regras escuras | Observação |
|---|---|---|
| `pasta-show.css` | 42 | Bloco de tokens (ps:209-326) reaponta cada `--ps-*` para `--bs-*` ou tints translúcidos; regras pontuais para abas, selo, etiqueta, avatar, situação, menus, X, interruptores. |
| `editor-rico.css` | 18 | |
| `pasta-explorador.css` | 4 | Tokens `--pex-*`. |
| `nota-tecnica.css` | 4 | Tokens do escuro mantidos como antes da E3. |
| `pasta-ia-agentes.css` | 3 | Inclui `--ia-marca-cab` #6fc3e0. |
| `pasta-documentos-sugeridos.css` | 3 | |
| `pasta-checklist.css`, `pasta-impressao.css` | 2 cada | |
| `pasta-ia.css` | 1 | |
| `_janela_confirmacao.html.twig` | 2 | Cabeçalho #0a5f86, OK vermelho #c0392f, azul #1479ad. |
| `show.html.twig` | 1 | Aviso #0b161d. |
| `visualizador-documento.css` | 0 | Visor já é escuro (#525659). |
| `_resp_estilo.html.twig` | 0 | Usa `--bs-*` direto; vale nos dois temas. |

Pontos sem par escuro encontrados: `.ps-mencao-lista` (ps:1522-1529) com `#fff`, `#12242f`, `#7b93a2`, `#e5f1f7` cravados (lista do "@" sai branca no escuro, VIS-16); `ps:2514` `background: #fff` (bola do interruptor, aceitável). Nada disso foi medido na tela.

---

### 5. Divergências classificadas

Classes: visual · dado (depende de backend ou de funcionalidade que não existe) · texto · decisão aprovada (P1 faixa preservada,
P2 "Como usar esta aba" preservada, P3 andamento não implementado, P4 foco da marca aceito, cantos 4px da E2, janela da E7) ·
decisão pendente (§3.1 da spec).

| ID | Bloco | Designer | BlueJus | Classe | Gravidade | Confiança |
|---|---|---|---|---|---|---|
| VIS-01 | Drawer BLUEJUS IA (camada) | Drawer de altura total sobre a página, cabeçalho e X visíveis (barra do topo não fixa) | `.ia-drawer` z 96 e `.ia-drawer-fundo` z 95 com `top: 0` (iag:144-166), abaixo da navbar fixa z 1050 de 102px (app.css:109-116): o cabeçalho do drawer e o X ficam provavelmente cobertos; o véu não escurece a navbar | visual | alta | PRECISA MEDIR NA TELA (ordem de camadas CONFIRMADA estática) |
| VIS-02 | Aba Documentos (moldura) | Um cartão só, sem respiro externo (dc:2150) | Cartão do `#documentos` com padding 18px 20px (ps:1274-1280) envolvendo o cartão `.pex` (pex:259-262): moldura dupla | visual | média | CONFIRMADO estático (cascata simples, nenhuma regra anula); ver o resultado na tela |
| VIS-03 | Próximos prazos: vazio e "+" | Vazio em uma linha 12.5px #7b93a2 (padding 16px 17px); botão "+" 30x28 (dc:1460-1463) | Bloco central com ícone 30px, título 14.5px/600 e nota (ps:1670-1674; tri:32-37); sem "+" | visual (vazio) · dado ("+", prazos manuais) | média | CONFIRMADO estático |
| VIS-04 | Abas a 1024px | `min-width: 0`: comprime, JS liga compacto | `min-width: max-content` + `overflow-x: auto` com barra oculta (ps:1128-1143, 1170-1177): pode rolar sem indicação antes do compacto | visual | média | PRECISA MEDIR NA TELA |
| VIS-05 | Dicas de `title` | Balão azul-claro `bj-dica.js` | Dica nativa | decisão pendente (§3.1) | média | CONFIRMADO estático |
| VIS-06 | Modal Editar dados: véu, posição e entrada | Véu rgba(14,37,51,.38), topo a 4vh, finIn | Véu e margem padrão do Bootstrap, `fade` | visual | baixa | CONFIRMADO estático (sem override); cor final PRECISA MEDIR |
| VIS-07 | Contagens zeradas | "0" nas Metas (aba) e nos cartões do trilho | Selo some com 0 (cab:532-537; tri:25-27) | visual | baixa | CONFIRMADO estático (CAB-44, TRI-03) |
| VIS-08 | Texto do selo e meta do prazo | "Hoje", "Faltam 1 dia"; meta "RESP · dd/mm HH:MM · ORIGEM" | "Vence hoje", "Falta 1 dia"; meta "resp · data" (PastaPrazoOutput) | texto · dado (origem, hora) | baixa | CONFIRMADO estático |
| VIS-09 | Movimento de menus e cabeçalho | `menuIn` .18s nos menus ⋮, Situação, Responsável; `pcIn` no nome; fundo com transição .18s em Voltar, setas e ⋮ | Menus aparecem sem animação (ps:512); nome sem `pcIn`; `.ps-btn` só transiciona borda e cor (ps:431) | visual | baixa | CONFIRMADO estático |
| VIS-10 | Troca de aba | `tabInA/B` com blur, direção (`--tx`) e `tabVarre` | `psPainelEntra` .46s (opacidade, 10px, scale .992) sem direção (ps:1252-1263) | visual | baixa | CONFIRMADO estático; sensação PRECISA MEDIR |
| VIS-11 | Histórico: dia | Rótulo do dia rola com a lista | `position: sticky` (ps:2059) | visual | baixa | CONFIRMADO estático |
| VIS-12 | Travessão em texto visível | Regra do dono (15/10): sem travessão (U+2014) nem meia-risca (U+2013) | travessão U+2014 no detalhe do histórico (`_historico_drawer.html.twig:73`) e na faixa da lápide (cab:302-315, conforme inventário da tela) | texto (regra do dono) | média | CONFIRMADO estático |
| VIS-13 | Trilho Documentos: ícone do tipo | Ícone composto com selo de letra (dc:1526) | Ícone Bootstrap 30px (ps:1950) | visual | baixa | PRECISA MEDIR NA TELA |
| VIS-14 | Clientes: ações extras | Só "Abrir cliente" | Estrela e lixeira no hover; estrela cheia fixa no principal (ps:2211-2234) | decisão já registrada (PMC-051, tri:118-127) | baixa | CONFIRMADO estático |
| VIS-15 | Cabeçalho no celular | Sem regra (h1 28px, máx. 195px a 390) | h1 22px, linha quebra, botões só ícone (ps:2086-2121) | visual (desenho omisso, convenção do sistema) | baixa | CONFIRMADO estático; estouro lateral PRECISA MEDIR |
| VIS-16 | Lista de @menção no escuro | (sem tema escuro) | Cores cravadas sem par escuro (ps:1522-1529) | visual (tema escuro) | baixa | CONFIRMADO estático |
| VIS-17 | Impressão: título | "Imprimir · título" (dc:205) | "Imprimir" | texto | baixa | CONFIRMADO pelo inventário da tela (PZ-MOD-06) |
| VIS-18 | Registro: elementos | Etiqueta, presença, destaque de cor, Encaminhar, ponto na cor da etiqueta | Ausentes; ponto sempre azul | dado | baixa | CONFIRMADO estático |
| VIS-19 | Situação e selos | 4 situações; selos SUSPENSO e CANCELADO | 2 situações; só ARQUIVADO e ATIVO | dado | média | CONFIRMADO estático |
| VIS-20 | Foto do cliente | Botão com hover (scale 1.06, anel) e menu | Ícone inerte | dado | baixa | CONFIRMADO estático |
| VIS-21 | Responsável | Linha 1, à direita | Faixa da linha 2 | decisão aprovada P1 | alta (aceita) | CONFIRMADO estático |
| VIS-22 | Ação e Processo vinculado | Cartões no topo do trilho de Dados | Faixa do cabeçalho; chip "N vinculados" em pílula | decisão aprovada P1 | alta (aceita) | CONFIRMADO estático |
| VIS-23 | Andamento da pasta | Trilha de 10 fases sob o nome | Ausente | decisão aprovada P3 | alta (aceita) | CONFIRMADO estático |
| VIS-24 | "Como usar esta aba" | Ausente no desenho novo | Presente em Detalhes | decisão aprovada P2 | baixa (aceita) | CONFIRMADO estático |
| VIS-25 | Foco da marca IA | Só contorno do navegador | Contorno + fundo #e5f1f7 | decisão aprovada P4 | baixa (aceita) | Medido na E3/E4 |
| VIS-26 | Itens do ⋮ | Mover, Link externo, Cadeado, Acompanhar | Ausentes (menu mais curto) | dado | baixa | CONFIRMADO estático |
| VIS-27 | Conteúdo do drawer IA | Abas Análise/Documentos/Relatórios/Perguntar, 5 contadores, CTA 42px | 7 agentes, 4 contadores | dado | média | CONFIRMADO estático |

Contagem: 27 divergências. Por gravidade: alta 4 (1 visual a corrigir: VIS-01; 3 decisões aceitas: VIS-21, 22, 23) ·
média 7 (VIS-02, 03, 04, 05, 12, 19, 27) · baixa 16 (VIS-06, 07, 08, 09, 10, 11, 13, 14, 15, 16, 17, 18, 20, 24, 25, 26).
Por confiança: PRECISA MEDIR NA TELA como condição principal 4 (VIS-01, 04, 13 e a cor do véu em VIS-06); CONFIRMADO estático 23
(alguns com o efeito final ainda a ver na tela: VIS-02, 10, 15).
Por classe: visual 13 · dado 6 · texto 3 (VIS-08 é texto e dado; VIS-12; VIS-17) · decisão aprovada 5 · decisão pendente 1 ·
decisão já registrada fora das P 1 (VIS-14).

Achados que corrigem registros anteriores (não são divergências):
- PZ-CAB-45 (pendência "a 45% na aba ativa"): no dc a linha só existe com a aba inativa; o BlueJus está igual. O ≈ do checklist pode virar ✓.
- Comentários desatualizados no CSS: setas "28px" (ps:484, regra 32px); tons de prazo "até 2 / até 8" (ps:1723, PHP usa ≤3 / ≤7 como o dc).

---

### 6. Limitações

- Análise estática: li declarações, não valores calculados. Não vi herança efetiva, especificidade real com AdminLTE/Bootstrap,
  `<style>` inline posteriores (`_resp_estilo`, `editor-rico.css` carregado três vezes no corpo), estilos inline postos por JS,
  arredondamento de pixel, fontes efetivas nem renderização de máscaras e animações.
- Não executei o protótipo: valores calculados em tempo de execução (`{{ ...St }}`) foram lidos no código do `Component`.
- Não li linha a linha: drawer do relatório da meta, menus de cada área, Push completo, checklist e sugeridos de Documentos,
  Timeline inteligente (só BlueJus), janela "Detalhes do cliente", modais Nova meta, Vincular processo, Marcadores, Adicionar
  pagamento. Para esses, apoiei-me nos inventários, no checklist e nas notas "auditoria 2" do próprio CSS; marquei n/c onde não comparei.
- As medições de 14/10 (E2, E3, E4, E7) foram feitas contra o pacote de 13/10; o diff para 16/10 mostra que os blocos medidos
  não mudaram, mas a Pasta do BlueJus mudou depois (E8b, E8c, E8d) e não foi remedida.
- Valor de `--jp-font-sans` e comportamento do JS de modo compacto das abas não conferidos.

### 7. O que só a medição na tela resolve

1. VIS-01: abrir o drawer da IA a 1440 e conferir se cabeçalho e X ficam sob a navbar fixa (e se o véu cobre a navbar).
2. VIS-02: aba Documentos a 1440, conferir moldura dupla e o respiro de 18/20px em volta do explorador.
3. VIS-04: abas a 1024 (e 1100, 900) com 7 abas e selos: rolagem invisível, rótulos cortados, momento em que o compacto liga.
4. VIS-15: 390px, rolagem horizontal da página inteira (cabeçalho, faixa P1, registro com recuo de 46px, explorador).
5. VIS-06: cor final do véu e posição vertical do modal Editar dados; altura real dos campos (`.form-control` x 34px).
6. Etiquetas de marcador com cor real (`color-mix`) x trios fixos do dc.
7. Selo carimbado: equivalência de `zoom: .8` x medidas multiplicadas.
8. Barra do editor (raio 9px no dc x 3px) e altura mínima do Quill com `editor-rico.css` posterior.
9. Menu do responsável com `_resp_estilo` inline posterior: tarja, busca, linha ativa.
10. Ícone de tipo do arquivo no trilho (VIS-13) e animação de "Abrir cliente".
11. Tema escuro da Pasta inteira (nunca medido nesta frente para a Pasta, salvo a janela de confirmação na E7).

## 8. Defeitos do próprio protótipo (não copiar)

Cada um com o comportamento correto a adotar. Parte A (cabeçalho, cadastro, Dados, Metas, Processo, Financeiro):

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

Parte B (Detalhes, Documentos, Push, IA, globais):

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

## 9. Funcionalidade real do BlueJus a preservar (não está no desenho)

Parte A:

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

Parte B:

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
