# Chat I.A consulta os prazos e metas da própria pessoa (etapa 1, só leitura)

Risco **MÉDIO**: a IA passa a LER dado de metas fora de uma pasta citada. Decisão do Samuel (07/11/2026), já aprovada:
só leitura; só o que a pessoa já vê na tela Minhas Metas dela, com as mesmas permissões e filtros; perguntas-alvo "tem prazo
hoje?", "o que vence esta semana?", "o que está atrasado?". Agenda e pastas ficam para a etapa 2. A disponibilidade da IA não
muda (vale onde ela já está liberada).

## Regra de ouro

**A ferramenta só LÊ.** Ela não cria, não altera, não conclui nem reabre meta, e não grava nada no banco (nem log de conteúdo).
O modelo não escolhe pessoa nem escritório: a consulta é sempre do usuário e do escritório da SESSÃO.

## Ferramenta

`consultar_prazos_e_metas` (tool use, mesmo trilho de `propor_cliente`/`propor_pasta`: oferecida no `PedidoDeLinguagem::ferramentas`
conforme a permissão, lida em `UsoDaTransmissao::chamadasDeFerramenta`).

Entrada (JSON Schema, `additionalProperties: false`):

| campo | obrigatório | valores |
|---|---|---|
| `periodo` | sim | `hoje`, `proximos_7_dias`, `atrasadas` |
| `papel` | não (padrão `responsavel`) | `responsavel`, `criei`, `acompanhando`, `todas` (as abas da tela) |

Chave a mais, valor fora da lista ou tipo errado = chamada inválida (vira a linha fixa "consulta não realizada", ver abaixo).

Saída (o que o modelo recebe, na segunda chamada): um bloco por consulta, com o cabeçalho escrito pelo código e os valores do banco
dentro de `<prazos_e_metas>`:

```
PRAZOS E METAS DA PESSOA (consulta feita agora, na tela Minhas Metas dela; hoje é 07/11/2026; período: vencem hoje; papel: sou responsável):
<prazos_e_metas>
• No período: 3 meta(s); mostrando 3.
• 1 meta(s) do período ficaram de fora por serem de processo em segredo de justiça (a pessoa as vê na tela Minhas Metas).
• Meta nº 0012 · Protocolar contestação · pasta 2025/0034 · cliente MARIA DA SILVA · vence hoje (07/11/2026) · prioridade alta · delegada por Ana
</prazos_e_metas>
```

## De onde vem o dado (a mesma consulta da tela)

`ConsultarPrazosEMetasUseCase` (domínio `ChatIa`) lê pela porta `ListaDasMinhasMetas`, cuja implementação chama
**`TarefaRepository::findParaMinhasMetas` com os mesmos parâmetros de `TarefaController::minhas`**: o usuário da sessão, a aba
(`AbaMetas`, o `papel`), o filtro `prazo` da barra da tela, `AcessoAPasta::idsOcultosPara` (cadeado) e
`PastasSoAcompanhadasSemVer::ids` (só acompanhar não basta, auditoria 3.12). Nenhuma regra de visibilidade nova:

| período | filtro da tela | recorte a mais |
|---|---|---|
| `atrasadas` | `prazo=vencidas` (prazo antes de hoje; concluída e em revisão fora) | nenhum |
| `proximos_7_dias` | `prazo=proximas` (de hoje até hoje + 7, o grupo "Vencem em 7 dias") | nenhum |
| `hoje` | `prazo=proximas` | só o prazo de hoje |

"Esta semana" vira `proximos_7_dias`, a janela que a tela já usa; cada item leva a data, e o modelo pode recortar dentro dela.

Depois da consulta da tela, o UseCase só TIRA (nunca acrescenta):
1. **outro escritório**: meta cujo `tenant` não é o da sessão sai (cinto: no HTTP o `TenantFilter` já filtra; a guarda explícita
   vale também sem ele, regra 2 da Inteligencia);
2. **segredo de justiça** (regra 4): meta de pasta com QUALQUER processo vinculado em segredo (`Processo::emSegredoDeJustica`,
   lido em lote por `ProcessoRepository::idsDasPastasEmSegredoDeJustica`) sai; o bloco diz só QUANTAS saíram, nunca título nem pasta;
3. **teto**: ordena por prazo (mais antigo primeiro, como a tela) e mostra até 20; o total do período vai no bloco.

## Quem pode (guardas)

A ferramenta só é oferecida (e a consulta só roda) se, além das guardas do chat (login, vínculo, módulo `inteligencia`,
disponibilidade, teto diário), valer:
1. módulo `tarefas` (`AcessoAoModuloTarefas::pode`), a mesma guarda da tela Minhas Metas;
2. aceite do escritório para envio de dados (`ConfiguracaoDeInteligencia::aceiteDeEnvioDado`), o mesmo do contexto de metas.

Os dois lados, como no cadastro: sem permissão a ferramenta não vai no pedido; e a execução reconfere (a permissão pode mudar no
meio). Recusada = linha fixa "consulta não realizada: a pessoa não tem acesso às metas por aqui", sem nenhum dado.

## Fluxo da resposta (duas chamadas, sem `tool_result`)

1. Primeira chamada com as ferramentas. Se o modelo pedir `consultar_prazos_e_metas` (até **3 consultas distintas** por resposta;
   repetida conta uma vez; o resto é ignorado), o servidor roda o UseCase para cada uma.
2. **Segunda chamada**: o mesmo pedido, com os blocos no começo da última mensagem da pessoa, um parágrafo fixo no sistema ("a
   consulta já foi feita...") e **sem ferramentas** (não há laço possível). Sem `tool_result`: o provedor e o `PedidoDeLinguagem`
   não mudam. O texto das duas chamadas sai no mesmo fluxo SSE (separado por linha em branco) e é gravado como UMA resposta.
3. Os tokens das duas chamadas são somados na resposta gravada (entram no teto diário). Se a pessoa parar na segunda, a primeira
   entra com os tokens reais e a segunda estimada, como hoje.
4. Chamada de cadastro na mesma resposta segue virando proposta (só as de `propor_*`; a consulta não gera o aviso "não consegui
   montar o cadastro").

## Prompt (regra 5)

- `chat-livre-v5`: `<prazos_e_metas>` entra na lista de DADOS NÃO CONFIÁVEIS e em `NeutralizadorDeConteudo::DELIMITADORES`.
- Todo valor do banco (título, número da pasta, nome do cliente, nomes da equipe, prioridade) passa por
  `ProtecaoDoTextoDoChat::rotulo` (neutraliza a tag e mascara conforme o escritório). Do cliente sai só o nome (regra 7).
- Parágrafo CONSULTA só quando a ferramenta é oferecida: ela só lê; usar quando a pessoa perguntar de prazos e metas DELA; nunca
  dizer que concluiu ou alterou; não traz agenda nem pastas. O parágrafo CADASTRO passa a entrar só quando uma ferramenta de
  cadastro é oferecida.

## Log e privacidade (regra 3)

Log `info` por consulta: `tenantId`, `conversaId`, `periodo`, `papel`, `total`, `mostradas`, `omitidasPorSegredo`, `recusada`.
Nunca título, pasta, cliente ou nome. O bloco não é persistido; a resposta do modelo é gravada como qualquer resposta do chat
(retenção do chat).

## Fora de escopo

Agenda, pastas, criar/alterar/concluir meta, prazo jurídico (a lista da tela não o mostra), meta de colega por nome, aguardando
revisão e sem prazo (podem vir numa etapa seguinte).

## Testes

Unit: UseCase (período e filtro da tela, papel, segredo omitido e contado, outro escritório, teto de 20, sem módulo, sem aceite,
nada gravado), interpretação da entrada, prompt (parágrafos, versão, bloco antes da pergunta, sem ferramentas na segunda),
transmissão (segunda chamada, texto unido, tokens somados, consulta + cadastro).
Functional (HTTP + ProvedorFalso): só as metas da pessoa; meta de colega não vem; pasta com cadeado não vem; segredo de justiça
(do tribunal e manual) não vem; outro escritório não vem; nada gravado (metas, auditoria e propostas iguais); sem módulo `tarefas`
ou sem aceite a ferramenta não é oferecida. Integração (sem `TenantFilter`): a guarda explícita de escritório.
