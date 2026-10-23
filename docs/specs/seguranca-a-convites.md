# Segurança A: o token do convite só existe no e-mail

Risco ALTO (identidade User/Tenant). Origem: achados de auditoria interna F1, F3, F4, F5, F6, F7, F10, F14, F15 e F16. Esta spec
descreve o defeito e a correção; não descreve como o defeito seria explorado.

## Estado da frente

- 23/10: spec escrita. PR 1 (raízes A e D, sem migration) implementado na branch `seguranca-a-convites`, com commit
  local e sem push. Testes direcionados no banco da frente; falta a revisão (`feature-review-agent`) e o smoke do
  dono nas duas telas de convites e em "Meus convites".
- Pendência anterior a esta frente, mantida igual: quem está logado SEM escritório na sessão e clica no convite da
  seleção de escritório volta para a própria seleção (o `TenantContextValidatorListener` não libera nem
  `auth_aceite_convite` nem as rotas novas `auth_meu_convite*`). Decisão do coordenador.
- 23/10: PR 2 (raízes B e C, com a migration `Version20261020150500`) implementado na branch `seguranca-a-hash`,
  empilhada sobre a `seguranca-a-convites`, com commit local e sem push. Migration aplicada no banco da frente.
  Falta a revisão e, depois do merge, aplicar a migration no dev (`scripts/migrar-dev.sh`).
- 23/10: raiz E decidida pelo dono (mensagem única no cadastro e aviso por e-mail a quem já tem conta) e
  implementada no PR 2, num commit próprio.

## Por que

O link do convite (`/convite/{token}`) é a prova de que a pessoa é dona da caixa de e-mail: no aceite sem conta
(`AceitarConviteEscritorioSemContaUseCase`, `ConviteController::aceitarSemConta`) a conta já nasce confirmada. Isso só
é seguro se o token nunca sair do e-mail. Hoje ele sai: aparece no HTML de quem gerencia convites e em outras telas.

## Raízes e entregas

| Raiz | Achados | O quê | Entrega |
|---|---|---|---|
| A | F4, F5, F6, F7 (F1 é consequência) | O token aparece para quem convida e nas telas do convidado logado | PR 1 |
| D | F3 | O e-mail do convite vai para um `onclick` e não é validado na criação | PR 1 |
| B | F15, F16 | O token fica no banco em texto puro | PR 2 |
| C | F10 | O token entra na trilha de auditoria | PR 2 |
| E | F14 | O cadastro público diz se o e-mail já tem conta | PR 2 |

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

## Raiz B (PR 2)

### Antes

`Invitation.token` guarda o token do link em texto puro (coluna de 64 caracteres, UNIQUE `uq_invitation_token`), o
repositório busca por ele, o `ConviteMailer` lê `getToken()` da entidade e o reenvio manda o mesmo token de novo.

### Depois

- **B1.** A entidade guarda só `tokenHash` (SHA-256 em hex, 64 caracteres, UNIQUE `uq_invitation_token_hash`), com
  `Invitation::hashDoToken()` e `Invitation::gerarToken()`, como a `ConviteDemanda` do Trabalho Externo. Não há mais
  `getToken()`. `InvitationRepository::encontrarPorToken()` recebe o token puro do link e busca pelo hash, então
  `/convite/{token}` e as rotas dele seguem iguais.
- O token puro só existe em memória: `CriarConviteEscritorioUseCase`, `CriarConvitePlataformaUseCase` e
  `ReenviarConviteUseCase` devolvem `App\Auth\DTO\ConviteEmitido` (o convite e o token puro), e o controller passa o
  token ao `ConviteMailer::enviarConviteEscritorio|enviarConvitePlataforma(Invitation, string $token)`. O
  `ConviteEmitido` não vai para tela, lista nem JSON.
- **B2.** O reenvio gera um token novo (`Invitation::trocarToken()`) e manda o link novo; o link anterior passa a dar
  "Convite não encontrado", a mesma tela do token que não existe.
- **B3.** Migration `Version20261020150500`: cria `token_hash`, preenche com
  `encode(sha256(convert_to(token, 'UTF8')), 'hex')` (o mesmo valor do `hash('sha256', $token)` do PHP, então os links
  já enviados continuam valendo), põe o UNIQUE em `token_hash` e apaga a coluna `token` (o UNIQUE antigo sai junto).
  O `down()` é irreversível: o token não volta.

## Raiz C (PR 2)

- **C1.** `token` e `tokenHash` entram no `IGNORED_FIELDS_POR_CLASSE` do `AuditLogSubscriber`, só para a
  `Invitation` (o nome é genérico demais para valer em toda entidade): nem o create, nem o update do reenvio, nem o
  delete gravam o token ou o hash.
- A mesma migration apaga a chave `token` das linhas de `audit_log` da `App\Entity\Auth\Invitation`, nos caminhos
  que o subscriber grava (`diff.after`, `diff.before`, `diff.changes`) e, por garantia, nos do formato antigo
  (`after`, `before`, `changes`). Um UPDATE por caminho, só nas linhas em que o pai é objeto
  (`jsonb_typeof = 'object'`), para um array nunca abortar a migration. Os outros campos da linha ficam.
- Conferido: a impressão `sha1` do subscriber (`computeAuditFingerprint`) só evita registro duplicado dentro do mesmo
  flush, não é verificação de integridade, então apagar a chave não quebra nada. O desfazer da auditoria
  (`DesfazerAlteracaoAuditLogUseCase`) só desfaz `update` de uma lista fechada de entidades, sem a `Invitation`, e
  nunca recria entidade a partir do log: não há caminho que recrie um convite sem o token.

## Raiz E (PR 2, decisão do dono de 23/10)

### Antes

`POST /cadastro/criar` com um e-mail que já tem conta responde 422 com "Já existe uma conta com este e-mail..." e o
e-mail novo responde 200 `{ok, confirmar, email}`: a resposta diz se a conta existe.

### Depois

- **E1.** A mesma resposta nos dois casos: 200 com `{"ok": true, "confirmar": true, "email": <e-mail normalizado>}`.
  O modal mostra o mesmo texto ("Se o e-mail ... puder ser usado, você receberá as instruções nele...",
  `ContaAConfirmar::TEXTO_ENVIAMOS_ANTES|DEPOIS`). A constante `ERRO_EMAIL_EXISTENTE` sai. A validação dos campos
  (422) continua igual, porque não depende de conta nenhuma.
- **E2.** `CriarContaEEscritorioUseCase` devolve `null` quando o e-mail já tem conta: nada do cadastro é gravado e o
  aviso segue a situação da conta existente:
  - ativa e confirmada: `CadastroMailer::enviarAvisoDeContaExistente()` (template
    `auth/cadastro/email_conta_existente.html.twig`, no padrão do e-mail de confirmação), que avisa que já há conta e
    leva ao "Esqueci a senha" (`auth_senha_esqueci`);
  - ainda a confirmar (sem senha): o link de confirmação normal dessa conta, pelo `ReenviarConfirmacaoDeEmailUseCase`
    e respeitando o `TetoDoReenvioDeConfirmacao` (no teto, não sai nada);
  - inativa ou em quarentena (`isActive` falso): nada.
  Falha no envio só vai para o log. Para o tempo de resposta não destoar tanto, o caminho calcula e descarta um hash
  de senha, como o caminho novo. A corrida no UNIQUE também devolve `null`, sem aviso.
- **E3.** O caminho do e-mail existente não grava conta nenhuma na sessão: grava na mesma chave
  (`ContaAConfirmar::CHAVE_SESSAO`) e com a mesma validade o marcador neutro `{neutro, email, em, emissoes}`, que
  também substitui a chave de um cadastro anterior do navegador. O marcador é lido por `neutroNaSessao()` (o
  `idNaSessao()` descarta o que não tem id).
  - O "Reenviar" público com o marcador responde como o envio real (`FLASH_ENVIADO`, success) sem mandar nada, com
    o mesmo teto (`TetoDoReenvioDeConfirmacao::recusaPelosInstantes`, sobre os instantes guardados no marcador):
    "Aguarde" e o teto diário aparecem no mesmo clique que para a conta nova.
  - A tela de entrada com o marcador mostra o mesmo aviso de conta a confirmar, com o e-mail digitado. Chave
    vencida ou ausente: a tela fica como estava.
  - O e-mail novo segue como antes (sessão com a conta, reenvio real).

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

## Verificação (PR 2)

- `GerenciarConvitesControllerTest`: o convite criado pela tela grava só o hash e o link do e-mail abre o convite; um
  token antigo com o hash calculado pela expressão do backfill continua valendo; o reenvio troca o link e o anterior
  dá "Convite não encontrado"; a auditoria do create e do reenvio não tem token nem hash.
- Unit: `CriarConviteEscritorioUseCaseTest`, `CriarConvitePlataformaUseCaseTest` (o hash é gravado e o token puro
  volta no `ConviteEmitido`), `ReenviarConviteUseCaseTest` (o reenvio troca o token) e `ConviteMailerTest`.
- Os outros testes que montavam `Invitation` com o token passaram a montar com o hash.
- Raiz E: `CadastroControllerTest` (o e-mail existente e o novo dão o mesmo status e o mesmo JSON; o aviso de conta
  existente só sai para o existente, sem escritório, sem confirmação e só com o marcador neutro na sessão; três
  cliques no reenvio dão as mesmas respostas nos dois casos, sem e-mail para o existente; a tela de entrada mostra o
  mesmo aviso; a conta inativa não recebe nada; a conta a confirmar recebe a confirmação normal),
  `CriarContaEEscritorioUseCaseTest` (null, nada gravado, as três situações da conta, o teto, falha no envio só no
  log, corrida) e `LoginDeContaAConfirmarTest` (o texto novo do modal).
- Smoke do dono: o modal "Confirme seu e-mail" depois de criar a conta, com um e-mail novo e com um que já tem conta;
  o e-mail "Você já tem uma conta no BlueJus" e o botão "Esqueci a senha" dele.
