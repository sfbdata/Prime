# Memória do layout por requisição (PR 2 da revisão de queries por página)

Risco: BAIXO a MÉDIO (toca a leitura do cadeado e o bloqueio por pedido de exclusão, sem mudar regra).
Origem: medição `QueriesPorPaginaTest` (descartável) de 05/11/2026. Todos os itens são "a mesma consulta repetida no
mesmo request". Nenhum filtro de escritório, permissão ou cadeado muda: só deixa de perguntar de novo ao banco.

## Regras comuns

- Memória vive só na requisição: todo serviço com memória implementa `ResetInterface` (tag `kernel.reset` pela
  autoconfiguração), que também zera entre mensagens do worker do Messenger.
- A chave inclui tudo de que a resposta depende (pessoa, escritório, chave da preferência).
- Quem grava o dado na mesma requisição limpa a memória no caminho da gravação.
- O PR 1 (#662) guarda o `UserTenant` no `PermissionChecker`; este PR não mexe no `PermissionChecker`,
  no `UserTenantRepository` nem na `TenantRolePermission`.

## O que fica guardado

| Onde | O que | Chave | Invalidação na mesma requisição |
|---|---|---|---|
| `Pasta\Service\NivelNoCadeado` | "Master ou primário?" (antes `RegraDosPrimarios::podeGerirPrimarios`, uma consulta por chamada): agora usa o vínculo já guardado em `vinculoAtivo()`; perfil e "ativo" são lidos da entidade na hora | pessoa:escritório | troca de perfil vale na hora (entidade gerenciada); o "sem vínculo" já era guardado antes deste PR |
| `NivelNoCadeado::idsDasPastasOcultas` | ids das pastas ocultas para quem não é Master nem primário | pessoa:escritório | `esquecer()`, chamado por todo UseCase que grava cadeado (Configurar, Remover, Decidir pedido) pelo `AcessoAPasta::esquecer()` |
| `Central\Twig\CentralExtension` | vínculo ativo de quem está logado (`central_disponivel()` e `presenca_sou_primario()`) | pessoa:escritório | só guarda vínculo achado; vínculo guardado que ficou inativo volta ao banco |
| `Central\Repository\ConfiguracaoDePresencaRepository::minutosAusente` | limite de ausência do escritório | escritório | `salvar()` (único escritor: `AlterarAusenciaDoEscritorioUseCase`) esquece tudo antes de gravar |
| `Dashboard\Repository\PreferenciaDoUsuarioRepository::valoresDoUsuario` | valor (ou ausência de linha) de cada chave já lida | escritório:pessoa, por chave | `gravar()` e `apagarDoUsuario()` esquecem a pessoa no escritório; todos os escritores passam por eles |
| `Tenant\Service\BloqueioPorPedidoDeExclusao::suspensaAPedido` | "tem pedido de exclusão pendente?" | escritório | ouvinte de entidade (`postPersist`, `postUpdate`, `postRemove` de `PedidoDeExclusaoDeConta`) esquece o escritório do pedido: cobre pedir, desistir, reativar e excluir |

O sino (`NotificacaoExtension`) faz 1 lista e 3 contagens; cada uma pedia as pastas ocultas do cadeado de novo.
Com a memória do `NivelNoCadeado` a pergunta sai uma vez; o silêncio (`SilencioDoSininho`) e os cortes de leitura
(`LeituraDasPastasDosAvisos`) já eram guardados. A contagem agrupada (3 contagens numa consulta) ficou de fora.

## Invalidação do cadeado

`NivelNoCadeado` é ouvinte de entidade (`AsEntityListener`, postPersist/postUpdate/postRemove) de `CadeadoDaPasta` e
`AcessoAPastaTrancada`: qualquer gravação pela entidade no mesmo processo (UseCase, comando, worker, teste) chama
`esquecer()`. Antes valia só o `esquecer()` dos UseCases; a sincronia de prazos com o Google tranca a pasta e
sincroniza no mesmo processo (`SincronizacaoDePrazosComGoogleTest::testTrancadaDepois`).

## Limites conhecidos

- `PurgarEscritorioUseCase` apaga `preferencia_usuario`, `central_configuracao_presenca` e `pedido_exclusao_conta`
  por SQL direto, sem passar pelos escritores. O escritório deixa de existir; ninguém pergunta por ele depois na
  mesma requisição. Não tratado.
- Vínculo criado ou reativado depois de o `NivelNoCadeado` ter guardado "sem vínculo" continua "sem vínculo" até o
  fim da requisição (comportamento que o `nivelPorId` já tinha; agora vale também para o critério e as ocultas).
- Memórias que já existiam antes deste PR (`SilencioDoSininho`, `PreferenciasDoPerfilExtension`) não esquecem quando
  a preferência é gravada na mesma requisição. Não mudou aqui.
- Decisão do pedido de exclusão que recebe 409 (`gravarDecisaoSePendente` acha o pedido já decidido por outra
  requisição): não há flush, o ouvinte não roda, e a memória do bloqueio continua com o valor lido antes até o fim
  desta requisição. A janela é só a resposta do próprio 409; a próxima requisição lê o banco.
- Valor lido dentro de uma transação que depois é revertida (entre o flush e o `rollBack()`) fica guardado até o fim
  da requisição. Hoje nenhum leitor pergunta nesse intervalo: os `$gravar` das decisões só fazem flush e auditoria.
- Nenhum `UPDATE` em massa de `user_tenant` existe hoje (grep). Se surgir, o vínculo guardado no `NivelNoCadeado` e
  na `CentralExtension` não o veria na mesma requisição.

## Testes

- `tests/Pasta/Functional/NivelNoCadeadoMemoriaTest.php`: equivalência memória x serviço zerado (primário, dentro e
  fora do cadeado), critério em DQL, 1 consulta em N chamadas, `reset()`, cadeado gravado pelo UseCase, perfil trocado.
- `tests/Tenant/Unit/BloqueioPorPedidoDeExclusaoMemoriaTest.php` e `tests/Tenant/Functional/BloqueioPorPedidoDeExclusaoMemoriaTest.php`.
- `tests/Dashboard/Functional/PreferenciaDoUsuarioRepositoryMemoriaTest.php`.
- `tests/Central/Functional/MemoriaDaCentralNoLayoutTest.php`.
- `tests/Service/Functional/QueriesDoLayoutGeralTest.php`: teto de consultas de `/perfil` e `/notificacoes/count`
  (primário e colaborador, medido + 3) e nenhuma consulta igual repetida nas tabelas guardadas.
