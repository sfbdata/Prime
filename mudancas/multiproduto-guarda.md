---
tipo: seguranca
---
## Changelog
- Guarda do produto (`App\Produto\EventListener\GuardaDoProduto`, `kernel.request`, prioridade 3): rota de um produto que o escritório não tem responde 404, fora do `PermissionChecker` (o primário também leva 404; só o Master passa). Não age em sub-requisição, rota de núcleo, pública ou plataforma, sem usuário logado nem sem escritório na sessão (é por aqui que passa o Convidado do Trabalho Externo; com escritório na sessão, o Trabalho Externo segue o Expediente). O escritório é o do `{tenantId}` da URL quando existe; senão, o da sessão.
- Chave `MULTIPRODUTO_MODO` (parâmetro `multiproduto.modo`, `App\Produto\Service\ChaveDoMultiproduto` e enum `ModoDoMultiproduto`): `desligado` (padrão), `observacao` (registra no canal de log `multiproduto`, sem dado pessoal, e deixa seguir) e `bloqueio` (404). Valor desconhecido vale `desligado`.
- Canal de log `multiproduto`; em produção com handler próprio em stderr (JSON), porque o `main` só grava a partir de erro.
- Conector de I.A. (D11): `LiberacaoDoConector::exigir` passa a exigir o Expediente do escritório em `bloqueio` (recusa como os outros níveis da liberação) e só registra em `observacao`. Desligada, tudo como antes.
- Tipo `seguranca`: é uma camada nova de autorização por escritório (risco ALTO na spec), mesmo nascendo desligada.

## Para o cliente
- Preparamos o BlueJus para abrir a cada escritório só as ferramentas que ele usa: quando esta trava for ligada, quem tentar abrir uma tela de ferramenta que o escritório não tem verá a página de "não encontrada". Por enquanto ela fica desligada, e todos continuam vendo as mesmas telas de sempre.
