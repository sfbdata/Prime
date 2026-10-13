# Engineering Loop de fidelidade — Claude Designer → BlueJus real

> **Para quem:** o Samuel e qualquer sessão futura que continue este loop sem ter visto a conversa.
> **O que é:** o ledger vivo do Engineering Loop. Cada divergência entre o Claude Designer e o BlueJus real
> tem um ID, uma classe, o estado das três dimensões (Visual, Funcional, Regressão) e a evidência.
> **Não substitui** os ledgers anteriores (`estado-geral-claude-designer-bluejus.md`, `trilha-b-designer.md`,
> `trilha-b-documentos-arquitetura.md`); eles ficam como histórico e mapa.

## 0. Estado e regras desta fase

| Item | Valor |
|---|---|
| Início do loop | 07/10/2026 |
| Baseline de produção homologado | **`719de0e4`** (EI2 publicada, migrations aplicadas, **smoke de produção APROVADO pelo Samuel**) |
| HEAD no início | `607f2c29` (1 commit documental local à frente de `origin/master`) |
| Modelo | Opus em tudo. **Fable suspenso** pelo Samuel até ordem contrária |
| Publicação | **Nada de push, deploy ou escrita em produção.** O Samuel publica quando houver entrega intermediária |

**Fontes de verdade (por ordem):** protótipos `.dc.html` do pacote `docs/design/claude-design-2026-10-05 (1)/`
(não versionado), os JS de comportamento do pacote, os READMEs de handoff, as capturas, as decisões
registradas do Samuel, o comportamento do BlueJus a preservar, o código atual. Ledgers são só mapa.
READMEs e protótipo são interpretação do Claude Designer: não atribuir as regras ao Farlei.

**Fonte pendente:** relatório do Farlei, sendo produzido no chat original do Claude Designer. Quando chegar,
provoca nova auditoria das áreas afetadas (todos os itens D primeiro).

**Classes:** **A** autônoma · **B** dependência externa · **C** decisão do Samuel · **D** aguardando
especificação do Farlei · **E** fora do escopo · **F** já concluída (com evidência).
**Prioridade:** P0 segurança/tenant/dados · P1 função do desenho que não funciona · P2 função faltante ·
P3 visual importante · P4 microfidelidade · P5 refinamento.

## 1. Agentes em andamento e próximo passo

**Ciclo 0 (07/10):** auditoria inicial por 8 subagentes Opus somente leitura, um por área. Cada um grava
o inventário completo fora do repositório (scratchpad da sessão) e o orquestrador consolida aqui.

| Área | Prefixo | Estado |
|---|---|---|
| Dashboard | `DASH-` | em andamento |
| Pasta: cabeçalho, Dados, Registro, Timeline | `PAS-` | em andamento |
| Processo, Metas, Clientes | `PMC-` | em andamento |
| Financeiro, Push, Notificações | `FPN-` | em andamento |
| Documentos e Visualizador | `DOC-` (novos a partir de `DOC-1xx`) | em andamento |
| Editor, atalhos, impressão | `EDT-` | em andamento |
| BlueJus Intelligence | `IA-` | em andamento |
| Segurança, tenant, autorização | `SEC-` | em andamento |

**Se a sessão cair aqui:** os inventários do scratchpad se perdem. Relance a auditoria da área que não
estiver consolidada na §3.

## 2. Matriz de convergência

**Medida em 07/10, ciclo 0** (auditoria inicial em `607f2c29`). O denominador é a lista real de requisitos
que cada auditor montou a partir do desenho, do JS e do README; ele **cresce** quando um requisito novo
aparece. "OK" = conferido no código como implementado (classe F). Contagem por leitura de código: o visual
(cor, raio, posição) foi conferido no CSS e no template, não na tela.

| Área | Visual | Interações | Funções | Responsivo | Dark | Total OK |
|---|---|---|---|---|---|---|
| Dashboard | 96/108 | 47/68 | 20/39 | 13/15 | 12/12 | **188/242** |
| Pasta: cabeçalho, Dados, Registro, Timeline | 53/71 | 50/58 | 24/51 | 10/10 | 12/12 | **149/202** |
| Processo, Metas, Clientes | 56/78 | 53/83 | 22/40 | 7/8 | 7/8 | **145/217** |
| Financeiro, Push, Notificações | 79/104 | 53/88 | 31/57 | 10/12 | 14/14 | **187/275** |
| Documentos e Visualizador | 72/80 | 89/96 | 44/65 | 15/15 | 6/6 | **226/262** |
| Editor, atalhos, impressão | 21/27 | 27/43 | 8/19 | 3/4 | 4/4 | **63/97** |
| BlueJus Intelligence (telas do código) | 21/34 | 13/37 | 27/33 | 4/4 | 5/5 | **70/113** |
| Segurança (checagens por rota, 57 rotas) | — | — | 371/392 | — | — | **371/392** |

**Divergências por classe (308 linhas):**

| Área | A | B | C | D | E |
|---|---|---|---|---|---|
| Dashboard | 12 | 2 | 15 | 3 | 2 |
| Pasta/Dados | 29 | 0 | 18 | 2 | 0 |
| Processo/Metas/Clientes | 24 | 6 | 23 | 1 | 2 |
| Financeiro/Push | 17 | 4 | 17 | 4 | 0 |
| Documentos | 21 | 4 | 11 | 0 | 3 |
| Editor | 22 | 0 | 12 | 0 | 0 |
| IA | 14 | 5 | 13 | 10 | 1 |
| Segurança | 9 | 0 | 2 | 0 | 0 |
| **Total** | **148** | **21** | **111** | **20** | **8** |

O README da Intelligence lista ainda **197 comandos de Chat I.A (29 grupos)** e 49 requisitos R1–R49, quase
todos D ou C; eles estão em `engineering-loop/inventario-ia.md` e **não** entram na tabela acima (a área IA
conta só as telas que existem no código).

**Duplicatas entre áreas** (a mesma função aparece em mais de um inventário; conta-se uma vez ao fechar):
@menção nas notas técnicas = EDT-010 = FPN-022 = PMC-019 · ⋮ da nota técnica = FPN-021 = PMC-017 · imprimir
extrato = EDT-004 = FPN-010 · foto do cliente = PAS-005 = PMC-052 · imprimir resumo com opções = EDT-001 =
PAS-015 · ⋮ da observação (Citar, Responder…) = EDT-009 ⊂ PAS-041 · zip sem limitador = DOC-117 = SEC-004 ·
`PastaSecaoController` sem tenant = DOC-119 = SEC-001 · Push marca lida no GET = FPN-013 (parte) = SEC-011 ·
Imprimir resumo do processo = EDT-005 = PMC-003 · Compartilhar sem Web Share = EDT-006 = PMC-002.

## 3. Divergências (ledger)

O detalhe de cada linha (fonte no desenho com linha, evidência no código, proposta e teste) está nos
inventários versionados em `docs/specs/engineering-loop/inventario-<área>.md`. Aqui fica o **estado** de
cada divergência A trabalhada e as filas B/C/D.

### 3.1 Divergências A em trabalho ou concluídas

| ID | Área | Elemento | Prior. | Estado | V | F | R | Commit | Revisão | Observação |
|---|---|---|---|---|---|---|---|---|---|---|
| DOC-101 | Documentos | Texto da lixeira prometia purga em 30 dias | P1 | **feito** | ✓ | ✓ | ✓ | `034f6ff1` | — (texto, teste de mutação) | Sem prazo nenhum: D-DOC-RET (prazo) segue aberta |
| DOC-102/103/105/109/110/114/121/123 | Documentos | Lote DOC-A1: textos da limpeza e do vazio, zoom ODP, data Modificado no visor, P7S, Espaço em pasta, `modificado_em` na peça | P4–P5 | **feito** | ✓ | ✓ | ✓ | `5038bbdf` | Opus: sem bloqueante | 8 mutações. Pendências menores: tamanho no aviso do P7S (desenho mostra) → DOC-141; testes de JS só leem o fonte (sem harness) |
| SEC-001 (=DOC-119) | Segurança | `PastaSecaoController` busca por id sem tenant | P0 | **feito** | — | ✓ | ✓ | `d9846179` | Opus: sem bloqueante | Teste com TenantFilter desligado (trait); mutação derruba 3 |
| SEC-002 | Segurança | Mensagem do Registro sem tenant explícito da pasta | P0 | **feito** | — | ✓ | ✓ | `d9846179` | Opus: sem bloqueante | Prod (MCP, agregado): 0 linhas com tenant nulo ou divergente em mensagem/seção/documento |
| SEC-003 | Segurança | XML/SVG/HTML do usuário servido inline sem CSP sandbox | P0 | **feito** | — | ✓ | ✓ | `3b6805f1` | Opus: sem bloqueante | `CSP: sandbox; default-src 'none'` fora de PDF, imagem raster, áudio e vídeo; nosniff sempre |
| SEC-004 (=DOC-117) | Segurança | Zip sem limitador (5 zips param o PHP-FPM) | P1 | **feito** com SEC-004b | — | ✓ | ✓ | `2fd0a672`, `688674e1`, `95819293` | Opus: sem bloqueante | + 2 vagas por escritório (flock; vale por container) | 3/min por usuário, 429. O teste original não provava nada (o ArrayAdapter zera entre pedidos); refeito com dublê + mutação. Ver DOC-140 |
| PMC-056 | Processo | `processo_new`/`processo_edit` sem CSRF | P0 | **feito** | — | ✓ | ✓ | `38df20f0` | Opus: sem bloqueante | Token por ação (`processo_edit_{id}`); mutação derruba 2 testes |
| IA-002 | IA | CPF/CNPJ sem pontuação não mascarados | P0 | **feito** | — | ✓ | ✓ | `a3f1352d`, `d808e5d0`, `727cec26` | Opus | + pontuação parcial, CNPJ alfanumérico (IN RFB 2.229/2024), NBSP | Só com DV válido; CNJ de 20 dígitos intacto |
| IA-001/003/004 | IA | Aceite incompleto; cota mensal dita diária; travessão | P1/P4 | **feito** | ✓ | ✓ | ✓ | `3f87fede`, `631fd4d2`, `78f1e716` | Opus | Aviso amarrado aos montadores por teste (campo novo derruba) | Lista do aceite gerada do enum `SecaoDoContexto` |

| PAS-038 | Pasta/Dados | "Alertar responsável" no ⋮ do prazo | P2 | feito (ver PAS-047L) | ✓ | ✓ | ✓ | `586b7952` | Opus: sem bloqueante | Um item por responsável |
| PMC-028/030 | Metas | Copiar link e Excluir no ⋮ da meta | P2 | feito (ver PAS-048) | ✓ | ✓ | ✓ | `5da8b263` | Opus: sem bloqueante | Excluir com confirm, sem Desfazer (exclusão física) |
| PAS-046 | Timeline | Observações de Detalhes e Financeiro na Timeline | P2 | feito (ver PAS-048) | ✓ | ✓ | ✓ | `c5f520c5` | Opus: sem vazamento no código | Financeiro só sob `podeVer` |

| SEC-012 | Segurança | Anexo da Tarefa servido inline sem sandbox | P0 | **feito** | — | ✓ | ✓ | `d24cfd0b` | Opus: sem bloqueante | Usa a política do `EntregaDeArquivo` sem mudar o contrato de storage (a chave do anexo é da E2.7) |
| SEC-005 | Auditoria | Desfazer recusava Cargo/Sede/Lotação | P2 | **feito** | — | ✓ | ✓ | `0d611444` | Opus: sem achado | Latente: nenhuma entidade reversível tem esse vínculo hoje |
| SEC-006 | Financeiro | Corrigir valor sem trava | P2 | **feito** | — | ✓ | ✓ | `eb95ff04` | Opus: sem achado | `SELECT … FOR UPDATE` + releitura; resolve também o §9 #16 sem migration |
| SEC-007 | Documentos | Caminho legado inválido travava purga, zip e cópia | P2 | **feito** (ver SEC-007b) | — | ✓ | ✓ | `83da8343` | Opus: `--limite` pode prender a fila | Prod (MCP): 22.566/22.566 caminhos válidos; latente |
| SEC-008 | Registro | Menção e resposta sem limitador | P3 | **feito** | — | ✓ | ✓ | `4db61a5f` | Opus | 30 avisos/10 min por autor; a mensagem sempre grava. Teto a ratificar |
| IA-033L | IA | Cabeçalho do Push e análise anterior iam ao provedor sem máscara | P1 | **feito** | — | ✓ | ✓ | `d5d2383a`, `916d0849`, `46d14264` | (mutação: 3 caem) | Achado do implementador do IA-002b |

| DOC-115 | Documentos | Anexar aceita ODT/ODS/ODP/RTF/PPTX/EML/CSV/GIF/WEBP/TIFF/HEIC | P2 | **feito** | ✓ | ✓ | ✓ | `6863247c` | (mutação: 9 caem) | Validação pelo conteúdo; zip com extensão ODF/PPTX só passa com o mimetype interno do pacote |
| DOC-108 | Visualizador | Imprimir qualquer conteúdo do visor, com a tarja | P2 | **feito** | ✓ | ✓ | ✓ | `af6800a4` | pendente | iframe `allow-same-origin allow-modals`, sem scripts; CSP do visualizador |
| DOC-106 | Documentos | "Possíveis arquivos duplicados" no Sugerir | P3 | **feito** | ✓ | ✓ | ✓ | `77e83988`, `523464af` | pendente | ≥85% por `SimilaridadeDeNomes`; lixeira fora |
| PAS-001 | Cabeçalho | Botão direito no nome do cliente abre Detalhes + dica | P2 | **feito** | ✓ | ✓ | ✓ | `883061ed` | Opus: sem bloqueante | |
| PMC-001/027 | Processo/Metas | Botão direito no cartão do processo e na meta | P2 | **feito** | ✓ | ✓ | ✓ | `0e889337` | Opus: sem bloqueante | Menu nativo mantido sobre links/campos/texto selecionado |
| PMC-004 | Processo | "Editar dados do processo" no ⋮ | P2 | **feito** | ✓ | ✓ | ✓ | `c3942091` | Opus: sem bloqueante | Vai para `processo_edit`; edição inline = PMC-005 (C) |
| PAS-014 | Cabeçalho | Escrita escondida de quem não pode | P3 | **feito** | ✓ | ✓ | ✓ | `ddbcfe72` | Opus: sem bloqueante | Função Twig `can_access_resource` = mesma decisão do servidor |
| PAS-047L | Dados/Metas | Alertar e sino só para quem edita | P4 | **feito** | ✓ | ✓ | ✓ | `25184023` | em revisão | |
| PAS-048 | Testes | Financeiro na timeline por todos os caminhos; meta cross-tenant; destino de outra pasta | P3 | **feito** | — | ✓ | ✓ | `bc5efc20` | em revisão | |
| SEC-007b | Documentos | Limite da purga sobre itens processados; log sem caminho | P4 | **feito** | — | ✓ | ✓ | `7468962a` | em revisão | |
| PAS-049L | Processo | Escrita da aba Processo escondida de quem não edita | P3 | **feito** | ✓ | ✓ | ✓ | `08f91d55` | em revisão | Também some na lápide |
| PAS-050 | Desempenho | `can_access_resource` memorizado por requisição | P3 | **feito** | — | ✓ | ✓ | `8690ac41` | em revisão | ResetInterface |
| SEC-013 | Segurança | Guarda da Tarefa conferia o vínculo, não a posse | P2 | **feito** (ver SEC-013b) | — | ✓ | ✓ | `8273810f` | em revisão | Cross-tenant → 404 |
| FPN-012 | Push | Título do cartão = trecho do teor | P2 | **feito** | ✓ | ✓ | ✓ | `a53d6cb6` | em revisão | Órgão vai para o `title` (o desenho não o mostra) |
| FPN-014 | Push | Botão direito no cartão: nota técnica, criar tarefa, copiar | P2 | **feito** (ver FPN-014b) | ✓ | ✓ | ✓ | `f0161bef` | em revisão | |
| FPN-006 | Financeiro | Botão direito no registro: copiar, editar, excluir (15 min) | P2 | **feito** | ✓ | ✓ | ✓ | `d5bb3e0f`, `f3345b0c` | em revisão | Responder depende de migration (FPN-005) |
| FPN-036 | Notificações | Sino laranja do alerta e ícone da análise | P4 | **feito** | ✓ | ✓ | ✓ | `d39f3dc6`, `b6b0c8c8` | em revisão | `.text-orange` criado com par escuro |
| FPN-019 | Push | Cor do ponto por quem praticou o ato | P4 | **feito** | ✓ | ✓ | ✓ | `0f0afbe8` | em revisão | Polo ativo/passivo exigiria inferir do texto (D) |
| FPN-031 | Push | Cabeçalho quebra linha no celular | P3 | **feito** | ✓ | — | ✓ | `6f50322b` | em revisão | Só o smoke prova 375px |
| DASH-025/026 | Dashboard | Modo avançado lembrado no servidor; confirmação; cartão 6s | P2 | **feito** | ✓ | ✓ | ✓ | `ac76c8b1`, `78b555f6`, `14a9fa5b` | as 3 falhas eram do teste | Confirmação só na 1ª ativação (README) × a cada vez (código do dc): ver D abaixo |
| DASH-008/010 | Dashboard | Links e setas das colunas extras | P2 | **feito** | ✓ | ✓ | ✓ | `f29386d5`, `7a36a92e`, `14a9fa5b` | célula = total da lista | |

| SEC-013b | Segurança | Cópia do guarda falho em `MetaNaListaController` | P2 | **feito** | — | ✓ | ✓ | `fa5ec109` | (mutação: 3 caem) | |
| FPN-014b/c | Push | Menu: Marcar como lida, Abrir documento; escrita escondida de quem não pode | P3 | **feito** | ✓ | ✓ | ✓ | `0039a027`, `470d5d35` | (mutações: 9) | Lida exige só ver (como o servidor) |
| PAS-049Lb | Processo | Interruptor Administrativo em só leitura; vazio sem citar botão ausente | P3 | **feito** | ✓ | ✓ | ✓ | `85c2368e` | | |
| FPN-006b | Financeiro | "Editar/Excluir · bloqueado" com cadeado; confirmação do desenho | P3 | **feito** | ✓ | ✓ | ✓ | `18555920` | | `confirm()` nativo com o texto do desenho (o desenho tem janela própria) |
| EDT-021/022 | Editor | Enviar desabilitado com editor vazio; rótulo em texto | P3 | **feito** | ✓ | ✓ | ✓ | `11c0a460` | Opus: sem achado | Contrato `data-editor-rico-envio` |
| EDT-009 | Editor | "Citar no novo registro" no ⋮ da observação | P2 | **feito** | ✓ | ✓ | ✓ | `fd6e49d6` | Opus: sem XSS | Só em Detalhes, como o desenho |
| EDT-011 | Editor | Menu de botão direito dentro do editor | P2 | **feito** | ✓ | ✓ | ✓ | `eb3acac5` | Opus: sem XSS | Itens de IA/terceiros ficam de fora (C) |
| EDT-017 | Editor | Colar com Manter/Mesclar/Somente texto | P3 | **feito** | ✓ | ✓ | ✓ | `76679c97` | Opus: sem XSS | |
| EDT-015 | Editor | Sugestão de palavras; Tab cede à @menção | P3 | **feito** | ✓ | ✓ | ✓ | `f2bb3421` | Opus | Vocabulário estático já existente |

| DOC-116 | Documentos | Nome do .zip do desenho; "Baixando" só após confirmação | P4 | **feito** | ✓ | ✓ | ✓ | `19f3ada0` | | Cookie de confirmação de 60 s (SameSite=Strict) |
| DOC-140 | Documentos | Recusa do .zip fora de XHR vira página legível | P3 | **feito** | ✓ | ✓ | ✓ | `7a7ea58b`, `d4366e55` | | 404 de pasta alheia segue a página do framework |
| DOC-118 | Documentos | Explorador e checklist sem escrita para quem só vê | P3 | **feito** | ✓ | ✓ | ✓ | `b7a074d5` | | Fecha também D-DOC-RO (tela) para o explorador |
| DOC-120 | Documentos | Copiar link de subpasta + `?pex` | P5 | **feito** | ✓ | ✓ | ✓ | `40ec0828` | | Parâmetro de outra pasta ignorado em silêncio |
| DOC-141 | Visualizador | Tamanho no aviso do P7S | P5 | **feito** | ✓ | ✓ | ✓ | `047fd224` | | |
| PMC-019 (=EDT-010=FPN-022) | Processo | @menção nas notas técnicas | P2 | **feito** | ✓ | ✓ | ✓ | `05d7f470` | (mutação: 2 caem) | Serviço de menções generalizado; cota compartilhada com o Registro |
| PMC-017 (=FPN-021) | Processo | ⋮ da nota técnica (e botão direito), bloqueado fora da janela | P2 | **feito** | ✓ | ✓ | ✓ | `e0f8f131` | | Responder não renderizado (ver PMC-057) |
| EDT-021 (nota) | Editor | "Salvar nota" desabilitado vazio | P3 | **feito** | ✓ | ✓ | ✓ | `33e84d88` | | |
| IA-007 | IA | Copiar, Baixar .txt e Imprimir no resultado | P2 | **feito** | ✓ | ✓ | ✓ | `ff9df25f` | | Opera sobre análise gravada; nada simulado |
| IA-008 | IA | "Registro de auditoria (N)" no drawer | P2 | **feito** | ✓ | ✓ | ✓ | `fff3f75f` | | Só metadado |
| IA-005/006 | IA | Chips e grade "O que a IA sabe desta pasta" | P3 | **feito** | ✓ | ✓ | ✓ | `39ca4566`, `38ef7c6e` | | 4 contadores reais; "fontes" fora |
| IA-010/011 | IA | Recolher com prévia (preferência no servidor); marcar como não lido | P3/P4 | **feito** | ✓ | ✓ | ✓ | `99cbb19a`, `856e83c6` | | |
| IA-012/013 | IA | Runbook de ativação; worker opt-in no compose de dev | P2/P3 | **feito** | — | ✓ | ✓ | `6c68555a`, `97a8a0cf` | | Worker em `--profile worker`: o dev roda dado real e a fila também leva sync do Drive |
| IA-014 | IA | Corpus de 67 ataques de injeção + homóglifos no neutralizador | P2 | **feito** | — | ✓ | ✓ | `9eccd415` | | |
| FPN-040 | Push | Teor sem escrita para quem só vê e na lápide | P3 | **feito** | ✓ | ✓ | ✓ | `68c3c1f2` | (mutações: 5) | Ver PMC-058L |
| DOC-106b | Documentos | Teto de 20 pares duplicados + "e mais N" | P1 | **feito** (ratificar teto) | ✓ | ✓ | ✓ | `4c438e1b` | (mutações: 5) | |
| PMC-044 | Metas | Fica na aba Metas com a meta nova realçada | P3 | **feito** | ✓ | ✓ | ✓ | `27d3972b` | | Recarrega a página (o desenho atualiza no lugar) |
| PMC-038 | Metas | Moldura do modal "Nova meta" | P3 | **parcial** | ✓ | ✓ | ✓ | `d8b7a015` | | Corpo do modal (rótulos, contador, chips) e janela não modal: PMC-059 |

| IA-034L | IA | Handler reconfere a disponibilidade: desligar corta a fila | P1 | **feito** | — | ✓ | ✓ | `77a013e0`, `45f0d2af` | Opus | Testes de "sem módulo"/nulo e cota: PAS-A8 |
| PMC-057/058L | Processo | Responder com outra nota; "Adicionar nota" só para quem edita | P4/P5 | **feito** | ✓ | ✓ | ✓ | `2a46c739`, `30b83bc8` | Opus | |
| PAS-041 | Detalhes | ⋮ da observação: Criar meta, Editar/Excluir (bloqueado fora da janela) | P2 | **feito** | ✓ | ✓ | ✓ | `42c9fc71` | Opus | Título da meta em branco, como o desenho |
| PAS-025 | Registro | Menu de botão direito + ⋮ no cartão do Registro | P2 | **feito** (ver PAS-025b) | ✓ | ✓ | ✓ | `b1155da8` | Opus: lápide oferecia Editar | |
| EDT-001..006 (=PAS-015, FPN-010, PMC-002/003) | Impressão | Imprimir resumo com prévia e opções; Baixar PDF (Dompdf); extrato; observação; resumo do processo; Compartilhar sem Web Share | P2 | **feito** | ✓ | ✓ | ✓ | `8ef83fb9`..`13916732`, `0364f447` | Opus: Dompdf sem remoto, iframe sem script | Imprimir no Registro/Financeiro (`eae03903`, `536c709e`) NÃO está no desenho: retirado no PAS-A8 |
| PAS-pend | Impressão | Pendência do Financeiro só para quem vê o financeiro | P2 | **feito** | — | ✓ | ✓ | `96d9004b` | | Também no cabeçalho |
| DASH-006 | Dashboard | "ver pastas"/"ver metas" com paridade card = lista | P2 | **feito** | ✓ | ✓ | ✓ | `6340a902` | Opus | "ver metas" lista também metas sem responsável/de quem saiu (o card conta): ligado a SEC-010 |
| DASH-020/017 | Dashboard | Janelas flutuantes; histórico de 12 meses no botão direito (Pastas criadas) | P2 | **feito** | ✓ | ✓ | ✓ | `e3cd352d`, `5f9082e1` | Opus | Os outros 3 cards dizem que dependem da foto diária (sem número) |
| DASH-010b | Dashboard | Sem seta em concluídas/taxa/tempo médio (comparação enviesada) | P1 | **feito** | ✓ | ✓ | ✓ | `61188192` | | Critério de comparação: C |
| IA-006b/008b | IA | Grade mostra o que a IA recebe (tetos do montador); testes de colega sem acesso | P3 | **feito** | ✓ | ✓ | ✓ | `b615e3f9`, `4d45251b` | | Rótulo "prazos de metas abertas" (o desenho diz "prazos") |
| DOC-118b/c | Documentos | Escrita marcada na origem do menu; lixeira só para quem o GET atende | P3 | **feito** | — | ✓ | ✓ | `43d50135`, `5dcdb37c` | Opus | |
| PAS-013 | Cabeçalho | Menu do responsável: ✓, dica, chip vazio do desenho | P4 | **feito** (ver PAS-013b) | ✓ | ✓ | ✓ | `af4205b2` | | |
| FPN-035 | Topo | Sino de contorno (`bi-bell`) em todo o sistema e no Peticionar | P4 | **feito** | ✓ | — | ✓ | `b216786b`, `f9505f67` | | Todos os desenhos com topo usam o contorno |
| PAS-047i | Timeline | Notificações do usuário ligadas à pasta, com chip próprio | P3 | **feito** | ✓ | ✓ | ✓ | `1a042302` | | Vazamento testado por todos os caminhos |
| PMC-036/029/031 | Metas | Todos os responsáveis; Abrir em Metas no ⋮; concluir/reabrir sem recarregar com Desfazer | P3 | **feito** | ✓ | ✓ | ✓ | `9e0a0ae4`, `fd9d5987`, `910575f0`, `2349cd44` | | Ver PMC-060/061 |
| EDT-012/016/018/019/020/023 | Editor | Sinônimos; "> "/"1)" ao digitar; Citação no estilo; Ctrl+Y; Tab = 4 espaços; botões 34×32 no toque | P3/P4 | **feito** | ✓ | ✓ | ✓ | `d4443c32`..`ef56307f` | (mutações: 7) | |

| EDT-003r | Impressão | Imprimir retirado do Registro e do Financeiro (não está no desenho) | — | **feito** | ✓ | ✓ | ✓ | `fdbb56b0` | | Proposta ao Samuel na §3.4 |
| PAS-025b/013b | Registro/Cabeçalho | Lápide sem escrita no Registro; chip vazio redesenhado pelo contexto | P3/P4 | **feito** | ✓ | ✓ | ✓ | `2e7c201c`, `8b21329c` | | |
| IA-034L (cota) | IA | Cancelada antes do provedor não toma a cota; testes de "sem módulo"/nulo | P3 | **feito** | — | ✓ | ✓ | `2caa5d7c` | (mutações: 4) | |
| DASH-009 | Dashboard/Agenda | "Eventos na agenda" leva à lista da pessoa, com paridade | P2 | **feito** | ✓ | ✓ | ✓ | `7b9152c6` | | Ver §3.4: "Para Todos" de colegas |
| DOC-107/108b/111/112 | Visualizador | "Contexto do processo"; `poster`/`background` limpos; .doc (OLE2) e TIFF multipágina (UTIF 3.1.0 vendorizado) | P3/P4 | **feito** | ✓ | ✓ | ✓ | `0e09587e`..`3d14e368`, `98239842` | | Tetos próprios contra bomba/laço; zoom e impressão |
| L7 (10 itens) | Cabeçalho/CSS | EDT-007/008, PAS-024, PAS-049i, PMC-025/026/007, PAS-002, PAS-022, PAS-023 | P3–P5 | **feito** | ✓ | ✓ | ✓ | `e0e5e7e5`..`4ca881fb` | | `tests/Pasta` 2529 verdes após integrar |
| FPN-032/020 | Push | Filtros com a lista vazia; notas técnicas penduradas sob o cartão com o teor fechado | P3 | **feito** | ✓ | ✓ | ✓ | `8d30a425`, `72dc6e53` | (mutação do tenant: cai) | Ids `-push` para não duplicar |

| PMC-060/061 | Metas | Lápide sem Concluir/Reabrir/Editar nome/sino; Desfazer do reabrir restaura a data original (guardada na sessão, 600 s, uso único) | P3/P4 | **feito** | ✓ | ✓ | ✓ | `a9d22090`, `ceea2f82` | | Sem migration |
| NOT-01 | Notificações | Excluir mensagem/nota apaga os avisos de menção e resposta dela (âncora exata) | P4 | **feito** | — | ✓ | ✓ | `12994ec6` | (LIKE `%a%` pego) | |
| SEC-015 | Segurança | Concluir meta por XHR devolvia a aba Metas a quem não vê a pasta | P0 | **feito** | — | ✓ | ✓ | `a8292b4d` | achado da revisão das ondas 1-2 | Guarda dentro do `ListaDeMetasDaPastaEmJson` (vale para todo chamador) |
| FPN-020b | Push | Nota pendurada criava no processo da 1ª nota, não no da publicação | P2 | **feito** | — | ✓ | ✓ | `3f99a456` | | Sem processo que case: sem compositor |
| DOC-111b/112b | Visualizador | Teto da FAT do .doc antes de alocar; teto somado de 150 Mpx no TIFF | P2 | **feito** | — | ✓ | ✓ | `89a34c25`, `45e7b3b7` | | Teto do TIFF a ratificar (A4 de 50 páginas mostra 36) |
| L6 (14 itens) | Pasta | PMC-059/039/041/040 (corpo e calendário da Nova meta), PAS-034 (diálogos), PAS-021/020/019 (Editar dados), PAS-033/037/032/030/031/040, EDT-014 (qualificação no editor), DOC-124 (rota legada removida) | P2–P5 | **feito** | ✓ | ✓ | ✓ | `fd011225`..`b575428d` | (mutações: 41) | Ver PAS-025c, PAS-034b |

| M1 FPN-005 | Financeiro | Responder a registro do Relatório financeiro (`resposta_a`) | P2 | **feito** | ✓ | ✓ | ✓ | `07ba1031` (`Version20261007205740`) | Opus onda 3: sem bloqueante | resposta_a de outra pasta/escritório → 422 |
| M2 PAS-039 | Detalhes | Responder nas observações de Detalhes | P2 | **feito** | ✓ | ✓ | ✓ | `6576bc7a` (`Version20261007211757`) | idem | |
| M3 EDT-010r | Observações | @menção em Detalhes e Financeiro | P2 | **feito** | ✓ | ✓ | ✓ | `ab78b61f` (sem migration) | idem | Fecha EDT-010 por completo |
| M4 PAS-043 | Timeline | "Enquanto você estava fora" (visita por usuário e pasta) | P2 | **feito** | ✓ | ✓ | ✓ | `207dd2fd` (`Version20261007215638`), `a70f0a2f` | idem | Visita gravada ao ABRIR (desenho: ao fechar), com a anterior guardada; 🧠 tirado (D-IA) |
| M6 PAS-045 | Timeline | "Ver alteração" do registro editado | P3 | **feito** | ✓ | ✓ | ✓ | `092338fd` (`Version20261007220558`) | idem | Só no Registro; Detalhes/Financeiro: PAS-045b |
| M8 PMC-020 | Processo | Versões anteriores da nota técnica a cada edição | P4 | **feito** (sem tela) | — | ✓ | ✓ | `97b0bc45` (`Version20261007221253`) | idem | O desenho não mostra tela de versões |
| M7 FPN-009/008 | Financeiro | "· juros N% a.m." nas parcelas Price; confirm de excluir do desenho | P3 | **feito** | ✓ | ✓ | ✓ | `be356e16` (`Version20261007221859`), `bc2273c7` | idem | Nenhum valor mudou (teste de foto); zera ao corrigir valor |
| M5 PAS-005/PMC-052 | Clientes | Foto do cliente | P2 | **C** | | | | | | Grava PII sem auditoria: depende de D-CLI-AUDIT |
| SEC-016 | Segurança | Lista da Agenda por pessoa ignorava quem olha (via `responsavel=<id>`) | P0 | **feito** | — | ✓ | ✓ | `a0f07c60` | achado da revisão do L6 | Regra de visibilidade num método só (7 consultas); o número do Dashboard pode ser maior que a lista: §3.4 |
| PAS-034c | Diálogos | Foco inicial no Cancelar; região viva criada na carga | P3 | **feito** | — | ✓ | ✓ | `2aa69c6f` | | |
| PAS-025c/PMC-062/PAS-022b/FPN-020c | Pasta | Compositor e "Nova meta" fora da lápide; X religado após XHR; cópias da nota atualizadas | P3–P5 | **feito** | ✓ | ✓ | ✓ | `ee636b4b`, `871c4ab3`, `54ac9e23`, `146eb3a5` | | |

**Novas divergências achadas no ciclo 1:**
- **SEC-012 (A, P0):** `tarefa_mensagem_arquivo_view` serve anexo da Tarefa inline por `BinaryFileResponse` próprio,
  sem `EntregaDeArquivo` (sem CSP), e o upload aceita `.html`. Achado da revisão do SEC-003. Em implementação.
- **SEC-004b (A, P1):** trava por escritório (2 zips simultâneos) além do limitador por usuário. Em implementação.
- **IA-001b / IA-002b (A, P2):** aceite completo; máscara de CPF com pontuação parcial e CNPJ alfanumérico. Em implementação.
- **PAS-047L (A, P4):** "Alertar" no ⋮ do prazo aparece para quem só vê a pasta (o servidor exige edit). Mesmo
  comportamento do sino das Metas.
- **PAS-048 (A, P3, testes):** faltam testes pedidos na spec: observação financeira escondida também na contagem,
  pessoas, "atenção" e busca (com autor exclusivo); PMC-030 de outro escritório → 404; destino de outra pasta do
  mesmo escritório → 404 (era 422) no mover documento.
- **SEC-007b (A, P4):** com `--limite`, item pulado ocupa vaga e a purga deixa de avançar; o log grava trecho do
  caminho (pode ter nome); o resultado não conta os pulados. Correção: limite sobre os processados.
- **SEC-008b (C, ratificar):** teto de 30 avisos/10 min pode suprimir aviso legítimo (2 mensagens com 16 menções) sem
  o autor ver. Alternativa técnica: subir o teto ou avisar o autor.
- **SEC-013b (A, P2):** `MetaNaListaController` tem cópia do guarda falho. Em implementação.
- **FPN-014b (A, P4):** "Marcar como lida" e "Abrir documento" no menu do Push. Em implementação.
- **DASH-026 (D):** o código do dc pede confirmação a cada ativação; o README diz "na primeira vez". Implementado como o README.
- **DASH-029 (C):** a lista "Poderá analisar" do Modo avançado foi reduzida ao que o motor por regras lê (tirados
  Processos, Produtividade, Tendências e Histórico) e saiu "Inteligência de Desempenho da equipe" do subtítulo, por
  regra de "nada fake". Ratificar com o Samuel.
- **DOC-106b (A, P1 desempenho; teto a ratificar):** a regra do desenho descarta números e gera 15.387 pares (~2,7 MB
  de HTML) na pasta de 1.112 arquivos. Teto de 20 pares + "e mais N". Em implementação.
- **DOC-108b (A, P4):** `limparArvore` não remove `poster`/`background` (hoje só a CSP `img-src data:` os barra).
- **DOC-108 (ratificar):** iframe de impressão com `allow-same-origin allow-modals` (inerte sem `allow-scripts`).
- **DOC-115b (B/C):** MSG não entrou no Anexar (o visor não abre MSG sem LibreOffice: D-LIBRE).
- **FPN-040 (A, P3):** botões do próprio teor oferecem escrita a quem só vê e na lápide. Em implementação.
- **PMC-044/038 (A, P3):** ficar na aba Metas após criar; moldura do modal "Nova meta". Em implementação.
- **FPN-014 (ratificar):** o desenho rotula a publicação lida como "Já lida" (sem ação); implementado
  "Marcar como não lida", igual ao botão do teor.
- **IA-034L (A, P1):** o handler da fila não reconfere `IA_HABILITADA`/disponibilidade: desligar a IA não corta o que
  já está na fila. Correção: o handler revalida a disponibilidade antes de chamar o provedor.
- **PMC-057 (A, P5):** "Responder com outra nota" do desenho só abre o compositor (sem migration).
- **PMC-058L (A, P4):** "Adicionar nota técnica" ainda aparece a quem só lê quando a publicação já tem nota.
- **PMC-059 (A, P3):** corpo do modal "Nova meta" (rótulos 13px/700, contador 0/600, chips de responsáveis) e janela
  não modal como no desenho.
- **PAS-025b (A, P3):** lápide oferecia Editar/Excluir/Responder no Registro. Em implementação (PAS-A8).
- **PAS-013b (A, P4):** o script redesenha o chip vazio com o visual antigo depois de salvar. Em implementação (PAS-A8).
- **EDT-003r (desvio retirado):** "Imprimir" no Registro e no Financeiro não está no desenho (`menuObs`/`extrasObs`
  só em Detalhes). Retirado no PAS-A8. **Proposta ao Samuel:** quer o Imprimir também nesses dois menus?
- **PMC-060 (A, P3):** Concluir/Reabrir continuam oferecidos na lápide (o servidor recusa).
- **PMC-061 (A, P4):** "Desfazer" de um reabrir grava a data de agora como conclusão, não a original.
- **NOT-01 (A, P4):** excluir mensagem do Registro não apaga a notificação de menção/resposta (a prévia fica no sino
  e agora na Timeline de quem recebeu).
- **SEC-014 → F (modelo documentado):** o Acervo lista por tenant e módulo; o acesso por item vale ao abrir. É o
  modelo de `docs/AUTORIZACAO.md` (l.357-373: "o módulo governa apenas a descoberta/listagem").
- **PAS-025c (A, P3):** o compositor do Registro (`#formTimelineMensagem`) continua na lápide (o servidor recusa).
- **PAS-034b (A, P4):** `alert`/`confirm` nativos restantes na página (clientes/processos, Financeiro, "Descartar
  alterações" do Editar dados).
- **FPN-020c (A, P5):** editar uma cópia da nota pendurada não atualiza a outra cópia até recarregar.
- **PAS-022b (A, P5):** popovers trocados pelo XHR das Metas ficam sem o X e sem `PsArrastar.zerar`; `pointercancel`.
- **PMC-062 (A, P5):** "Nova meta" no cabeçalho da aba continua na lápide.
- **PAS-045b (A, migration):** "Ver alteração" também nas observações de Detalhes e do Financeiro.
- **LGPD-01 (C):** `nota_tecnica_versao` e `pasta_mensagem.conteudo_anterior` guardam o texto apagado na edição sem
  prazo de retenção e sem tela.
- **PUB-01 (publicação):** as migrations M4 e M8 criam FKs para `pasta`/`user`/`tenant` sem `lock_timeout`. Publicar
  fora do minuto múltiplo de 15 (cron do Sync) ou com `SET lock_timeout`.
- **Deprecations:** `debug:container --deprecations` mostra **5**, não 9 como diz o §11 #6 do mestre.
- **DOC-140 (A, P3):** o .zip sai por `<form target=_blank>`; qualquer recusa (429, 422, 403) abre uma aba com o
  JSON cru e o toast "Preparando o .zip" aparece mesmo assim. Proposta: a rota responder uma página simples
  em pt-BR quando o pedido não for XHR, ou o JS conferir a cota antes. Pré-existente para os 4xx; o 429 agrava.
- **DOC-141 (A, P5):** o aviso do P7S no visor não mostra o tamanho, que o desenho mostra (`bj-visualizar.js:87`).

> **IDs com sufixo `L`** (PAS-047L, PAS-049L, PMC-058L, IA-033L, IA-034L) nasceram neste ledger e colidiam com IDs
> já usados nos inventários para outra coisa; foram renomeados em 07/10. Os IDs sem sufixo são os do inventário.

### 3.2 Fila A priorizada (próximos lotes)

**Reconciliação de 07/10 (fim do ciclo 3):** `engineering-loop/fila-a-restante.md`. Dos 148 A do inventário: 71 feitos,
15 em andamento, 1 virou F por vizinhança (FPN-029, coberto pelo IA-010), **53 pendentes** + 2 novos do ledger;
8 reclassificados (SEC-011 e FPN-013 → C; IA-009 → B/C; DASH-011/012 → D; DASH-027/028 → C; PAS-044 → D/C).
Os lotes L1–L9 (ondas 1 e 2) e M1–M8 (migrations, uma por vez) estão no arquivo.

(Fila original do ciclo 0, mantida como histórico:)

Ordem: P0 → P1 → P2 → P3 → P4/P5, agrupando por arquivo para não haver dois escritores no mesmo lugar.

1. **Segurança restante:** SEC-005 (desfazer Cargo/Sede/Lotação), SEC-006 (trava no corrigir valor, sem
   migration), SEC-007 (caminho inválido para a purga/zip/copiar), SEC-008 (limitador da @menção), SEC-011
   (Push marca lida no GET; ver C abaixo).
2. **P1/P2 da Pasta, sem migration:** PAS-038 (Alertar responsável no ⋮ do prazo), PAS-046 (Timeline lê as
   observações), PMC-030/028 (Excluir e Copiar link no ⋮ da meta), PMC-001/027 e PAS-001/025 (botão
   direito), PAS-041 (⋮ da observação), PMC-004 (Editar dados do processo, depois do PMC-056), PAS-014 e
   DOC-118 (esconder escrita de quem só vê).
3. **@menção nas notas técnicas** (PMC-019) e ⋮ da nota técnica (PMC-017).
4. **Documentos P2:** DOC-115 (Anexar mais formatos), DOC-108 (imprimir qualquer conteúdo do visor),
   DOC-106 (possíveis duplicados no Sugerir), DOC-116 (nome do zip).
5. **Dashboard:** DASH-025/026 (Modo avançado lembrado), DASH-008/010 (links e setas das extras), DASH-006.
6. **Push/Financeiro:** FPN-012 (título = teor), FPN-014 (botão direito), FPN-006, FPN-036, FPN-019, FPN-031.
7. **Editor:** EDT-021/022, EDT-009, EDT-011, EDT-017, EDT-015.
8. **Com migration** (uma por vez): PAS-039/FPN-005 (Responder em Detalhes e Financeiro), PAS-043
   (Enquanto você estava fora), PMC-020 (versões da nota), PAS-005 (foto do cliente), IA-009.

### 3.3 Itens D (aguardando especificação do Farlei)

DASH-002 (o que é "Este mês" e com o que compara) · DASH-034 (Urgentes com ou sem período) · DASH-021
(objetivo automático do ritmo) · PAS-018 (número de pasta repetido: o desenho recusa, o código aceita por
causa do Drive) · PAS-029 (etiqueta do registro: só aparece nos dados de exemplo) · PMC-015 (Relatório geral
de processos por palavras-chave) · FPN: êxito/sucumbência "Previsto" e a base do % (trava o P16b), o que dá o
selo "Assinado", "Criar tarefa" com prazo = data da publicação, "Lida" por escritório ou por pessoa · IA:
10 linhas (fase, situação, providências, memória e os 10 comandos do drawer como regra fixa; 197 comandos do
Chat I.A; ver `inventario-ia.md`).

### 3.4 Decisões novas para o Samuel (além das 35 do §10.3 do documento mestre)

- **SEC-009:** excluir análise da IA e mudar o cadeado "interna" exigem só VER a pasta. Editar? Só o autor?
- **SEC-010:** `/tarefas/equipe` é guardado só pelo módulo `bi` e mostra NUP e cliente sem checar o acesso
  por pasta. O painel de gestão vê tudo, exige também `pastas`, ou filtra pasta a pasta?
- **SEC-011:** abrir o teor no Push marca como lida (GET). Passar para o POST que já existe muda o momento
  em que a publicação conta como lida.
- **PAS (Editar dados):** o "Editar dados" pode alterar CPF e nome no cadastro do cliente? Conflita com a
  decisão de 01/09 (`nome_cliente` é o identificador da pasta).
- **PMC-005:** edição manual dos dados do processo com precedência sobre o Datajud.
- **PMC-034:** alertar pelo sino quem não é responsável da meta.
- **PMC-051:** onde ficam "Tornar principal" e "Desvincular" na linha do cliente.
- **FPN:** card "Valor da causa" = soma dos pagamentos ou campo da pasta; monitoramento e pausa por
  processo; quem recebe o aviso de publicação nova.
- **EDT:** Definir/Traduzir/Pesquisar na web (manda texto do cliente a terceiros); rascunho do editor em
  `localStorage`; dono do atalho Ctrl+K (link do editor × busca global), ligado a D-EDITOR e D-BARRA.
- **Agenda (DASH-009):** o gestor vê na lista da pessoa eventos "Para Todos" de colegas dos quais não participa
  (coerente com o contador e o `agenda_show`, não com o calendário). A lista sem filtro esconde "Para Todos" com
  participantes (anterior).
- **EDT-003r:** quer o "Imprimir" também nos menus do Registro e do Financeiro? (o desenho só o tem em Detalhes)
- **FPN-020:** o bloco de notas pendurado sob o Push leva o cabeçalho "Notas técnicas · N", como na aba Processo; o
  desenho do Push (dc:2534) não o mostra.
- **IA-024:** ratificar a troca dos 10 comandos do desenho pelos 7 agentes. **IA-028:** "Enviar análise ao
  cliente" com cópia para uma Controladoria que não existe. **IA-032:** o botão do Dashboard usa o nome e a
  estrela da IA real sobre um painel por regras.
- **DASH-028:** abertura animada de 2,65 s a cada carga do Dashboard (o auditor recomenda confirmar antes).

### 3.5 Dependências externas (B)

Cron `app:dashboard:fotografar` (DASH-005: tendências inertes em prod) · backfill `calcular-hash` · provedor
de LLM e chave (IA) · PJe/MNI e OCR ("Buscar agora", leitura do processo) · LibreOffice (DOC formatado,
PPT, MSG, HEIC) · Drive em 403 · servidor de chamadas (Chat/Connect) · pool do PHP-FPM (SEC-004, ajuste do
dono).

## 4. Ciclos executados

(um registro por ciclo: o que foi auditado, implementado, testado e revisado)

### Ciclo 6 — reauditoria de convergência (12/10/2026)

- **Estado inicial:** master `4a98cc6b` (topo do loop `cceb7e48`; `4a98cc6b` é de outra frente). Suíte completa
  **9007/9007** (50.022 asserções). Árvore com `scripts/restore.sh` e `scripts/testar-restore.sh` de outra frente
  (não tocados).
- **Auditoria:** 8 auditores Opus somente leitura. Relatórios em `engineering-loop/c6/reauditoria-*.md`,
  consolidação em `engineering-loop/c6/consolidacao.md`.
- **Resultado:** 162 feitos conferidos → 151 ✓, 11 ◐, **1 regressão** (C6-PAS-01, P1: cartão recém-enviado do
  Registro com `data-janela-ate="NaN"`, vindo de `b1155da8`). 5 A pendentes do inventário, 37 A novos, 5 C/D novos.
  1 P0 novo: **C6-SEC-03** (`tarefa_criar_para_pasta` sem posse nem permissão na pasta).
- **Implementação:** lotes S6-* da consolidação §3 (registro de cada lote abaixo).
- **Onda 1 lançada (12/10, noite):** S6-SEC, S6-SHOW, S6-DET, S6-CLI, S6-TL, S6-DOC, S6-PUSH, S6-IMP, S6-EDT,
  S6-DASH, S6-MISC — implementadores Opus em worktrees `agent-*` saídas de `3ff6d9b0`; cada um entrega commit local
  na própria worktree. Integração: revisão Opus read-only → cherry-pick individual → testes direcionados.

| Lote | Itens | Commits no master | Testes direcionados | Revisão | Resultado |
|---|---|---|---|---|---|
| S6-TL | C6-PAS-06, C6-PAS-08 | `b4077ad7` | 24/24 | Opus: aprovado c/ ressalvas menores | ✓ |
| S6-EDT | C6-EDT-05 (+ metade de C6-EDT-09 em `editor-rico.js`) | `3e4bc262`, `1050971f` | 126/126 | Opus: 1 importante (teste não travava o fim sem sugestão) → corrigido | ✓ |
| S6-CLI | C6-PMC-03, C6-PMC-06 | `64367e07`, `26296bcf`, `06592edd` | 65/65 | Opus: token CSRF e dica de trocar ainda saíam a quem não troca → corrigido | ✓ |
| S6-DASH | DASH-008r | `39e32d63`, `4b463629` | 43/43 | Opus: 0% virava link e rótulo ≠ desenho (`celulaLink`: zero sem href; "Taxa de conclusão de X") → alinhado ao desenho; teste com meta fora da janela | ✓ |
| S6-SHOW | C6-PAS-01 (regressão P1), C6-PAS-07 (Registro), C6-PMC-01, C6-PMC-02 | `64927a09`, `cccddbb5` | 79/79 | Opus: sem trava de foco o Esc não fechava e outras janelas ficavam por baixo → foco no título, Esc no documento, `aria-modal` fora, z-index 1049 | ✓ (smoke: Nova meta recolhida + outra janela) |
| S6-DOC | C6-DOC-01, 02, 03 | `29fee857`, `775a7951`, `51c6b660` | 190/190 | Opus: ✎/+/Modelos do checklist ainda no Twig; Imprimir do HEIC ativo → completado; token do painel só p/ quem escreve | ✓ |
| S6-MISC | C6-FPN-02, C6-IA-02 | `4baade61`, `bbb0deee`, `a0ef20d2` | 70/70 | Opus: aprovado | ✓ |
| S6-MISC | C6-IA-01 | **não integrado** (`3260e87e` fica na worktree) | — | Opus: gravar auditoria num GET (sem CSRF) permite linhas falsas; tela e drawer não ligados | ⏳ refazer na onda 2 como POST + CSRF + drawer |
| S6-DET | C6-PAS-02, 03, 04, 05, C6-EDT-04 | `2c2bec4a`, `3dc0e749` | 138/138 | Opus: leitor via "Editar/Excluir · bloqueado" com motivo falso (prazo); nota de só leitura inventada → itens somem para quem não escreve (PAS-014/025c); nota só na lápide | ✓ · C6-PAS-07 de Detalhes vai no S6-OBS |
| S6-IMP | C6-EDT-01, 02, 03 (= PMC-04), 06, 07, 08 | `7d3aa289`, `a011e330` | 112/112 (3 testes do lote com entidade desanexada/`%PDF-` no dump de erro, corrigidos) | Opus: id do processo por captura (frágil) → `pasta-processo.js` passa `pdf`; registro/processo só como PDF (folha HTML 404); autor `??` como o cartão | ✓ (smoke: resumo de 2+ páginas, tema escuro) |
| S6-PUSH | C6-FPN-01, 03, 04, C6-IA-03, 04, C6-PMC-05 | `27cdae74`, `a15be1d5`, `85686de6` | 918/918 (PastaPush, NotaTecnica, Inteligencia) | Opus: ClipboardItem sem try; aviso vermelho ≠ `gmAviso` neutro → corrigidos | ✓ (smoke: Adicionar nota sem abrir teor; Copiar com teor fechado) |
| S6-SEC | C6-SEC-03 (P0), C6-SEC-01, C6-SEC-02 | `9a2b3801`, `03d1fda5`, `20217298` | 297/297; mutação: tirar posse ou VER derruba `CriarTarefaControllerTest` | Opus: aprovado c/ ressalvas | ✓ · pendente: mencionáveis do compositor financeiro (C6-SEC-02b), testes 404-antes-de-403 com não-editor e edição financeira |

- **Suíte completa após a onda 1:** **9095/9095** (50.745 asserções, 06:39), em `854cd6f8`.

| Lote (onda 2) | Itens | Commits no master | Testes direcionados | Revisão | Resultado |
|---|---|---|---|---|---|
| S6-OBS | C6-SEC-02b, testes 404-antes-de-403 e edição financeira, C6-PAS-07 (Detalhes) | `720086bd`, `abda487d`, `260bbaaa` | 292/292 | Opus: aprovado; filtro do financeiro hoje idêntico ao VER (latente, preparado) | ✓ · novo **C6-FPN-05 (A, P5)**: minutos do ⋮ do Relatório financeiro fixos desde a carga (mesma classe do C6-PAS-05) |
| S6-JS | PAS-022r, PAS-023r, PAS-034b, C6-PAS-09, C6-EDT-09 | `4c2a991c`, `242ac329` | 78/78 | Opus: arraste pelo fundo do painel inventado (desenho: só o 1º filho) → retirado; drawer/Timeline arrastados saíam com parte à vista → zera ao fechar; guarda no `insertBefore` | ✓ · C: Restaurar com botão vermelho (`confirmarPs` sem variante neutra; o desenho não tem esse diálogo) · registro: o X fecha por Esc sintético (como o desenho), `alert`/`confirm` nativos restantes fora da lista do PAS-034b (desvincular, situação/prioridade, `_resp_script`, explorador, checklist) |

| S6-IA01 | C6-IA-01 (refeito) | `ca97a7e7`, `86709cfe` | 763/763 (Inteligencia + PastaSomenteLeitura) | Opus: lápide recusava o registro em silêncio → liberado como o .zip (`ROTAS_LIBERADAS`); impressão registrava antes do texto chegar → só depois; "· por Nome" fora (desvio do desenho) | ✓ · registro: a trilha é informativa (download e registro são pedidos separados; o .zip grava no mesmo POST) |
| S6-M1 | PAS-045b (migration `Version20261012230000`) | `7265636b`, `3a6d3278` | 226/226 | Opus: aprovado; `schema:validate` OK; dump-sql só com as 6 linhas pré-existentes (cobrança) | ✓ · migration aplicada no dev (`saas_ux`) e no `saas_test` por `migrations:execute --up`; **em prod: o Samuel aplica no deploy** (ADD COLUMN NULL = metadado no PG15, lock curto) |

- **12/10 ~23h: o WSL travou e a máquina foi reiniciada** com 11 implementadores + revisores + a suíte rodando ao mesmo tempo
  (cada processo Claude ~300 MB; WSL com 9,6 GB). Nada se perdeu: commits no master e nas worktrees intactos; containers
  `jusprime_db_dev`/`jusprime_php_dev` religados à mão (`docker start`). **Regra a partir daqui: no máximo 2 agentes ao
  mesmo tempo, e a suíte sempre sozinha.** Atenção: `ps` não existe no container — para saber se há suíte rodando,
  olhe os `docker exec` no host (`ps aux | grep "docker exec jusprime_php_dev"`).
- **Onda 2 (lançada 12/10):** S6-JS (PAS-022r, PAS-023r, PAS-034b, C6-PAS-09, C6-EDT-09 `_cabecalho`), S6-OBS
  (C6-SEC-02b mencionáveis do Financeiro, testes 404-antes-de-403 e edição financeira, C6-PAS-07 de Detalhes),
  S6-IA01 (C6-IA-01 como POST + CSRF + drawer). Depois: S6-M1 (PAS-045b, migration).
- **Decisões novas para o Samuel (Ciclo 6):** C6-SEC-03 parte C (criar meta exige VER ou EDITAR; com EDITAR o botão
  "Nova meta" some de quem só vê e o responsável também deveria ver a pasta) · C6-DASH-01 (cargo no "ver metas") ·
  C6-PAS-10 (visita da Timeline ao abrir × ao fechar) · C6-PMC-07 (limites 120/600 × 255/5000) · C6-EDT-10
  (download do PDF na auditoria) · C6-DOC-04 (D: tarja no PDF impresso).

## 5. Handoff

**Se a sessão cair durante o Ciclo 6:** a consolidação (`engineering-loop/c6/consolidacao.md` §3) é a fila. Para
saber o que já entrou, procure os IDs C6-* no `git log 3ff6d9b0..master`. Lote sem commit no master = refazer
(as worktrees `agent-*` com commit do lote podem ser reaproveitadas: `git log` na branch `worktree-agent-*` e
cherry-pick individual depois de revisar). Não integre lote sem revisão.
