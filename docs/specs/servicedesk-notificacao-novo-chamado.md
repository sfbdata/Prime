# Spec — Notificação de novo chamado no Service Desk (E3)

## Motivo
Ao abrir um chamado (`POST /servicedesk/novo`), a equipe de TI **não era avisada**: o
método `ServiceDeskController::notificarNovoChamado()` estava vazio (`// TODO`). As demais
notificações do domínio (interação, atribuição, mudança de status) já funcionavam. Esta
mudança fecha o gap. Toca notificação + filtro por permissão → risco **MÉDIO**.

> Decisão de escopo: feito **cirúrgico no controller legado** (Opção A), não migração. A
> migração completa do ServiceDesk é a etapa E4 do plano de pendências; quando ela ocorrer,
> esta lógica migra para o UseCase `AbrirChamado`.

## Comportamento
- Ao criar um chamado, **todos os gestores de TI do tenant** recebem uma notificação
  apontando para o chamado (`servicedesk_show`).
- **Gestor de TI** = usuário com `UserTenant` ativo no tenant e permissão
  `admin.servicedesk.manage` (via `PermissionChecker::canAdminister`). Cobre tanto papéis
  de sistema (`isSystem`) quanto papéis com a permissão explícita.
- **O solicitante é excluído** da notificação, mesmo que ele próprio seja gestor de TI
  (quem abriu o chamado não precisa ser avisado do próprio chamado).
- **Best-effort:** sem tenant ativo, não notifica e não quebra o fluxo de abertura.

## Tipo de notificação
- Nova constante `Notificacao::TIPO_SERVICEDESK_NOVO = 'servicedesk_novo'`.
- Entra em `Notificacao::TIPOS_GESTAO` (categoria **gestão**, como `servicedesk_atribuicao`).
- Ícone: `bi-ticket-detailed text-primary` (mapa em `Notificacao::getIcone()`).
- Mensagem: `"Novo chamado #{id}: {título}"`.

## Acabamento (tidy)
- As notificações de interação e mudança de status usavam a string literal `'servicedesk'`.
  Padronizado para a constante `Notificacao::TIPO_SERVICEDESK = 'servicedesk'` (mesmo valor,
  sem mudança de comportamento — continua categoria pessoal).

## Componentes tocados
- `src/Entity/Notificacao.php` — constantes + `TIPOS_GESTAO` + `getIcone()`.
- `src/Service/NotificacaoService.php` — novo método
  `notificarNovoChamado(Chamado $chamado, Tenant $tenant, string $url): void`
  (espelha `notificarJustificativaEnviada`: busca usuários ativos do tenant via
  `UserTenant`, filtra por `admin.servicedesk.manage`, exclui o solicitante, cria
  notificações com URL).
- `src/Controller/ServiceDeskController.php` — `notificarNovoChamado()` resolve tenant +
  URL e delega ao serviço; literais `'servicedesk'` → constante.

## Testes
- **Integração** (`KernelTestCase`, `tests/Notificacao/Functional/NotificacaoServiceTest.php`):
  gestor com a permissão recebe; usuário sem a permissão **não** recebe; solicitante
  (mesmo sendo gestor) é excluído; tipo/URL corretos.
- **Functional** (`tests/ServiceDesk/Functional/CriarChamadoControllerTest.php`,
  `JusPrimeWebTestCase`): `POST /servicedesk/novo` cria o chamado e notifica o gestor;
  estabelece a pasta de testes do ServiceDesk (útil para E4).

## Descobertas durante a implementação
- **Bug pré-existente crítico (corrigido):** o `ServiceDeskController` e
  `templates/servicedesk/show.html.twig` chamavam `ChamadoInteracao::setUsuario()/
  getUsuario()` e `ChamadoAnexo::setUsuario()` — métodos **inexistentes** (os corretos são
  `setAutor()/getAutor()` e `setEnviadoPor()`). Resultado: **todo o módulo dava HTTP 500**
  ao abrir chamado, interagir, atribuir ou mudar status. Corrigidas todas as ocorrências
  (controller linhas ~181/263/309/356/384/422 e template `show.html.twig:91`). Provável
  rename de entidade (`usuario`→`autor`) que não propagou para os consumidores.
- **Deprecation corrigida:** `ChamadoType` usava `new File([...])` (array de opções),
  deprecado no symfony/validator 7.3 e fatal sob `failOnDeprecation=true`. Convertido para
  argumentos nomeados. (O teste functional novo passou a exercitar o form e expôs isso.)

## Limitações conhecidas (para E4)
- `Chamado` não persiste o tenant; o isolamento da notificação depende do tenant passado
  pelo chamador (resolvido via `TenantContext`). Coberto por teste de isolamento
  cross-tenant, mas a garantia é por convenção, não por modelo. Na migração E4, **persistir
  o tenant no `Chamado`** e derivá-lo no serviço/UseCase.

## Não-objetivos
- Não altera o fluxo de abertura, anexos, nem as outras notificações além do tidy.
- Não migra o domínio (isso é E4). Os caminhos interagir/atribuir/status tiveram só o
  conserto de método (não há teste automatizado deles ainda — entram no test net da E4).
