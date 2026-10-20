# Drawer BlueJus IA da pasta (desenho 02)

Frente `ia-drawer`, sessão f6, aberta em 19/10/2026; o coordenador passou o drawer da 4b para a f6 (a
Inteligência cadastral continua com a 4b). Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS
18.10.26.dc.html` (dc), drawer em L938-1139 e script em L5711-6005 (comandos em L5894-5905, relatórios em
L5906-5913). Regras: `app/src/Inteligencia/CLAUDE.md` (toda resposta vem do modelo; conteúdo de documento não é
lido; do cliente sai só o nome) e, do coordenador, **nada roda sozinho em lote**. Base anterior:
`docs/specs/inteligencia-agentes-da-pasta.md` (os 7 agentes) e `docs/specs/engineering-loop/inventario-ia.md`
(IA-016 a IA-027).


## Estado da frente (19/10, sessão f6)

- F1+F2: PR #114 (rascunho, base #68), suíte completa e visual feitos. Commits já enviados.
- F3 Perguntar: PR #122 (rascunho, base #114), suíte completa OK (10788), revisão aprovada com ressalvas corrigidas.
  Migration provisória `Version20261021110000`. Auditoria "quem perguntou e quando, sem texto": regra do "Só eu" (coordenador).
- F4 Documentos: branch `ia-drawer-f4` (sobre `ia-drawer-f3`), sem migration. F5 Criar tarefa: a fazer.

## O que o desenho tem, e o que pode entrar

O drawer do desenho é, em grande parte, **regra rotulada como IA** (`inventario-ia.md`, nota 3): fase com
"Confiança", "Fase errada?", "INCONSISTÊNCIA IDENTIFICADA", "Situação da pasta", providências marcáveis,
"Memória de aprendizado", indicadores "fontes"/"ausentes", e um texto local (`iaLocal`) quando o provedor falha.
Só os comandos, os relatórios, "Ler"/"Índice" e o Perguntar chamam um modelo, e o desenho monta o prompt no
navegador (sem tenant, sigilo, delimitação nem máscara; manda CPF do cliente). Nada disso entra como está.

| Item do desenho | Entra? | Como |
|---|---|---|
| Abas Análise, Documentos, Relatórios, Perguntar | sim | F1: abas renderizadas só com conteúdo que funciona |
| 10 comandos (Analisar, Riscos, Prazos, Parado?, Providências, Briefing, Linha do tempo, Histórico, Raio-X, Jurídica) | sim | F1: cada comando é um pedido ao modelo pelo agente do desenho, com o contexto montado no servidor (`MontadorDeContextoDaPasta`) |
| 6 relatórios | 5 sim | F2: mesmo mecanismo; Copiar, Baixar .txt e Imprimir já existem. "Relatório do cliente" com TODAS as pastas espera a D12 |
| Perguntar (conversa) | sim | F3: tipo `PerguntaLivre` com fluxo, conversa guardada por pasta e pessoa, histórico curto no prompt, assíncrono com polling |
| Aba Documentos: lista e seleção | sim (metadados) | F4: lista com nome, tipo, data e tamanho; "Índice" só por metadados (o Agente Documental de hoje), dizendo que o conteúdo não foi lido |
| "Ler N documentos", índice com leitura, OCR | **não** | depende de LER CONTEÚDO: com o dono (regra 7, D13) |
| Fase, inconsistência, situação, providências marcáveis, memória, indicadores | **não, por ora** | regra apresentada como IA (regra 1); a regra precisa vir do dono/Farlei (IA-020 a IA-025) e, se for regra, o rótulo diz "por regras" (precedente S-4). Com o dono |
| "Criar tarefa" do resultado | sim | F5: abre a nova meta pré-preenchida, UMA por clique, com confirmação (sem lote) |
| "Criar N tarefas em Metas" (lista por regra, em lote) | **não** | lote a partir de lista por regra: fora (regra do coordenador) |
| "Criar prazo" | **não** | IA-026, bloqueado por D-PRAZOS |
| "Enviar para revisão" | **não** | IA-027: não existe fluxo de revisão. Com o dono |
| Texto local quando o provedor falha | **não** | regra 1: falha mostra a falha ("IA não configurada" / "Não foi possível gerar") |

## Fatias (uma migration no máximo por PR; vez com o coordenador)

- **F1 Abas + comandos.** Abas no drawer; aba Análise com o grade "O que a IA sabe" (existe) e os 10 comandos.
  Modelo: `Comando` (enum com rótulo, agente e pedido literais do desenho, sem "documento" onde o conteúdo não é
  lido) e a análise guardando qual comando a gerou (coluna nova em `inteligencia_analise`, migration). Idempotência
  por comando. Os 7 cartões de agente de hoje viram os comandos do desenho (Gestor = "Analisar esta pasta"...): a
  troca de taxonomia (IA-024) é o que o desenho mostra (regra do dono "tudo que está no desenho entra").
- **F2 Relatórios.** Aba Relatórios com 5 relatórios pelo mesmo mecanismo do F1 (sem migration própria).
- **F3 Perguntar.** Tipo `PerguntaLivre` com fluxo e interpretador; conversa por pasta e pessoa (tabela nova,
  migration); "MODO CONVERSA" do desenho; últimas 8 mensagens; sugestões de pergunta literais; Enter envia.
- **F4 Documentos.** Aba com a lista por metadados e o "Índice" por metadados; textos sem prometer leitura.
- **F5 Ações do resultado.** "Criar tarefa" do desenho (confirmação, uma por vez) sobre o modal de nova meta.

## F3 Perguntar: contrato (19/10)

Desenho: aba "Perguntar" (`bi bi-chat-dots`, dc L5945), painel em L1072-1084, rodapé com o campo em L1126-1131,
`perguntar` em L5920-5938, estilos das mensagens em L5972, sugestões em L5974.

- **Tela.** Quarta aba depois de Relatórios (a ordem do desenho é Análise, Documentos, Relatórios, Perguntar; Documentos
  entra no F4). Mensagens em coluna: as minhas à direita em degradê, as da IA à esquerda em branco com borda, `max-width:88%`,
  `white-space:pre-wrap`, escapadas. Sem mensagens: "Pergunte sobre esta pasta. A IA já conhece o cliente, o processo, os
  prazos, as metas, os registros e os documentos." (literal do desenho; os documentos entram só por nome e data, e o prompt
  diz isso). Sugestões literais: "Esse processo está parado?", "O que falta fazer?", "Veja se existe prazo", "Existe algum
  risco?" (clique envia). Rodapé fixo só na aba: campo "Pergunte sobre esta pasta..." + botão enviar (`bi-send-fill`),
  desabilitado vazio ou com pergunta em andamento; Enter envia. Em andamento: o cartão "Consultando os dados desta pasta"
  (dc L1086-1092) até a resposta chegar (polling, como os comandos). IA indisponível: campo e botão desabilitados com o
  motivo real. Falha: a mensagem da IA diz a falha ("Não foi possível responder"), nunca texto local (regra 1).
- **Modelo.** Cada pergunta é uma `AnaliseDeInteligencia` de tipo `PerguntaLivre`, agente `Gestor` (o contexto mais amplo,
  com o financeiro só se `VisibilidadeDoFinanceiroDaPasta` disser sim), sem comando. A pergunta mora numa tabela nova,
  `inteligencia_pergunta` (entidade `PerguntaDaConversa`: tenant, pasta_id, autor, texto, analise_id único, criada_em). A
  resposta é o `texto_da_analise` da análise (não duplica). Conversa = perguntas da MESMA pessoa na MESMA pasta; ninguém
  vê a conversa de outra pessoa. Purga do escritório cobre a tabela.
- **Pedido.** `PerguntarSobreAPastaUseCase`: mesmas guardas do `SolicitarAnaliseDaPastaUseCase` (pasta do tenant,
  `canAccessResource`, disponibilidade, sigilo, abas ocultas gravadas no resumo); texto aparado de 1 a 2000 caracteres;
  uma pergunta em andamento por pessoa e pasta (a segunda devolve a que está em andamento, sem criar); o limitador só gasta
  ao criar. Rotas: `POST /pasta/{id}/ia/conversa/perguntas` (`inteligencia_conversa_perguntar`, CSRF
  `inteligencia_agentes_{id}`) e `GET /pasta/{id}/ia/conversa` (`inteligencia_conversa_listar`, as últimas 20 da pessoa).
- **Prompt.** `FluxoDaPerguntaLivre` + `PromptDaPergunta` (versão `pergunta-v1`): o sistema do agente + "MODO CONVERSA:
  responda à pergunta com a menor estrutura suficiente; para perguntas simples, CONCLUSÃO e EVIDÊNCIAS bastam." (literal
  do desenho). Uma mensagem do usuário: os blocos do contexto, as últimas 4 perguntas concluídas da conversa com as
  respostas (8 mensagens, como o desenho) dentro de `<conversa_anterior>` e a pergunta dentro de `<pergunta>`, ambos
  neutralizados e passados pela máscara de dados pessoais. Nenhum texto de pergunta em log.
- **Migration**: só a tabela nova (provisória `Version20261021110000`, depois da do F1).

## F4 Documentos: contrato (19/10)

Desenho: aba "Documentos" (`bi bi-file-earmark-text`, dc L5945), painel em L1041-1058, lógica em L5915 e L5961-5968.

- **Tela.** Aba entre Análise e Relatórios (ordem do desenho). Não aparece para quem tem a aba Documentos da pasta oculta.
  Texto do topo: "A IA monta o índice pelos dados de cada arquivo: nome, tipo, data e tamanho. O conteúdo dos arquivos não
  é lido." (o do desenho promete leitura e OCR: regra 7 e D13, a lei do domínio corrige o protótipo). Lista num cartão:
  linha "Todos" que marca e desmarca tudo, divisória, e cada documento com a caixa de marcar, o ícone do arquivo com a
  letra do tipo, o nome (uma linha, reticências) e a linha de dados "PDF · 1,2 MB · 12/10/2026". Embaixo, um botão só,
  "Índice inteligente" (`bi-list-columns-reverse`), na largura toda, desabilitado sem seleção ou com a IA indisponível
  (motivo real). "Ler N documentos" NÃO entra (D13, com o dono). O resultado e o histórico aparecem embaixo, como nos
  comandos. Pasta sem documento: "Nenhum documento nesta pasta." e o botão desabilitado.
- **Modelo.** Comando novo `IndiceDosDocumentos` (`indice_documentos`, rótulo "Índice inteligente", agente Documental,
  fora da grade de 10 comandos e dos relatórios). Pedido: o canônico do Documental restrito aos selecionados, mantendo
  "O conteúdo dos arquivos não foi lido: não descreva o que está dentro deles." A análise legada do Documental (sem
  comando) passa a aparecer no histórico deste comando (não mais no Raio-X).
- **Seleção.** O POST do comando aceita `documentos[]` (ids). Só vale id de documento da pasta, visível para quem pede
  (abas ocultas) e dentro da lista mostrada (os mesmos `LIMITE_DOCUMENTOS` mais recentes do contexto); id fora disso ou
  seleção vazia → 422. Os ids ordenados vão em `contexto_resumo.documentosSelecionados`; o worker obedece (não decide) e a
  seção `<documentos>` do contexto leva só esses. A seleção muda o hash: "nada novo" vale por seleção.
- **Sem migration.**

## Contratos compartilhados (avisar a 4b antes)

`Agente`, `TipoDeAnalise`, `FluxoDeAnalise`, `PromptDoAgente`, `MontadorDeContextoDaPasta`, rotas do
`AnaliseDaPastaController`. **Conflito provável com o #68** (pasta-ocultar-aba mexe em contexto, agentes e
controllers): quem entrar depois resolve; o F1 começa só depois de olhar o estado do #68.

## Para o dono

1. Fase, inconsistência, situação, providências e memória do desenho: são regras. Entram como "por regras" (S-4)
   com a regra vinda do dono/Farlei, ou ficam fora?
2. Leitura de conteúdo de documentos ("Ler N documentos", índice com leitura, OCR): D13.
3. "Relatório do cliente" com todas as pastas do cliente: D12.
4. "Criar prazo" (D-PRAZOS) e "Enviar para revisão" (não há fluxo de revisão).
