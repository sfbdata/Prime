# Perfis 1.2 · Suspender, reativar e excluir conta pelo Master

Frente `perfis-contas` · **empilhada sobre `perfis-masters` (#52)** · risco **ALTO** (Tenant, perda de dado) ·
aberta em 19/10/2026. Q2 a Q4 decididas pela coordenação (19/10); **Q1 (exclusão) está com o dono**: a exclusão e o bloco de pedidos esperam.

## 1. Fontes

- Desenho `05 - Perfis 1.2 - VS 18.10.26.dc.html`: menu de contexto da conta em "Contas e escritórios"
  (linhas 160 e 170-176, lógica 734-735), confirmação genérica (linhas 411-419 e 624), bloco "Pedidos de exclusão
  de conta" (linhas 148 e 679).
- Contrato com a frente da 7d (PR 5, "excluir conta em 2 etapas", desenho 07): entidade
  `App\Tenant\Entity\PedidoDeExclusaoDeConta`, estados pendente | desistida | reativada | excluida, transições
  `marcarReativada(ator)` e `marcarExcluida(ator)` para o Master, repositório `pendentes()`.
- Estado medido (19/10): suspensão e exclusão gravam o mesmo `Tenant.isActive=false`; a diferença é
  `Tenant.excluidoEm`. O `TenantContextValidatorListener` barra a cada request, então não é preciso derrubar sessão.
  Exclusão hoje = soft delete com quarentena de 365 dias (`tenant_carencia_purga_dias`) e purga às 03h
  (`app:purgar-dados-expirados`), que preserva `User` e `audit_log`. Não existe suspender nem reativar.

## 2. Regras

| # | Regra |
|---|---|
| C1 | Só `ROLE_SUPER_ADMIN`. Rotas POST sob `/master/contas/{id}/…`, com CSRF por conta e 403 para os outros. |
| C2 | **Suspender**: `isActive=false`, `excluidoEm` nulo. Os usuários perdem o acesso no request seguinte; nenhum dado é apagado. Conta já suspensa ou excluída: recusa com mensagem. |
| C3 | **Reativar**: um único `ReativarContaUseCase`, usado também pelo bloco "Pedidos de exclusão". Conta suspensa pelo Master: `isActive=true`. Conta com pedido de exclusão pendente (estado próprio da 7d; o Tenant continua `isActive=true`): só `marcarReativada(ator)`. Recusa conta com `excluidoEm` preenchido, salvo se a §6 Q1 disser que a quarentena continua. Se houver pedido pendente, chama `marcarReativada(ator)` na mesma transação. |
| C4 | **Excluir**: conforme a §6 Q1. Em qualquer caso grava `excluidoEm`, também quando a conta já está suspensa (o `ExcluirEscritorioUseCase` atual sai cedo quando ela já está inativa: não serve aqui). Se houver pedido pendente, chama `marcarExcluida(ator)`. |
| C5 | **Conta do Master**: o item "Excluir conta" não aparece e o servidor recusa quando o criador do escritório (`Tenant.criadoPor`) é um Master; sem criador registrado, vale "algum Master tem vínculo ativo". Suspender essa conta também é recusado: hoje o Master não tem bypass de conta suspensa e ficaria fora do próprio escritório. Mensagem: "Esta conta foi criada por um Master. Suspendê-la tiraria dele o acesso ao próprio escritório." |
| C10 | **Vaga no limite de escritórios** (Q3): a conta suspensa e não excluída continua ocupando a vaga do criador. |
| C11 | **Rotas antigas** (Q4): `escritorio_excluir` e `app_tenant_delete` recusam a conta criada por um Master, com a mesma regra da C5. |
| C6 | **Auditoria**: a linha grava o `tenant_id` da **conta alvo** (e por isso também aparece na `/auditoria` do próprio escritório: a conta vê quem agiu sobre ela, o que não é o caso de terceiro da M7), não o do escritório da sessão, para que "Histórico de alterações" da conta mostre a ação. O rótulo é "Conta suspensa", "Conta reativada" ou "Conta excluída", e o detalhe é "Ação confirmada pelo Master". O `RotuloDeAuditoria` passa a testar `excluidoEm` antes de `isActive`. |
| C7 | **Painel**: `/master/dados/contas` ganha `ehDoMaster` (bool). O menu da conta passa a ter os itens do desenho, na ordem: "Editar limite de armazenamento" e "Definir limite de usuários" (da frente de limites, depois da C1 da Contribua; até lá, ocultos), "Estatísticas de uso", "Histórico de alterações", "Suspender conta" ou "Reativar conta", e "Excluir conta" (oculto quando `ehDoMaster`). |
| C8 | Confirmação no modal genérico do desenho (`bi-pause-circle` `#c08a12` "Suspender"; `bi-play-circle` `#1e7b45` "Reativar"; `bi-exclamation-octagon` `#a3232b` "Excluir definitivamente"), com os textos exatos da linha 624. |
| C9 | **Mensagem da conta suspensa**: "A conta do escritório {nome} está suspensa. Fale com o responsável pela conta." na tela de seleção (no lugar de "Você ainda não faz parte de nenhum escritório"); o listener só põe o aviso quando a página de destino não mostra o alerta, para o texto não sair duplicado. |
| C12 | **PENDENTE DO DONO, não implementado**: proposta da 7d de dar ao Master bypass de conta suspensa ("o Master pode tudo"). Abre exceção numa barreira de acesso, então só entra com confirmação direta do dono. Se aprovada, a C5 passa a permitir suspender a conta do Master. |
| C13 | **Ordem das barreiras no acesso** (`TenantContextValidatorListener`, um método privado por barreira; combinada com a 7d em 19/10): (a) `barreiraDaConta`, conta suspensa ou excluída, desta frente; (b) pedido de exclusão pendente, da 7d no PR 5: bloqueia a equipe com "Este escritório está suspenso a pedido do usuário primário e aguarda a decisão do BlueJus." e deixa só o primário no Meu Perfil; (c) `barreiraDoVinculo`, vínculo inativo; (d) situação do usuário, da 7d no PR 4, valores aprovado / em_analise / suspenso, mensagens "Seu acesso ao escritório {nome} está suspenso pelo usuário primário. Seus dados e o histórico continuam guardados." e "Seu acesso ao escritório {nome} está em análise pelo usuário primário.". Vale a mensagem da primeira que falhar. O Master não tem bypass na (a) (ver a C12) e não passa pela (d). Quem mergear primeiro cria o esqueleto. |

## 3. Bloco "Pedidos de exclusão de conta · N"

Lê `PedidoDeExclusaoDeContaRepository::pendentes()` (da 7d), com o subtítulo "contas suspensas aguardando sua
decisão". Cada linha mostra escritório, quem pediu (nome e e-mail), data e motivo. "Reativar" usa
`confirm('Reativar a conta X?')` e chama o `ReativarContaUseCase`. "Excluir definitivamente" pede para digitar
EXCLUIR e segue a C4. O bloco só aparece quando há pedido pendente. Depende do merge do PR 5 da 7d e da decisão
dela sobre o estado de "suspensa por pedido".

## 4. Testes

Unit dos três UseCases: cada recusa e o caminho feliz, com e sem pedido pendente. Functional: 403 para primário e
secundário; CSRF; suspender barra o usuário no request seguinte (redirect com a mensagem da C9); reativar devolve
o acesso; excluir a conta do Master recusado no servidor; trilha mostra o rótulo certo e a conta certa; o menu do
painel mostra os itens certos por situação.

## 5. Fora desta frente

Limites de armazenamento e de usuários (frente própria, depois da C1 da Contribua). Rotas antigas
`escritorio_excluir` e `app_tenant_delete`: ficam como estão nesta frente; ver a §6 Q4.

## 6. Perguntas de produto (19/10)

Decididas pela coordenação: Q2 (recusar; a revisão com bypass do Master, C12, espera o dono), Q3 (ocupa vaga) e Q4 (recusar já nesta frente).
Pendente com o dono: Q1.

- **Q1. "Excluir conta" do Master.** O desenho diz: "A exclusão de X é irreversível: todos os usuários, pastas e
  arquivos da conta serão apagados." O sistema hoje põe a conta em quarentena de 365 dias, purga depois e nunca
  apaga o `User` (só os vínculos). Recomendação: excluir pelo Master **purga na hora**, ignorando a carência e
  exigindo a palavra EXCLUIR, como no bloco de pedidos. As contas de usuário continuam preservadas, porque podem
  ser de outros escritórios. O texto do modal fica "todos os vínculos de usuários, pastas e arquivos". Alternativa:
  manter a quarentena e trocar "irreversível" por "recuperável por 365 dias".
- **Q2. Suspender a conta do Master.** O desenho só esconde "Excluir". Recomendação: também recusar a suspensão
  da conta criada por um Master, para ele não se trancar fora do próprio escritório.
- **Q3. Vaga no limite de escritórios.** Hoje conta suspensa não ocupa vaga, e o titular suspenso pode abrir
  outro escritório. Recomendação: a conta suspensa (sem `excluidoEm`) continua ocupando a vaga.
- **Q4. Rotas antigas de exclusão** (`escritorio_excluir` para qualquer perfil com
  `admin.tenant.settings.manage`, e `app_tenant_delete`). Recomendação: passam a recusar a conta criada por um
  Master já nesta frente. A troca de `escritorio_excluir` pelo "pedido de exclusão" é da 7d (PR 5).

## 7. Contrato com a Contribua para a frente de limites (combinado em 19/10, PR #64 da f6)

- Leitura: `App\Contribua\Service\SituacaoDoEspaco::doEscritorio(int $tenantId)` devolve `RetratoDoEspaco` (bytes usados
  e limite, percentual, faixa, origem do limite, plano, limite de usuários e origem, bloqueio). Formatação em
  `RetratoDoEspaco::formatarGb()`. Nunca ler `EspacoDoEscritorio` fora do Contribua (teste de arquitetura da f6).
- Escrita (controller do Master, `ROLE_SUPER_ADMIN`):
  - `DefinirLimiteDeArmazenamentoUseCase` (tenantId, ?cotaGb, semLimite, justificativa);
  - `DefinirLimiteDeUsuariosUseCase` (tenantId, ?limite, justificativa);
  - `ConfigurarFaixasDoEspacoUseCase` (faixas e bloqueioAtivo).

  Erros de regra chegam como `\DomainException`, já com os textos do 05.
- Auditoria: o controller do Master envolve as duas primeiras escritas no escopo "como a conta alvo", para gravar
  com o `tenant_id` da conta, e as faixas no escopo de plataforma. A f6 não envolve nada.
- Entidades `Auditavel`: `EspacoDoEscritorio`, `ConfiguracaoDoEspaco` e `Plano`. A justificativa é um campo de
  `EspacoDoEscritorio`, regravado a cada mudança. O rótulo "Limite de armazenamento" ou "Limite de usuários" sai
  da coluna que mudou no diff (`cota_gb`/`sem_limite` ou `limite_usuarios`), e o detalhe é
  "ant → novo · justificativa".
- Bloqueio de envio (decisão do dono de 19/10, registrada na spec do PR #64, §5): a publicação sai com
  `bloqueioAtivo=false` e sem alterar dado de escritório. A tela do Master que liga a chave
  (`ConfigurarFaixasDoEspacoUseCase`) mostra ao lado dela: "Antes de ligar, confira os escritórios acima do plano
  e defina sem limite ou uma cota própria." Motivo: o único escritório com arquivos em produção usa 24,29 GB,
  acima do Free de 10 GB.
- Planos (decisão do dono de 19/10, via coordenação). Preços do desenho 08: Essencial R$ 19,90, Profissional
  R$ 39,90 e Avançado R$ 69,90. Limites de usuários do desenho 05: Free 3, Essencial 8, Profissional 15 e Avançado
  sem limite. A carga inicial é da C1 da f6 (#64). A seção "Planos e limites" do painel edita preço, cota e
  limite de usuários de cada plano: o dono quer mudar esses valores depois com facilidade. A escrita fica com um
  UseCase de catálogo pedido à f6 (`AlterarPlanoUseCase`, nome a confirmar), mais uma leitura do catálogo com a
  contagem de contas por plano. O Master grava no escopo de plataforma.
- Catálogo de planos, nomes finais no #64 (commit 8f494919 da f6):
  - escrita: `App\Contribua\UseCase\AlterarPlanoUseCase::executar(AlterarPlanoInput(codigo, nome, cotaGb,
    precoCentavos, ?limiteUsuarios, descricao, ativo))`. O ator vem do escopo de auditoria e o código do plano não
    muda. Erros chegam como `\DomainException` com o texto para a tela;
  - leitura: `App\Contribua\Service\CatalogoDePlanos::todos()`, que devolve `list<PlanoDoCatalogo>`, com
    `escritorios` = contas por plano.

  Carga do dono (D5):

  | Plano | Espaço | Preço | Usuários |
  |---|---|---|---|
  | Free | 10 GB | R$ 0 | 3 |
  | Essencial | 20 GB | R$ 19,90 | 8 |
  | Profissional | 50 GB | R$ 39,90 | 15 |
  | Avançado | 100 GB | R$ 69,90 | sem limite |

  Limite de usuários no convite: `RetratoDoEspaco::aceitaMaisUmUsuario(int)`. Só recusa com o bloqueio ligado.
