# Backup e Exportação de Dados (BlueJus)

Spec curta da Frente D (23/11/2026). Origem: análise `bluejus-coordenacao/backup-analise-2311.md`.
Aprovado pelo Samuel em 23/11: a tabela `pedido_de_backup` e a categoria de arquivo `BACKUP_ZIP`.
Risco: BAIXO pelo domínio, mas lida com **dado de cliente de vários módulos**; o cuidado é o de isolamento
entre escritórios e entre pessoas do mesmo escritório.

## 1. O que é

O usuário pede, numa seção discreta das configurações, um **backup** de tudo o que ele pode ver no
escritório atual. O sistema monta um ZIP em segundo plano, avisa pelo sino quando fica pronto e deixa
baixar por alguns dias. O ZIP traz os arquivos (documentos) e os dados (registros em JSON).

## 2. Regras que não mudam

1. **Só sai o que o usuário pode ver.** O backup nunca lê "tudo do escritório" para filtrar depois: cada
   coletor parte da mesma consulta de visibilidade que a tela usa (`AcessoAPasta::criterioDeVisivel`,
   `EscopoDePastas`, `VisibilidadeDasAbasDaPasta::documentosVisiveis`). Lixeira fica de fora.
   **No worker, só as variantes com `User` e `Tenant` explícitos**: `AcessoAPasta::idsDasPastasOcultas(User,
   Tenant)`, `VisibilidadeDasAbasDaPasta::abasOcultasPorPasta(User, Tenant)`,
   `EscopoDePastas::idsDosClientesNoEscopo(User, Tenant)` e `PermissionChecker::canAccessModule(User, Tenant, …)`.
   Proibido no worker: `criterioDeVisivel`, `restringirAoVisivel`, `restringirAoEscopo`, `soAsVisiveis` e
   tudo que lê `TenantContext` ou `Security`. Sem sessão eles deixam de filtrar o perfil restrito (vazamento).
2. **Tenant explícito no worker.** O `TenantFilter` não liga fora de request. Toda consulta dos coletores
   e do handler leva `tenant_id = :tenant` escrito na mão; nada depende do filtro.
3. **A mensagem só leva o id do pedido.** O worker recarrega pedido, usuário e escritório pelo banco.
4. **A permissão vale no momento de montar**, não no do clique. O download confere de novo: dono do pedido
   = usuário logado, mesmo escritório atual, pedido `pronto` e não vencido. Fora disso, 404.
5. **Nunca link público.** Download só por rota autenticada.
6. **Fora do ZIP, sempre:** senhas e hashes, tokens (Google, integrações), segredos, `audit_log` bruto,
   dados de outros escritórios, painel do Master, selfies de signatários da Assinatura.
7. **Auditoria** de cada pedido e de cada download no `audit_log`: o `PedidoDeBackup` é `Auditavel` (criação e
   mudanças de situação entram pela trilha automática) e o download é registrado à mão (ação curta `backup_dl`).

## 3. Modelo (PR 1)

Domínio novo `app/src/Backup/`. Tabela `pedido_de_backup` (migration `Version20261123120000`, aditiva):

| coluna | tipo | nota |
|---|---|---|
| `id` | serial | |
| `tenant_id` | FK tenant, `ON DELETE CASCADE` | |
| `usuario_id` | FK user, `ON DELETE CASCADE` | quem pediu; só ele baixa |
| `situacao` | varchar(20) | `pedido`, `montando`, `pronto`, `falhou`, `vencido` |
| `pedido_em`, `iniciado_em`, `concluido_em`, `expira_em` | timestamp | `expira_em` nasce na conclusão |
| `chave_arquivo` | varchar(255) nulo | só o NOME da chave `BACKUP_ZIP` (escopo = tenant do pedido) |
| `tamanho_bytes` | bigint nulo | |
| `sha256` | varchar(64) nulo | |
| `arquivos_incluidos`, `arquivos_fora` | int | contagens para a tela e o LEIA-ME |
| `erro` | varchar(255) nulo | motivo curto, sem dado de cliente |

Índice `(tenant_id, usuario_id, pedido_em)`. Entidade rica: `iniciarMontagem()`, `concluir()`, `falhar()`,
`vencer()`, `podeSerBaixadoPor()`. Transição fora de ordem lança `DomainException`.

Categoria de arquivo `BACKUP_ZIP`, **isolada por escritório no disco** (`uploads/pastas/backups/<tenantId>/`,
como a Assinatura), **sem espelho no R2 e sem liberação do disco**: é descartável e pesado. A
`PoliticaDeEspelhamento` recusa a categoria mesmo que alguém a ponha na variável de ambiente. A purga do
escritório leva o diretório inteiro pelo prefixo, inclusive ZIP órfão (worker que caiu depois de gravar).
Fábrica de chaves: `ChavesDeBackup`.

## 4. Conteúdo do ZIP (PR 2 e PR 5)

```
backup-bluejus-AAAA-MM-DD/
  LEIA-ME.txt        quem pediu, quando, escritório, contagens, o que ficou de fora e por quê
  manifesto.json     versão do formato, lista de arquivos com sha256 e tamanho
  dados/*.json       registros e vínculos por módulo
  pastas/<NUP ou nome>/documentos/<seção>/<arquivo>
```

PR 2: Pastas (documentos com a árvore de seções, dados, partes, processos vinculados, andamentos, prazos,
comentários, observações; aba Financeiro só para quem a vê) e Clientes (cadastro e documentos; restrito vê
só os clientes do seu escopo). PR 5: demais módulos, um coletor por domínio; o Ponto só o do próprio
usuário; Cobrança só para quem tem o módulo.

Reaproveita o padrão do `MontarZipDeDocumentosUseCase` (nomes seguros, LEIA-ME, arquivo emprestado sem
copiar, `CM_STORE` para formato já comprimido) sem alterar o UseCase existente.

## 5. Segundo plano e limites (PR 3)

- **Pedido preso em `montando`.** O worker pode cair depois de `iniciarMontagem()` e o Messenger reentrega a
  mensagem; a entidade só começa a montar a partir de `pedido`, então a reentrega cai em `DomainException`. O
  handler trata a reentrega (pedido já em `montando` não é montado de novo) e a tarefa agendada marca `falhou`
  o `montando` velho (mais de 2 h), senão a pessoa fica sem poder pedir outro backup.
- Mensagem `GerarBackup(pedidoId)` no transport `async`. O handler marca `montando`, monta o ZIP na área
  temporária privada, grava no armazenamento (`BACKUP_ZIP`; o `/tmp` do worker não é compartilhado com o
  php) e marca `pronto`. Erro: marca `falhou` com motivo curto e não derruba a fila.
- **Teto por pedido: 5 GB** de arquivos. O que passar fica de fora e é listado no LEIA-ME. Um ZIP só
  (libzip faz ZIP64); dividir em partes fica para quando aparecer escritório que precise.
- **Validade: 7 dias.** Tarefa agendada nova marca `vencido` e apaga o arquivo.
- **Um pedido aberto por vez por usuário e escritório, e no máximo 1 por dia** (contado em 24h corridas).
  - **Pedido `falhou` não conta** no limite do dia: a falha é do sistema, a pessoa pede de novo.
  - **Corrida de dois cliques:** `PedirBackupUseCase` checa e grava dentro de uma transação com
    `pg_advisory_xact_lock(4432, hashtext('tenant:usuario'))`; o `GerarBackup` só é despachado depois do commit.
  - **EntityManager fechado no worker:** erro de banco fecha o EM; o handler chama `resetManager()`, recarrega o pedido
    pelo id e marca `falhou` com aviso no sino (inclusive quando o flush do `pronto` falha, depois de apagar o ZIP).
- Aviso de pronto pelo sino (`Notificacao`); sem e-mail em lote (cota da Hostinger).

## 6. Tela (PR 4)

Seção discreta "Backup e Exportação de Dados": botão "Gerar backup", situação do último pedido, lista dos
últimos pedidos com download. Local e desenho: a confirmar com o Coordenador antes do PR 4 (não há desenho
de Backup em `docs/design/`).

## 7. Decisões tomadas pela recomendação (23/11)

Registradas aqui porque o Samuel delegou as decisões de implementação: teto 5 GB e validade 7 dias
(sugestão do relatório); selfies de signatários ficam de fora; dados de devedores da Cobrança entram só
para quem tem o módulo; ZIP único em vez de partes; 1 pedido por dia por usuário.

## 8. Testes obrigatórios

1. Unit da entidade: transições válidas e inválidas, `podeSerBaixadoPor`.
2. Isolamento: usuário A (escritório 1) e B (escritório 2), e usuário restrito no escritório 1. O ZIP de A
   não contém nenhum id nem arquivo de B; o do restrito não contém pasta fora do escopo, pasta com cadeado
   que ele não abre, nem a aba Financeiro oculta.
3. Download: outro usuário, outro escritório, pedido vencido ou não pronto respondem 404.
4. Purga do escritório apaga os ZIPs e as linhas.
