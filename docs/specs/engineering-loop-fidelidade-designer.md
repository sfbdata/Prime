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

(preenchida a partir do inventário real — §3)

## 3. Divergências (ledger)

(preenchido a partir da auditoria inicial)

## 4. Ciclos executados

(um registro por ciclo: o que foi auditado, implementado, testado e revisado)

## 5. Handoff
