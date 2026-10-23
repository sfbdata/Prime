# Segurança A: o token do convite só existe no e-mail

Risco ALTO (identidade User/Tenant). Origem: achados de auditoria interna F1, F3, F4, F5, F6, F7 e F14. Esta spec
descreve o defeito e a correção; não descreve como o defeito seria explorado.

## Estado da frente

- 23/10: spec escrita. PR 1 (raízes A e D, sem migration) implementado na branch `seguranca-a-convites`, com commit
  local e sem push. Testes direcionados no banco da frente; falta a revisão (`feature-review-agent`) e o smoke do
  dono nas duas telas de convites e em "Meus convites".
- Pendência anterior a esta frente, mantida igual: quem está logado SEM escritório na sessão e clica no convite da
  seleção de escritório volta para a própria seleção (o `TenantContextValidatorListener` não libera nem
  `auth_aceite_convite` nem as rotas novas `auth_meu_convite*`). Decisão do coordenador.
- PR 2 (raízes B e C, com a migration `Version20261020150500`): não começado.
- Raiz E: decisão pendente com o dono.

## Por que

O link do convite (`/convite/{token}`) é a prova de que a pessoa é dona da caixa de e-mail: no aceite sem conta
(`AceitarConviteEscritorioSemContaUseCase`, `ConviteController::aceitarSemConta`) a conta já nasce confirmada. Isso só
é seguro se o token nunca sair do e-mail. Hoje ele sai: aparece no HTML de quem gerencia convites e em outras telas.

## Raízes e entregas

| Raiz | Achados | O quê | Entrega |
|---|---|---|---|
| A | F4, F5, F6, F7 (F1 é consequência) | O token aparece para quem convida e nas telas do convidado logado | PR 1 |
| D | F3 | O e-mail do convite vai para um `onclick` e não é validado na criação | PR 1 |
| B | | O token fica no banco em texto puro | PR 2 |
| C | | O token entra na trilha de auditoria | PR 2 |
| E | F14 | O cadastro público diz se o e-mail já tem conta | Decisão do dono |

## Raiz A (PR 1)

### Antes

- `GerenciarConvitesController::index` lista todos os convites do escritório com o token (`ConviteAdminOutput`), e
  sem o filtro C5 por perfil. Revogar e reenviar usam `/{token}/...` na rota e `convite_revogar_<token>` /
  `convite_reenviar_<token>` no CSRF, então o token vai para o `action` e para o campo `_token` do formulário.
- O mesmo nos convites de plataforma (`AdminConviteController`, `admin/platform/convites.html.twig`).
- O convidado logado vê o token em "Meus convites" (`meus_convites.html.twig`) e na seleção de escritório
  (`tenant/selecionar.html.twig`): os dois levam ao link público `/convite/{token}`.

### Depois

- **A1.** Nenhuma tela, DTO ou JSON de quem gerencia convites leva o token. `ConviteAdminOutput` troca `token` por
  `id`. Revogar e reenviar viram `POST /escritorio/convites/{id}/revogar|reenviar` e
  `POST /admin/platform/convites/{id}/revogar|reenviar`, com CSRF `convite_revogar_<id>` e `convite_reenviar_<id>`.
  A posse continua conferida: convite de outro escritório responde 404 (busca por id e escritório da sessão);
  na plataforma, só convite de plataforma e só o Master (`ROLE_SUPER_ADMIN`, como hoje). `RevogarConviteInput` e
  `ReenviarConviteInput` passam a levar o id.
- **A2.** Filtro C5 na lista, na revogação e no reenvio do escritório: quem gerencia só vê e mexe no convite cujo
  perfil ele poderia atribuir (`HierarquiaDePerfis::podeMexerNoAlvo`, que usa a `podeAtribuirPerfil`: primário e
  Master veem tudo; o secundário não vê convite de primário nem de perfil com permissão que ele não tem; convite sem
  perfil passa). Fora do alcance responde 404, igual a convite inexistente.
- **A3.** Convidado logado: "Meus convites" e a seleção de escritório levam a `GET /convites/{id}`
  (`auth_meu_convite`), a mesma tela de hoje (`ver_logado.html.twig`), com aceitar e recusar em
  `POST /convites/{id}/aceitar|recusar` e CSRF `convite_aceitar_<id>` / `convite_recusar_<id>`. A tela e os dois
  POST só valem para convite de escritório do e-mail da conta logada e com o e-mail confirmado (a tela responde 404
  fora disso; os casos de uso recusam com flash). As três rotas entram nas liberadas do `TermoAceiteListener`, como as
  do link público. `AceitarConviteEscritorioComContaInput` e `RecusarConviteEscritorioInput`
  ganham `porId()`; pelo id, o caso de uso exige o e-mail confirmado, porque ali não há o link do e-mail como prova.
  O resto do aceite (vínculo, regra dos primários, vaga) não muda. `ConviteOutput` troca a leitura do token na
  entidade pelo token que veio na URL: só a tela do link público o recebe.
- **A4.** O link público `/convite/{token}` do e-mail (ver, criar conta, aceitar e recusar logado, plataforma) segue
  igual.

## Raiz D (PR 1)

### Antes

`gerenciar.html.twig` e `admin/platform/convites.html.twig` montam `confirm('… {{ convite.email|e('html_attr') }}?')`
dentro do `onclick`: o escape de atributo não serve para texto dentro de JavaScript. Na criação, os casos de uso só
fazem `strtolower(trim())` no e-mail.

### Depois

- **D1.** `CriarConviteEscritorioUseCase` e `CriarConvitePlataformaUseCase` validam o e-mail com a regra do
  `CriarContaEEscritorioUseCase::emailValido` (constraint Email estrita e até 180 caracteres), num ponto único:
  `App\Auth\Service\EmailDoConvite`. Inválido é recusado com "Informe um e-mail válido." (flash de erro, como as
  outras recusas de cada fluxo) e nada é gravado.
- **D2.** Sem `onclick`: o formulário leva `data-confirmar` com o texto já escapado pelo Twig, e o
  `public/js/menu-usuario.js` (carregado no `base.html.twig`) já pergunta no `submit`. Nenhum script novo. O texto da
  plataforma sai do template para o catálogo (`plataforma.convites.confirmar_reenviar` e `confirmar_revogar`).

## Raiz B (PR 2, não implementada aqui)

- O banco passa a guardar só o hash (sha256) do token do convite, como o Trabalho Externo (`ConviteDemanda`).
- Migration `Version20261020150500`: backfill `sha256` dos tokens que existem, sem quebrar os links já enviados (o
  link traz o token puro; a busca passa a comparar o hash).
- O reenvio gera um token novo e manda o link novo, como o `TrabalhoExterno/UseCase/ReenviarConviteUseCase`. O
  `ConviteMailer` recebe o token puro do caso de uso, porque a entidade não o terá mais.
- Depois do PR 1 nenhuma tela lê o token da entidade, então a troca fica contida no repositório, nos casos de uso de
  criar e reenviar e no mailer.

## Raiz C (PR 2, não implementada aqui)

- O token sai da trilha de auditoria: o campo entra no `IGNORED_FIELDS` do `AuditLogSubscriber`.
- O que já foi gravado no `audit_log` tem o valor do token redigido.

## Raiz E (decisão pendente com o dono)

O cadastro público diz, pela mensagem, se o e-mail já tem conta (achado F14). Tirar isso muda o texto que o cliente
vê no cadastro; fica registrado até o dono decidir.

## Verificação (PR 1)

- `GerenciarConvitesControllerTest`, `AdminConviteControllerTest`, `ConvitePorIdTest`, `TipoDoConviteTest` e
  `ConviteComEmailAConfirmarTest`: a lista de quem gerencia não contém o token; revogar e reenviar por id; convite de
  outro escritório e convite de perfil acima dão 404 e não aparecem; aceite e recusa logados por id, e a recusa do
  convite de outro e-mail; o link público por token segue funcionando; e-mail inválido na criação é recusado; os
  templates não têm `onclick` com o e-mail.
- Unit: `RevogarConviteUseCaseTest`, `ReenviarConviteUseCaseTest`, `AceitarConviteEscritorioComContaUseCaseTest`,
  `RecusarConviteEscritorioUseCaseTest`, `CriarConviteEscritorioUseCaseTest`, `CriarConvitePlataformaUseCaseTest`.
- Smoke do dono: os botões Reenviar e Revogar nas duas telas de convites ainda perguntam antes de enviar; "Meus
  convites" abre o convite e aceita.
