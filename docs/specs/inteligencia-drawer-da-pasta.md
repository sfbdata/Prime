# Drawer BlueJus IA da pasta (desenho 02)

Frente `ia-drawer`, sessão f6, aberta em 19/10/2026; o coordenador passou o drawer da 4b para a f6 (a
Inteligência cadastral continua com a 4b). Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS
18.10.26.dc.html` (dc), drawer em L938-1139 e script em L5711-6005 (comandos em L5894-5905, relatórios em
L5906-5913). Regras: `app/src/Inteligencia/CLAUDE.md` (toda resposta vem do modelo; conteúdo de documento não é
lido; do cliente sai só o nome) e, do coordenador, **nada roda sozinho em lote**. Base anterior:
`docs/specs/inteligencia-agentes-da-pasta.md` (os 7 agentes) e `docs/specs/engineering-loop/inventario-ia.md`
(IA-016 a IA-027).

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
