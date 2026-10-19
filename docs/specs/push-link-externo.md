# Link externo do Push Processual (PR 1 de 3 da frente "Expediente: Processo e Push")

Desenho: projeto Claude Design `02 - EXPEDIENTES - VS 18.10.26` (igual ao `02 - EXPEDIENTES 1.2.3.dc.html` nestes
trechos) e `Push Compartilhado.dc.html`, ambos em `docs/design/claude-design-2026-10-05 (1)/`.
Linhas citadas como [EXP] (02 1.2.3), [PC] (Push Compartilhado), [CEN] (bluejus-central.js), [LNK] (bj-link.js).

Risco: tela pública sem login que mostra dado de cliente. Pela tabela do CLAUDE.md é BAIXO; tratamos com rigor de
ALTO na parte pública (re-revisão de segurança antes do PR).

## Decisões do dono (19/10/2026)

- D1. Ordem: este PR é só o link externo. Ocultar aba = PR 2; relatório geral + Controladoria = PR 3.
- D2. Renovação: o pedido do terceiro vira **aviso no sino/Caixa da Central** para quem criou o link (o chat da IA com
  botões não existe). Renovar/Não renovar ficam na lista "Links desta pasta" do modal.
- D3. O servidor resolve o link curto na hora: a caixa mostra **um link só**, o curto, já ativo. Saem o selo
  "ATIVA COM O DOMÍNIO", o "Copiar link completo" e a linha "Link completo: N mil caracteres".

## 1. Modelo

`push_link_externo` (entidade `App\Pasta\Entity\LinkExternoDoPush`, `TenantAware`, NÃO `Auditavel`: guarda o código):

| campo | tipo | regra |
|---|---|---|
| id | int PK | |
| tenant | FK tenant, not null | |
| pasta | FK pasta, not null, ON DELETE CASCADE | |
| processo | FK processo, not null | o principal da pasta no momento da criação |
| endereco | varchar(80) UNIQUE | `bluejus-<slug cliente>-<8 aleatórios>` ([LNK] 17-19). Slug: sem acento, minúsculas, até 4 palavras e 40 chars. Sem nome do cliente: `processo-<7 primeiros dígitos>`. Os 8 caracteres vêm de `random_bytes` no alfabeto `23456789abcdefghjkmnpqrstuvwxyz` (minúsculo, sem 0/1/i/l/o) |
| codigo | char(6) null | 6 dígitos (`random_int(100000, 999999)`), só quando "Exigir código". Guardado em claro: o escritório precisa copiá-lo de novo na lista (desenho C.6). Nunca vai para log nem audit |
| mostrar_cliente | bool | |
| incluir_analises | bool | |
| criado_por | FK user | |
| criado_em | datetime_immutable | |
| expira_em | datetime_immutable | criado_em + 30 dias |
| revogado_em / revogado_por | null | revogar é definitivo (não há desfazer no desenho) |
| renovacao_pedida_em | null | |
| renovacao_nome | varchar(60) null | |
| renovacao_mensagem | varchar(280) null | |
| renovacao_resposta | varchar(10) null | `renovado` / `recusado` |
| tentativas_codigo / bloqueado_ate | int / null | 5 erros seguidos bloqueiam o link por 15 min |

`push_link_externo_acesso` (`AcessoAoLinkExternoDoPush`): id, link (FK, cascade), tenant, acessado_em. Sem IP nem
user-agent (LGPD: o desenho só mostra contagem por dia e horários).

Estado derivado (método da entidade, recebe `$agora`): `revogado` > `expirado` (expira_em <= agora) > `ativo`.
"Renovação pedida" = expirado com `renovacao_pedida_em` e sem resposta.

## 2. Casos de uso (`App\Pasta\UseCase`)

1. `GerarLinkExternoDoPushUseCase(pasta, usuario, exigirCodigo, mostrarCliente, incluirAnalises)`.
   Bloqueios (mensagens exatas do desenho, [EXP] 4379-4387, como exceção de domínio):
   - sem processo vinculado: "A pasta não tem processo vinculado."
   - pasta cancelada: "A pasta está cancelada: o link externo fica indisponível."
   - pasta arquivada: "A pasta está arquivada: reative para compartilhar o Push."
   - sem movimentações: "Ainda não há movimentações para compartilhar."
   - incluirAnalises sem nenhuma análise não interna: força `false`.
   - Pasta excluída (lápide): bloqueia como cancelada.
   Gera endereço único (repete em colisão), código, grava. Devolve o Output (inclui URL absoluta e código).
2. `RevogarLinkExternoDoPushUseCase(link, usuario)`: só se ativo ou expirado; idempotente se já revogado.
3. `RenovarLinkExternoDoPushUseCase(link, usuario)`: só expirado e não revogado. expira_em = agora + 30 dias; se havia
   pedido, resposta = `renovado`.
4. `RecusarRenovacaoDoLinkUseCase(link, usuario)`: só com pedido pendente; resposta = `recusado`.
5. `AbrirLinkExternoDoPushUseCase(endereco, ?codigo)` (público): resolve o estado e devolve um Output de página:
   `nao_encontrado` | `revogado` | `expirado` (com estado do pedido) | `pede_codigo` (com erro opcional) | `bloqueado`
   | `ok` (dados). Em `ok` grava 1 acesso. Código conferido com `hash_equals`; erro soma tentativa; acerto zera.
6. `PedirRenovacaoDoLinkUseCase(endereco, nome, mensagem)` (público): só expirado, não revogado e sem pedido
   pendente ou recusado. Grava o pedido e cria `Notificacao` para `criado_por` (tipo novo
   `push_link_renovacao`, mensagem "<Nome ou 'Quem recebeu o link'> pediu a renovação do link externo do Push da
   Pasta N." e link para a pasta com `#push`). O tipo entra em `TiposDeAvisoDaCentral` (grupo `atencao`,
   rótulo "Renovação", ícone `bi-arrow-clockwise`).

## 3. Rotas

Internas (`App\Pasta\Controller\PastaLinkExternoController`, prefixo `/pasta/{id}/push/links`), guarda igual ao
`PastaPushProcessualController` (tenant da pasta + `canAccessResource(...,'pasta', id, 'view')`; o desenho diz que
qualquer um que vê a aba pode gerar/copiar). Escrita com CSRF `pasta_push_link_{pastaId}`, JSON:

- `GET  ''` `pasta_push_links_listar`: links da pasta (mais recente primeiro) com estado, endereço, URL, código,
  criado por/em, validade, acessos (total, último, por dia até 7, últimos 5), pedido de renovação. Mais o resumo
  (`n links · n ativos · último dd/mm hh:mm`), total de acessos, e o "pode gerar" + motivo do bloqueio.
- `POST ''` `pasta_push_links_gerar` (exigir_codigo, mostrar_cliente, incluir_analises).
- `POST '/{linkId}/revogar'`, `'/{linkId}/renovar'`, `'/{linkId}/recusar'`. O link tem de ser DESTA pasta e deste
  tenant na consulta (`findOneDaPasta`), 404 caso contrário.

Públicas (`App\Pasta\Controller\PushCompartilhadoController`), `security.yaml`: `^/p/` em `PUBLIC_ACCESS`:

- `GET  /p/{endereco}` `push_compartilhado` (requirements `bluejus-[a-z0-9-]{3,70}`).
- `POST /p/{endereco}` `push_compartilhado_codigo` (campo `codigo`, CSRF `push_publico_{endereco}`).
- `POST /p/{endereco}/renovacao` `push_compartilhado_renovacao` (nome, mensagem, CSRF).
- Limitador por IP (`push_publico`, 30 req/min, sliding window) em todas; 429 com a mesma página.
- Cabeçalhos: `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`.
- Sem `TenantContext`: toda consulta passa o tenant do link explicitamente (o `TenantFilter` fica desligado aqui).

## 4. O que a página pública mostra (D.1-D.3, [PC])

Template próprio `templates/push_compartilhado/show.html.twig` (não estende `base.html.twig`: sem menu, sem sessão).
CSS/JS em `public/css/push-compartilhado.css` e `public/js/push-compartilhado.js` (proteções do D.3, vanilla).

- Cabeçalho: faixa gradiente, barra petróleo com logo branco, "CONSULTA DE ANDAMENTO PROCESSUAL" / "Push Processual
  · acesso externo", selo "SOMENTE LEITURA · PROTEGIDO". Barra do advogado = quem criou: nome, "OAB <número>/<UF>"
  (se houver), e-mail, e à direita o nome do escritório. Telefone: não há no cadastro, fica fora.
- Estados: textos exatos de [PC] (Link não encontrado / Link desativado / Link expirado + formulário de renovação
  e caixas de pedido enviado/recusado / Código de acesso / OK). "Abrindo o andamento…" não existe (render no servidor).
- OK: cartão PROCESSO (número com máscara, cliente se `mostrar_cliente`, ação da pasta, "Link válido até …"); cartões
  de Análise IA (só se `incluir_analises`, só análises concluídas e NÃO `internaDoEscritorio` da pasta, lidas ao vivo);
  "Movimentações recebidas" ao vivo: publicações DJEN dos processos da pasta (mesma consulta da aba, mesmo teto 100),
  com data, hora, tipo colorido, fonte (tribunal · órgão) e o **resumo** do teor (o mesmo título do cartão da aba,
  texto puro), nunca o teor inteiro nem o link do documento.
- Marca d'água "BlueJus · <processo> · <data/hora> · <8 primeiros do endereço aleatório>".
- Rodapé com o texto exato do desenho.

Não aparece nunca: documentos, financeiro, metas, registros, notas técnicas, nome de outros usuários além de quem criou.

## 5. Tela interna

- Aba Push: botão direito na aba "Push Processual" abre o menu "PUSH PROCESSUAL" ([EXP] 4388-4395) só com os itens
  de link neste PR: "Copiar link externo ativo" (se houver ativo), "Ver acessos ao link · N acesso(s)" (se houver
  link), "Gerar link externo do Push" / "Gerar novo link externo". Bloqueado: item único "Link externo indisponível"
  com cadeado, que mostra o motivo. O menu tem o X de fechar (print do dono).
- Menu ⋮ da pasta: item "Link externo do Push" na seção Compartilhar, com badge de ativos ([EXP] 1104).
- Modal `#modalLinkExternoPush` ([EXP] 506-552, textos e toggles iguais) com os estados C.3/C.5/C.6 menos o que D3
  tirou. "Gerar e copiar link" copia "URL\nCódigo de acesso: XXXXXX". "Ver como terceiro" abre a URL em nova aba
  (o acesso conta, como no desenho). "Criar outro" volta ao formulário mantendo os toggles.
- Lista "LINKS DESTA PASTA" com estados Ativo / Revogado / Expirado / Renovação pedida, copiar, Revogar (confirm
  "Revogar este link? Quem tiver o endereço perde o acesso na hora."), "Renovar 30 dias" (expirado), "Não renovar"
  (pedido pendente), popover de acessos em linha e painel "Acessos ao link do Push" (C.7).
- JS `public/js/pasta-push-link.js` (vanilla, IIFE, `textContent`, nunca `innerHTML` com dado), CSS em
  `public/css/pasta-push-link.css`. Confirmação via `window.confirmarPs`.

## 6. Fora deste PR

Ocultar aba (PR 2); relatório geral e Controladoria (PR 3); dicas (balões "DICA 3/3"); painel lateral do Push
(monitoramento, situação atual, avisar cliente, envio automático); filtro por processo; "Vincular apensado".

## 7. Testes mínimos

Unit: entidade (estados, renovar, revogar, tentativas/bloqueio), gerador de endereço (slug, sem nome, colisão),
cada UseCase (bloqueios com as mensagens exatas). Functional: guarda das rotas internas (outro tenant = 404, sem VIEW =
403, link de outra pasta = 404, CSRF), página pública em todos os estados, código errado/certo, bloqueio após 5 erros,
acesso contado só em `ok`, análise interna nunca aparece, teor inteiro nunca aparece, pedido de renovação gera
notificação, revogado/expirado não mostram dado, `/p/` sem login responde 200. Tela: menu e modal presentes na aba.
