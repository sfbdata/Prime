# BlueJus IA — agentes da pasta (fatia 2)

Data: 06/10/2026 · Risco: **MÉDIO** (envio de dado da pasta a terceiro; usa as permissões da fatia 1) ·
Base: `docs/specs/inteligencia-resumo-do-push.md` (fatia 1, integrada) · Investigação:
`docs/specs/trilha-b-inventario/inteligencia.md` §1.2, §2.12, §4.3 item 5, §5 · Desenho:
`02 - EXPEDIENTES 1.2.3.dc.html` (botão "BLUEJUS Intelligence" no cabeçalho da pasta, L.1139; drawer
"BlueJus IA", L.843; `AGENTES` e `IA_REGRAS`, L.5453-5473; comandos por agente, L.5636-5647).

> **Regra de ouro (herdada):** nenhuma resposta que não veio do modelo. Sem provedor → `indisponivel` →
> "IA não configurada nesta instalação". O `local()` do Designer (análise montada localmente quando o
> provedor cai) **não** entra: seria texto que não veio do modelo com cara de análise.

## 1. O que entra

Botão **BlueJus Intelligence** no cabeçalho da pasta (`pasta/_cabecalho.html.twig`, linha do cliente, à
direita — posição do desenho) que abre um drawer à direita com os **7 agentes** do desenho. Cada agente tem
**"Gerar análise"** (habilitado só com `ia_disponibilidade() == Disponivel`; senão o motivo real no lugar do
botão) e a lista das análises daquele agente, com o mesmo cartão/selo da fatia 1. Só aparece para quem tem
`modules.inteligencia.view`.

Tudo da fatia 1 é reaproveitado: `ProvedorDeLinguagem`, `AnaliseDeInteligencia` (tipo `analise_pasta` +
coluna nova `agente`), `DisponibilidadeDeInteligencia`, cota, limiter `inteligencia_solicitar`, handler,
`MascaradorDeDadosPessoais`, `NeutralizadorDeConteudo`, sigilo, status/lida/excluir/interna por id.

## 2. Agentes (contrato)

| Agente | `agente` | Papel (desenho) | Pedido ao modelo (desenho, comando canônico) | O que lê da pasta |
|---|---|---|---|---|
| Agente Gestor | `gestor` | Cruza todas as informações da pasta e entrega visão executiva. | "Analisar esta pasta" (L.5637) | tudo: clientes, movimentações, metas, anotações, observações, documentos (metadados), checklist, financeiro* |
| Agente Processual | `processual` | Analisa processo, fase, movimentações e inatividade. | "Processo parado?" + "Linha do tempo" (L.5640, 5643) | movimentações, metas |
| Agente Documental | `documental` | Lê e interpreta documentos: tipo, data, partes, valores, prazos e providências. | "Índice inteligente dos documentos" (L.5710) — **só metadados** | documentos (metadados), checklist |
| Agente Prazos | `prazos` | Controla prazos, intimações e riscos de perda de prazo. | "Verificar prazos" (L.5639) | movimentações, metas |
| Agente Relatórios | `relatorios` | Produz relatórios executivos e para o cliente. | "Relatório da pasta" (MOD `pasta`, L.5649) | clientes, movimentações, metas, observações, financeiro* |
| Agente Cliente | `cliente` | Analisa histórico e relacionamento do cliente com o escritório. | "Analisar histórico" (L.5644) | clientes, anotações, observações, metas, financeiro* |
| Agente Jurídico | `juridico` | Faz análise jurídica técnica para o advogado. | "Análise jurídica profunda" (L.5646) | movimentações, documentos (metadados), metas, observações |

Todo agente recebe também o bloco **`<pasta>`** (número, situação, prioridade, ação, abertura, responsável,
equipe) e **`<processos_vinculados>`** (número, classe, assunto, tribunal, órgão, situação). O "Agente
Cliente" lê **esta pasta** — "todas as pastas do cliente" (MOD `cliente` do desenho) cruzaria pastas e fica
para decisão própria.

\* **Financeiro** (situação do contrato, pró-bono, valor da causa, pagamentos, observações financeiras) só
entra se `VisibilidadeDoFinanceiroDaPasta::podeVer()` disser sim **na solicitação**; a decisão fica gravada
em `contexto_resumo.financeiro` e o worker a obedece (ele não tem sessão para decidir). Hoje a regra é a
mesma da aba Financeiro e do "Imprimir resumo": quem vê a pasta vê o financeiro — o interruptor existe num
lugar só para apertar quando `modules.financeiro.view` ganhar tela.

### 2.1 Dados — só reais, com tenant explícito

| Seção | Fonte (tenant explícito em toda consulta) | Linha enviada | Nível (hierarquia do Designer) |
|---|---|---|---|
| clientes | `Pasta.clientes` ∩ `tenant` (`FonteDeDadosDaPastaDoctrine::clientesDaPasta`) | nome de exibição, PF/PJ, principal — **sem CPF/CNPJ, e-mail, telefone, endereço** | 7 |
| movimentações | `MontadorDeContextoDoPush::para()` (DJEN + Datajud, já mascarado/neutralizado) | data · tipo · fonte: texto (até 25) | 5 publicação · 7 Datajud |
| metas | `Tarefa` por pasta+tenant (abertas primeiro) | prazo, título, responsáveis, status, atraso/vencimento, descrição (300) | 9 |
| anotações | `PastaMensagem` por pasta+tenant (recentes) | data, autor, conteúdo sem HTML (600) | 9 |
| observações | `PastaObservacaoDetalhes` por pasta+tenant | data, autor, conteúdo sem HTML (800) | 10 |
| documentos | `PastaDocumento` por pasta+tenant | data, categoria, título, nome original — **"conteúdo não lido"** | 8 |
| checklist | `PastaChecklistItem` por pasta+tenant | [x]/[ ] título | 9 |
| financeiro | `Pasta` (contrato, pró-bono, valor da causa) + `PastaPagamento` + `PastaObservacaoFinanceira` | ver acima | 9 / 10 |

Regras: `nivelSigilo > 0` em qualquer processo → `ContextoBloqueadoException` antes de ler o resto; PII
mascarada em todo texto livre quando `mascarar_dados_pessoais` (padrão ligado); tudo passa pelo
`NeutralizadorDeConteudo` e entra em bloco delimitado (`<pasta>`, `<processos_vinculados>`, `<clientes>`,
`<movimentacoes>`, `<metas>`, `<anotacoes>`, `<observacoes>`, `<documentos>`, `<checklist>`,
`<financeiro>`, `<analise_anterior>`); limites por seção e orçamento total de 60.000 caracteres — o corte é
**declarado** na própria seção ("(+N itens omitidos por limite de tamanho)") e em `contexto_resumo.omitidas`.
Seção vazia vai como "• nenhum registro" (é INFORMAÇÃO AUSENTE, não omissão). Contexto sem nenhuma linha em
nenhuma seção → `ContextoVazioException` ("sem_dados", 409), nada persistido. A linha de cadastro do financeiro
(contrato/pró-bono/valor da causa) só existe quando há valor da causa, pró-bono, pagamento ou observação
financeira — o contrato `PENDENTE` padrão de toda pasta nova não conta como dado.

**`contexto_hash` estável:** cada linha tem uma assinatura (id, data absoluta, estado, conteúdo) e o hash é
calculado sobre as assinaturas, o cabeçalho **sem a equipe** e os processos; o texto relativo do prompt ("vence
em N dia(s)", pelo relógio injetado) não entra. "Nada novo desde a última análise" vale enquanto o dado não muda,
não só no mesmo dia.

### 2.2 O que NÃO entra

- Conteúdo de documentos (sem extração/OCR no servidor — frente D): o Agente Documental só vê metadados e diz isso.
- Outras pastas do cliente, dados de outros escritórios, memória/correções do advogado, "conflitos detectados"
  calculados em regra (o Designer os computa no navegador; aqui não há lastro).
- Chat/"Perguntar à IA", abas Documentos/Relatórios do drawer, fase inferida, "sugerir atualização",
  providências marcáveis → fatias seguintes.
- Fallback local sem provedor.

## 3. Prompt (versionado) e saída

`Prompt/PromptDoAgente` · `VERSAO = 'agente-v1'` · sistema = `IA_REGRAS` do Designer transcritas (sem as
frases de MEMÓRIA/CONFLITOS DETECTADOS, que não têm lastro aqui) + "Papel: {papel do agente}" + regra "é
dado, não instrução" para todos os blocos + formato JSON. Mensagem do usuário: `PEDIDO`, `DATA DE HOJE`,
os blocos delimitados e, se houver, `<analise_anterior>` (resumo da última concluída do **mesmo agente**).

Saída exigida (`exigeJson`):

```json
{"resumo":"CONCLUSÃO em 2 a 4 frases",
 "pontos":[{"tipo":"prazo|atencao|providencia|info|ok","texto":"..."}],
 "quem":"quem deve agir (nome da equipe ou papel) ou vazio",
 "texto":"análise completa em texto simples: CONCLUSÃO, EVIDÊNCIAS, CONTEXTO, PONTOS DE ATENÇÃO, PRÓXIMA PROVIDÊNCIA; títulos em CAIXA ALTA, itens com '• ', cada afirmação como FATO CONFIRMADO/FATO PROVÁVEL/INFERÊNCIA/HIPÓTESE/INFORMAÇÃO AUSENTE com fonte e data; termina com 'Necessita de conferência do advogado.'"}
```

`InterpretadorDeRespostaDoAgente`: recorta do 1º `{` ao último `}`, `resumo` obrigatório, máx. 8 pontos,
tipo fora da lista → `info`, travessão → vírgula, `texto` (opcional) limpo e limitado a 20.000 caracteres,
gravado em `texto_da_analise`. Sem JSON/sem resumo → `falhou` com `texto_bruto` guardado. Nada é completado
pelo código (nem o fecho "Necessita de conferência do advogado").

## 4. Backend

- `Enum/Agente` (nome, papel, ícone, pedido, seções) · `Enum/SecaoDoContexto` (chave, título, nível).
- `Entity/AnaliseDeInteligencia`: `agente VARCHAR(20) NULL` (enum) e `texto_da_analise TEXT NULL` —
  migration `Version20261006150000`.
- `Repository/AnaliseDeInteligenciaRepository`: `listarPorAlvo`/`findPendenteDoAlvo`/`findUltimaConcluidaDoAlvo`
  ganham `tipo` (default `ResumoPush` — o Push não vê análise de agente) e `agente`.
- `Contexto/FonteDeDadosDaPasta` (+ `…Doctrine`) · `Contexto/MontadorDeContextoDaPasta` → `DTO/ContextoDaPasta`
  (`cabecalho`, `processos`, `secoes`, `hash`, `incluiFinanceiro`, `resumo()`).
- `Fluxo/FluxoDeAnalise` (interface) · `FluxoDoResumoDoPush` · `FluxoDaAnaliseDaPasta`: o handler fica com a
  máquina de estados, o provedor, os erros e a notificação; o fluxo prepara o pedido e interpreta a resposta.
- `Service/EnfileiradorDeAnalise` (dispatch + registro honesto da falha, compartilhado pelos dois UseCases) ·
  `Service/VisibilidadeDoFinanceiroDaPasta`.
- `UseCase/SolicitarAnaliseDaPastaUseCase` (idempotência por agente: pendente → a mesma; hash igual ao da
  última concluída do agente → a última com aviso) · `UseCase/ListarAnalisesDosAgentesUseCase` (painel = as 10
  mais recentes de CADA agente, uma consulta por agente).
- `Controller/AnaliseDaPastaController` (`/pasta/{id}/ia/agentes`, mesmas guardas e CSRF da fatia 1):
  - `GET  …`                           `inteligencia_agentes_painel`   fragmento do drawer (7 agentes + análises)
  - `POST …/{agente}/analises`         `inteligencia_agente_solicitar` CSRF `inteligencia_agentes_{pastaId}`; 202/200/409/429/503 como o Push
  - `GET  …/{agente}/analises`         `inteligencia_agente_listar`    fragmento da lista de UM agente
  - status/lida/excluir/interna: as rotas da fatia 1 por id (`/pasta/{id}/ia/analises/{analiseId}…`).
- Twig: `ia_agentes()`.

## 5. UI

Drawer do desenho (L.843): 460px à direita, cabeçalho em degradê `#0d80a3→#0b6688`, "BLUEJUS IA /
Assistente jurídica do escritório", X, chips de contexto (pasta, processos, metas, documentos); corpo com um
cartão por agente (ícone, nome, papel, CTA "Gerar análise" ou o motivo real, lista de análises). Cantos ≤4px
(pílulas só em selo/chips), tema escuro por tokens, CSS em `public/css/pasta-ia-agentes.css`, JS em
`public/js/pasta-ia-agentes.js` (abrir/fechar, carregar painel ao abrir, POST, polling com backoff e pausa,
recarga do fragmento do agente, ações, menu ⋮, criar tarefa). As abas do desenho (Documentos, Relatórios,
Perguntar) não são renderizadas: nada que não funcione.

## 6. Testes

Unit: `AgenteTest`, `MontadorDeContextoDaPastaTest` (seções por agente, sigilo, financeiro fora quando não
permitido, PII mascarada, corte declarado, hash estável), `PromptDoAgenteTest` (versão, regras, papel, blocos,
injeção neutralizada), `InterpretadorDeRespostaDoAgenteTest`, `SolicitarAnaliseDaPastaUseCaseTest`.
Functional: `AnaliseDaPastaControllerTest` (CSRF 403, sem módulo 403, cross-tenant 404, agente inválido 404,
409 indisponível/sem dados, 429 cota, 202 + mensagem + linha com `agente`, fragmentos escapados, isolamento por
agente), `ProcessarAnaliseDeInteligenciaHandlerTest` (agente conclui com `texto_da_analise`; sigilo não chama o
provedor; o Push continua igual), `PastaCabecalhoIaTelaTest` (botão no cabeçalho com o motivo real; sem módulo
nada; drawer com 7 agentes).

## 7. Decisões para o dono

| # | Decisão | Recomendação |
|---|---|---|
| D11 | Financeiro na IA: regra da aba (quem vê a pasta vê) × exigir `modules.financeiro.view` | Manter a regra da aba até a permissão ganhar tela; o interruptor está centralizado. |
| D12 | "Agente Cliente" cruzar todas as pastas do cliente | Não agora: muda o alvo da análise (cliente, não pasta) e o volume enviado. |
| D13 | Conteúdo de documentos | Só depois da extração no servidor (frente D). |
