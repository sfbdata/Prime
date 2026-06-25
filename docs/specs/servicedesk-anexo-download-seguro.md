# Spec — Hotfix: download seguro de anexos do ServiceDesk (H2)

## Motivo (segurança)
Os anexos de chamado eram servidos como **arquivo público estático**
(`templates/servicedesk/show.html.twig` linkava `asset('uploads/chamados/' ~ nomeArquivo)`),
sem autenticação nem verificação de tenant. Os arquivos ficavam em
`public/uploads/chamados/`, acessíveis por URL direta. O nome é aleatório
(`bin2hex(random_bytes(16))`), o que dificulta a enumeração, mas não há controle de acesso.

## Solução
1. **Tirar do `public/`:** `chamados_uploads_dir` passa de `public/uploads/chamados` para
   `var/uploads/chamados` (`config/services.yaml`). Fora do web root, não há mais URL direta.
2. **Rota controlada:** `GET /servicedesk/{id}/anexo/{anexoId}` (`servicedesk_anexo`) no
   `ServiceDeskController::downloadAnexo`, seguindo o padrão de `PontoController::downloadAnexo`:
   - `garantirChamadoDoTenant()` (404 se de outro tenant);
   - acesso = admin (`admin.servicedesk.manage`) **ou** solicitante; senão 403;
   - o anexo precisa pertencer ao chamado (busca na coleção `chamado.anexos`); senão 404;
   - serve via `ArquivoStorageService::servir()` (BinaryFileResponse, inline).
3. **Template:** o link passa a usar `path('servicedesk_anexo', {id, anexoId})`.

## Testes
`tests/ServiceDesk/Functional/AnexoDownloadControllerTest.php`: dono baixa (200 +
Content-Disposition com nome original), gestor de outro tenant → 404, usuário comum não
solicitante → 403. Suíte 718/718.

## Deploy / notas
- **Mover arquivos existentes** em produção de `public/uploads/chamados/` para
  `var/uploads/chamados/` no deploy. Localmente o diretório está vazio, mas **confirmar no
  servidor de produção (bluejus.com.br)** antes do deploy — se houver anexos legados e não
  forem movidos, todo download retorna 404. Garantir que `var/uploads/` seja gravável pelo
  processo PHP (o storage cria o diretório no 1º upload).
- `ChamadoAnexo::getCaminho()` (retornava `'uploads/chamados/...'`) é **código morto** (sem
  consumidores) e agora desatualizado; será removido na migração de domínio (E4).

## Escopo / não-objetivos
- **Outros módulos seguem com uploads em `public/`** (`pastas`, `justificativas`,
  `clientes`, `perfil`). O mesmo padrão (rota controlada + fora do public) deveria ser
  aplicado a eles — recomendado como esforço de segurança próprio, app-wide. Aqui só o
  ServiceDesk foi tratado.
