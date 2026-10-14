# Central de Comunicação — proposta de arquitetura (PROPOSTA, aguarda D2/D6 e aprovação)

Origem: `bluejus-central.js` (6.845 linhas) do pacote do Designer de 13/10. No protótipo, tudo mora num
JSON em `localStorage` com cópia no IndexedDB; o "multi-tenant" é um prefixo de chave
(`bj-contas.js` sobrescreve `Storage.prototype`); o tempo real é o evento `storage` entre abas do mesmo
navegador. **Nada disso vai para o BlueJus** — esta proposta traduz o comportamento para Symfony.

Risco: **MÉDIO** (conteúdo entre pessoas, multi-tenant, notificação). Spec definitiva em `docs/specs/` antes
de qualquer código, revisada pelo `feature-review-agent`.

## 1. Escopo da Fase A (o que é núcleo de comunicação, sem IA)

| Entra | Fica para depois |
|---|---|
| conversa 1:1 e grupo do escritório; histórico paginado | Chat I.A e `@ia` (D1) |
| mensagem: enviar, responder, editar (janela igual à do Registro, 15 min), excluir (lápide) | ligações / BlueJus Connect (D-CONNECT) |
| anexos pelo storage E2 | presença e "digitando" em tempo real (D2) |
| não lidas por conversa; marcar lida/não lida; fixar, silenciar, arquivar | reações, favoritos, encaminhar |
| `@menção` dentro da conversa → `Notificacao` existente | grupo automático "Equipe Pasta N" |
| menus de botão direito de **conversa** e **mensagem** só com as ações acima | Central de Atenção, controle de baixas |

## 2. Modelo (todas `TenantAware` + `Auditavel`, PK integer)

- `central_conversa` — `tenant_id`, `tipo` (`individual`\|`grupo`), `nome` e `foto` (grupo), `criada_por`,
  `criada_em`, `pasta_id` opcional (contexto). Conversa individual tem **chave única** `(tenant, menor_id, maior_id)`
  para nunca duplicar o par.
- `central_participante` — `conversa_id`, `user_id`, `entrou_em`, `saiu_em`, `ultima_lida_mensagem_id`,
  `fixada`, `silenciada`, `arquivada`. É aqui que mora o "não lido": o maior id da conversa menos o lido.
- `central_mensagem` — `conversa_id`, `autor_id`, `conteudo` (texto puro; renderização escapada),
  `resposta_a_id`, `editada_em`, `conteudo_anterior`, `excluida_em` (lápide), `criada_em`.
- `central_anexo` — `mensagem_id`, chave no `ArmazenamentoDeArquivos` (categoria nova, isolada por tenant:
  `t/{tenant}/central/{chave}`), nome, tipo, tamanho, `sha256`. Limites do desenho: 10 por mensagem, 25 MB,
  executável recusado — validados no servidor.

Regra de acesso: **só participante ativo lê ou escreve** na conversa; nenhuma rota por id sem
`conversa.tenant = sessão` **e** participante. Admin do escritório **não** lê conversa alheia (o desenho
diz o mesmo do Master: "não lê conteúdo de outras contas").

## 3. Tempo real — recomendação para a D2

**Polling com cursor**, o padrão que o BlueJus já usa (notificações a cada 30 s):

`GET /central/novidades?desde=<ultimo_id>` → mensagens novas das conversas do usuário + contadores de não
lidas, numa consulta indexada por `(conversa_id, id)`. Intervalo: 5 s com a janela aberta, 30 s fechada,
pausado com a aba oculta (`visibilitychange`).

- Por que não WebSocket/Mercure agora: é infraestrutura nova (processo, proxy no nginx compartilhado,
  autenticação do canal, deploy), sem medida de que o polling não basta para um escritório de ~20 pessoas.
- Quando reavaliar: se, medido em produção, o endpoint passar a ocupar parcela relevante dos workers do
  PHP-FPM ou a latência percebida de entrega incomodar quem usa. ⚠️ SSE servido pelo próprio PHP-FPM
  (`StreamedResponse`) **não** é a saída barata: cada aba aberta prende um worker do FPM enquanto a conexão
  dura. O passo seguinte real é um hub dedicado (Mercure) — processo novo, rota no nginx compartilhado e
  token de assinatura —, e por isso é decisão de infraestrutura, não de código. Ligações exigem ainda
  sinalização + TURN (D-CONNECT).

## 4. Interface (D6)

O botão flutuante e a janela da Central aparecem em **todas** as telas → exige `base.html.twig` (arquivo
compartilhado que nenhuma frente toca sem o dono). Proposta: um único `{% include
'central/_janela.html.twig' %}` atrás de `{% if central_disponivel() %}`, com `central.css` e `central.js`
(JS nativo, eventos `data-*`, sem `localStorage` para dado de negócio — só posição da janela e som).
Quem faz: esta frente, **depois** de a `fidelidade-designer` integrar, para não disputarem o layout.

## 5. Permissões (D3)

Módulo novo `central` (`modules.central.view`), concedido pelo papel do escritório como os demais. Criar
grupo, renomear e trocar foto: o criador do grupo. Nenhum papel "Master" é criado aqui.

## 6. Decisões necessárias antes da spec definitiva

1. D2 — polling agora (recomendado) ou infraestrutura de tempo real já.
2. D6 — autorização para o include em `base.html.twig` e a ordem com a `fidelidade-designer`.
3. Retenção: mensagens e anexos ficam para sempre? Entram na exportação/LGPD do titular?
4. Conversa com pessoa que saiu do escritório: fica legível só para quem ficou?
5. Anexo da conversa pode ser "salvo na pasta" (o desenho tem `origem: pasta` e `salvo`)? Se sim, com qual
   permissão da pasta.
