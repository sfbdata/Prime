# Spec: Trabalho Externo VS 18.10.26 (demandas, vagas e currículos)

Frente `trabalho-externo-vs1810`, base `origin/master` @ `1d3e664c` (v2.2.2). Continua a spec
`docs/specs/trabalho-externo.md`, que segue valendo para tudo o que ela já cobre.

Desenho: `06 - Trabalho Externo - VS 18.10.26.dc.html`, projeto Claude Design `4f6a5f61-…`.
Cópia local **parcial** em `docs/design/trabalho-externo/vs-18-10-26/` (o DesignSync entrega no máximo
256 KiB). A marcação veio inteira (linhas 1–706); o script foi cortado na linha 930, dentro de `cvHTML`.
Perdeu-se o `renderVals()`, que monta todas as listas e os textos dinâmicos. O zip completo foi pedido
ao dono. **O desenho manda** onde mostra algo; onde a parte que falta é omissa, vale a convenção do
sistema e o item fica marcado como **provisório até o zip**.

Risco **BAIXO** pela régua do projeto (não toca ponto, identidade User/Tenant nem TenantRole/Permission).
A spec existe pelo tamanho: são 754 ligações novas na marcação.

## 1. Escopo e divisão

Regra do dono (19/10, via coordenador): tudo o que está no desenho entra. Os quatro scripts globais
do `<helmet>` têm donos em outras frentes e esta só os consome: `bj-idioma` (7d), `bj-recursos` (b5),
`bj-lembretes` (4b), `bj-plano` (f6). A ordem por "preferência de assinante" (Contribua C6, f6) entra
quando a f6 combinar o contrato.

| Fase | Conteúdo | Migration | Depende do zip |
|---|---|---|---|
| **F1** | Demandas: marcações por usuário (seleção em lote, estrela, importância, lixeira pessoal, lida/não lida, desfazer) e chat avançado (seleção, favorito, lixeira pessoal de 30 dias, excluir definitivo, apagar para todos) | sim | só as listas de rótulos (§3.5) |
| **F2** | Vagas: vaga, oferta com tempo, candidatura, aprovação de vaga de escritório, compartilhar, campanha por e-mail, configuração interna | sim | regras de quem faz o quê, menus |
| **F3** | Currículos: banco de talentos, painel (KPIs, gráficos, crescimento), lista com filtros, exportar CSV, currículo em PDF, Termo de Voluntariado, nota interna | sim | KPIs, menus, PDF |
| **F4** | Visão externa: Meu currículo, Minhas candidaturas, boas-vindas, "quase lá", lembrete diário, abas de módulo e banner de engajamento/alertas | talvez | quase tudo |

Cada fase é um PR. Uma migration por vez entre os PRs abertos (fila do coordenador).

## 2. Modelo de marcação por usuário (vale para F1–F4)

O desenho trata quatro tipos de item com o mesmo conjunto de operações por usuário (`lote()` na linha
901 e `tlLote()` na linha 912): lixeira, restaurar, definitivo, lida, não lida, estrela, sem estrela,
importante, não importante. **Nada disso altera o item para os outros participantes** ("A lixeira é só
sua: as demandas continuam para os outros participantes").

Tabela única `trabalho_externo_marcacao`:

| Coluna | Tipo | Regra |
|---|---|---|
| `usuario_id` | FK `user`, `ON DELETE CASCADE` | dono da marcação |
| `tipo` | enum `TipoItemMarcado`: `demanda`, `vaga`, `curriculo`, `candidatura` | |
| `item_id` | int | id do item; sem FK (polimórfico). A remoção do item apaga as marcações no UseCase que remove |
| `estrela` | bool, padrão `false` | |
| `importante` | bool **anulável** | `null` = automático; `true`/`false` = escolha do usuário, vence o cálculo |
| `nao_lida` | bool, padrão `false` | marca manual "não lida" |
| `lixeira_em` | datetime anulável | |
| `excluida_em` | datetime anulável | some da lista do usuário para sempre |

Único em (`usuario_id`, `tipo`, `item_id`). **Decisão:** tabela única em vez de uma por tipo. As
operações e colunas são idênticas nos quatro tipos e o painel de cada aba lê por (`usuario`, `tipo`).
O preço é não ter FK para o item; ele é pago num só ponto (remoção do item), coberto por teste.

A entidade não é `TenantAware`: a marcação é do usuário, e o Master marca demandas de todos os
escritórios. Toda leitura filtra por `usuario` (o usuário logado); nenhuma rota recebe usuário.

Na prévia do Master (`?como=`), marcar é escrita e continua negado (spec base §9).

## 3. F1: Demandas

### 3.1 Operações em lote

`POST /trabalho-externo/api/demandas/marcacoes` com `{codigos: [...], op}`. `op` ∈ `lixeira`,
`restaurar`, `definitivo`, `lida`, `naolida`, `estrela`, `semestrela`, `imp`, `naoimp`.

- Só entram códigos que o usuário **vê** pelo repositório com escopo atual. Código fora do escopo é
  ignorado e não vaza existência (a resposta conta só os aplicados).
- `lida`: avança o ponteiro de `LeituraDemanda` até a última mensagem visível e zera `nao_lida`.
  `naolida`: liga `nao_lida`.
- `definitivo`: o navegador confirma antes, com o texto do desenho ("Excluir esta demanda da sua lista
  definitivamente? Ela continua existindo para os outros participantes.").
- Resposta: `{aplicados, texto}`, com o texto do desenho (`T` em `lote()`): "N demanda(s) movida(s)
  para a lixeira", "… restaurada(s)", "… excluída(s) da sua lista", "… marcada(s) como lida",
  "… marcada(s) como não lida", "… com estrela", "… sem estrela", "… marcada(s) como importante",
  "… marcada(s) como não importante".
- **Desfazer** (8 s, só para `lixeira`): o navegador chama `restaurar` com os mesmos códigos.

### 3.2 Importância (calculada no servidor, `impDe()` linha 889)

Pontos: urgente e aberta +3; prioridade Urgente +3, Alta +2; prazo (aberta) vencido ou em até 1 dia
+3, até 3 dias +2, até 7 dias +1; valor da última proposta ≥ R$ 5 mil +2, ≥ R$ 1 mil +1; mensagens
não lidas +1; situação `nova`, `info`, `proposta` ou `validacao` +1.
Nível: ≥ 6 → 3, ≥ 4 → 2, ≥ 2 → 1, senão 0. Demanda fechada (`FECHADAS`) fica no máximo em 1.
Escolha manual vence: `importante = true` → 3 ("você marcou como importante"), `false` → 0.
A linha recebe `{nivel, motivos[]}`; a dica do botão lista os motivos.

### 3.3 Lista

- Demanda com `excluida_em` não aparece mais para o usuário (nem conta nos KPIs e chips dele).
- Demanda com `lixeira_em` só aparece no filtro Lixeira, com o aviso do desenho (linha 126).
- `nao_lida` faz a linha contar 1 não lida quando o ponteiro já está em dia (`naoLidas()` linha 747).
- Linha em negrito quando há não lidas.
- A coluna nova (96 px) leva seleção, estrela e importância (linhas 140–141); cabeçalho com
  selecionar todas, "Selecionar por" e Atualizar (linhas 130–133).

### 3.4 Chat avançado

Tabela `trabalho_externo_marcacao_mensagem` (`mensagem_id` FK cascade, `usuario_id` FK cascade,
`favorita` bool, `lixeira_em`, `excluida_em`; único por par). Coluna nova
`trabalho_externo_mensagem.apagada_em` (apagar para todos).

- `POST …/demandas/{codigo}/mensagens/marcacoes` com `{ids, op}`, `op` ∈ `lixeira`, `restaurar`,
  `definitivo`, `fav` (`_chatLote()` linha 878). Só mensagens que o usuário vê na demanda.
- Lixeira do chat expira em 30 dias: na leitura, `lixeira_em` com mais de 30 dias conta como
  excluída (`_chatLixeira()` linha 886). Sem cron.
- Apagar para todos: o autor, nas próprias mensagens `normal` ou `interna`, e o Master em qualquer
  uma (o Master pode tudo, regra do dono, via coordenador em 19/10). Registro do sistema não se
  apaga. A mensagem continua na conversa sem o texto e some das não lidas. **Provisório até o zip**
  (a regra `m.podeApagar` se perdeu).
- Teclas Delete ou `#` com mensagens selecionadas: lixeira (ou definitivo, dentro da lixeira).
- Desfazer de 8 s para a lixeira e o restaurar.

### 3.5 Provisório até o zip (rótulos que estavam no código perdido)

As estruturas abaixo existem na marcação, mas o conteúdo vinha do `renderVals()`:
`loteAcoes` (botões da barra de lote), `selOps` ("Selecionar por"), o chip Lixeira (o desenho só
mostra o aviso `lixGrupo`; estrela e importância não ganham chip, para não inventar filtro), itens novos do menu de contexto da linha (`menuItens`) e do
menu da mensagem (`tlMenuItens`), `chatFiltros`, `chatVazioTxt`. Até o zip, usam o conjunto de
operações de `lote()`/`_chatLote()` com os rótulos do mapa `T`, e o menu da linha mantém os itens da
1.0 mais estrela, importante, lida/não lida e lixeira.

## 4. F2–F4: o que já se sabe

Da marcação e das estáticas que vieram (linhas 832–846):
- Vaga: status `VST` (Aguardando aprovação, Não aprovada, Aberta, Pausada, Preenchida, Expirada,
  Encerrada); durações `DUR` 5/10/15/30/60 dias; oferta relâmpago com cronômetro, nº de vagas e
  aceites; formulário em `tmVagaForm` (linhas 230–381). Configuração padrão `tlCfg()`:
  `{dur: 15, sino: true, motiv: true, maxDias: 0, minEnvio: 3}`.
- Currículo: status `CST` (Novo, Em análise, Entrevista, Favorito, Arquivado); campos em `tmCvForm`;
  áreas `AREAS` (12), cargos `CARGOS` (7), contratação `CONTR`, modalidade `MODAL`.
- Candidatura: status `AST` (Recebida, Em análise, Entrevista, Aprovado, Contratado, Não selecionado).
- Termo de Voluntariado: `termoHTML()` completo (Lei 9.608/1998), versão `TERMO_V = '1.0'`.

Cada fase ganha sua seção aqui antes de começar, depois do zip.

## 5. Verificação

- Unit dos UseCases e functional dos controllers (escopo, prévia negada, código fora do escopo).
- Lista item a item do desenho com ✓/✗ no PR. A comparação no navegador depende de autorização do
  dono nesta sessão (CLAUDE.md: o smoke é do dono).
