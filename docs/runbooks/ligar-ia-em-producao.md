# Runbook: ligar a BlueJus IA em produção

Para quem não acompanhou a implementação: a BlueJus IA (resumo do Push e os sete agentes da pasta)
está **inteira no código e desligada de propósito**. Sem provedor, toda análise termina como
`indisponivel` e a tela diz "IA não configurada nesta instalação" — nenhuma resposta é inventada.
Este runbook diz o que precisa existir antes de ligar, como ligar, como conferir e como desligar.

Domínio e regras: `app/src/Inteligencia/CLAUDE.md`. Specs: `docs/specs/inteligencia-resumo-do-push.md`
e `docs/specs/inteligencia-agentes-da-pasta.md`. Decisões: `docs/specs/trilha-b-inventario/inteligencia.md` §5
e `docs/specs/trilha-b-designer.md` §4 (D-IA, D-IA2).

Este arquivo **não contém segredo nenhum**. A chave do provedor só existe no `.env.prod` da VPS.

---

## 0. Pré-requisitos de DECISÃO (sem eles, não ligue)

Cada item abaixo é do dono. Enquanto qualquer um estiver aberto, `IA_HABILITADA` fica vazio.

| Decisão | O que precisa estar decidido | Onde aparece |
|---|---|---|
| **D-IA / D1** (provedor) | Qual provedor e qual modelo; contrato com vedação de retenção e de uso para treinamento (zero-retention). | Escolha do adaptador (Passo 1) e as variáveis do Passo 2 |
| **D-IA / D2** (custo) | Cota diária/mensal por escritório (padrão da tela: 50/dia, 500/mês) e quem paga. | `/admin/inteligencia` de cada escritório |
| **D-IA / D3** (LGPD) | O provedor entra como **suboperador** no **Anexo I** da Política de Privacidade; **comunicação prévia aos contratantes**; base da transferência internacional (Cap. V da LGPD). | `templates/legal/_politica_privacidade_texto.html.twig` (Anexo I) e o texto de aceite em `/admin/inteligencia` (ver IA-001 e IA-030 do inventário) |
| **D-IA / D4** (o que sai) | Confirmar o que os agentes enviam (nomes de clientes, metas, anotações, observações, metadados de documentos, checklist e, quando permitido, financeiro). `nivelSigilo > 0` nunca sai. | `SecaoDoContexto`, `MontadorDeContextoDaPasta` |
| **D-IA2** (quem usa) | A quais papéis conceder `modules.inteligencia.view` e `admin.inteligencia.manage`. Hoje **nenhum papel recebe por padrão**: só o Administrador do Escritório (bypass) e o super admin usam. | Papéis de cada escritório |

## 0.1 Console da Anthropic (tarefa técnica, nunca na tela do Master)

Na organização da Console onde está a chave de produção:

1. Workspace de produção com `allowed_inference_geos: ["us"]` e `default_inference_geo: "us"` (o adaptador já envia
   `inference_geo: "us"` por `IA_INFERENCE_GEO`, padrão `us`).
2. Teto de gasto mensal da organização (o 429 `enforced_spend_limit_reached` já é tratado como definitivo).
3. A ZDR é pedida pelo BlueJus ao comercial (`https://claude.com/contact-sales`) e vale por organização: a chave tem
   de ser de uma organização com a ZDR aceita.

Região de processamento: EUA (`us`). A tela do Master não mostra a ZDR nem a região (decisão do dono de 24/10/2026):
o pedido da ZDR, a região e estas instruções ficam só aqui.

## 1. O adaptador já existe (corrigido em 24/10/2026)

O adaptador da Anthropic está pronto: `App\Inteligencia\Service\ProvedorAnthropic` é o alias da porta
`ProvedorDeLinguagem` em `app/config/services.yaml`. Ele fica **dormente** enquanto faltar `ANTHROPIC_API_KEY`
ou `IA_MODELO` válidos: a tela continua dizendo "IA não configurada nesta instalação" (é o comportamento
correto). Proposta de modelo, custo e privacidade: `docs/specs/inteligencia-provedor-anthropic.md` e
`docs/specs/ia-anthropic-ligar.md` (decisões do dono de 24/10 no §10).

Falta só, fora do código: o Anexo I da Política de Privacidade (D3) e a retenção zero pedida à Anthropic
(`docs/specs/ia-anthropic-ligar.md` §10.2).

## 2. Variáveis de ambiente (`.env.prod` da VPS)

| Variável | Situação hoje | Para que serve |
|---|---|---|
| `IA_HABILITADA` | **Existe** (`services.yaml`, parâmetro `ia_habilitada`, default `false`) | Flag da plataforma, nível 1 da disponibilidade. `1` liga; vazio ou ausente desliga. |
| `ANTHROPIC_API_KEY` | **Existe** (`services.yaml`, parâmetro `ia_anthropic_chave`) | Chave da API da Anthropic. Segredo: só no `.env.prod`, gravada pelo dono, nunca no repositório, nunca em log. |
| `IA_MODELO` | **Existe** (`ia_anthropic_modelo`) | ID exato do modelo (padrão decidido em 24/10: `claude-haiku-5-5`). |
| `IA_ESFORCO` | **Existe** (`ia_anthropic_esforco`), opcional | `low`, `medium` ou `high`. Sem ele, vale o padrão do modelo. |

Não existe `IA_BASE_URL`: a URL da API é fixa no adaptador. O nome antigo `IA_API_KEY` **não é lido**; quem
gravar só ele deixa a IA dormente.

Na VPS (`/opt/jusprime`), sem editor e com guarda: cada comando troca a linha se ela existir e
acrescenta se não existir. A chave é lida sem eco e não fica no histórico do shell.

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && cp -p .env.prod ".env.prod.antes-da-ia.$(date +%Y%m%d%H%M%S)"

read -rs -p 'Chave da Anthropic (não aparece na tela): ' CHAVE_IA; echo
test -n "$CHAVE_IA" && sed -i '/^ANTHROPIC_API_KEY=/d;/^IA_API_KEY=/d' .env.prod && printf 'ANTHROPIC_API_KEY=%s\n' "$CHAVE_IA" >> .env.prod; unset CHAVE_IA

read -r -p 'Modelo (ex.: claude-haiku-5-5): ' MODELO_IA
test -n "$MODELO_IA" && sed -i '/^IA_MODELO=/d' .env.prod && printf 'IA_MODELO=%s\n' "$MODELO_IA" >> .env.prod; unset MODELO_IA


sed -i '/^IA_HABILITADA=/d' .env.prod && echo 'IA_HABILITADA=1' >> .env.prod

# Conferência SEM imprimir valor: só os nomes das variáveis de IA presentes.
grep -o -E '^(IA_[A-Z_]*|ANTHROPIC_API_KEY)=' .env.prod
```

Não use `debug:container --env-vars` para conferir: ele imprime o segredo em claro.

## 3. Rebuild e subida (prod é imagem baked)

Código novo (adaptador, alias, Anexo I) só entra por **rebuild**, e o `env_file` só é relido quando o
container é recriado. O script faz os dois, para o `php` e para o `worker` (mesma imagem):

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && ./scripts/deploy-prod-tls.sh
```

Não troque por `docker compose restart`: restart não relê o `.env.prod` nem pega imagem nova.

## 4. O worker

A análise roda no worker (`jusprime_worker_prod`, `messenger:consume async`). Sem worker a análise
fica `pendente` para sempre e a tela avisa "demorando mais que o normal" depois de 10 minutos.

```bash
# Execute manualmente no terminal externo (na VPS)
docker ps --filter name=jusprime_worker_prod --format '{{.Names}} {{.Status}}'
docker logs jusprime_worker_prod --since 15m 2>&1 | tail -n 50
```

O canal de log `inteligencia` registra ids, escritório, provedor, tokens, duração e classe do erro;
**nunca o texto enviado**. Em prod ele sai no stderr do container (JSON), só a partir de `error`
(handler `fingers_crossed`).

## 5. Conferir o estado: `app:inteligencia:status`

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec -w /var/www/app jusprime_php_prod php bin/console app:inteligencia:status
```

O esperado depois do Passo 3:

- **Provedor**: o nome do adaptador, `(configurado)`. Se vier `nao_configurado (não configurado)`,
  o alias não foi trocado ou a chave não chegou: volte aos Passos 1 e 2.
- **Flag da plataforma (IA_HABILITADA)**: `ligada`.
- **Escritórios com a IA ligada**: `0` até alguém ligar em `/admin/inteligencia` (é o nível 2, por escritório).
- A tabela por escritório mostra pendentes, processando, concluídas, falhas e indisponíveis. Pendente que
  não anda = worker parado (Passo 4).

## 6. Smoke (num escritório de teste, não num cliente)

1. Num escritório de teste, como administrador, abrir `/admin/inteligencia`, ler o aviso de envio,
   ligar a IA e conferir as cotas.
2. Abrir uma pasta **sem sigilo** desse escritório, com publicação no Push.
3. Aba Push → "Resumir com IA". Esperado: cartão "Analisando…", depois o cartão "✦ Análise IA ·
   Gerado por IA · não é movimentação oficial" com resumo e pontos.
4. Cabeçalho da pasta → "BlueJus Intelligence" → um agente → "Gerar análise". Esperado: o cartão do
   agente concluído, com Copiar · Baixar .txt · Imprimir, e o "Registro de auditoria (N)" contando a ação.
5. Pasta com `nivelSigilo > 0`: o pedido tem de ser **recusado** (nada sai).
6. Rodar `app:inteligencia:status` de novo: as análises do smoke aparecem como concluídas.
7. Escritório que **não** ligou a IA: o botão diz "desligada neste escritório" e nada é enviado.

Se o smoke falhar, siga direto para o rollback.

## 7. Rollback

Desligar é sempre seguro: nenhuma tabela muda, as análises já gravadas ficam (e continuam visíveis), e
pedidos novos são recusados com a mensagem honesta ("IA não configurada nesta instalação").

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && sed -i '/^IA_HABILITADA=/d' .env.prod && ./scripts/deploy-prod-tls.sh
docker exec -w /var/www/app jusprime_php_prod php bin/console app:inteligencia:status
```

Esperado: `Flag da plataforma (IA_HABILITADA): desligada`. Para cortar também o provedor (chave
vazada, contrato encerrado), apague a chave e revogue-a no painel do provedor:

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && sed -i '/^ANTHROPIC_API_KEY=/d' .env.prod && ./scripts/deploy-prod-tls.sh
```

Para desligar só **um** escritório, sem deploy: `/admin/inteligencia` daquele escritório → desligar.

**A fila também é cortada.** O `ProcessarAnaliseDeInteligenciaHandler` reconfere a disponibilidade
**antes de chamar o provedor** (IA-034L): plataforma (`IA_HABILITADA` + provedor), escritório e a
permissão de módulo de quem pediu — a mesma pergunta da solicitação, sem a cota (a análise já foi
contada quando entrou na fila). Uma análise que estava na fila quando a IA foi desligada termina sem
sair para o provedor:

- plataforma desligada → `indisponivel`, motivo `cancelada antes do provedor: IA não configurada nesta instalação`;
- escritório desligado ou permissão retirada → `falhou`, motivo `cancelada antes do provedor: <motivo>`.

O corte vale a partir do momento em que o worker roda com a flag nova — ou seja, **depois** do deploy
que o recria com o `.env.prod` novo. Entre o `sed` e o fim do deploy o worker antigo ainda lê a flag
antiga. Para corte imediato (incidente, chave vazada), apague também a chave (comando acima) e pare o
worker antes do deploy; confira as pendentes no `app:inteligencia:status` depois:

```bash
# Execute manualmente no terminal externo (na VPS)
docker stop jusprime_worker_prod
```

O deploy do rollback recria o worker com a imagem e o `.env.prod` novos.
