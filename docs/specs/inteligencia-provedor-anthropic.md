# BlueJus IA — provedor Anthropic (Claude): proposta técnica (D1)

Decisão do dono (14/10/2026): **o provedor é exclusivamente a Anthropic**, modelos Claude pela API oficial. Nada foi
contratado, nenhuma credencial foi inserida e a IA continua desligada em produção. Fatos de modelo, preço, limite e
privacidade lidos nas páginas oficiais em 14/10/2026 (URLs em §7); recheque antes de ativar — mudam com frequência.

## 1. O que está pronto (frente `designer-central-ia`)

- `App\Inteligencia\Service\ProvedorAnthropic` implementa a porta existente `ProvedorDeLinguagem` sobre o
  `symfony/http-client` já instalado (sem dependência nova). Alias da porta aponta para ele.
- **Dormente sem configuração:** sem `ANTHROPIC_API_KEY` **e** `IA_MODELO` válidos no ambiente, `estaConfigurado()`
  é falso, `nome()` responde `nao_configurado` (a coluna `provedor` da análise não diz que algo saiu) e nenhuma
  chamada sai. Configuração torta — chave com espaço/controle, esforço desconhecido — também deixa dormente, nunca
  lança (a extensão Twig da IA é montada em toda página). Ligar exige ainda `IA_HABILITADA=1`, o escritório habilitado e o consentimento dele
  (`DisponibilidadeDeInteligencia`, inalterada).
- Requisição: `POST /v1/messages` com `model`, `max_tokens`, `system`, `messages`; `anthropic-version: 2023-06-01`.
  **Não envia** `temperature` (modelos 4.7+ respondem 400), nem metadado com id de escritório/usuário/pasta.
  `output_config.effort` só quando `IA_ESFORCO` estiver definido. Prefill (último turno do assistente) é recusado
  antes da rede. `max_tokens` = teto do prompt + `IA_MARGEM_RACIOCINIO` (padrão 8.000): o raciocínio adaptativo conta
  no `max_tokens`; sem a folga, o resumo de 1.200 tokens seria cortado (falha definitiva com custo pago). A margem
  não custa nada se não for usada — paga-se o que é gerado. **Medir no piloto** quanto o raciocínio consome.
- **Segredo:** chave aparada; exceção de transporte **não encadeada** (a do cliente HTTP cita o cabeçalho, e o
  Messenger serializa o `previous` na fila); `max_redirects: 0` (o `x-api-key` não segue para outro host).
- Resposta: concatena só blocos `text` (ignora `thinking`); `refusal`, `max_tokens` e texto vazio são falha
  definitiva; usa `usage.input_tokens/output_tokens` e o `model` devolvido.
- Erros: rede/timeout/408/429/5xx/529 **transitórios** (o worker já re-tenta); 4xx definitivos, inclusive com corpo
  que não é JSON (página de proxy); 429 do teto mensal
  (`enforced_spend_limit_reached`) **definitivo**. A mensagem da exceção — gravada como motivo da análise — leva só
  status, tipo e `request-id`; nunca o corpo do erro nem o texto do pedido.
- Testes: `ProvedorAnthropicTest` (com `MockHttpClient`, zero chamada real); a suíte segue usando o
  `ProvedorFalso` (`when@test`).

**SDK PHP oficial (`anthropic-ai/sdk`) não foi adotado** — decisão D1-a abaixo. Ele está em 0.x ("APIs may change"),
puxa 7 dependências novas (PSR-18 discovery, OpenTelemetry, standard-webhooks) e recomenda Guzzle; a regra do projeto
é não acrescentar dependência sem aprovação. Para chamada única sem streaming, o HTTP direto cobre o necessário.

## 2. Modelos (14/10/2026) e recomendação

| Modelo (ID exato) | Entrada / saída por MTok | Uso proposto |
|---|---|---|
| `claude-haiku-5-5` | $0,10 / $0,50 (prompt ≤100k) | triagem barata, se um dia houver volume alto |
| **`claude-sonnet-5-5`** | **$2 / $10** | **padrão proposto**: resumo do Push e agentes da pasta |
| `claude-opus-5-5` | $4 / $20 | opção para análises jurídicas mais pesadas |
| `claude-fable-5-1` | $10 / $50 | **não usar** — "Covered Model": retenção obrigatória de 30 dias |

Todos com contexto de 1M e saída de até 128K. Nos 5.5: `temperature`/`top_p`/`top_k` e prefill dão 400;
`thinking` é adaptativo; `tool_choice` `any`/`tool` dá 400 no Opus/Sonnet 5.5 (o Agente usa `auto`).

**Custo estimado por chamada** (contexto típico de ~10k tokens de entrada; saída até o teto do prompt: 1.200 no
Push, 2.500 nos agentes):

| | Haiku 5.5 | Sonnet 5.5 | Opus 5.5 |
|---|---|---|---|
| típica (10k entrada, 1,5k saída) | ~US$ 0,002 | ~US$ 0,035 | ~US$ 0,07 |
| pior caso (30k entrada, 2,5k saída) | ~US$ 0,004 | ~US$ 0,085 | ~US$ 0,17 |
| teto atual por escritório (500/mês) | ~US$ 1–2 | **~US$ 17–43** | ~US$ 35–85 |

Estimativa, não medição: o tokenizador dos modelos 4.7+ gera ~30% mais tokens para o mesmo texto, o raciocínio
adaptativo é saída cobrada, e o tamanho real do contexto só se mede com uso. **Multiplicador de retentativa:** um
timeout depois de a API já ter gerado a resposta é repetido (até 4 tentativas no worker) e cobrado de novo; o 429 por
limite de taxa também é repetido, sem respeitar o `retry-after`. Batch custaria 50% menos, mas **fica fora do ZDR**
(retenção de 29 dias) — não usar com dado jurídico enquanto a D1-c valer.

**Configuração proposta:** `IA_MODELO=claude-sonnet-5-5`, `IA_ESFORCO=medium` (o padrão do Sonnet é `high`; para
resumo estruturado `medium` corta custo), cotas por escritório mantidas em 50/dia e 500/mês, e **teto de gasto
mensal na Console da Anthropic** como segunda trava (o adaptador já trata o 429 do teto como definitivo).

## 3. Privacidade e LGPD — o que os documentos dizem e o que falta confirmar

- **Treino:** os Commercial Terms dizem que a Anthropic não treina com o conteúdo do cliente da API.
- **Retenção:** as fontes oficiais **divergem** — o artigo de privacidade (01/07/2026) fala em exclusão em até 30 dias;
  a documentação da API diz que o conteúdo não é retido por padrão, salvo nos "Covered Models" (30 dias). Conteúdo
  sinalizado por violação de política pode ficar até 2 anos.
- **ZDR (Zero Data Retention):** existe, por organização, **pedido ao time comercial** — não é self-service. Fora do
  ZDR: Batch (29 dias), Files API e execução de código. Prompt caching e saída estruturada são compatíveis.
- **DPA** com Cláusulas Contratuais Padrão (UE/UK/Suíça) vem incorporado aos Commercial Terms; **não menciona a
  LGPD**. Processamento nos EUA ou global (`inference_geo`); **não há região Brasil**.
- O BlueJus já minimiza o que sai: `nivelSigilo > 0` nunca sai, do cliente vai só o nome (CPF/CNPJ/contato nunca),
  documentos não são lidos, conteúdo externo vai delimitado e neutralizado, o prompt não é persistido nem logado.

## 4. Coordenação com a Central e o Agente de Pastas

- **Chat I.A na Central:** reaproveita a fila — a mensagem para a conversa da IA vira uma análise na fila (padrão
  Messenger existente), o worker chama o provedor e grava a resposta como mensagem da conversa; a tela já recebe
  pelo polling da A1 (D2). Exige: autor "BlueJus I.A" na `central_mensagem` (hoje o autor é sempre um usuário),
  um `FluxoDeAnalise` de conversa com contexto delimitado e a cota da `DisponibilidadeDeInteligencia`.
- **Agente de Pastas:** o planejador vira tool use com as ferramentas de **leitura** da Fase 1 (`buscar_pastas`,
  `contar_pastas`, `listar_documentos`, `opcoes`) executadas no servidor com a permissão de quem pede.
  `tool_choice: auto` (os 5.5 recusam `any`/`tool`). Alterações em lote seguem **bloqueadas** (D3 do dono).
- **Comandos `/` classe (b):** cada um vira um fluxo com recorte de dado próprio, depois do Chat I.A.

## 5. Ativação (quando o dono aprovar) — checklist

1. Conta comercial na Anthropic; pedir **ZDR** e confirmação **por escrito** da retenção e do DPA para dado
   jurídico brasileiro.
2. Teto de gasto mensal na Console; chave de API **só** no `.env.prod` da VPS (nunca no repositório — a guarda de
   segredos da S1 cobra), numa linha sem espaço nem `\r` (arquivo com fim de linha LF); workspace com
   `allowed_inference_geos` definido. Conferir com `bin/console app:inteligencia:status`, que mostra `anthropic`
   só quando chave e modelo são válidos (sem imprimir a chave).
3. Atualizar o Anexo I da Política de Privacidade (operador, finalidade, transferência internacional).
4. `IA_MODELO`, `IA_ESFORCO`, `ANTHROPIC_API_KEY`, `IA_HABILITADA=1` no `.env.prod` → rebuild (imagem baked).
5. Habilitar **um** escritório piloto com consentimento registrado; medir tokens e custo reais nas primeiras análises.

## 6. Decisões restantes do dono

- **D1-a — SDK oficial ou HTTP direto.** Recomendação: manter o HTTP direto agora; reavaliar quando o SDK sair da
  0.x ou quando streaming/tool runner forem necessários.
- **D1-b — modelo e esforço padrão.** Proposta: `claude-sonnet-5-5` + `medium`.
- **D1-c — ZDR e confirmação de retenção por escrito** antes de qualquer dado real.
- **D1-d — base legal da transferência internacional (LGPD art. 33)** e texto do Anexo I — com o jurídico.
- **D1-e — `inference_geo`:** `global` (preço padrão) ou `us` (+10%, dado fixado nos EUA).
- **D1-f — escritório piloto** e orçamento mensal da conta.

## 7. Fontes (lidas em 14/10/2026)

Modelos: platform.claude.com/docs/en/about-claude/models/overview.md · Preços: …/about-claude/pricing.md ·
Limites: …/api/rate-limits.md · Erros: …/api/errors.md · Tool use: …/agents-and-tools/tool-use/overview.md ·
Saída estruturada: …/build-with-claude/structured-outputs.md · Retenção da API: …/manage-claude/api-and-data-retention.md ·
Residência: …/manage-claude/data-residency.md · Termos: anthropic.com/legal/commercial-terms ·
DPA: anthropic.com/legal/data-processing-addendum · Privacidade: privacy.claude.com (artigos 7996866 e 7996862) ·
SDK PHP: github.com/anthropics/anthropic-sdk-php.
