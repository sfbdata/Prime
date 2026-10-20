# Spec: revisão das OABs pelo Master, por inscrição

## Estado da frente (atualizado em 20/10/2026)

- Branch `oab-revisao-por-inscricao`, **empilhada sobre o #65** (`meu-perfil-oab`, da 7d), criada a partir de
  `origin/meu-perfil-oab` sem tocar na branch da 7d. Merge depois do #65 (e do #60, base dele).
- Risco **MÉDIO** (situação da OAB de usuários; só o Master age). **Sem migration.**
- Pedido do coordenador em 20/10: o desenho 07 diz que a suplementar "fica Pendente até o Master validar"
  (L217 a 219), mas **o desenho é omisso** sobre a tela: o 05 só tem a seção "Usuários e OABs" de leitura
  (L183 a 192). Vale a convenção: a **tela que já existe** (`/admin/platform/oab`) passa a listar por inscrição.
- Nenhum PR aberto (inclusive da b5) toca `OabReviewController` ou `templates/admin/platform/oab.html.twig`
  (conferido em 20/10).

## O que muda

- A lista deixa de ser por **usuário** (só a principal) e passa a ser por **inscrição** (`InscricaoOab`,
  principal e suplementares), pelo `InscricaoOabRepository::paraRevisao` e `contarParaRevisao` do #65.
- Cada linha: o nome e o e-mail do usuário (como hoje), número/UF, selo **PRINCIPAL** ou **SUPLEMENTAR**,
  situação e data da verificação.
- **Mesmos filtros** de hoje (Pendentes, Divergentes, Não verificadas, Confirmadas, Todas), mesma busca e
  paginação, mapeados para os `StatusOab`.
- **Mesmas ações** (confirmar, reverter para Não verificada ou Divergente, reverificar), agora pelo **id da
  inscrição**, ligadas aos 3 UseCases do #65 (`ConfirmarInscricaoOabUseCase`, `ReverterInscricaoOabUseCase`,
  `VerificarInscricaoOabUseCase`). A principal continua espelhada no usuário (os UseCases já fazem).
- Sem recusa com motivo (a entidade não tem campo; fica de fora).
- Edição mínima no `OabReviewController` e no template; nomes de rota mantidos quando possível.

## Segurança
- Só quem já acessa a tela hoje (`ROLE_SUPER_ADMIN`, conferir no controller) e CSRF nas ações, como hoje.
- Id de inscrição inexistente: 404 (o UseCase lança `DomainException`).
- Cada ação fica na auditoria (`InscricaoOab` é `Auditavel`).

## Verificação
- Functional: lista mostra principal e suplementar com o selo; filtros; confirmar/reverter/reverificar uma
  suplementar muda só ela; confirmar a principal espelha no usuário; sem permissão 403; CSRF errado 403;
  id inexistente 404.
- Navegador: a tela com uma suplementar Pendente sendo confirmada.
